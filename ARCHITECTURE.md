# ResQLink — System Architecture

**Version:** 1.0  
**Project:** ResQLink — Emergency Resource & Volunteer Coordination Platform  
**Target:** TYBCA Hackathon MVP  
**Primary Stack:** PHP + MySQL + HTML/CSS/JavaScript  
**Architecture Style:** Layered MVC-style monolithic web application with service modules

---

## 1. Architecture Overview

ResQLink is designed as a modular PHP web application.

The architecture separates:

1. Presentation/UI
2. HTTP routing and controllers
3. Business services
4. Data models/repositories
5. MySQL database
6. External integrations
7. Security and audit infrastructure

### High-Level Architecture

```text
                         ┌──────────────────────────┐
                         │        USERS             │
                         │ Citizen / Volunteer      │
                         │ Organization / Admin     │
                         └────────────┬─────────────┘
                                      │
                                      ▼
                         ┌──────────────────────────┐
                         │     WEB FRONTEND         │
                         │ HTML / CSS / JavaScript  │
                         │ Responsive Dashboard     │
                         └────────────┬─────────────┘
                                      │ HTTP / JSON
                                      ▼
                         ┌──────────────────────────┐
                         │     PHP APPLICATION       │
                         │                          │
                         │ Routes → Controllers     │
                         │         ↓                │
                         │      Services            │
                         │         ↓                │
                         │       Models             │
                         └───────┬───────┬──────────┘
                                 │       │
                  ┌──────────────┘       └──────────────┐
                  ▼                                     ▼
        ┌───────────────────┐                 ┌────────────────────┐
        │    MySQL          │                 │ External Services  │
        │ Users             │                 │                    │
        │ Requests          │                 │ Maps / Geolocation │
        │ Volunteers        │                 │ AI API             │
        │ Resources         │                 │ Email (optional)   │
        │ Matches           │                 │                    │
        └───────────────────┘                 └────────────────────┘
```

---

# 2. Architectural Principles

The project should follow these principles.

### 2.1 Separation of Concerns

UI should not contain database queries.

Controllers should coordinate requests but should not contain large business rules.

Business logic belongs in services.

Database access belongs in models/repositories.

### 2.2 Security by Default

Every protected operation must verify:

```text
Authentication
      ↓
Authorization
      ↓
Input Validation
      ↓
Business Rules
      ↓
Database Operation
```

### 2.3 Graceful Degradation

External services must never make the core application unusable.

For example:

```text
AI unavailable
      ↓
Request still works
      ↓
Use rule-based classification
```

Similarly:

```text
Map API unavailable
      ↓
Coordinates remain stored
      ↓
User can continue using request system
```

### 2.4 Explainable Decisions

The priority engine should produce reasons.

Bad:

```text
Priority = CRITICAL
```

Good:

```text
Priority = CRITICAL

Reasons:
• Medical emergency
• 3 people affected
• Immediate assistance requested
```

---

# 3. Technology Stack

## Frontend

- HTML5
- CSS3
- JavaScript
- Tailwind CSS or custom CSS
- Fetch API
- Leaflet.js
- Chart.js

## Backend

- PHP 8+
- PDO
- Session-based authentication
- REST-style JSON endpoints where useful

## Database

- MySQL 8+
- InnoDB
- Foreign keys
- Indexed location/status fields

## External Services

### Maps

Preferred:

- Leaflet.js
- OpenStreetMap

Optional:

- Geocoding service

### AI

AI provider should be abstracted behind an internal service.

Example:

```text
AIService
   ├── classifyEmergency()
   ├── summarizeRequest()
   └── extractRequestData()
```

This prevents the application from depending directly on one AI vendor.

---

# 4. Application Layers

## 4.1 Presentation Layer

Responsible for:

- Pages
- Components
- Forms
- Dashboards
- Map rendering
- Client-side validation
- AJAX/fetch requests

Example:

```text
views/
├── auth/
├── citizen/
├── volunteer/
├── organization/
├── admin/
├── components/
└── layouts/
```

The frontend should never directly connect to MySQL.

---

# 5. Routing Layer

Routes map HTTP requests to controllers.

Example:

```text
GET  /                         → HomeController@index

GET  /login                    → AuthController@loginForm
POST /login                    → AuthController@login

GET  /dashboard                → DashboardController@index

GET  /requests                 → EmergencyController@index
GET  /requests/create          → EmergencyController@create
POST /requests                 → EmergencyController@store
GET  /requests/{id}            → EmergencyController@show
POST /requests/{id}/update     → EmergencyController@update
POST /requests/{id}/cancel     → EmergencyController@cancel
POST /requests/{id}/attachment → EmergencyController@attachment

GET  /volunteer/dashboard      → VolunteerController@dashboard
GET  /volunteer/profile        → VolunteerController@profile
POST /volunteer/profile        → VolunteerController@updateProfile
POST /volunteer/availability   → VolunteerController@availability
GET  /volunteer/requests       → VolunteerController@requests

GET  /admin/volunteers                     → AdminVolunteerController@index
POST /admin/volunteers/{id}/verify         → AdminVolunteerController@verify

POST /matches/{id}/accept      → MatchController@accept

GET  /resources                → ResourceController@index
POST /resources                → ResourceController@store

GET  /admin                    → AdminController@dashboard
```

---

# 6. Controller Layer

Controllers handle:

1. Request input
2. Authentication checks
3. Authorization
4. Validation
5. Calling services
6. Returning HTML or JSON responses

Controllers should remain thin.

### Example

```text
EmergencyController
        │
        ├── validate request
        │
        ├── call EmergencyService
        │
        └── return response
```

Do NOT place the complete matching algorithm inside the controller.

---

# 7. Service Layer

The service layer contains core application logic.

Recommended services:

```text
services/
├── AuthService.php
├── EmergencyService.php
├── PriorityService.php
├── MatchingService.php          ← implemented (Phase 7)
├── VolunteerService.php
├── OrganizationService.php
├── ResourceService.php
├── NotificationService.php      ← implemented (Phase 8)
├── AIService.php               ← implemented (Phase 10)
├── LocationService.php          ← implemented (Phase 8)
├── AdminService.php             ← implemented (Phase 9)
└── AuditService.php             ← Phase 11
```

---

# 8. Core Modules

## 8.1 Authentication Module

Responsibilities:

- Registration
- Login
- Logout
- Password hashing
- Session management
- Role verification

Roles:

```text
CITIZEN
VOLUNTEER
ORGANIZATION
ADMIN
```

### 8.1.1 Implemented (Phase 3)

```text
app/services/AuthService.php    register / login / logout, password_hash+verify,
                                session regeneration, role-profile creation
app/middleware/AuthMiddleware   revalidates the session user against the DB so a
                                suspended account loses access immediately
app/middleware/RoleMiddleware   server-side role allow-list (never trusts input)
app/middleware/CsrfMiddleware   token check for POST/PATCH/PUT/DELETE
app/controllers/AuthController       web: login, register, logout, account
app/controllers/AuthApiController    JSON: /api/v1/auth/{register,login,logout,me}, /csrf
app/controllers/DashboardController  role landings for the four dashboards
routes/web.php, routes/api.php       middleware attached per route
```

Passwords are stored only as `password_hash()` values; `ADMIN` is rejected during public
registration; authorization always reads the role from the session/database.

---

# 9. Emergency Request Module

This is the core business module.

### Request Flow

```text
Citizen
   │
   ▼
Emergency Form
   │
   ▼
Validation
   │
   ▼
Create Request
   │
   ├──────────────► AI Classification
   │
   ▼
Priority Engine
   │
   ▼
Matching Engine
   │
   ▼
Notify Eligible Providers
```

### Request Status

```text
CREATED
   ↓
VERIFIED
   ↓
MATCHING
   ↓
ACCEPTED
   ↓
IN_PROGRESS
   ↓
RESOLVED
```

Alternative paths:

```text
CREATED → CANCELLED
CREATED → ESCALATED
MATCHING → ESCALATED
```

---

# 10. Priority Engine

The priority engine should be deterministic and explainable.

### Inputs

```text
Emergency Category
People Affected
Vulnerability Indicators
Immediate Danger
Available Nearby Resources
```

### Example

```text
Medical emergency      +30
Fire / active danger   +35
5+ people affected     +20
Vulnerable person      +15
No nearby resource     +10
```

The exact scoring values can be configured later.

### Output

```json
{
  "priority": "CRITICAL",
  "score": 82,
  "reasons": [
    "Medical emergency",
    "Multiple people affected",
    "Immediate assistance requested"
  ]
}
```

The score is a product classification for demo coordination and must not be represented as a professional emergency triage system.

---

# 11. Matching Engine

Implemented in Phase 7 as `App\Services\MatchingService` with
`config/matching.php`, `App\Models\MatchRepository` and
`App\Controllers\MatchingApiController` / `MatchingController`.

The matching engine connects requests to suitable helpers/resources. It is
**deterministic and rule-based** — no AI. Every suggestion carries human-readable
reasons. Capability compatibility outranks raw distance.

## Matching Pipeline (implemented)

```text
Emergency Request (open statuses only)
       │
       ▼
Load candidates
  • Volunteers: VERIFIED + AVAILABLE + ACTIVE user
  • Resources:  status ≠ UNAVAILABLE, available_quantity > 0, org VERIFIED
       │
       ▼
Capability Filter
  • Volunteer skills → VolunteerService::SKILL_CATEGORIES
  • Resource types → config matching.resource_compatibility[category]
       │
       ▼
Service Radius Filter (volunteers, when both sides have coordinates)
       │
       ▼
Distance Calculation (Haversine — VolunteerService::distanceKm)
       │
       ▼
Match Score
  100 × (w_cap·capability + w_avail·1 + w_dist·distance + w_ver·1)
  Defaults: capability 0.50 · availability 0.20 · distance 0.20 · verification 0.10
  Distance unknown → neutral 0.5 (config matching.distance_unknown_score)
       │
       ▼
Rank Candidates (score DESC, then distance ASC)
       │
       ▼
Persist as SUGGESTED match rows (upsert; no duplicate open match)
  Volunteer double-assignment guard: no ACCEPTED match on another live request
       │
       ▼
Lifecycle
  SUGGESTED → NOTIFIED → ACCEPTED → COMPLETED
  SUGGESTED / NOTIFIED → REJECTED
  Acceptable request statuses: CREATED, VERIFIED, MATCHING, ESCALATED
```

## Transactional accept

`MatchingService::accept` runs in one `Database::transaction`:

1. Re-check request status + provider eligibility + no competing ACCEPTED match + volunteer not double-assigned
2. Match → `ACCEPTED`
3. Request advances CREATED→VERIFIED→MATCHING→ACCEPTED (or shorter path) with `STATUS_CHANGED` events
4. Volunteer availability → `BUSY`
5. `MATCH_ACCEPTED` timeline event + `ACCEPT_MATCH` audit row

`complete` moves ACCEPTED→IN_PROGRESS→RESOLVED (or IN_PROGRESS→RESOLVED), frees the
volunteer to `AVAILABLE`, and records `MATCH_COMPLETED` + `COMPLETE_MATCH`.

## Privacy rules

- Volunteers never see exact request coordinates on suggestions or unaccepted matches.
- Match payloads never include volunteer phone/email.
- Suggestion payloads for non-owners omit request coordinates entirely.

## Example

```text
Request:
Medical assistance
Location: 20.3883, 72.9092

Volunteer A — MEDICAL + FIRST_AID — 2.1 km — AVAILABLE — VERIFIED
  → capability 100 · score ~91 (exact skill match)

Volunteer B — GENERAL — 1.5 km — AVAILABLE — VERIFIED
  → capability 85 · score ~87 (GENERAL fallback)

Volunteer C — DRIVING — 0.8 km — AVAILABLE — VERIFIED
  → excluded (skill not compatible with MEDICAL)

Organization D — MEDICAL_SUPPORT stock — VERIFIED org
  → resource suggestion when compatibility map allows

The engine prefers capability compatibility over distance alone.
```

## Configuration

`config/matching.php`:

| Key | Default | Purpose |
|-----|---------|---------|
| `weights.capability` | 0.50 | Skill / resource-type fit |
| `weights.availability` | 0.20 | Provider available |
| `weights.distance` | 0.20 | Haversine vs radius |
| `weights.verification` | 0.10 | Verified provider |
| `max_suggestions` | 10 | Persisted suggestions per type |
| `distance_unknown_score` | 0.5 | Neutral score when coords missing |
| `resource_compatibility` | per category | Emergency category → resource types (`[]` = any) |

---

# 12. Distance Calculation

For the MVP, use the Haversine formula for approximate geographic distance.

Concept:

```text
latitude + longitude
        ↓
Haversine calculation
        ↓
distance_km
```

Example:

```text
Emergency → Volunteer
Distance = 2.4 km
```

The database should store latitude and longitude separately.

---

# 13. Resource Management Module

Organizations can maintain:

```text
Food
Water
Shelter
Transport
Medical Support
Blankets
Other
```

Each resource contains:

```text
resource_type
quantity
available_quantity
status
location
```

### Resource Lifecycle

```text
AVAILABLE
   ↓
RESERVED
   ↓
ALLOCATED
   ↓
CONSUMED
```

Resources can also be restored/replenished.

---

# 14. Volunteer Module

Volunteer profile:

```text
User
 ├── Skills
 ├── Vehicle
 ├── Equipment
 ├── Availability
 ├── Service Radius
 ├── Location
 └── Verification Status
```

Example:

```text
Volunteer:
Rahul

Skills:
First Aid
Driving

Vehicle:
Car

Radius:
10 km

Status:
AVAILABLE
```

### 14.1 Implemented (Phase 5)

```text
app/core/VolunteerException.php         domain errors (not found, forbidden,
                                        validation, invalid skills)
app/services/VolunteerService.php       profile CRUD, skill/vehicle vocabulary,
                                        availability, admin verification,
                                        eligible-request discovery (capability +
                                        haversine radius)
app/controllers/VolunteerController     web: dashboard, profile, availability,
                                        nearby requests
app/controllers/VolunteerApiController  JSON: profile, availability, requests,
                                        admin list/show/verify
app/controllers/AdminVolunteerController  web admin verification queue
app/models/VolunteerRepository.php      profile persistence + admin joins
app/views/volunteer/*                   dashboard, profile form, request list
app/views/admin/volunteers/index.php    admin verification queue
routes/web.php, routes/api.php          volunteer + admin volunteer routes
```

Rules enforced in `VolunteerService`:

- Only the volunteer (VOLUNTEER role) may edit their own profile; admins verify.
- Skills must come from the controlled vocabulary; vehicle from its enum list.
- Service radius is 0.1–1000 km; latitude/longitude must be provided together.
- `verification_status` is never client-writable.
- Unavailable (BUSY/OFFLINE) and unverified volunteers are never treated as
  available; their request list is empty with a machine-readable `reason`.
- Eligible request list filters by open status, capability heuristic and
  service radius; exact request coordinates are withheld until Phase 7/8.
- Admin volunteer reads never return phone or email.

---

# 15. Organization Module

Organizations can be:

```text
NGO
Hospital
Shelter
Food Provider
Community Group
Other
```

Organization workflow:

```text
Register
   ↓
Verification
   ↓
Add Resources
   ↓
Maintain Availability
   ↓
Receive/coordinate Requests
```

The MVP can use admin verification status:

```text
PENDING
VERIFIED
REJECTED
```

### 15.1 Implemented (Phase 6)

```text
app/core/OrganizationException.php        domain errors (not found, forbidden,
                                         not verified, validation)
app/core/ResourceException.php           domain errors (not found, forbidden,
                                         insufficient, not available,
                                         request not found/closed, validation)
app/services/OrganizationService.php     profile CRUD, admin verify/reject,
                                         inventory stats, safe presentations
app/services/ResourceService.php         role-scoped list, create/update/
                                         replenish, atomic allocation, targets
app/controllers/OrganizationController   web: dashboard, profile (PRG)
app/controllers/ResourceController       web: create/edit/update/replenish/
                                         allocate (PRG)
app/controllers/AdminOrganizationController  web admin verification queue
app/controllers/OrganizationApiController    JSON: profile, admin list/verify/
                                             reject
app/controllers/ResourceApiController        JSON: list, create, update,
                                             allocate
app/models/OrganizationRepository.php    org persistence, admin list w/ counts
app/models/ResourceRepository.php        allocate (row lock), replenish,
                                         stats, visibility-scoped pagination
app/views/organization/*                 dashboard (stats + inventory cards +
                                         allocation), profile, resource edit
app/views/admin/organizations/index.php   admin verification queue
routes/web.php, routes/api.php            organization + resource routes
```

Rules enforced in `OrganizationService` / `ResourceService`:

- Only the organization (ORGANIZATION role) may edit its own profile; admins verify.
- Organization `type` comes from the controlled vocabulary; latitude/longitude
  must be provided together.
- `verification_status` is never client-writable.
- Resource mutations require a VERIFIED organization; only the owning
  organization (or an admin) may mutate a resource — no IDOR.
- `available_quantity` is never negative and never exceeds `quantity`
  (`organization_update` / `resource_update` rules + service bounds + CHECK).
- Resource status is derived from quantities (≤20% ⇒ LIMITED, 0 ⇒
  UNAVAILABLE) unless explicitly set for mark-unavailable.
- Allocation is one atomic unit: availability guard, row lock, decrement,
  status refresh, `RESOURCE_ALLOCATED` timeline event, audit entry — committed
  or rolled back together (nested `Database::transaction()` joins the outer
  transaction).
- Allocation targets only active requests (RESOLVED/CANCELLED rejected with
  `INVALID_REQUEST_STATUS`).
- Public inventory lists VERIFIED organizations only, never UNAVAILABLE rows,
  and omit private coordinates; admin reads never return email or phone.

---

# 16. Notification Architecture

Notifications should be generated through a central service.

```text
NotificationService
        │
        ├── In-App Notification
        │
        └── Email (optional)
```

Events:

```text
Emergency Created
Match Found
Request Accepted
Request In Progress
Request Resolved
Request Escalated
Resource Updated
```

For the hackathon MVP, in-app notifications are sufficient.

### 16.1 Implemented (Phase 8)

```text
app/services/NotificationService.php
    event constants, best-effort notify / notifyAdmins (actor never self-notified),
    domain helpers (requestCreated / statusChanged / matchSuggested /
    matchAccepted / resourceAllocated), listForUser / markRead / markAllRead

app/models/NotificationRepository.php
    owner-scoped paginated list, unread count, IDOR-safe mark-read

app/controllers/NotificationController.php      web centre  /notifications
app/controllers/NotificationApiController.php   JSON §43–§46
app/controllers/MapController.php               web map     /map
app/controllers/MapApiController.php            JSON        /api/v1/map/markers
app/services/LocationService.php                haversine, validatePair,
                                                role-aware markersForViewer /
                                                requestMarker (exact vs ~2 dp)
public/assets/js/map.js                         Leaflet init, filters, polling
public/assets/js/notifications.js               badge polling (~12 s)
```

Wiring (best-effort, after transactions commit):

```text
EmergencyService::create      → REQUEST_CREATED → ACTIVE admins
EmergencyService::transition  → STATUS_CHANGED / REQUEST_RESOLVED / ESCALATED
MatchingService::persist      → MATCH_FOUND → volunteer or resource org
MatchingService::accept       → MATCH_ACCEPTED → request owner (+ resource org)
MatchingService::complete     → REQUEST_RESOLVED
ResourceService::allocate     → RESOURCE_ALLOCATED → request owner
```

Map privacy (MASTER_PROMPT §20): exact private coordinates are never shown to
unauthorized roles. Citizens: own requests. Admins: operational map (all active
work + volunteer homes). Volunteers: exact only after ACCEPTED/COMPLETED match;
otherwise approximate (~2 dp ≈ 1 km). Organizations: own org/resource coords
exact; other verified orgs approximate.

### 16.2 Implemented (Phase 9 — Admin Control Center)

```text
app/services/AdminService.php
    kpis() / dashboard() — aggregate KPIs, status/priority/category breakdowns,
    critical queue, activity, resource stats + shortages
    requestQueue() — validated filters (status/priority/category/date/search)
    resourceOverview() / resourceShortages() / activity() / auditLogs()
    audited actions: verifyRequest / escalateRequest / changeRequestStatus /
    verifyVolunteer / verifyOrganization (ADMIN re-checked server-side)

app/controllers/AdminController.php             web /admin, /admin/requests,
                                                /admin/resources, /admin/activity
app/controllers/AdminApiController.php          JSON §54–§59 /api/v1/admin/*
app/core/AdminException.php                     admin domain exception
app/models/EmergencyRequestRepository.php       adminPage() filtered queue +
                                                KPI counts (total/high/in-progress)
app/models/ResourceRepository.php               adminStats() / adminOverview() /
                                                availableCount()
app/models/MatchRepository.php                  activeCount()
app/models/RequestEventRepository.php           recentActivity() feed
app/models/AuditRepository.php                  adminPage() paginated trail
```

Authorization: every admin route is wrapped in `RoleMiddleware(['ADMIN'])` and
the service independently re-checks `role === 'ADMIN'`, so no admin capability
depends on a hidden UI element. Mutations follow Post/Redirect/Get and each one
records an audit row (actor, entity, safe metadata). Existing workflow rules are
reused — request transitions delegate to `EmergencyService`, verification to
`VolunteerService` / `OrganizationService` — so the state machine and guards are
never bypassed.

Performance: dashboard/queue reads are single aggregate or joined queries
(no N+1); filters are validated against enum vocabularies and bound as prepared
statements; LIMIT/OFFSET are validated integers.

### 16.3 Implemented (Phase 11 — Analytics & Audit)

```text
app/services/AnalyticsService.php        overview() — period-validated dashboard
                                         payload (request breakdowns, series,
                                         resolution, resources, volunteers)
                                         apiPayload() — API.md §58 shape
                                         normalisePeriod() — whitelist + default
app/controllers/AdminController.php      web /admin/analytics
app/controllers/AdminApiController.php   JSON §58 /api/v1/admin/analytics
app/models/AnalyticsRepository.php       countsByBucket() / countsByCategory() /
                                         countsByPriority() / countsByStatus() /
                                         totalRequests() / activeRequests() /
                                         resolutionStats() / volunteerTaskCounts()
app/views/admin/analytics.php            Chart.js dashboard + accessible tables
public/assets/js/charts.js               progressive-enhancement chart renderer
config/services.php                      services.charts.chartjs_js (optional CDN)
```

This completes the Phase 9 audit track (audited actions + paginated trail) with
operational analytics. Authorization: `/admin/analytics` is wrapped in
`RoleMiddleware(['ADMIN'])` and the JSON endpoint re-checks the role, exactly
like the rest of `/api/v1/admin/*`.

Analytics are read-only (no audit writes). `period` is mapped to a fixed window
+ bucket granularity; the DATE_FORMAT pattern is chosen from a static map and the
window boundary is bound as a parameter, so no request input reaches SQL. Reads
are single aggregate queries (no N+1). Chart.js is loaded from a CDN as a
progressive enhancement — the view always renders the underlying numbers as
accessible tables, so the analytics page works without it.

### 16.4 Implemented (Phase 12 — Hardening & Polish)

```text
app/core/RateLimiter.php                 file-based sliding-window limiter
                                         (storage/cache/rate_limit/, per host,
                                         fails open when storage is read-only)
app/core/SecurityHeaders.php             default security response headers
app/controllers/AuthController.php       web login/register rate-limited
app/controllers/AuthApiController.php    API login/register rate-limited (429)
app/core/App.php / ErrorHandler.php      headers applied to every response
config/app.php                           rate_limit.login/register/decay
tests/security.php                       hardening suite
tests/demo.php                           end-to-end demo verification
docs/DEMO.md                             repeatable demo runbook
```

Rate limiting is a **transport concern**: it lives in the HTTP layer
(controllers), not in `AuthService`, so the business layer carries no notion of
client throttling. Only failed sign-ins count toward the window and a successful
sign-in clears it; a blocked request never establishes a session. Default
security headers are applied centrally (`App::run` for normal responses,
`ErrorHandler::render` for error responses) and never override a value a handler
already set. No Content-Security-Policy is sent because the map/chart UI loads
from a CDN and uses inline data blocks.

Performance (measured over the seeded dataset): admin dashboard ~8.6 ms,
analytics overview ~3.4 ms, request queue ~0.5 ms; all read paths use the
indexes defined in the migrations.

---

# 17. AI Architecture

AI must be isolated from the core business logic.

```text
EmergencyService
       │
       ▼
AIService
       │
       ▼
AI Provider
       │
       ▼
Structured Response
       │
       ▼
Validation
       │
       ▼
Human/User Confirmation
```

## AI Capabilities

### Classification

```text
Text
 ↓
Category
 ↓
Extracted indicators
```

### Summarization

```text
Long description
 ↓
Short operational summary
```

### Structured Extraction

```text
Natural language
 ↓
People affected
Need type
Risk indicators
Location hints
```

AI should not autonomously:

- Diagnose medical conditions
- Give medical treatment instructions
- Declare an official emergency
- Guarantee safety
- Override administrators

### 17.1 Implemented (Phase 10)

```text
app/services/AIService.php
    single gateway: classifyEmergency() / summarizeRequest() /
    extractRequestData() / normalizeRequest()
    + static config(), isAvailable(), fallbackClassify()
    dispatch() → injectable transport (tests) | curl() (production)
    ask() → timeout / non-2xx / malformed handling → null
    decodeBody() → unwraps chat envelopes + markdown fences, rejects lists
    minimize() / enum() / stringList() / boundedString() / confidence()

app/core/AIException.php            AI_UNAVAILABLE / AI_TIMEOUT /
                                    AI_INVALID_RESPONSE / AI_RATE_LIMITED
app/controllers/AiAssistController.php
    POST /api/v1/ai/{classify,summarize,extract,normalize}
    POST /requests/ai-assist
public/assets/js/ai.js              suggestion panel + explicit "Use category"
app/views/emergency/create.php      AI / System / Your decision labels
config/services.php  ai             AI_API_KEY, AI_API_URL, AI_MODEL,
                                    AI_TIMEOUT_SECONDS (all optional)
```

Data flow and safety properties:

```text
Citizen text
   │  (bounded to 500 chars; emails & phone numbers redacted)
   ▼
AIService ── provider configured? ── no ──▶ deterministic keyword fallback
   │ yes
   ▼
cURL (timeout + connect timeout)
   │  error / timeout / non-2xx / bad JSON
   └───────────────────────────────▶ deterministic keyword fallback
   │ 200 + JSON object
   ▼
Allow-list validation (category, priority, arrays, 0..1 confidence)
   │  unusable
   └───────────────────────────────▶ deterministic keyword fallback
   ▼
{ source: 'ai', … }  ──▶ controller envelope
                         { available, source, result, disclaimer }
   ▼
UI renders an "AI suggestion" card — user explicitly applies it
```

Invariants:

- No AI output is ever written to `emergency_requests`. Only the category the
  citizen selects is stored.
- `PriorityService` remains the sole priority authority; AI may propose a
  different priority and the stored value is unchanged.
- HTTP status stays **200** whenever the request itself is valid — AI outage is
  communicated by `available`/`source`, never by a 5xx.
- Logs record status + latency only. The API key, prompt and provider payload
  are never logged (`Logger` also redacts `api_key`).
- The AI controller exposes no state-mutating or admin methods.

---

# 18. Database Architecture

## Entity Relationship Overview

```text
USERS
  │
  ├──────────────┐
  │              │
  ▼              ▼
VOLUNTEER      ORGANIZATION
PROFILE          │
  │              │
  │              ▼
  │          RESOURCES
  │
  └──────┐
         │
         ▼
EMERGENCY_REQUESTS
         │
         ▼
      MATCHES
         │
         ▼
   VOLUNTEER / RESOURCE

EMERGENCY_REQUESTS
         │
         ├── REQUEST_EVENTS
         │
         └── NOTIFICATIONS
```

---

# 19. Database Tables

## users

```text
id PK
name
email UNIQUE
phone
password_hash
role
status
created_at
updated_at
```

## emergency_requests

```text
id PK
user_id FK
category
description
people_affected
priority
priority_score
priority_reason
latitude
longitude
status
created_at
updated_at
```

## volunteer_profiles

```text
id PK
user_id FK
skills
vehicle
equipment
availability
service_radius_km
latitude
longitude
created_at
updated_at
```

## organizations

```text
id PK
user_id FK
name
type
verification_status
contact
latitude
longitude
created_at
updated_at
```

## resources

```text
id PK
organization_id FK
resource_type
quantity
available_quantity
status
latitude
longitude
created_at
updated_at
```

## matches

```text
id PK
request_id FK
volunteer_id FK NULL
resource_id FK NULL
distance_km
match_score
status
created_at
updated_at
```

## notifications

```text
id PK
user_id FK
type
title
message
read_status
created_at
```

## request_events

```text
id PK
request_id FK
actor_id FK
old_status
new_status
note
created_at
```

## audit_logs

```text
id PK
actor_id FK
action
entity_type
entity_id
metadata
created_at
```

---

# 20. Database Relationships

```text
users 1 ─────── 1 volunteer_profiles

users 1 ─────── 1 organizations

users 1 ─────── N emergency_requests

organizations 1 ─────── N resources

emergency_requests 1 ─────── N matches

emergency_requests 1 ─────── N request_events

users 1 ─────── N notifications

users 1 ─────── N audit_logs
```

---

# 21. API Architecture

The application can expose JSON endpoints under:

```text
/api/v1/
```

## Authentication

```text
POST /api/v1/auth/register
POST /api/v1/auth/login
POST /api/v1/auth/logout
GET  /api/v1/auth/me
```

## Emergency Requests

```text
GET    /api/v1/requests
POST   /api/v1/requests
GET    /api/v1/requests/{id}
PATCH  /api/v1/requests/{id}
POST   /api/v1/requests/{id}/cancel
POST   /api/v1/requests/{id}/verify
POST   /api/v1/requests/{id}/escalate
POST   /api/v1/requests/{id}/status
GET    /api/v1/requests/{id}/events
GET    /api/v1/requests/{id}/attachments
POST   /api/v1/requests/{id}/attachments
```

## Matching

```text
GET  /api/v1/requests/{id}/matches
POST /api/v1/matches/{id}/accept
POST /api/v1/matches/{id}/reject
```

## Volunteers

```text
GET   /api/v1/volunteers/profile
PATCH /api/v1/volunteers/profile
POST  /api/v1/volunteers/availability
GET   /api/v1/volunteers/requests
GET   /api/v1/volunteers/{id}          (admin)
GET   /api/v1/admin/volunteers         (admin)
POST  /api/v1/admin/volunteers/{id}/verify  (admin)
```

## Resources

```text
GET   /api/v1/resources
POST  /api/v1/resources
PATCH /api/v1/resources/{id}
```

## Admin

```text
GET /api/v1/admin/dashboard
GET /api/v1/admin/requests
GET /api/v1/admin/resources
GET /api/v1/admin/volunteers
GET /api/v1/admin/analytics
GET /api/v1/admin/audit-logs
```

---

# 22. API Response Standard

Success:

```json
{
  "success": true,
  "message": "Emergency request created successfully.",
  "data": {
    "id": 1024,
    "status": "CREATED"
  }
}
```

Error:

```json
{
  "success": false,
  "message": "Unable to create emergency request.",
  "errors": {
    "category": "Category is required."
  }
}
```

---

# 23. Authentication & Authorization

Use server-side sessions for the MVP.

## Authentication Flow

```text
Login
 ↓
Verify credentials
 ↓
Create secure session
 ↓
Store user ID + role
 ↓
Redirect to role dashboard
```

## Authorization

Every protected endpoint must verify:

```text
Is logged in?
      ↓
Is role allowed?
      ↓
Does resource belong to user/organization?
      ↓
Execute action
```

Example:

A citizen must not be able to:

```text
Edit another citizen's request
Access admin dashboard
Modify organization inventory
Accept a volunteer task as another user
```

---

# 24. Security Architecture

## Required Controls

### Password Security

Use:

```php
password_hash()
password_verify()
```

### SQL Injection Protection

Use PDO prepared statements.

Never:

```php
$sql = "SELECT * FROM users WHERE email = '$email'";
```

Use parameterized queries instead.

### XSS Protection

Escape rendered user content.

### CSRF Protection

All state-changing browser forms should use CSRF tokens.

### File Upload Security

For uploaded images:

```text
Validate extension
Validate MIME type
Limit file size
Generate safe filename
Store outside executable PHP paths
```

### Session Security

Use:

```text
HttpOnly
Secure (when HTTPS)
SameSite
Session regeneration after login
```

---

# 25. Location & Privacy Architecture

Location is sensitive operational information.

### Visibility Rules

Citizen:

```text
Own location
Own request
Relevant matched helper information
```

Volunteer:

```text
Accepted/eligible request location
Own location
```

Admin:

```text
Operational map
All active requests
```

Public visitors:

```text
No exact private emergency coordinates
```

Avoid exposing personal phone numbers or exact home locations publicly.

---

# 26. File Storage Architecture

Recommended:

```text
storage/
├── uploads/
│   ├── emergency/
│   └── profile/
├── logs/
└── temp/
```

Database stores the file reference, not the binary content.

Example:

```text
emergency_requests
       │
       └── media_path
```

For a more scalable version, storage can later move to object storage.

---

# 27. Admin Dashboard Architecture

The Admin Control Center should combine:

```text
KPI Layer
     +
Request Queue
     +
Map
     +
Resource Status
     +
Activity Feed
```

### KPI Cards

```text
Active Emergencies
Critical Requests
Available Volunteers
Available Resources
Resolved Requests
```

### Operational Panels

```text
Critical Queue
Recent Requests
Resource Shortages
Map
Recent Activity
```

---

# 28. Analytics Architecture

Analytics should be generated from operational tables.

Metrics:

```text
Requests by category
Requests by priority
Requests by status
Resolved requests
Active requests
Volunteer acceptance rate
Resource utilization
Requests by time period
```

Chart examples:

```text
Line Chart
Requests over time

Bar Chart
Requests by category

Doughnut Chart
Request status

Bar Chart
Resource availability
```

---

# 29. Error Handling

Errors must be handled at each layer.

```text
Frontend
   ↓
Validation error

Controller
   ↓
Authorization error

Service
   ↓
Business rule error

Repository
   ↓
Database error

External Service
   ↓
API timeout/error
```

User-facing errors should be simple.

Example:

```text
Something went wrong.
Please try again.
```

Technical details should go into logs, not the UI.

---

# 30. Logging & Audit

Application logs:

```text
storage/logs/
```

Audit events:

```text
Admin verified organization
Admin changed request priority
Volunteer accepted request
Organization updated resource
Request status changed
```

Audit records should include:

```text
actor
action
entity
timestamp
metadata
```

---

# 31. Recommended Project Structure

```text
resqlink/
│
├── public/
│   ├── index.php
│   ├── assets/
│   │   ├── css/
│   │   ├── js/
│   │   └── images/
│   └── uploads/
│
├── app/
│   ├── controllers/
│   │   ├── AuthController.php
│   │   ├── EmergencyController.php
│   │   ├── VolunteerController.php
│   │   ├── OrganizationController.php
│   │   ├── ResourceController.php
│   │   ├── MatchController.php
│   │   └── AdminController.php
│   │
│   ├── models/
│   │   ├── User.php
│   │   ├── EmergencyRequest.php
│   │   ├── VolunteerProfile.php
│   │   ├── Organization.php
│   │   ├── Resource.php
│   │   ├── Match.php
│   │   ├── Notification.php
│   │   └── AuditLog.php
│   │
│   ├── services/
│   │   ├── AuthService.php
│   │   ├── EmergencyService.php
│   │   ├── PriorityService.php
│   │   ├── MatchingService.php
│   │   ├── VolunteerService.php
│   │   ├── ResourceService.php
│   │   ├── NotificationService.php
│   │   ├── AIService.php
│   │   ├── LocationService.php
│   │   └── AuditService.php
│   │
│   ├── middleware/
│   │   ├── AuthMiddleware.php
│   │   ├── RoleMiddleware.php
│   │   └── CsrfMiddleware.php
│   │
│   ├── helpers/
│   │   ├── response.php
│   │   ├── validation.php
│   │   └── security.php
│   │
│   └── views/
│       ├── layouts/
│       ├── components/
│       ├── auth/
│       ├── citizen/
│       ├── volunteer/
│       ├── organization/
│       └── admin/
│
├── config/
│   ├── app.php
│   ├── database.php
│   └── services.php
│
├── routes/
│   ├── web.php
│   └── api.php
│
├── database/
│   ├── migrations/
│   └── seeders/
│
├── storage/
│   ├── logs/
│   ├── uploads/
│   └── cache/
│
├── .env
├── .env.example
├── .gitignore
├── README.md
└── composer.json
```

---

### 31.1 Implemented foundation (Phase 1)

The Phase 1 implementation adds a small `app/core/` layer of framework primitives
(autoloading, bootstrap, routing, request/response, views, logging, error handling,
validation) plus the concrete files below:

```text
app/autoload.php     dependency-free PSR-4 autoloader (App\ → app/)
app/bootstrap.php    env + config + errors + session + routes
app/core/            Env, Config, App, Router, Request, Response, View,
                     Controller, Service, Model, Logger, ErrorHandler,
                     Validator, Database, HttpException, DatabaseException
app/helpers/         helpers.php, security.php, response.php, validation.php
config/              app.php, database.php, services.php
routes/              web.php (HTML), api.php (JSON under /api/v1)
public/index.php     front controller (the only publicly exposed PHP file)
public/router.php    router for PHP's built-in development server
public/.htaccess     Apache rewrite + security rules
```

The `Database` connection is created lazily, so the application boots and serves pages
even when MySQL is unavailable (graceful degradation). Remaining controllers, services,
and middleware are registered in their respective phases. The layer boundaries above
(thin controllers, business logic in services, no SQL in views) remain mandatory.

---

### 31.2 Database layer (Phase 2)

```text
app/core/Migrator.php          ordered .sql runner + migrations tracking table
app/core/Repository.php        base repository (prepared-statement CRUD,
                               pagination, transaction helpers) — extends Model
app/models/*Repository.php     User, EmergencyRequest, Volunteer, Organization,
                               Resource, Match, Notification, RequestEvent,
                               Attachment, Audit  (persistence only)
config/validation.php          reusable rule sets aligned with DATABASE.md §27
database/migrations/001..010   exact schema from DATABASE.md, applied in order
database/seeders/*             deterministic fictional demo data
                               (PHP seeders so passwords use password_hash())
database/migrate.php           CLI: migrate / --fresh / --status
database/seed.php              CLI: truncate + seed demo data
```

Repositories contain persistence logic only; business rules stay in services and are
introduced in later phases. Schema changes are made exclusively through migration files.

---

# 32. Request Creation Sequence

```text
Citizen
  │
  │ POST /api/v1/requests
  ▼
EmergencyController
  │
  ├── Authenticate
  ├── Authorize
  └── Validate
  │
  ▼
EmergencyService
  │
  ├── Save request
  │
  ├── PriorityService
  │      └── Calculate priority
  │
  ├── AIService (optional)
  │      └── Extract/summarize
  │
  ├── MatchingService
  │      └── Find providers
  │
  └── NotificationService
         └── Notify candidates
  │
  ▼
MySQL
```

---

# 33. Match Acceptance Sequence

```text
Volunteer
   │
   │ Click ACCEPT
   ▼
MatchController
   │
   ▼
Authorization
   │
   ▼
MatchingService
   │
   ├── Verify match exists
   ├── Verify volunteer is available
   ├── Verify request is still open
   └── Prevent duplicate acceptance
   │
   ▼
Database Transaction
   │
   ├── Match → ACCEPTED
   ├── Request → ACCEPTED
   └── Event → logged
   │
   ▼
NotificationService
   │
   ▼
Citizen notified
```

---

# 34. Transaction Requirements

Critical multi-table operations should use database transactions.

Example:

```text
BEGIN TRANSACTION

Create Match
Update Request
Update Volunteer Availability
Create Request Event
Create Notification

COMMIT
```

If any critical operation fails:

```text
ROLLBACK
```

This prevents inconsistent state.

---

# 35. Concurrency Considerations

Two volunteers may attempt to accept the same request.

The system must prevent duplicate assignment.

Concept:

```text
Request status = MATCHING

Volunteer A accepts
        ↓
Transaction locks/checks request
        ↓
Request = ACCEPTED

Volunteer B attempts
        ↓
System rejects:
"Request already accepted."
```

For the hackathon MVP, database transactions and status checks are sufficient.

---

# 36. External Service Failure Strategy

## AI Failure

```text
AI request fails
      ↓
Log error
      ↓
Use rule-based classification
      ↓
Continue workflow
```

## Map Failure

```text
Map fails
      ↓
Show coordinates/address text
      ↓
Continue workflow
```

## Email Failure

```text
Email fails
      ↓
In-app notification remains available
```

The application should never crash because an optional integration is unavailable.

---

# 37. Seed / Demo Data

The hackathon build should include realistic seed data.

Example:

```text
5 Citizens
10 Volunteers
3 Organizations
8 Resources
10 Emergency Requests
```

Example organizations:

```text
Community Food Center
City Shelter
Volunteer Medical Group
```

Use clearly fictional/demo data unless real organizations have explicitly authorized use.

---

# 38. Environment Configuration

Use `.env`.

Example:

```env
APP_ENV=development
APP_URL=http://localhost/resqlink

DB_HOST=localhost
DB_NAME=resqlink
DB_USER=root
DB_PASSWORD=

AI_API_KEY=
AI_API_URL=
AI_MODEL=
AI_TIMEOUT_SECONDS=10
MAP_PROVIDER=
MAIL_HOST=
MAIL_USERNAME=
MAIL_PASSWORD=
```

Never commit secrets to Git.

Provide:

```text
.env.example
```

instead.

---

# 39. Deployment Architecture

For a simple hackathon deployment:

```text
Browser
   │
   ▼
HTTPS Web Server
   │
   ▼
PHP Application
   │
   ▼
MySQL Database
   │
   ├── Map API
   ├── AI API
   └── Email API
```

Possible hosting options:

- PHP shared hosting
- VPS
- Local XAMPP for presentation
- Cloud VM

The architecture should avoid provider-specific dependencies where possible.

---

# 40. Testing Architecture

Testing should be divided into:

## Unit Tests

Test:

```text
PriorityService
MatchingService
Distance calculation
Validation
Permission checks
```

## Integration Tests

Test:

```text
Create Request
Create Match
Accept Match
Update Status
Resource Update
Notification Creation
```

## UI / Manual Tests

Test:

```text
Citizen flow
Volunteer flow
Organization flow
Admin flow
Mobile layout
Map interaction
```

---

# 41. Core Testing Scenarios

### Scenario 1

```text
Citizen creates medical emergency
→ Request created
→ Priority calculated
→ Match generated
```

### Scenario 2

```text
Volunteer unavailable
→ Volunteer excluded from matching
```

### Scenario 3

```text
Volunteer outside service radius
→ Candidate excluded
```

### Scenario 4

```text
Two volunteers accept simultaneously
→ Only one successful assignment
```

### Scenario 5

```text
AI API unavailable
→ Request still created
```

### Scenario 6

```text
Unauthorized user attempts admin route
→ Access denied
```

---

# 42. Performance Guidelines

For the TYBCA MVP:

- Add indexes on foreign keys.
- Index `status`, `priority`, and `created_at`.
- Avoid `SELECT *` where practical.
- Paginate request lists.
- Keep dashboard queries optimized.
- Cache static assets.
- Compress uploaded images.
- Avoid calling AI APIs unnecessarily.

Suggested indexes:

```text
emergency_requests(user_id)
emergency_requests(status)
emergency_requests(priority)
emergency_requests(created_at)

volunteer_profiles(availability)

resources(organization_id)
resources(status)

matches(request_id)
matches(status)
```

---

# 43. Architecture Decision Summary

| Decision | Choice | Reason |
|---|---|---|
| Backend | PHP | Familiar, practical for TYBCA and easy deployment |
| Database | MySQL | Relational data and clear relationships |
| Architecture | Modular MVC-style | Easier maintenance and development |
| API | REST-style JSON | Clean frontend/backend communication |
| Maps | Leaflet + OpenStreetMap | Lightweight and hackathon-friendly |
| AI | Service abstraction | Avoid vendor lock-in |
| Auth | PHP sessions | Simple and suitable for MVP |
| Matching | Rule + distance based | Explainable and implementable |
| Priority | Rule-based | Transparent and safer than opaque AI |
| Foundation | Plain PHP + PSR-4 autoloader | Runs with zero dependencies; Composer optional |
| Notifications | In-app first | Reliable MVP scope |
| Realtime | Polling first | Simpler than WebSockets |
| Deployment | Standard PHP hosting/VPS | Easy to demonstrate |

---

# 44. Architecture Boundaries

The following rules are mandatory:

```text
VIEW
  ❌ No SQL

CONTROLLER
  ❌ No complex business algorithms

SERVICE
  ❌ No direct HTML rendering

MODEL/REPOSITORY
  ❌ No UI logic

AI SERVICE
  ❌ Cannot make irreversible decisions

EXTERNAL APIs
  ❌ Must not be required for core request creation
```

---

# 45. Recommended Build Order

Build in this order:

```text
1. Project skeleton
        ↓
2. Database + migrations
        ↓
3. Authentication
        ↓
4. Role-based dashboards
        ↓
5. Emergency requests
        ↓
6. Priority engine
        ↓
7. Volunteer system
        ↓
8. Resource system
        ↓
9. Matching engine
        ↓
10. Map
        ↓
11. Notifications
        ↓
12. Admin control center
        ↓
13. AI layer
        ↓
14. Analytics
        ↓
15. Security hardening
        ↓
16. Testing
        ↓
17. Demo polish
```

---

# 46. Final Architecture Goal

The finished system should support this complete flow:

```text
                    RESQLINK
                       │
                       ▼
                 NEED HELP?
                       │
                       ▼
              CREATE EMERGENCY
                       │
                       ▼
             VALIDATE + PRIORITIZE
                       │
                       ▼
              FIND NEARBY HELP
                       │
             ┌─────────┴─────────┐
             ▼                   ▼
        VOLUNTEER            ORGANIZATION
             │                   │
             └─────────┬─────────┘
                       ▼
                  ACCEPT TASK
                       │
                       ▼
                 HELP IN PROGRESS
                       │
                       ▼
                    RESOLVED
                       │
                       ▼
                 AUDIT + ANALYTICS
```

The core architectural objective is:

> **Keep the MVP simple enough to build reliably, modular enough to extend, and impressive enough to demonstrate a complete real-world coordination workflow.**

---

## End of Architecture Document
