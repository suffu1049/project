<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\AIException;
use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Services\AIService;

/**
 * AI assistance endpoints (Phase 10).
 *
 * AI is OPTIONAL enrichment that never gates the emergency workflow:
 *   - The response always carries `source` (`ai` or `fallback`) plus a
 *     `disclaimer` so the UI can visually separate an AI suggestion from a
 *     system decision or a human decision.
 *   - AI may only propose a category / priority / summary. Nothing is written
 *     to the request row; the citizen picks the stored category and
 *     PriorityService stays authoritative.
 *   - Every failure path (missing key, timeout, malformed body, provider
 *     outage) degrades to `source = fallback` with HTTP 200 — never a 5xx —
 *     so a client can always continue without AI.
 */
final class AiAssistController extends Controller
{
    private const DISCLAIMER = 'AI suggestion only — review before relying on it. '
        . 'Priority is always calculated by the deterministic rule engine, and '
        . 'the category you select is what gets stored.';

    public function __construct(private readonly AIService $ai = new AIService())
    {
    }

    /**
     * POST /api/v1/ai/classify
     */
    public function classify(Request $request): Response
    {
        if (($denied = $this->denied($request)) !== null) {
            return $denied;
        }

        $description = $this->description($request);

        if ($description === null) {
            return $this->jsonError(
                'Validation failed.',
                'VALIDATION_ERROR',
                422,
                ['description' => 'A description of at least 10 characters is required.']
            );
        }

        $result = $this->ai->classifyEmergency($description);

        return $this->json([
            'feature'    => 'classify',
            'available'  => $this->ai->isAvailable(),
            'source'     => $result['source'],
            'result'     => $result,
            'disclaimer' => self::DISCLAIMER,
        ], $result['source'] === 'ai' ? 'AI suggestion ready.' : 'Fallback suggestion ready.');
    }

    /**
     * POST /api/v1/ai/summarize
     */
    public function summarize(Request $request): Response
    {
        if (($denied = $this->denied($request)) !== null) {
            return $denied;
        }

        $description = $this->description($request);

        if ($description === null) {
            return $this->jsonError(
                'Validation failed.',
                'VALIDATION_ERROR',
                422,
                ['description' => 'A description of at least 10 characters is required.']
            );
        }

        $result = $this->ai->summarizeRequest($description);

        return $this->json([
            'feature'    => 'summarize',
            'available'  => $this->ai->isAvailable(),
            'source'     => $result['source'],
            'result'     => $result,
            'disclaimer' => self::DISCLAIMER,
        ], 'Summary ready.');
    }

    /**
     * POST /api/v1/ai/extract
     */
    public function extract(Request $request): Response
    {
        if (($denied = $this->denied($request)) !== null) {
            return $denied;
        }

        $description = $this->description($request);

        if ($description === null) {
            return $this->jsonError(
                'Validation failed.',
                'VALIDATION_ERROR',
                422,
                ['description' => 'A description of at least 10 characters is required.']
            );
        }

        $result = $this->ai->extractRequestData($description);

        return $this->json([
            'feature'    => 'extract',
            'available'  => $this->ai->isAvailable(),
            'source'     => $result['source'],
            'result'     => $result,
            'disclaimer' => self::DISCLAIMER,
        ], 'Extraction ready.');
    }

    /**
     * POST /api/v1/ai/normalize
     */
    public function normalize(Request $request): Response
    {
        if (($denied = $this->denied($request)) !== null) {
            return $denied;
        }

        $description = $this->description($request);

        if ($description === null) {
            return $this->jsonError(
                'Validation failed.',
                'VALIDATION_ERROR',
                422,
                ['description' => 'A description of at least 10 characters is required.']
            );
        }

        $result = $this->ai->normalizeRequest($description);

        return $this->json([
            'feature'    => 'normalize',
            'available'  => $this->ai->isAvailable(),
            'source'     => $result['source'],
            'result'     => $result,
            'disclaimer' => self::DISCLAIMER,
        ], 'Normalization ready.');
    }

    /**
     * POST /requests/ai-assist — browser route (same payload, JSON response).
     */
    public function assist(Request $request): Response
    {
        if (($denied = $this->denied($request)) !== null) {
            return $denied;
        }

        $description = $this->description($request);

        if ($description === null) {
            return $this->jsonError(
                'Validation failed.',
                'VALIDATION_ERROR',
                422,
                ['description' => 'A description of at least 10 characters is required.']
            );
        }

        $feature = (string) ($request->input('feature') ?: 'classify');

        $result = match ($feature) {
            'summarize' => $this->ai->summarizeRequest($description),
            'extract'   => $this->ai->extractRequestData($description),
            'normalize' => $this->ai->normalizeRequest($description),
            default     => $this->ai->classifyEmergency($description),
        };

        return $this->json([
            'feature'    => $feature,
            'available'  => $this->ai->isAvailable(),
            'source'     => $result['source'],
            'result'     => $result,
            'disclaimer' => self::DISCLAIMER,
        ], 'Assistance ready.');
    }

    /**
     * Guests get 401; every other authenticated role may ask for suggestions
     * (only citizens can create requests, but volunteers/orgs benefit from the
     * summary view). No role can turn AI output into a system decision here.
     */
    private function denied(Request $request): ?Response
    {
        $user = $request->attribute('user') ?? auth_user();

        if (!is_array($user) || $user === []) {
            return $this->jsonError('Authentication required.', 'UNAUTHORIZED', 401);
        }

        if ((string) ($user['role'] ?? '') === '') {
            return $this->jsonError('You are not authorized to perform this action.', 'FORBIDDEN', 403);
        }

        return null;
    }

    /**
     * Read + validate the description (bounded in the service too).
     */
    private function description(Request $request): ?string
    {
        $raw = $request->input('description');

        if (!is_string($raw)) {
            return null;
        }

        $raw = trim($raw);

        if (mb_strlen($raw) < 10 || mb_strlen($raw) > 5000) {
            return null;
        }

        return $raw;
    }
}
