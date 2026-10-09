<?php

declare(strict_types=1);

/**
 * API routes (JSON) under the /api/v1 prefix.
 *
 * Feature endpoints (requests, matches, resources, admin, ai, ...) are
 * registered here in later phases. Auth endpoints follow API.md §9–§12.
 */

use App\Controllers\AdminApiController;
use App\Controllers\AiAssistController;
use App\Controllers\AuthApiController;
use App\Controllers\EmergencyApiController;
use App\Controllers\HealthController;
use App\Controllers\MapApiController;
use App\Controllers\MatchingApiController;
use App\Controllers\NotificationApiController;
use App\Controllers\OrganizationApiController;
use App\Controllers\ResourceApiController;
use App\Controllers\VolunteerApiController;
use App\Core\Router;
use App\Middleware\AuthMiddleware;
use App\Middleware\CsrfMiddleware;
use App\Middleware\RoleMiddleware;

return static function (Router $router): void {
    // API root / health
    $router->get('/api/v1', [HealthController::class, 'base']);
    $router->get('/api/v1/health', [HealthController::class, 'index']);

    // CSRF token issue (send it back as X-CSRF-Token on POST/PATCH/DELETE)
    $router->get('/api/v1/csrf', [AuthApiController::class, 'csrf']);

    // Authentication
    $router->post('/api/v1/auth/register', [AuthApiController::class, 'register'], [new CsrfMiddleware()]);
    $router->post('/api/v1/auth/login', [AuthApiController::class, 'login'], [new CsrfMiddleware()]);
    $router->post('/api/v1/auth/logout', [AuthApiController::class, 'logout'], [new CsrfMiddleware()]);
    $router->get('/api/v1/auth/me', [AuthApiController::class, 'me'], [new AuthMiddleware()]);

    // Emergency requests (Phase 4) — API.md §15–§24
    $router->get('/api/v1/requests', [EmergencyApiController::class, 'index'], [new AuthMiddleware()]);
    $router->post('/api/v1/requests', [EmergencyApiController::class, 'store'], [new RoleMiddleware(['CITIZEN']), new CsrfMiddleware()]);
    $router->get('/api/v1/requests/{id}', [EmergencyApiController::class, 'show'], [new AuthMiddleware()]);
    $router->patch('/api/v1/requests/{id}', [EmergencyApiController::class, 'update'], [new AuthMiddleware(), new CsrfMiddleware()]);
    $router->post('/api/v1/requests/{id}/cancel', [EmergencyApiController::class, 'cancel'], [new AuthMiddleware(), new CsrfMiddleware()]);
    $router->post('/api/v1/requests/{id}/verify', [EmergencyApiController::class, 'verify'], [new RoleMiddleware(['ADMIN']), new CsrfMiddleware()]);
    $router->post('/api/v1/requests/{id}/escalate', [EmergencyApiController::class, 'escalate'], [new RoleMiddleware(['ADMIN']), new CsrfMiddleware()]);
    $router->post('/api/v1/requests/{id}/status', [EmergencyApiController::class, 'status'], [new RoleMiddleware(['ADMIN']), new CsrfMiddleware()]);
    $router->get('/api/v1/requests/{id}/events', [EmergencyApiController::class, 'events'], [new AuthMiddleware()]);
    $router->get('/api/v1/requests/{id}/attachments', [EmergencyApiController::class, 'attachments'], [new AuthMiddleware()]);
    $router->post('/api/v1/requests/{id}/attachments', [EmergencyApiController::class, 'storeAttachment'], [new AuthMiddleware(), new CsrfMiddleware()]);

    // Matching engine (Phase 7) — API.md §31–§35
    $router->get('/api/v1/requests/{id}/matches', [MatchingApiController::class, 'requestMatches'], [new AuthMiddleware()]);
    $router->post('/api/v1/requests/{id}/matches/suggest', [MatchingApiController::class, 'suggest'], [new RoleMiddleware(['CITIZEN', 'ADMIN']), new CsrfMiddleware()]);
    $router->get('/api/v1/matches', [MatchingApiController::class, 'index'], [new AuthMiddleware()]);
    $router->get('/api/v1/matches/{id}', [MatchingApiController::class, 'show'], [new AuthMiddleware()]);
    $router->post('/api/v1/matches/{id}/accept', [MatchingApiController::class, 'accept'], [new AuthMiddleware(), new CsrfMiddleware()]);
    $router->post('/api/v1/matches/{id}/reject', [MatchingApiController::class, 'reject'], [new AuthMiddleware(), new CsrfMiddleware()]);
    $router->post('/api/v1/matches/{id}/complete', [MatchingApiController::class, 'complete'], [new AuthMiddleware(), new CsrfMiddleware()]);

    // Admin control center (Phase 9) — API.md §54–§59
    $router->get('/api/v1/admin/dashboard', [AdminApiController::class, 'dashboard'], [new RoleMiddleware(['ADMIN'])]);
    $router->get('/api/v1/admin/requests', [AdminApiController::class, 'requests'], [new RoleMiddleware(['ADMIN'])]);
    $router->get('/api/v1/admin/resources', [AdminApiController::class, 'resources'], [new RoleMiddleware(['ADMIN'])]);
    $router->get('/api/v1/admin/audit-logs', [AdminApiController::class, 'auditLogs'], [new RoleMiddleware(['ADMIN'])]);
    $router->get('/api/v1/admin/analytics', [AdminApiController::class, 'analytics'], [new RoleMiddleware(['ADMIN'])]);
    $router->get('/api/v1/admin/activity', [AdminApiController::class, 'activity'], [new RoleMiddleware(['ADMIN'])]);

    // AI assistance (Phase 10) — API.md §70; optional, degrades to fallback
    $router->post('/api/v1/ai/classify', [AiAssistController::class, 'classify'], [new AuthMiddleware(), new CsrfMiddleware()]);
    $router->post('/api/v1/ai/summarize', [AiAssistController::class, 'summarize'], [new AuthMiddleware(), new CsrfMiddleware()]);
    $router->post('/api/v1/ai/extract', [AiAssistController::class, 'extract'], [new AuthMiddleware(), new CsrfMiddleware()]);
    $router->post('/api/v1/ai/normalize', [AiAssistController::class, 'normalize'], [new AuthMiddleware(), new CsrfMiddleware()]);

    // Notifications & map (Phase 8) — API.md §43–§46
    $router->get('/api/v1/notifications', [NotificationApiController::class, 'index'], [new AuthMiddleware()]);
    $router->post('/api/v1/notifications/{id}/read', [NotificationApiController::class, 'markRead'], [new AuthMiddleware(), new CsrfMiddleware()]);
    $router->post('/api/v1/notifications/read-all', [NotificationApiController::class, 'markAllRead'], [new AuthMiddleware(), new CsrfMiddleware()]);
    $router->get('/api/v1/map/markers', [MapApiController::class, 'markers'], [new AuthMiddleware()]);

    // Volunteer system (Phase 5) — API.md §25–§30, §59
    $router->get('/api/v1/volunteers/profile', [VolunteerApiController::class, 'profile'], [new RoleMiddleware(['VOLUNTEER'])]);
    $router->patch('/api/v1/volunteers/profile', [VolunteerApiController::class, 'updateProfile'], [new RoleMiddleware(['VOLUNTEER']), new CsrfMiddleware()]);
    $router->post('/api/v1/volunteers/availability', [VolunteerApiController::class, 'availability'], [new RoleMiddleware(['VOLUNTEER']), new CsrfMiddleware()]);
    $router->get('/api/v1/volunteers/requests', [VolunteerApiController::class, 'requests'], [new RoleMiddleware(['VOLUNTEER'])]);
    $router->get('/api/v1/volunteers/{id}', [VolunteerApiController::class, 'show'], [new RoleMiddleware(['ADMIN'])]);
    $router->get('/api/v1/admin/volunteers', [VolunteerApiController::class, 'adminIndex'], [new RoleMiddleware(['ADMIN'])]);
    $router->post('/api/v1/admin/volunteers/{id}/verify', [VolunteerApiController::class, 'adminVerify'], [new RoleMiddleware(['ADMIN']), new CsrfMiddleware()]);

    // Organization & resources (Phase 6) — API.md §37–§42, §58
    $router->get('/api/v1/organizations/profile', [OrganizationApiController::class, 'profile'], [new RoleMiddleware(['ORGANIZATION'])]);
    $router->patch('/api/v1/organizations/profile', [OrganizationApiController::class, 'updateProfile'], [new RoleMiddleware(['ORGANIZATION']), new CsrfMiddleware()]);
    $router->get('/api/v1/resources', [ResourceApiController::class, 'index'], [new AuthMiddleware()]);
    $router->post('/api/v1/resources', [ResourceApiController::class, 'store'], [new RoleMiddleware(['ORGANIZATION']), new CsrfMiddleware()]);
    // Update/allocate are owner-or-admin in the service (API.md §41) — role
    // middleware stays permissive so admins pass through to the same guard.
    $router->patch('/api/v1/resources/{id}', [ResourceApiController::class, 'update'], [new AuthMiddleware(), new CsrfMiddleware()]);
    $router->post('/api/v1/resources/{id}/allocate', [ResourceApiController::class, 'allocate'], [new AuthMiddleware(), new CsrfMiddleware()]);
    $router->get('/api/v1/admin/organizations', [OrganizationApiController::class, 'adminIndex'], [new RoleMiddleware(['ADMIN'])]);
    $router->post('/api/v1/admin/organizations/{id}/verify', [OrganizationApiController::class, 'adminVerify'], [new RoleMiddleware(['ADMIN']), new CsrfMiddleware()]);
    $router->post('/api/v1/admin/organizations/{id}/reject', [OrganizationApiController::class, 'adminReject'], [new RoleMiddleware(['ADMIN']), new CsrfMiddleware()]);
};
