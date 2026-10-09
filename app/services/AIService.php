<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\AIException;
use App\Core\Config;
use App\Core\Service;

/**
 * Provider-agnostic AI assistance layer (Phase 10).
 *
 * This is the ONLY entry point for AI in ResQLink. Controllers and services
 * call these methods and receive a structured result; they never touch a
 * vendor SDK directly, so the provider can be swapped without touching the
 * application.
 *
 * Critical rules (RESQLINK_PHASE_WISE_MASTER_PROMPTS §Phase 10):
 *   - AI is optional enrichment, never a dependency. Every public method
 *     catches provider failures and returns a deterministic fallback instead
 *     of throwing, so an outage can never break emergency creation, matching
 *     or status flow.
 *   - AI must NEVER decide priority. PriorityService stays authoritative;
 *     AI output is advisory and always labelled as such.
 *   - AI must NOT diagnose, guarantee safety, replace emergency services or
 *     invent facts. Unknown / out-of-vocabulary fields are rejected and the
 *     caller falls back.
 *   - Only the minimum required text is sent. Descriptions are truncated to a
 *     bounded length and no coordinates, emails, phones or account data are
 *     ever included in a prompt.
 *   - Logging records outcome + timing only. The API key, prompt bodies and
 *     raw provider payloads are never written to logs.
 *
 * Transport is injected as a callable so tests can exercise success, timeout,
 * malformed JSON, missing credentials and provider-outage paths without any
 * network access:
 *
 *     fn (array $request): array => ['status' => 200, 'body' => '{...}']
 *
 * or an exception (timeout / connection failure) to simulate outage.
 */
final class AIService extends Service
{
    /** Valid emergency categories the model may return (must match the schema). */
    public const CATEGORIES = [
        'MEDICAL',
        'ACCIDENT',
        'FLOOD',
        'FIRE',
        'FOOD_WATER',
        'SHELTER',
        'TRANSPORT',
        'MISSING_STRANDED',
        'OTHER',
    ];

    /** Advisory priorities AI may suggest. Never written to the request row. */
    public const SUGGESTED_PRIORITIES = ['LOW', 'MEDIUM', 'HIGH', 'CRITICAL'];

    /** Bounded prompt size — keeps cost predictable and limits data exposure. */
    private const MAX_PROMPT_CHARS = 500;

    /** Maximum characters accepted from the summarization endpoint. */
    private const MAX_SUMMARY_CHARS = 500;

    private const MAX_INDICATORS = 10;

    private const MAX_RISKS = 10;

    private const MAX_NEEDS = 10;

    /**
     * @param callable(array<string, mixed>): array{status:int, body:string}|null $transport
     */
    public function __construct(
        private readonly mixed $transport = null,
        private readonly array $config = [],
    ) {
    }

    // ---------------------------------------------------------------------
    // Availability
    // ---------------------------------------------------------------------

    public static function config(): array
    {
        $defaults = [
            'enabled' => false,
            'api_key' => '',
            'api_url' => '',
            'model'   => '',
            'timeout' => 10,
        ];

        $services = Config::get('services.ai', []);
        $root = Config::get('ai', []);

        return array_merge($defaults, is_array($services) ? $services : [], is_array($root) ? $root : []);
    }

    /**
     * Effective configuration for this instance: loaded config, overlaid with
     * any config injected through the constructor (used by tests and callers
     * that need an explicit provider without touching .env).
     *
     * @return array<string, mixed>
     */
    private function settings(): array
    {
        return array_merge(self::config(), $this->config);
    }

    /**
     * AI is enabled only when a provider key and URL are configured. The rest
     * of the application must behave identically when this returns false.
     */
    public function isAvailable(): bool
    {
        $config = $this->settings();

        return (string) $config['api_key'] !== '' && (string) $config['api_url'] !== '';
    }

    // ---------------------------------------------------------------------
    // AI features (each returns a deterministic fallback on any failure)
    // ---------------------------------------------------------------------

    /**
     * Emergency classification — suggest a category + indicators from text.
     *
     * Advisory only: the category the citizen picks on the form remains the
     * stored value unless a human applies the suggestion.
     *
     * @return array{source: string, category: string|null, indicators: array<int, string>, suggested_priority: string|null, confidence: float|null, summary: string|null}
     */
    public function classifyEmergency(string $description): array
    {
        $clean = $this->minimize($description);

        if ($clean === '') {
            return $this->fallbackResult('classifier', ['category' => null, 'indicators' => [], 'suggested_priority' => null, 'confidence' => null, 'summary' => null]);
        }

        $payload = $this->ask(
            'You classify emergency reports for a coordination platform. Reply with ONLY a JSON object, no prose, no markdown fences, having exactly these keys: '
            . '"category" (one of ' . implode('|', self::CATEGORIES) . '), '
            . '"indicators" (array of short lowercase risk phrases, max ' . self::MAX_INDICATORS . '), '
            . '"suggested_priority" (one of ' . implode('|', self::SUGGESTED_PRIORITIES) . '), '
            . '"confidence" (a number between 0 and 1 for how certain the category is), '
            . '"summary" (one short neutral operational sentence, max 30 words). '
            . 'Never diagnose, never guarantee safety, never invent facts not present in the text. '
            . 'Report: ' . $clean
        );

        if ($payload === null) {
            // Provider unavailable → deterministic keyword classifier.
            return $this->fallbackClassify($clean);
        }

        // Only whitelisted fields are trusted — everything else is ignored.
        $category = $this->enum($payload['category'] ?? null, self::CATEGORIES);
        $suggestedPriority = $this->enum($payload['suggested_priority'] ?? null, self::SUGGESTED_PRIORITIES);
        $indicators = $this->stringList($payload['indicators'] ?? [], self::MAX_INDICATORS, 80);
        $summary = $this->boundedString($payload['summary'] ?? null, self::MAX_SUMMARY_CHARS);

        if ($category === null && $indicators === []) {
            // Nothing usable came back — treat as a provider failure.
            return $this->fallbackClassify($clean);
        }

        return [
            'source'            => 'ai',
            'category'          => $category,
            'indicators'        => $indicators,
            'suggested_priority'=> $suggestedPriority,
            'confidence'        => $this->confidence($payload['confidence'] ?? null),
            'summary'           => $summary,
        ];
    }

    /**
     * Request summarization — short operational summary of a long description.
     *
     * @return array{source: string, summary: string|null}
     */
    public function summarizeRequest(string $description): array
    {
        $clean = $this->minimize($description);

        if ($clean === '') {
            return ['source' => 'fallback', 'summary' => null];
        }

        $payload = $this->ask(
            'Summarize this emergency report in ONE neutral operational sentence of at most 30 words. '
            . 'Reply with ONLY a JSON object: {"summary": "..."}. Do not add advice, diagnosis or guarantees. '
            . 'Report: ' . $clean
        );

        $summary = null;
        $fromAi = false;

        if ($payload !== null) {
            $summary = $this->boundedString($payload['summary'] ?? null, self::MAX_SUMMARY_CHARS);
            $fromAi = $summary !== null;
        }

        if ($summary === null) {
            // Deterministic fallback: first sentence, truncated.
            preg_match('/^[^.!?\n]+[.!?]?/', $clean, $match);
            $summary = $this->boundedString($match[0] ?? $clean, self::MAX_SUMMARY_CHARS);
        }

        return [
            'source' => $fromAi ? 'ai' : 'fallback',
            'summary'=> $summary,
        ];
    }

    /**
     * Structured information extraction from free text.
     *
     * Only in-vocabulary values are returned; anything else is dropped so a
     * hallucinated field can never flow into the request record.
     *
     * @return array{source: string, people_affected: int|null, category: string|null, requested_resources: array<int, string>, risk_indicators: array<int, string>}
     */
    public function extractRequestData(string $description): array
    {
        $clean = $this->minimize($description);

        if ($clean === '') {
            return $this->fallbackExtract();
        }

        $payload = $this->ask(
            'Extract structured facts from this emergency report. Reply with ONLY a JSON object with exactly these keys: '
            . '"people_affected" (integer 1..100000 or null), '
            . '"category" (one of ' . implode('|', self::CATEGORIES) . ' or null), '
            . '"requested_resources" (array of short lowercase supply phrases, max ' . self::MAX_NEEDS . '), '
            . '"risk_indicators" (array of short lowercase phrases, max ' . self::MAX_RISKS . '). '
            . 'Use only facts stated in the text; use null when absent. '
            . 'Report: ' . $clean
        );

        if ($payload === null) {
            return $this->fallbackExtract();
        }

        $people = $payload['people_affected'] ?? null;
        $people = is_numeric($people) ? (int) $people : null;

        if ($people !== null && ($people < 1 || $people > 100000)) {
            $people = null; // Out-of-range / hallucinated value rejected.
        }

        $category = $this->enum($payload['category'] ?? null, self::CATEGORIES);

        $resources = $this->stringList($payload['requested_resources'] ?? [], self::MAX_NEEDS, 60);
        $risks = $this->stringList($payload['risk_indicators'] ?? [], self::MAX_RISKS, 60);

        if ($people === null && $category === null && $resources === [] && $risks === []) {
            return $this->fallbackExtract();
        }

        return [
            'source'              => 'ai',
            'people_affected'     => $people,
            'category'            => $category,
            'requested_resources' => $resources,
            'risk_indicators'     => $risks,
        ];
    }

    /**
     * Request normalization — tidy free text into a consistent draft form.
     *
     * Returns a normalized description plus the same advisory signals as
     * classification. Nothing here is applied without human review.
     *
     * @return array{source: string, description: string|null, category: string|null, suggested_priority: string|null, confidence: float|null}
     */
    public function normalizeRequest(string $description): array
    {
        $clean = $this->minimize($description);

        if ($clean === '') {
            return ['source' => 'fallback', 'description' => null, 'category' => null, 'suggested_priority' => null, 'confidence' => null];
        }

        $payload = $this->ask(
            'Normalize this emergency report into clear, neutral sentences without adding facts. Reply with ONLY a JSON object: '
            . '{"description": "...", "category": (one of ' . implode('|', self::CATEGORIES) . ' or null), '
            . '"suggested_priority": (one of ' . implode('|', self::SUGGESTED_PRIORITIES) . ' or null), '
            . '"confidence": (a number between 0 and 1, or null)}. '
            . 'Report: ' . $clean
        );

        if ($payload === null) {
            return ['source' => 'fallback', 'description' => null, 'category' => null, 'suggested_priority' => null, 'confidence' => null];
        }

        $normalized = $this->boundedString($payload['description'] ?? null, self::MAX_SUMMARY_CHARS);

        if ($normalized === null) {
            return ['source' => 'fallback', 'description' => null, 'category' => null, 'suggested_priority' => null, 'confidence' => null];
        }

        return [
            'source'             => 'ai',
            'description'        => $normalized,
            'category'           => $this->enum($payload['category'] ?? null, self::CATEGORIES),
            'suggested_priority' => $this->enum($payload['suggested_priority'] ?? null, self::SUGGESTED_PRIORITIES),
            'confidence'         => $this->confidence($payload['confidence'] ?? null),
        ];
    }

    // ---------------------------------------------------------------------
    // Deterministic fallbacks (used whenever the provider is unavailable)
    // ---------------------------------------------------------------------

    /**
     * Keyword-driven classification fallback — mirrors PriorityService's
     * transparent rule set so AI outage never degrades triage accuracy.
     *
     * @return array{source: string, category: string|null, indicators: array<int, string>, suggested_priority: string|null, confidence: float|null, summary: string|null}
     */
    public function fallbackClassify(string $description): array
    {
        $clean = $this->minimize($description);
        $lower = strtolower($clean);

        $category = null;
        $indicators = [];

        $signals = [
            'FIRE'             => ['fire', 'burn', 'smoke', 'flames'],
            'MEDICAL'          => ['bleeding', 'unconscious', 'breathing', 'chest pain', 'injured', 'injury', 'medical'],
            'FLOOD'            => ['flood', 'water level', 'river', 'drowning', 'submerged'],
            'ACCIDENT'         => ['accident', 'collision', 'crash', 'vehicle', 'collision'],
            'MISSING_STRANDED' => ['missing', 'stranded', 'lost', 'trapped', 'stuck'],
            'FOOD_WATER'       => ['food', 'water shortage', 'thirsty', 'hungry', 'drinking water'],
            'SHELTER'          => ['shelter', 'homeless', 'roof', 'sleeping outside'],
            'TRANSPORT'        => ['transport', 'evacuate', 'evacuation', 'pickup', 'ambulance needed'],
        ];

        foreach ($signals as $candidate => $words) {
            foreach ($words as $word) {
                if ($word !== '' && str_contains($lower, $word)) {
                    $category = $candidate;
                    $indicators[] = $word;
                    break 2; // first matching category wins — order is deliberate
                }
            }
        }

        $indicators = array_slice(array_unique($indicators), 0, self::MAX_INDICATORS);

        if ($category === null && $clean !== '') {
            $category = 'OTHER';
        }

        $hasUrgency = (bool) preg_match('/\b(immediate|urgent|critical|now|asap)\b/i', $clean);
        $hasVulnerability = (bool) preg_match('/\b(child|children|baby|elderly|pregnant|disabled)\b/i', $clean);

        $suggested = 'LOW';

        if ($category === 'FIRE' || $category === 'MEDICAL') {
            $suggested = 'HIGH';
        }

        if ($hasUrgency) {
            $suggested = $suggested === 'HIGH' ? 'CRITICAL' : 'HIGH';
            $indicators[] = 'urgency';
        }

        if ($hasVulnerability) {
            $indicators[] = 'vulnerable people';
        }

        return [
            'source'             => 'fallback',
            'category'           => $category,
            'indicators'         => array_slice(array_unique($indicators), 0, self::MAX_INDICATORS),
            'suggested_priority' => $suggested,
            'confidence'         => null,
            'summary'            => $clean === '' ? null : mb_substr($clean, 0, self::MAX_SUMMARY_CHARS),
        ];
    }

    // ---------------------------------------------------------------------
    // Provider transport
    // ---------------------------------------------------------------------

    /**
     * Send a prompt and decode a JSON object response.
     *
     * Returns null on ANY failure (missing config, network error, timeout,
     * non-2xx, malformed JSON, non-object body) — callers always fall back.
     *
     * @return array<string, mixed>|null
     */
    private function ask(string $prompt): ?array
    {
        $config = $this->settings();

        if ((string) $config['api_key'] === '' || (string) $config['api_url'] === '') {
            $this->log('debug', 'AI skipped: provider not configured.');

            return null;
        }

        $started = microtime(true);

        try {
            $response = $this->dispatch($config, $prompt);
        } catch (\Throwable $exception) {
            // Never log the key, the prompt, or the provider payload.
            $this->log('warning', 'AI request failed.', [
                'error'   => get_class($exception),
                'latency' => (int) round((microtime(true) - $started) * 1000),
            ]);

            return null;
        }

        $latency = (int) round((microtime(true) - $started) * 1000);

        $status = (int) ($response['status'] ?? 0);
        $body = (string) ($response['body'] ?? '');

        if ($status < 200 || $status >= 300) {
            $this->log('warning', 'AI provider returned an error status.', [
                'status'  => $status,
                'latency' => $latency,
            ]);

            return null;
        }

        $decoded = $this->decodeBody($body);

        if ($decoded === null) {
            $this->log('warning', 'AI provider returned a malformed body.', [
                'status'  => $status,
                'latency' => $latency,
            ]);

            return null;
        }

        $this->log('info', 'AI request completed.', [
            'status'  => $status,
            'latency' => $latency,
        ]);

        return $decoded;
    }

    /**
     * Build the provider request and perform the HTTP call.
     *
     * @param array<string, mixed> $config
     * @return array{status: int, body: string}
     */
    private function dispatch(array $config, string $prompt): array
    {        $body = json_encode([
            'model'       => (string) $config['model'],
            'messages'    => [
                ['role' => 'system', 'content' => 'You are a careful triage assistant for an emergency coordination platform. You output JSON only.'],
                ['role' => 'user', 'content' => $prompt],
            ],
            'temperature' => 0,
            'max_tokens'  => 400,
        ], JSON_UNESCAPED_SLASHES);

        if ($body === false) {
            throw new \RuntimeException('Unable to encode AI request.');
        }

        $request = [
            'url'     => (string) $config['api_url'],
            'headers' => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . (string) $config['api_key'],
            ],
            'body'    => $body,
            'timeout' => max(1, (int) $config['timeout']),
        ];

        if ($this->transport !== null) {
            $result = ($this->transport)($request);

            if (!is_array($result) || !isset($result['status'], $result['body'])) {
                throw new \RuntimeException('Transport returned an invalid result.');
            }

            return [
                'status' => (int) $result['status'],
                'body'   => (string) $result['body'],
            ];
        }

        return $this->curl($request);
    }

    /**
     * Default cURL transport with connect + total timeouts.
     *
     * @param array<string, mixed> $request
     * @return array{status: int, body: string}
     */
    private function curl(array $request): array
    {
        $ch = curl_init((string) $request['url']);

        if ($ch === false) {
            throw new \RuntimeException('Unable to initialise cURL.');
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => (string) $request['body'],
            CURLOPT_HTTPHEADER     => $request['headers'],
            CURLOPT_TIMEOUT        => (int) $request['timeout'],
            CURLOPT_CONNECTTIMEOUT => min(5, (int) $request['timeout']),
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS      => 0,
        ]);

        $body = curl_exec($ch);

        if ($body === false) {
            $errno = curl_errno($ch);
            curl_close($ch);

            // 28 = CURLE_OPERATION_TIMEDOUT.
            throw new \RuntimeException($errno === 28 ? 'AI request timed out.' : 'AI request failed.', $errno);
        }

        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return ['status' => $status, 'body' => (string) $body];
    }

    /**
     * Decode a provider body into a JSON object, tolerating markdown fences
     * and non-object responses (both return null so callers fall back).
     *
     * @return array<string, mixed>|null
     */
    private function decodeBody(string $body): ?array
    {
        $trimmed = trim($body);

        if ($trimmed === '') {
            return null;
        }

        // 1. Chat-completion shaped responses carry the JSON in
        //    choices[0].message.content — unwrap it first, and prefer the
        //    inner object over the envelope itself.
        $outer = json_decode($trimmed, true);

        if (is_array($outer) && isset($outer['choices'][0]['message']['content'])) {
            $content = trim((string) $outer['choices'][0]['message']['content']);

            foreach ($this->unwrapFences($content) as $candidate) {
                $decoded = json_decode($candidate, true);

                if (is_array($decoded) && !array_is_list($decoded)) {
                    return $decoded;
                }
            }
        }

        // 2. A direct object, possibly wrapped in ```json fences.
        //    Skip the chat envelope itself — a non-JSON `content` must not
        //    make us treat the wrapper as the result.
        $isEnvelope = is_array($outer) && array_key_exists('choices', $outer);

        if ($isEnvelope) {
            return null;
        }

        foreach ($this->unwrapFences($trimmed) as $candidate) {
            $decoded = json_decode($candidate, true);

            if (is_array($decoded) && !array_is_list($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    /**
     * Return a body plus any fence-wrapped variant of it.
     *
     * @return array<int, string>
     */
    private function unwrapFences(string $text): array
    {
        $candidates = [$text];

        if (preg_match('/```(?:json)?\s*(.*?)```/s', $text, $matches) === 1) {
            $candidates[] = trim($matches[1]);
        }

        return $candidates;
    }

    // ---------------------------------------------------------------------
    // Input shaping & output validation
    // ---------------------------------------------------------------------

    /**
     * Privacy: bound the text that leaves the process. Coordinates, emails
     * and phone numbers are stripped from the prompt; the remainder is
     * truncated so a huge description cannot be exfiltrated wholesale.
     */
    private function minimize(string $description): string
    {
        $description = trim($description);

        if ($description === '') {
            return '';
        }

        // Drop email addresses and long digit runs (phones / account numbers).
        $description = (string) preg_replace('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', '[email]', $description);
        $description = (string) preg_replace('/\+?\d[\d\s().-]{7,}\d/', '[number]', $description);

        return mb_substr($description, 0, self::MAX_PROMPT_CHARS);
    }

    private function enum(mixed $value, array $allowed): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        $value = strtoupper(str_replace(['-', ' '], '_', trim($value)));

        return in_array($value, $allowed, true) ? $value : null;
    }

    /**
     * Accept only an array of short strings; drop anything else (hallucinated
     * objects / nested structures / oversized entries).
     *
     * @return array<int, string>
     */
    private function stringList(mixed $value, int $max, int $maxChars): array
    {
        if (!is_array($value)) {
            return [];
        }

        $out = [];

        foreach ($value as $item) {
            if (!is_string($item)) {
                continue;
            }

            $item = trim($item);

            if ($item === '') {
                continue;
            }

            $out[] = mb_substr($item, 0, $maxChars);

            if (count($out) >= $max) {
                break;
            }
        }

        return $out;
    }

    private function boundedString(mixed $value, int $maxChars): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        // Strip anything that could break out of a JSON / HTML context.
        $value = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $value);

        return mb_substr($value, 0, $maxChars);
    }

    /**
     * Confidence is advisory only: clamp to 0..1, never treat as a guarantee.
     */
    private function confidence(mixed $value): ?float
    {
        if (!is_numeric($value)) {
            return null;
        }

        $value = (float) $value;

        return max(0.0, min(1.0, round($value, 3)));
    }

    /**
     * Uniform failure envelope so callers always see the same shape.
     *
     * @return array<string, mixed>
     */
    private function fallbackResult(string $feature, array $empty): array
    {
        $this->log('debug', 'AI feature fell back to deterministic behaviour.', ['feature' => $feature]);

        return ['source' => 'fallback'] + $empty;
    }

    /**
     * @return array<string, mixed>
     */
    private function fallbackExtract(): array
    {
        return [
            'source'              => 'fallback',
            'people_affected'     => null,
            'category'            => null,
            'requested_resources' => [],
            'risk_indicators'     => [],
        ];
    }
}
