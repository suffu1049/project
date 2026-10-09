<?php

declare(strict_types=1);

/**
 * Web routes (HTML).
 *
 * Route files return a callable that receives the Router. Controller actions
 * are referenced as [Class::class, 'method']. Middleware instances are created
 * inline so role guards can carry their allowed roles.
 */

use App\Controllers\AdminController;
use App\Controllers\AdminOrganizationController;
use App\Controllers\AdminVolunteerController;
use App\Controllers\AiAssistController;
use App\Controllers\AuthController;
use App\Controllers\DashboardController;
use App\Controllers\EmergencyController;
use App\Controllers\HomeController;
use App\Controllers\MapController;
use App\Controllers\MatchingController;
use App\Controllers\NotificationController;
use App\Controllers\OrganizationController;
use App\Controllers\ResourceController;
use App\Controllers\VolunteerController;
use App\Core\Router;
use App\Middleware\AuthMiddleware;
use App\Middleware\CsrfMiddleware;
use App\Middleware\RoleMiddleware;

return static function (Router $router): void {
    // Public
    $router->get('/', [HomeController::class, 'index']);
    $router->get('/system-status', [HomeController::class, 'status']);

    // Authentication
    $router->get('/login', [AuthController::class, 'loginForm']);
    $router->post('/login', [AuthController::class, 'login'], [new CsrfMiddleware()]);
    $router->get('/register', [AuthController::class, 'registerForm']);
    $router->post('/register', [AuthController::class, 'register'], [new CsrfMiddleware()]);
    $router->post('/logout', [AuthController::class, 'logout'], [new CsrfMiddleware()]);

    // Any authenticated role
    $router->get('/account', [AuthController::class, 'profile'], [new AuthMiddleware()]);

    // Phase 8 — notifications & operational map
    $router->get('/notifications', [NotificationController::class, 'index'], [new AuthMiddleware()]);
    $router->post('/notifications/{id}/read', [NotificationController::class, 'markRead'], [new AuthMiddleware(), new CsrfMiddleware()]);
    $router->post('/notifications/read-all', [NotificationController::class, 'markAllRead'], [new AuthMiddleware(), new CsrfMiddleware()]);
    $router->get('/map', [MapController::class, 'index'], [new AuthMiddleware()]);

    // Role dashboards — authorization happens server-side in RoleMiddleware.
    $router->get('/dashboard', [DashboardController::class, 'citizen'], [new RoleMiddleware(['CITIZEN'])]);
    $router->get('/volunteer/dashboard', [VolunteerController::class, 'dashboard'], [new RoleMiddleware(['VOLUNTEER'])]);
    // Organization dashboard — full Phase 6 inventory overview
    $router->get('/organization/dashboard', [OrganizationController::class, 'dashboard'], [new RoleMiddleware(['ORGANIZATION'])]);

    // Admin control center (Phase 9)
    $router->get('/admin', [AdminController::class, 'dashboard'], [new RoleMiddleware(['ADMIN'])]);
    $router->get('/admin/dashboard', [AdminController::class, 'dashboard'], [new RoleMiddleware(['ADMIN'])]);
    $router->get('/admin/requests', [AdminController::class, 'requests'], [new RoleMiddleware(['ADMIN'])]);
    $router->post('/admin/requests/{id}/verify', [AdminController::class, 'verifyRequest'], [new RoleMiddleware(['ADMIN']), new CsrfMiddleware()]);
    $router->post('/admin/requests/{id}/escalate', [AdminController::class, 'escalateRequest'], [new RoleMiddleware(['ADMIN']), new CsrfMiddleware()]);
    $router->post('/admin/requests/{id}/status', [AdminController::class, 'changeRequestStatus'], [new RoleMiddleware(['ADMIN']), new CsrfMiddleware()]);
    $router->get('/admin/resources', [AdminController::class, 'resources'], [new RoleMiddleware(['ADMIN'])]);
    $router->get('/admin/activity', [AdminController::class, 'activity'], [new RoleMiddleware(['ADMIN'])]);
    // Analytics & audit (Phase 11)
    $router->get('/admin/analytics', [AdminController::class, 'analytics'], [new RoleMiddleware(['ADMIN'])]);

    // Volunteer system (Phase 5)
    $router->get('/volunteer/profile', [VolunteerController::class, 'profile'], [new RoleMiddleware(['VOLUNTEER'])]);
    $router->post('/volunteer/profile', [VolunteerController::class, 'updateProfile'], [new RoleMiddleware(['VOLUNTEER']), new CsrfMiddleware()]);
    $router->post('/volunteer/availability', [VolunteerController::class, 'availability'], [new RoleMiddleware(['VOLUNTEER']), new CsrfMiddleware()]);
    $router->get('/volunteer/requests', [VolunteerController::class, 'requests'], [new RoleMiddleware(['VOLUNTEER'])]);

    // Matching engine (Phase 7)
    $router->get('/volunteer/matches', [MatchingController::class, 'volunteerMatches'], [new RoleMiddleware(['VOLUNTEER'])]);
    $router->post('/volunteer/matches/{id}/accept', [MatchingController::class, 'acceptVolunteerMatch'], [new RoleMiddleware(['VOLUNTEER']), new CsrfMiddleware()]);
    $router->post('/volunteer/matches/{id}/reject', [MatchingController::class, 'rejectVolunteerMatch'], [new RoleMiddleware(['VOLUNTEER']), new CsrfMiddleware()]);
    $router->post('/volunteer/matches/{id}/complete', [MatchingController::class, 'completeVolunteerMatch'], [new RoleMiddleware(['VOLUNTEER']), new CsrfMiddleware()]);
    $router->get('/requests/{id}/matches', [MatchingController::class, 'requestMatches'], [new AuthMiddleware()]);
    $router->post('/requests/{id}/matches/suggest', [MatchingController::class, 'suggest'], [new RoleMiddleware(['CITIZEN', 'ADMIN']), new CsrfMiddleware()]);
    $router->post('/requests/{id}/matches/{matchId}/accept', [MatchingController::class, 'acceptRequestMatch'], [new RoleMiddleware(['CITIZEN', 'ADMIN']), new CsrfMiddleware()]);
    $router->post('/requests/{id}/matches/{matchId}/reject', [MatchingController::class, 'rejectRequestMatch'], [new RoleMiddleware(['CITIZEN', 'ADMIN']), new CsrfMiddleware()]);
    $router->post('/requests/{id}/matches/{matchId}/complete', [MatchingController::class, 'completeRequestMatch'], [new RoleMiddleware(['CITIZEN', 'ADMIN']), new CsrfMiddleware()]);

    // Admin volunteer verification (Phase 5)
    $router->get('/admin/volunteers', [AdminVolunteerController::class, 'index'], [new RoleMiddleware(['ADMIN'])]);
    $router->post('/admin/volunteers/{id}/verify', [AdminVolunteerController::class, 'verify'], [new RoleMiddleware(['ADMIN']), new CsrfMiddleware()]);

    // Organization & resources (Phase 6)
    $router->get('/organization/profile', [OrganizationController::class, 'profile'], [new RoleMiddleware(['ORGANIZATION'])]);
    $router->post('/organization/profile', [OrganizationController::class, 'updateProfile'], [new RoleMiddleware(['ORGANIZATION']), new CsrfMiddleware()]);
    $router->post('/organization/resources', [ResourceController::class, 'store'], [new RoleMiddleware(['ORGANIZATION']), new CsrfMiddleware()]);
    $router->get('/organization/resources/{id}/edit', [ResourceController::class, 'edit'], [new RoleMiddleware(['ORGANIZATION'])]);
    $router->post('/organization/resources/{id}', [ResourceController::class, 'update'], [new RoleMiddleware(['ORGANIZATION']), new CsrfMiddleware()]);
    $router->post('/organization/resources/{id}/replenish', [ResourceController::class, 'replenish'], [new RoleMiddleware(['ORGANIZATION']), new CsrfMiddleware()]);
    $router->post('/organization/resources/{id}/allocate', [ResourceController::class, 'allocate'], [new RoleMiddleware(['ORGANIZATION']), new CsrfMiddleware()]);

    // Admin organization verification (Phase 6)
    $router->get('/admin/organizations', [AdminOrganizationController::class, 'index'], [new RoleMiddleware(['ADMIN'])]);
    $router->post('/admin/organizations/{id}/verify', [AdminOrganizationController::class, 'verify'], [new RoleMiddleware(['ADMIN']), new CsrfMiddleware()]);

    // Emergency requests (Phase 4)
    // List/show are auth-only: ownership is enforced in EmergencyService.
    // AI assistance (Phase 10) — optional enrichment, never gates submission.
    $router->post('/requests/ai-assist', [AiAssistController::class, 'assist'], [new AuthMiddleware(), new CsrfMiddleware()]);
    $router->get('/requests', [EmergencyController::class, 'index'], [new AuthMiddleware()]);
    $router->get('/requests/create', [EmergencyController::class, 'create'], [new RoleMiddleware(['CITIZEN'])]);
    $router->post('/requests', [EmergencyController::class, 'store'], [new RoleMiddleware(['CITIZEN']), new CsrfMiddleware()]);
    $router->get('/requests/{id}', [EmergencyController::class, 'show'], [new AuthMiddleware()]);
    $router->post('/requests/{id}/update', [EmergencyController::class, 'update'], [new AuthMiddleware(), new CsrfMiddleware()]);
    $router->post('/requests/{id}/cancel', [EmergencyController::class, 'cancel'], [new AuthMiddleware(), new CsrfMiddleware()]);
    $router->post('/requests/{id}/attachment', [EmergencyController::class, 'attachment'], [new AuthMiddleware(), new CsrfMiddleware()]);
};
