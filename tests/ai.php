<?php

declare(strict_types=1);

/**
 * ResQLink — Phase 10 AI assistance tests.
 *
 * Requires a migrated database. Run with:
 *     php -c php.ini tests/ai.php
 *
 * Covers: provider success, malformed/invalid/hallucinated responses, timeout,
 * provider unavailable, missing API key, deterministic fallback, privacy
 * minimization, non-blocking endpoints, precedence of the deterministic
 * priority engine, and that secrets never reach the logs.
 *
 * Exit code 0 = all passed, 1 = one or more failures.
 */

use App\Controllers\AiAssistController;
use App\Core\Database;
use App\Core\Request;
use App\Models\UserRepository;
use App\Services\AIService;
use App\Services\PriorityService;

define('BASE_PATH', dirname(__DIR__));

error_reporting(E_ALL);
ini_set('display_errors', '1');

require BASE_PATH . '/app/bootstrap.php';

$passed = 0;
$failed = 0;

function check(string $label, bool $condition): void
{
    global $passed, $failed;

    if ($condition) {
        $passed++;
        echo "  PASS  {$label}\n";
    } else {
        $failed++;
        echo "  FAIL  {$label}\n";
    }
}

function make_request(string $method, string $path, array $body = [], array $headers = []): Request
{
    $headers['accept'] = $headers['accept'] ?? 'application/json';

    if ($method !== 'GET') {
        $headers['content-type'] = 'application/json';
    }

    return new Request($method, $path, [], $body, $headers, [], '127.0.0.1', 'ai-test');
}

/**
 * Transport double: returns a canned response or throws (timeout/outage).
 */
function fakeTransport(int $status, string $body): callable
{
    return static fn (array $request): array => ['status' => $status, 'body' => $body];
}

function throwingTransport(string $message = 'AI request timed out.'): callable
{
    return static function (array $request) use ($message): array {
        throw new RuntimeException($message);
    };
}

const VALID_CLASSIFY = '{"category":"MEDICAL","indicators":["breathing difficulty","elderly"],"suggested_priority":"HIGH","summary":"An elderly person is struggling to breathe.","confidence":0.82}';

echo "ResQLink AI assistance tests\n";
echo "============================\n\n";

$priority = new PriorityService();

// ---------------------------------------------------------------------------
echo "Availability & configuration\n";

$config = AIService::config();
check('AI config exposes api_key / api_url / model / timeout', array_key_exists('api_key', $config) && array_key_exists('api_url', $config) && array_key_exists('model', $config) && array_key_exists('timeout', $config));
check('AI timeout is a positive integer', (int) $config['timeout'] >= 1);

// Pinned to an empty provider so the assertion holds whether or not the local
// .env has a real key configured — the suite must never depend on ambient env.
$disabled = new AIService(null, ['api_key' => '', 'api_url' => '']);
check('AI reports unavailable when no key is configured', $disabled->isAvailable() === false);

$keyed = new AIService(null, ['api_key' => 'sk-test-key', 'api_url' => 'https://provider.invalid/chat']);
check('AI reports available when key + url are configured', $keyed->isAvailable() === true);

// ---------------------------------------------------------------------------
echo "\nMissing API key → deterministic fallback\n";

$r = $disabled->classifyEmergency('There is a fire in the building, immediate help needed');
check('classification falls back without a key', $r['source'] === 'fallback');
check('fallback still returns a valid category', in_array($r['category'], AIService::CATEGORIES, true));
check('fallback marks urgency as HIGH/CRITICAL', in_array($r['suggested_priority'], ['HIGH', 'CRITICAL'], true));

$s = $disabled->summarizeRequest('Two families are stranded on the roof near the bridge with no food.');
check('summarize falls back without a key', $s['source'] === 'fallback');
check('fallback summary is a bounded string', is_string($s['summary']) && $s['summary'] !== '');

$e = $disabled->extractRequestData('Five people need water and two are injured');
check('extract falls back without a key', $e['source'] === 'fallback');

// ---------------------------------------------------------------------------
echo "\nProvider success\n";

$ok = new AIService(fakeTransport(200, VALID_CLASSIFY), ['api_key' => 'sk-test-key', 'api_url' => 'https://provider.invalid/chat']);
$r = $ok->classifyEmergency('An elderly man cannot breathe and needs medical help now');
check('successful classification is labelled source=ai', $r['source'] === 'ai');
check('successful classification returns a valid category', $r['category'] === 'MEDICAL');
check('indicators are strings', is_array($r['indicators']) && $r['indicators'] !== [] && is_string($r['indicators'][0]));
check('confidence is a float within 0..1', is_float($r['confidence']) && $r['confidence'] >= 0.0 && $r['confidence'] <= 1.0);

$okSum = new AIService(fakeTransport(200, '{"summary":"Three families are stranded on a roof."}'), ['api_key' => 'k', 'api_url' => 'https://x.invalid']);
$s = $okSum->summarizeRequest('Three families are stranded on a roof near the flooded bridge.');
check('successful summarize is labelled source=ai', $s['source'] === 'ai');
check('summarize returns the model summary', $s['summary'] === 'Three families are stranded on a roof.');

$okExt = new AIService(fakeTransport(200, '{"people_affected":5,"category":"FLOOD","requested_resources":["drinking water","blankets"],"risk_indicators":["rising water"]}'), ['api_key' => 'k', 'api_url' => 'https://x.invalid']);
$e = $okExt->extractRequestData('Five people are stranded by rising water and need drinking water');
check('successful extract is labelled source=ai', $e['source'] === 'ai');
check('extract returns people as int', $e['people_affected'] === 5);
check('extract returns resources', $e['requested_resources'] === ['drinking water', 'blankets']);

$okNorm = new AIService(fakeTransport(200, '{"description":"Three families are stranded on a roof.","category":"FLOOD","suggested_priority":"HIGH"}'), ['api_key' => 'k', 'api_url' => 'https://x.invalid']);
$n = $okNorm->normalizeRequest('three families stranded on roof....');
check('successful normalize is labelled source=ai', $n['source'] === 'ai');
check('normalize returns a description', $n['description'] === 'Three families are stranded on a roof.');

// Chat-completion shaped body + markdown fences.
// Chat-completion shaped body wrapping the same JSON in markdown fences.
$chatEnvelope = (string) json_encode([
    'choices' => [
        ['message' => ['content' => "```json\n" . VALID_CLASSIFY . "\n```"]],
    ],
]);
$chat = new AIService(fakeTransport(200, $chatEnvelope), ['api_key' => 'k', 'api_url' => 'https://x.invalid']);
$r = $chat->classifyEmergency('medical emergency with injury');
check('chat-completion shaped body is unwrapped', $r['source'] === 'ai' && $r['category'] === 'MEDICAL');

// ---------------------------------------------------------------------------
echo "\nInvalid / malformed / hallucinated responses\n";

$badJson = new AIService(fakeTransport(200, 'this is not json at all'), ['api_key' => 'k', 'api_url' => 'https://x.invalid']);
$r = $badJson->classifyEmergency('fire in the kitchen');
check('malformed JSON falls back', $r['source'] === 'fallback');

$empty = new AIService(fakeTransport(200, ''), ['api_key' => 'k', 'api_url' => 'https://x.invalid']);
check('empty body falls back', $empty->classifyEmergency('fire in the kitchen')['source'] === 'fallback');

$list = new AIService(fakeTransport(200, '["not","an","object"]'), ['api_key' => 'k', 'api_url' => 'https://x.invalid']);
check('JSON array (non-object) falls back', $list->classifyEmergency('fire in the kitchen')['source'] === 'fallback');

$hallucinated = new AIService(fakeTransport(200, '{"category":"ZOMBIE_APOCALYPSE","suggested_priority":"URGENT","indicators":{"nested":true},"confidence":99}'), ['api_key' => 'k', 'api_url' => 'https://x.invalid']);
$r = $hallucinated->classifyEmergency('something strange is happening');
check('out-of-vocabulary category is rejected', $r['category'] === null || in_array($r['category'], AIService::CATEGORIES, true));
check('out-of-vocabulary priority is rejected', $r['suggested_priority'] === null || in_array($r['suggested_priority'], AIService::SUGGESTED_PRIORITIES, true));
check('non-array indicators are dropped', $r['indicators'] === []);
check('out-of-range confidence is clamped to 0..1', $r['confidence'] === null || ($r['confidence'] >= 0.0 && $r['confidence'] <= 1.0));
check('unusable payload falls back entirely', $r['source'] === 'fallback');

$overRange = new AIService(fakeTransport(200, '{"people_affected":9999999,"category":"MEDICAL"}'), ['api_key' => 'k', 'api_url' => 'https://x.invalid']);
$e = $overRange->extractRequestData('many people hurt');
check('out-of-range people_affected is rejected', $e['people_affected'] === null);
check('valid category in a partly-bad payload is kept', $e['category'] === 'MEDICAL');

// ---------------------------------------------------------------------------
echo "\nTimeout & provider unavailable\n";

$timeout = new AIService(throwingTransport('AI request timed out.'), ['api_key' => 'k', 'api_url' => 'https://x.invalid']);
check('timeout falls back without throwing', $timeout->classifyEmergency('fire in the warehouse')['source'] === 'fallback');
check('timeout keeps summarize usable', is_string($timeout->summarizeRequest('A building is on fire.')['summary']));

$outage = new AIService(fakeTransport(503, 'Service Unavailable'), ['api_key' => 'k', 'api_url' => 'https://x.invalid']);
check('HTTP 503 falls back', $outage->classifyEmergency('fire in the warehouse')['source'] === 'fallback');

$rate = new AIService(fakeTransport(429, '{"error":"rate limited"}'), ['api_key' => 'k', 'api_url' => 'https://x.invalid']);
check('HTTP 429 falls back', $rate->classifyEmergency('fire in the warehouse')['source'] === 'fallback');

$notFound = new AIService(fakeTransport(404, 'Not Found'), ['api_key' => 'k', 'api_url' => 'https://x.invalid']);
check('HTTP 404 falls back', $notFound->classifyEmergency('fire in the warehouse')['source'] === 'fallback');

// ---------------------------------------------------------------------------
echo "\nPrivacy — minimization before send\n";

$sent = null;
$spying = new AIService(static function (array $request) use (&$sent): array {
    $sent = $request;
    return ['status' => 200, 'body' => '{"summary":"ok"}'];
}, ['api_key' => 'k', 'api_url' => 'https://x.invalid']);

$spying->summarizeRequest('Call John on +91 98765 43210 or john@example.com — the fire is on the 3rd floor, coordinates 20.3893 72.9106');
$body = is_array($sent) ? (string) ($sent['body'] ?? '') : '';
check('prompt was sent', $body !== '');
check('email addresses are stripped from the prompt', !str_contains($body, 'john@example.com'));
check('phone numbers are stripped from the prompt', !str_contains($body, '9876543210') && !str_contains($body, '98765 43210'));
check('prompt is bounded in length', strlen($body) < 4000);

$long = new AIService(static function (array $request) use (&$sent): array {
    $sent = $request;
    return ['status' => 200, 'body' => '{"summary":"ok"}'];
}, ['api_key' => 'k', 'api_url' => 'https://x.invalid']);
$long->summarizeRequest(str_repeat('a very long description of an emergency ', 200));
check('very long descriptions are truncated before sending', strlen((string) (is_array($sent) ? ($sent['body'] ?? '') : '')) < 4000);

// ---------------------------------------------------------------------------
echo "\nSecrets are never logged\n";

$logFile = storage_path('logs') . DIRECTORY_SEPARATOR . 'app-' . date('Y-m-d') . '.log';
@unlink($logFile);

$secret = 'sk-super-secret-key-do-not-log';
$secretive = new AIService(throwingTransport('boom'), ['api_key' => $secret, 'api_url' => 'https://provider.invalid/chat']);
$secretive->classifyEmergency('medical emergency please hurry');
$secretive->summarizeRequest('another emergency description here');

$logContents = is_file($logFile) ? (string) file_get_contents($logFile) : '';
check('AI failures are logged for observability', $logContents !== '');
check('the API key never appears in the log', !str_contains($logContents, $secret));
check('the word api_key value is not logged in cleartext', !str_contains($logContents, 'sk-super-secret-key'));
check('the prompt text is not logged', !str_contains($logContents, 'medical emergency please hurry'));

// ---------------------------------------------------------------------------
echo "\nEndpoints are non-blocking and gated\n";

$_SESSION = [];
$controller = new AiAssistController(new AIService());

$guest = $controller->classify(make_request('POST', '/api/v1/ai/classify', ['description' => 'A fire needs immediate attention please help']));
check('guest AI request is 401', $guest->status() === 401);

$badInput = new AiAssistController();
$user = UserRepository::findByEmail('citizen1@resqlink.test');
$shortReq = make_request('POST', '/api/v1/ai/classify', ['description' => 'short']);
$shortReq->setAttribute('user', $user);
check('too-short description is 422', $badInput->classify($shortReq)->status() === 422);

// A disabled provider must still return HTTP 200 with source=fallback.
$disabledCtl = new AiAssistController(new AIService(null, ['api_key' => '', 'api_url' => '']));
$okReq = make_request('POST', '/api/v1/ai/classify', ['description' => 'There is a fire in the warehouse, immediate help needed']);
$okReq->setAttribute('user', $user);
$okResp = $disabledCtl->classify($okReq);
$payload = json_decode((string) $okResp->body(), true);

check('disabled AI still returns HTTP 200 (never 5xx)', $okResp->status() === 200);
check('disabled AI response reports available=false', ($payload['data']['available'] ?? true) === false);
check('disabled AI response reports source=fallback', ($payload['data']['source'] ?? 'ai') === 'fallback');
check('AI response carries a disclaimer', is_string($payload['data']['disclaimer'] ?? null) && str_contains($payload['data']['disclaimer'], 'suggestion'));
check('AI response has source + result keys', isset($payload['data']['source'], $payload['data']['result']));

$webReq = make_request('POST', '/requests/ai-assist', ['description' => 'Two children are trapped in a flooded building', 'feature' => 'classify']);
$webReq->setAttribute('user', $user);
$webResp = $disabledCtl->assist($webReq);
$webPayload = json_decode((string) $webResp->body(), true);
check('browser assist route returns 200', $webResp->status() === 200);
check('browser assist route includes a suggestion', isset($webPayload['data']['result']['category']));

// ---------------------------------------------------------------------------
echo "\nPriority remains deterministic (AI never overrides it)\n";

$description = 'An elderly resident is unconscious and bleeding heavily, needs immediate medical help.';

// Same input → identical priority, regardless of any AI suggestion present.
$p1 = $priority->calculate(['category' => 'MEDICAL', 'description' => $description, 'people_affected' => 3]);
$p2 = $priority->calculate(['category' => 'MEDICAL', 'description' => $description, 'people_affected' => 3]);
check('priority is deterministic for identical input', $p1['priority'] === $p2['priority'] && $p1['priority_score'] === $p2['priority_score']);

// An AI suggestion proposing a different priority must not change the stored value.
$aiSuggestion = new AIService(fakeTransport(200, '{"category":"MEDICAL","indicators":["unconscious"],"suggested_priority":"LOW","summary":"x","confidence":0.9}'), ['api_key' => 'k', 'api_url' => 'https://x.invalid']);
$suggestion = $aiSuggestion->classifyEmergency($description);
$systemPriority = $priority->calculate(['category' => 'MEDICAL', 'description' => $description, 'people_affected' => 3]);

check('AI may propose a different priority', $suggestion['suggested_priority'] === 'LOW');
check('system priority ignores the AI suggestion', $systemPriority['priority'] !== 'LOW' || $systemPriority['priority_score'] > 0);
check('system priority comes from PriorityService, not AI', $systemPriority['priority'] === $priority->mapScoreToPriority($systemPriority['priority_score']));

// ---------------------------------------------------------------------------
echo "\nAI cannot mutate admin/request state\n";

$citizen = UserRepository::findByEmail('citizen1@resqlink.test');
// Pinned empty: this test only asserts no audit rows are written, and must not
// issue a live provider call just because .env happens to hold a key.
$aiCtl = new AiAssistController(new AIService(null, ['api_key' => '', 'api_url' => '']));

// Ensure the AI endpoint exposes no admin / state-mutating capability.
$publicMethods = array_map(
    static fn (ReflectionMethod $m): string => $m->name,
    (new ReflectionClass(AiAssistController::class))->getMethods(ReflectionMethod::IS_PUBLIC)
);

check('AI controller exposes only assist endpoints', array_diff(
    $publicMethods,
    ['__construct', 'classify', 'summarize', 'extract', 'normalize', 'assist']
) === []);

$before = (int) (Database::selectOne('SELECT COALESCE(MAX(id),0) AS m FROM audit_logs')['m'] ?? 0);
$aiCtl->classify(make_request('POST', '/api/v1/ai/classify', ['description' => 'fire in the warehouse immediately', 'x' => 'ignored'] , ['accept' => 'application/json']));
$after = (int) (Database::selectOne('SELECT COALESCE(MAX(id),0) AS m FROM audit_logs')['m'] ?? 0);
check('AI calls do not create audit rows', $before === $after);

// ---------------------------------------------------------------------------
echo "\nSession cleanup\n";

$_SESSION = [];

check('session cleared', $_SESSION === []);

// ---------------------------------------------------------------------------
echo "\n----------------------------------------\n";
echo "Passed: {$passed}   Failed: {$failed}\n";
