<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\AuthException;
use App\Core\Controller;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Core\Response;
use App\Services\AuthService;

/**
 * Browser authentication flows: register, login, logout, account page.
 *
 * Validation failures use the Post/Redirect/Get pattern: errors and the old
 * input are flashed, then the form is re-rendered on the next request.
 */
final class AuthController extends Controller
{
    public function registerForm(Request $request): Response
    {
        if (auth_user() !== null) {
            return $this->redirect(role_home((string) (auth_user()['role'] ?? '')));
        }

        return $this->view('auth/register', [
            'title'  => 'Create account',
            'errors' => pull_flash('form_errors') ?? [],
        ]);
    }

    public function register(Request $request): Response
    {
        $limiter = new RateLimiter();
        $key = 'auth.register|' . $request->ip();
        $max = (int) config('app.rate_limit.register_attempts', 5);
        $decay = (int) config('app.rate_limit.decay_seconds', 300);

        if ($limiter->tooManyAttempts($key, $max, $decay)) {
            flash('error', 'Too many registration attempts. Please try again later.');

            return $this->redirect(url('register'));
        }

        $limiter->hit($key, $decay);

        $service = new AuthService();

        try {
            $data = $service->validateRegistration($request->all());
            $user = $service->register($data);
            $service->authenticate($user);
        } catch (AuthException $exception) {
            flash('form_errors', $exception->errors);
            flash('_old_input', $request->all());

            return $this->redirect(url('register'));
        }

        pull_flash('_old_input'); // clear stale input on success
        flash('success', 'Welcome, ' . $user['name'] . '! Your account is ready.');

        return $this->redirect(role_home((string) $user['role']));
    }

    public function loginForm(Request $request): Response
    {
        if (auth_user() !== null) {
            return $this->redirect(role_home((string) (auth_user()['role'] ?? '')));
        }

        return $this->view('auth/login', [
            'title'  => 'Sign in',
            'errors' => pull_flash('form_errors') ?? [],
        ]);
    }

    public function login(Request $request): Response
    {
        $limiter = new RateLimiter();
        $key = 'auth.login|' . $request->ip();
        $max = (int) config('app.rate_limit.login_attempts', 10);
        $decay = (int) config('app.rate_limit.decay_seconds', 300);

        if ($limiter->tooManyAttempts($key, $max, $decay)) {
            flash('error', 'Too many sign-in attempts. Please try again in about '
                . (int) ceil($limiter->availableIn($key, $decay) / 60) . ' minute(s).');

            return $this->redirect(url('login'));
        }

        $service = new AuthService();

        try {
            $data = $service->validateLogin($request->all());
            $user = $service->login((string) $data['email'], (string) $data['password']);
        } catch (AuthException $exception) {
            // Only failed attempts count toward the brute-force guard.
            $limiter->hit($key, $decay);

            flash('error', $exception->userMessage());
            flash('_old_input', ['email' => $request->input('email')]);

            return $this->redirect(url('login'));
        }

        $limiter->clear($key);
        pull_flash('_old_input');
        flash('success', 'Welcome back, ' . $user['name'] . '.');

        $role = (string) $user['role'];
        $intended = safe_intended($_SESSION['intended'] ?? null, $role);
        unset($_SESSION['intended']);

        return $this->redirect(url($intended ?? role_home($role)));
    }

    public function logout(Request $request): Response
    {
        (new AuthService())->logout();

        flash('success', 'You have been signed out.');

        return $this->redirect(url('login'));
    }

    /**
     * Authenticated account page (any role) — shows only the caller's own data.
     */
    public function profile(Request $request): Response
    {
        $user = $request->attribute('user') ?? auth_user();

        return $this->view('auth/profile', [
            'title' => 'My account',
            'user'  => $user,
        ]);
    }
}
