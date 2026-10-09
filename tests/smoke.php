<?php

declare(strict_types=1);

/**
 * ResQLink — Phase 1 smoke tests.
 *
 * Dependency-free assertion runner. Run with:
 *     php tests/smoke.php
 *
 * Exit code 0 = all passed, 1 = one or more failures.
 */

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\View;

define('BASE_PATH', dirname(__DIR__));

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

function request(string $method, string $path): Request
{
    return new Request($method, $path, [], [], [], [], '127.0.0.1', 'smoke-test');
}

echo "ResQLink smoke tests\n";
echo "====================\n\n";

echo "Configuration\n";
check('app name loads from config', config('app.name') === 'ResQLink');
check('public roles exclude ADMIN', !in_array('ADMIN', config('app.public_roles', []), true));
check('database host configured', is_string(config('database.host')));

echo "\nHelpers\n";
check('e() escapes HTML (XSS)', e('<script>alert(1)</script>') === '&lt;script&gt;alert(1)&lt;/script&gt;');
check('e() escapes quotes', e('"x\'y"') === '&quot;x&#039;y&quot;');
check('asset() builds a URL', str_contains(asset('css/app.css'), '/assets/css/app.css'));

echo "\nResponse envelopes\n";
$ok = Response::success(['id' => 1], 'Created.', 201);
$decoded = json_decode($ok->body(), true);
check('success response status', $ok->status() === 201);
check('success envelope has success=true', ($decoded['success'] ?? null) === true);
check('success envelope has data', ($decoded['data']['id'] ?? null) === 1);

$err = Response::error('Bad input.', 'VALIDATION_ERROR', 422, ['email' => 'Required']);
$decoded = json_decode($err->body(), true);
check('error response status', $err->status() === 422);
check('error envelope has code', ($decoded['code'] ?? null) === 'VALIDATION_ERROR');
check('error envelope has errors', isset($decoded['errors']['email']));

echo "\nValidator\n";
$v = validator(['email' => 'not-an-email', 'age' => 0], [
    'email' => 'required|email',
    'age'   => 'required|integer|min:1',
]);
check('validator detects failures', $v->fails());
check('validator reports email error', isset($v->errors()['email']));
check('validator reports age error', isset($v->errors()['age']));

$v = validator(['name' => 'Rahul', 'role' => 'VOLUNTEER'], [
    'name' => 'required|string|max:120',
    'role' => 'required|in:CITIZEN,VOLUNTEER,ORGANIZATION',
]);
check('validator passes valid input', $v->passes());
check('validator returns only validated fields', $v->validated() === ['name' => 'Rahul', 'role' => 'VOLUNTEER']);

echo "\nRouter\n";
$router = new Router();
$router->get('/', static fn (): Response => Response::html('home'));
$router->get('/requests/{id}', static fn (Request $req, string $id): Response => Response::html('request-' . $id));
$router->post('/only-post', static fn (): Response => Response::html('posted'));

check('GET / dispatches', $router->dispatch(request('GET', '/'))->body() === 'home');
check('path parameter is passed', $router->dispatch(request('GET', '/requests/42'))->body() === 'request-42');

try {
    $router->dispatch(request('GET', '/missing'));
    check('unknown route throws 404', false);
} catch (HttpException $e) {
    check('unknown route throws 404', $e->statusCode === 404);
}

try {
    $router->dispatch(request('GET', '/only-post'));
    check('wrong method throws 405', false);
} catch (HttpException $e) {
    check('wrong method throws 405', $e->statusCode === 405);
}

echo "\nViews & layout\n";
check('404 view renders', str_contains(View::render('errors/404', ['message' => 'Not found']), 'Not found'));
check('home view renders', str_contains(View::render('home', [
    'title'      => 'Test',
    'date'       => 'now',
    'database'   => 'unavailable',
    'foundation' => ['A', 'B'],
]), 'Coordinate help, faster.'));

echo "\n----------------------------------------\n";
echo "Passed: {$passed}   Failed: {$failed}\n";

exit($failed === 0 ? 0 : 1);
