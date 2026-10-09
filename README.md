# ResQLink

**Emergency Resource & Volunteer Coordination Platform**

ResQLink connects people who need emergency assistance with suitable nearby volunteers,
organizations, and available resources through a transparent, trackable workflow.

> ResQLink is a **coordination and information platform**. It does **not** replace police,
> fire, ambulance, or disaster-management authorities. In a real emergency, contact your
> local emergency number (e.g. **112** / **108**).

---

## Status

**Phase 1 — Project Foundation: COMPLETE**  
**Phase 2 — Database & Persistence: COMPLETE**  
**Phase 3 — Authentication & RBAC: COMPLETE**  
**Phase 4 — Emergency Request Core: COMPLETE**  
**Phase 5 — Volunteer System: COMPLETE**  
**Phase 6 — Organization & Resources: COMPLETE**  
**Phase 7 — Smart Matching Engine: COMPLETE**  
**Phase 8 — Location, Map & Notifications: COMPLETE**  
**Phase 9 — Admin Control Center: COMPLETE**  
**Phase 10 — AI Assistance Layer: COMPLETE**  
**Phase 11 — Analytics & Audit: COMPLETE**  
**Phase 12 — Hardening, Testing & Hackathon Polish: COMPLETE**

The application boots, routes requests, renders a responsive UI shell, handles errors
centrally and logs safely. The MySQL schema, migrations, repositories, transactions and
deterministic demo seed data are in place, and all four roles can register, sign in, sign
out and reach only their authorized areas.

Phase 4 adds the citizen emergency-request workflow: creation with validation, a
deterministic explainable priority engine, a validated status state machine, request
event history, citizen history + detail UI, authorized update/cancel, safe image
attachments (stored outside the web root), and the request API endpoints from `API.md`.

Phase 5 adds the volunteer system: profile skills/vehicle/equipment, service radius and
location, availability controls, a dedicated volunteer dashboard, nearby open-request
discovery (capability + distance), admin verification/reject, and the volunteer JSON API.

Phase 6 adds organization & resources: organization profile editing, admin
organization verification/reject with audit trail, role-scoped resource inventory
(visible inventory rules per API contract), resource creation/update/replenish/mark
unavailable, transactional resource allocation to active requests (decrement +
timeline event + audit in one atomic unit), an organization inventory dashboard,
and the organization/resource JSON API.

Phase 7 adds the smart matching engine: deterministic explainable scoring
(capability 50% · availability 20% · distance 20% · verification 10%, configurable
via `config/matching.php`), volunteer + resource candidate discovery with radius and
capability filters, transactional accept/reject/complete with double-assignment
guards, timeline events + audit rows, volunteer match inbox UI, request match panel,
and the matching JSON API. No AI — ranking is rule-based and explainable on every
suggestion.

Phase 8 adds location, the operational map and in-app notifications: Leaflet + OSM
map at `/map` with role-aware marker precision (exact only for authorized roles),
request detail-page map embeds, browser geolocation capture on create/profile forms,
best-effort notification delivery on REQUEST_CREATED / MATCH_FOUND / MATCH_ACCEPTED /
STATUS_CHANGED / RESOURCE_ALLOCATED / REQUEST_RESOLVED / ESCALATED, a notification
centre at `/notifications` with unread badge polling, and the notification + map JSON
APIs (`API.md` §43–§47).

Phase 9 adds the admin control center: a single operational dashboard with KPI
cards (active/critical/high-priority/in-progress/resolved requests, available
volunteers and resources, active matches), a filterable + paginated request
queue (status · priority · category · date · search), audited verify/escalate/
status actions, a platform resource overview with shortage detection, a request
activity feed and a paginated audit trail. Admin web pages and the
`/api/v1/admin/*` JSON endpoints are all server-side ADMIN-gated (never
UI-hidden), and every mutating action writes an audit row.

Phase 10 adds an optional AI assistance layer. All AI access goes through the
single `AIService` gateway (never a vendor SDK in a controller): emergency
classification, summarization, structured extraction and request normalization
with a structured input/output contract, bounded prompts, timeout + HTTP error
handling, safe JSON parsing (fences and chat envelopes tolerated),
allow-listed field validation, clamped confidence and redacted logging.
Nothing is a dependency: with no key, or on timeout/outage/malformed JSON, the
service returns a deterministic keyword fallback and the endpoints still
respond **200** so the emergency workflow never blocks. PriorityService stays
the sole authority on priority; AI only proposes, andthe UI labels every card as **AI suggestion**, **System decision** or **Your decision**.

Phase 11 completes the analytics & audit layer. The audit trail built in Phase 9
(audited actions + paginated viewer) is joined by operational analytics: an
`AnalyticsService` over an `AnalyticsRepository` exposes period-scoped request
breakdowns (category / priority / status), a requests-over-time series with
hour/day/month buckets, resolution-time statistics, resource availability and
volunteer task metrics — each from a single aggregate query, with the period
validated against a whitelist (`day|week|month|year`, invalid values degrade to
`week`) and no N+1. The dashboard at `/admin/analytics` renders four Chart.js
charts plus always-present accessible data tables, so the numbers remain fully
readable when JavaScript is disabled or the CDN is unreachable, and
`GET /api/v1/admin/analytics` returns the documented §58 payload. Both surfaces
are server-side ADMIN-gated (web route middleware + service/controller checks),
and analytics reads are strictly read-only — they never write audit rows.

Phase 12 hardens and packages the build: a per-host **rate limiter** now guards the
web and JSON auth endpoints against brute-force/credential-stuffing (429 for the
API, a flash + redirect for the web), every response carries conservative
**security headers** (`X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`,
`Permissions-Policy`), and the security/validation/database/API/performance audits
are backed by a dedicated `tests/security.php` suite plus the existing phase
suites. The full hackathon demo loop is verified end-to-end by `tests/demo.php`,
and [`docs/DEMO.md`](./docs/DEMO.md) is the repeatable runbook (reset, credentials,
10-step scenario, pre-demo checklist, troubleshooting). All 13 suites pass.

See [`PHASES(3).md`](./PHASES(3).md) for the development roadmap and
[`RESQLINK_PHASE_WISE_MASTER_PROMPTS.md`](./RESQLINK_PHASE_WISE_MASTER_PROMPTS.md) for the
per-phase execution prompts.

---

## Tech stack

| Layer      | Technology                                   |
|------------|----------------------------------------------|
| Backend    | PHP 8.1+ (tested on 8.4), PDO, modular MVC   |
| Database   | MySQL 8+ (InnoDB, utf8mb4) — Phase 2         |
| Frontend   | HTML5, CSS3, vanilla JavaScript, Fetch API   |
| Maps       | Leaflet.js + OpenStreetMap — Phase 8         |
| Charts     | Chart.js — Phase 11                          |
| AI         | Optional `AIService` gateway + deterministic fallback — Phase 10 |

No PHP framework and no build step. Composer is **optional** (see `composer.json`); the
application runs with a built-in PSR-4 autoloader.

---

## Requirements

- **PHP 8.1+** with extensions: `pdo`, `pdo_mysql`, `json`, `openssl`
  (`mbstring` is recommended for correct multibyte string handling, but optional)
- **MySQL 8+** or **MariaDB 10.4+** (InnoDB, utf8mb4); not required to boot the app
- Git (recommended)

### Verify your PHP setup

```bash
php -v
php -m | grep -i pdo_mysql   # must output pdo_mysql (enable it in php.ini if missing)
```

> If `pdo_mysql` is missing, enable the `pdo_mysql` extension in `php.ini` (remove the
> leading `;`) and restart PHP. The app still boots without it; the database check simply
> reports "unavailable" (graceful degradation).

---

## Installation

```bash
# 1. Clone / enter the project
cd resqlink

# 2. Create your environment file
cp .env.example .env
#    then edit .env and set APP_URL and DB_* values

# 3. Start the development server
php -S localhost:8000 -t public public/router.php
```

Open <http://localhost:8000>.

> With Apache, point the document root at `public/`. The included `public/.htaccess`
> routes all requests to `public/index.php`.

> **Windows note:** if the PHP CLI loads no `php.ini` (so `pdo_mysql` is missing),
> copy `php.ini.example` to `php.ini`, set `extension_dir`, and prefix commands with
> `php -c php.ini` (see [Database setup](#database-setup)).

---

## Database setup

The schema lives in `database/migrations/*.sql` (one statement per file, applied in
filename order) and is tracked in a `migrations` table. All schema details are defined in
[`DATABASE.md`](./DATABASE.md).

```bash
# Create the database and an application user (example; keep creds in .env)
#   CREATE DATABASE resqlink CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
#   CREATE USER 'resqlink_app'@'localhost' IDENTIFIED BY 'your-password';
#   GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, DROP, INDEX,
#         REFERENCES, CREATE TEMPORARY TABLES, LOCK TABLES
#         ON resqlink.* TO 'resqlink_app'@'localhost';

# Point .env at your database
#   DB_HOST=127.0.0.1  DB_PORT=3306  DB_NAME=resqlink
#   DB_USER=resqlink_app  DB_PASSWORD=your-password

# Apply migrations (all, or drop everything first)
php -c php.ini database/migrate.php --fresh
php -c php.ini database/migrate.php --status

# Load the deterministic demo dataset
php -c php.ini database/seed.php
```

### Demo credentials

All seeded accounts share the local-only password **`Password123!`** (never reuse in
production). Emails use the reserved `.test` TLD:

| Role         | Email                       |
|--------------|-----------------------------|
| Admin        | `admin@resqlink.test`       |
| Citizen      | `citizen1@resqlink.test` … `citizen5@resqlink.test` |
| Volunteer    | `volunteer1@resqlink.test` … `volunteer10@resqlink.test` |
| Organization | `org1@resqlink.test` … `org3@resqlink.test` |

Seeded volume: 1 admin, 5 citizens, 10 volunteers, 3 organizations, 8 resources,
10 emergency requests (mixed priorities/statuses), matches, notifications, request
events, attachments and audit logs.

---

## Project structure

```text
resqlink/
├── public/                  # Web document root (the only publicly exposed folder)
│   ├── index.php            # Front controller
│   ├── router.php           # Router for PHP's built-in server
│   ├── .htaccess            # Apache rewrite + security rules
│   └── assets/{css,js,images}
├── app/
│   ├── autoload.php         # Dependency-free PSR-4 autoloader
│   ├── bootstrap.php        # Env, config, errors, session, routes
│   ├── core/                # Router, Request, Response, Database, Repository, Migrator…
│   ├── controllers/         # HTTP coordinators (thin)
│   ├── models/              # Repositories (persistence; prepared statements only)
│   ├── services/            # Business logic (later phases)
│   ├── middleware/          # Auth / role / CSRF guards (Phase 3+)
│   ├── helpers/             # Global helper functions
│   └── views/               # layouts, components, feature views
├── config/                  # app.php, database.php, services.php, validation.php
├── routes/                  # web.php (HTML), api.php (JSON)
├── database/                # migrations/*.sql, seeders/, migrate.php, seed.php
├── storage/                 # logs/, uploads/, cache/ (outside web root)
├── tests/                   # smoke, database, auth, emergency, volunteer,
│                            # organization, matching, notifications, admin, ai
├── .env.example
├── php.ini.example
├── .gitignore
└── composer.json
```

### Layered architecture

```text
Request → Router → Middleware → Controller → Service → Model → Database
                                     │
                                   View (presentation only)
```

- **Controllers** receive/validate/authorize and delegate.
- **Services** hold business rules.
- **Models / Repositories** handle persistence (prepared statements only).
- **Views** never contain SQL or business logic.

---

## Available routes

**Web (HTML)**

| Method | Path                    | Access                | Purpose                         |
|--------|-------------------------|-----------------------|---------------------------------|
| GET    | `/`                     | Public                | Landing / foundation status     |
| GET    | `/system-status`        | Public                | Runtime diagnostics             |
| GET/POST | `/login`              | Guest                 | Sign in                         |
| GET/POST | `/register`           | Guest                 | Create an account               |
| POST   | `/logout`               | Authenticated         | End the session                 |
| GET    | `/account`              | Authenticated         | Own profile (any role)          |
| GET    | `/dashboard`            | Citizen               | Citizen dashboard               |
| GET    | `/volunteer/dashboard`  | Volunteer             | Volunteer dashboard             |
| GET/POST | `/volunteer/profile`  | Volunteer             | Edit volunteer profile          |
| POST   | `/volunteer/availability` | Volunteer           | Toggle availability             |
| GET    | `/volunteer/requests`   | Volunteer             | Nearby open requests            |
| GET    | `/volunteer/matches`    | Volunteer             | Match inbox (accept / reject)   |
| POST   | `/volunteer/matches/{id}/accept` | Volunteer     | Accept a match                  |
| POST   | `/volunteer/matches/{id}/reject` | Volunteer     | Reject a match                  |
| POST   | `/volunteer/matches/{id}/complete` | Volunteer   | Complete an accepted match      |
| GET    | `/requests/{id}/matches` | Citizen / Admin / Volunteer | Request match panel     |
| POST   | `/requests/{id}/matches/suggest` | Citizen / Admin | Regenerate suggestions     |
| POST   | `/requests/{id}/matches/{matchId}/accept` | Citizen / Admin | Accept match from panel |
| POST   | `/requests/{id}/matches/{matchId}/reject` | Citizen / Admin | Reject match from panel |
| POST   | `/requests/{id}/matches/{matchId}/complete` | Citizen / Admin | Complete match from panel |
| GET    | `/organization/dashboard` | Organization        | Inventory dashboard             |
| GET/POST | `/organization/profile` | Organization        | Edit organization profile       |
| POST   | `/organization/resources` | Organization        | Create resource                 |
| GET    | `/organization/resources/{id}/edit` | Organization | Edit / replenish resource   |
| POST   | `/organization/resources/{id}` | Organization     | Update resource                 |
| POST   | `/organization/resources/{id}/replenish` | Organization | Replenish stock          |
| POST   | `/organization/resources/{id}/allocate` | Organization | Allocate to request          |
| GET    | `/admin/organizations`  | Admin                 | Organization verification queue |
| POST   | `/admin/organizations/{id}/verify` | Admin        | Verify / reject organization    |
| GET    | `/admin` (`/admin/dashboard`) | Admin           | Control center (Phase 9)        |
| GET    | `/admin/requests`       | Admin                 | Filterable request queue        |
| POST   | `/admin/requests/{id}/verify` | Admin          | Verify a request (audited)      |
| POST   | `/admin/requests/{id}/escalate` | Admin        | Escalate a request (audited)    |
| POST   | `/admin/requests/{id}/status` | Admin          | Apply a workflow transition     |
| GET    | `/admin/resources`      | Admin                 | Platform resource overview      |
| GET    | `/admin/activity`       | Admin                 | Activity feed + audit trail     |
| GET    | `/admin/volunteers`     | Admin                 | Volunteer verification queue    |
| POST   | `/admin/volunteers/{id}/verify` | Admin         | Verify / reject volunteer       |
| GET/POST | `/requests` …         | Citizen / Admin       | Emergency request workflow      |

**API (JSON)** — see [`API.md`](./API.md)

| Method | Path                     | Access       | Purpose                  |
|--------|--------------------------|--------------|--------------------------|
| GET    | `/api/v1`                | Public       | API metadata             |
| GET    | `/api/v1/health`         | Public       | Health check             |
| GET    | `/api/v1/csrf`           | Public       | Issue a CSRF token       |
| POST   | `/api/v1/auth/register`  | Guest        | Create an account        |
| POST   | `/api/v1/auth/login`     | Guest        | Authenticate             |
| POST   | `/api/v1/auth/logout`    | Session      | End the session          |
| GET    | `/api/v1/auth/me`        | Session      | Current user             |
| GET    | `/api/v1/requests` …     | Auth / roles | Emergency request API    |
| GET/PATCH | `/api/v1/volunteers/profile` | Volunteer | Own volunteer profile  |
| POST   | `/api/v1/volunteers/availability` | Volunteer | Availability toggle |
| GET    | `/api/v1/volunteers/requests` | Volunteer | Eligible nearby requests |
| GET    | `/api/v1/volunteers/{id}` | Admin      | Restricted volunteer read |
| GET    | `/api/v1/admin/volunteers` | Admin      | Volunteer list           |
| POST   | `/api/v1/admin/volunteers/{id}/verify` | Admin | Verify / reject volunteer |
| GET/PATCH | `/api/v1/organizations/profile` | Organization | Own organization profile |
| GET    | `/api/v1/resources`         | Authenticated | Role-scoped inventory list   |
| POST   | `/api/v1/resources`         | Organization | Create resource (verified org) |
| PATCH  | `/api/v1/resources/{id}`    | Owner/Admin  | Update resource              |
| POST   | `/api/v1/resources/{id}/allocate` | Owner/Admin | Allocate to request       |
| GET    | `/api/v1/admin/organizations` | Admin       | Organization list            |
| POST   | `/api/v1/admin/organizations/{id}/verify` | Admin | Verify organization    |
| POST   | `/api/v1/admin/organizations/{id}/reject` | Admin | Reject organization     |
| GET    | `/api/v1/requests/{id}/matches` | Authenticated | Stored matches for a request |
| POST   | `/api/v1/requests/{id}/matches/suggest` | Citizen / Admin | Generate + persist suggestions |
| GET    | `/api/v1/matches`       | Authenticated | Role-scoped match list        |
| GET    | `/api/v1/matches/{id}`  | Authenticated | Single match read             |
| POST   | `/api/v1/matches/{id}/accept` | Authenticated | Accept match              |
| POST   | `/api/v1/matches/{id}/reject` | Authenticated | Reject match              |
| POST   | `/api/v1/matches/{id}/complete` | Authenticated | Complete match            |
| GET    | `/api/v1/admin/dashboard` | Admin | KPI + breakdown summary (§55)   |
| GET    | `/api/v1/admin/requests` | Admin | Filtered operational queue (§56) |
| GET    | `/api/v1/admin/resources` | Admin | Resource overview (§57)        |
| GET    | `/api/v1/admin/audit-logs` | Admin | Paginated audit trail (§59)   |
| POST   | `/api/v1/ai/classify` | Authenticated | AI category/summary suggestion (§70) |
| POST   | `/api/v1/ai/summarize` | Authenticated | AI short summary (§70)      |
| POST   | `/api/v1/ai/extract`  | Authenticated | AI structured extraction (§70) |
| POST   | `/api/v1/ai/normalize` | Authenticated | AI request normalization (§70) |
| POST   | `/requests/ai-assist` | Authenticated | Browser AI assist (JSON)     |

State-changing requests require a CSRF token: a `<meta name="csrf-token">` tag on pages,
or the `X-CSRF-Token` header (from `GET /api/v1/csrf`) for API clients.

API responses follow the contract in [`API.md`](./API.md):

```json
{ "success": true, "message": "Service healthy.", "data": { "status": "ok" } }
```

---

## Environment variables

All configuration lives in `.env` (never committed). See `.env.example` for the full list:
application (`APP_*`), session (`SESSION_*`), database (`DB_*`), and optional integrations
(`AI_*`, `MAP_*`, `MAIL_*`).

---

## Testing

The project ships a dependency-free smoke test runner (no PHPUnit/Composer needed):

```bash
php tests/smoke.php
```

It asserts configuration loading, escaping (XSS), JSON response envelopes, the validator,
routing (dispatch, 404, 405, path parameters), and view rendering.

Database integration tests (require a migrated + seeded database):

```bash
php -c php.ini tests/database.php
```

They cover table existence, seed counts, repository CRUD, unique/foreign-key/check
constraints, cascade vs restrict behavior, transaction rollback, resource allocation
limits and status recalculation.

Authentication & RBAC tests (require a migrated + seeded database):

```bash
php -c php.ini tests/auth.php
```

They cover password hashing/verification, duplicate + invalid-role registration, role
profiles, credential and suspended-account handling, session lifecycle, the auth/role/CSRF
middleware, and logout.

Emergency request core tests (require a migrated + seeded database):

```bash
php -c php.ini tests/emergency.php
```

They cover the deterministic priority engine, request creation/validation boundaries,
ownership authorization, update/cancel rules, the status state machine, timeline events,
safe attachment upload/rejection, web PRG flows, and the request JSON API.

Volunteer system tests (require a migrated + seeded database):

```bash
php -c php.ini tests/volunteer.php
```

They cover volunteer profile management, availability controls, admin verification,
eligible nearby-request discovery, web PRG flows and the volunteer JSON API.

Organization & resource tests (require a migrated + seeded database):

```bash
php -c php.ini tests/organization.php
```

They cover organization profiles, admin verification, resource CRUD/quantity rules,
transactional allocation (including rollback), IDOR guards, web PRG flows and the
organization/resource JSON API.

Smart matching engine tests (require a migrated + seeded database):

```bash
php -c php.ini tests/matching.php
```

They cover capability scoring, radius filtering, candidate exclusions, score
determinism, explanation reasons, coordinate privacy, transactional accept/reject/
complete, conflict guards, CSRF, IDOR, duplicate suggestion prevention and the
web/API surfaces.

Admin control center tests (require a migrated + seeded database):

```bash
php -c php.ini tests/admin.php
```

They cover KPI aggregation against raw SQL counts, queue filters (status /
priority / category / date / search) and pagination, invalid-filter safety,
resource overview + shortage detection, activity/audit reads, server-side ADMIN
enforcement on web and API, audited verify/escalate/status actions, workflow
guardrails, volunteer/organization verification delegation, rendered admin pages
and map-privacy preservation.

AI assistance tests (require a migrated database):

```bash
php -c php.ini tests/ai.php
```

They cover availability/config detection, provider success (direct, fenced and
chat-envelope bodies), invalid/malformed/empty/array responses, hallucinated and
out-of-vocabulary field rejection, out-of-range confidence clamping, HTTP 5xx /
429 / 404, timeout and connection-refused fallback, missing-key fallback, prompt
privacy (email/phone stripping + bounded length), secret-free logging, endpoint
auth/CSRF/validation gating (200 fallback rather than 5xx), and that the
deterministic PriorityService result is unaffected by any AI suggestion.

Location, map & notification tests (require a migrated + seeded database):

```bash
php -c php.ini tests/notifications.php
```

They cover geo math (haversine / validatePair / rounding), role-aware marker privacy
(admin exact, citizen own-only, volunteer exact only after accepted match, org own vs
approximate), domain-event notifications (create / escalate / resolve / match found /
match accepted / resource allocated), owner-scoped read + mark-read with IDOR
non-enumeration, CSRF via middleware, `/map` + `/notifications` page embeds, and the
notification + map JSON APIs.

Analytics tests (require a migrated database):

```bash
php -c php.ini tests/analytics.php
```

They cover period validation and windowing (day/week/month/year bucket counts,
recent vs old rows inside/outside the day window), request aggregates verified
against raw SQL counts, the requests-over-time series totalling, resolution-time
statistics, resource availability/allocated metrics, volunteer task counts, the
API.md §58 payload, read-only behaviour (no audit rows written), and server-side
ADMIN enforcement on the web page, the web route middleware and the JSON API.

Security hardening tests (no database required for the limiter/header checks):

```bash
php -c php.ini tests/security.php
```

They cover the auth brute-force guard (attempt accounting, sliding-window
pruning, key isolation, clear-on-success, 429 for the API and a redirect for the
web, no session on a blocked request, other clients unaffected), the default
security response headers (applied, not duplicated, never overriding an explicit
value), and production error handling (no path/class/stack leakage).

Hackathon demo verification (require a migrated + seeded database):

```bash
php -c php.ini tests/demo.php
```

It drives the full scripted demo through the real service layer and asserts each
step: citizen creates a medical emergency → priority calculated → matching
suggests a capable nearby volunteer → volunteer sees it and accepts (request
ACCEPTED, volunteer BUSY, citizen notified, audited) → admin sees it in the queue
and as an exact map marker → admin moves it to IN_PROGRESS (audited) → volunteer
completes (request RESOLVED, volunteer AVAILABLE, citizen notified) → admin KPIs
and analytics update. Every fixture it creates is removed afterwards.

Volunteer system tests (require a migrated + seeded database):

```bash
php -c php.ini tests/volunteer.php
```

They cover profile create/update, skill/vehicle vocabulary, invalid skill data, service
radius and location validation, availability toggles, IDOR/role-escalation guards, admin
verification, volunteer request visibility (capability + distance + availability gates),
web PRG flows, and the volunteer JSON API.

Organization & resource tests (require a migrated + seeded database):

```bash
php -c php.ini tests/organization.php
```

They cover organization profile create/update, type/location validation, IDOR/role
guards, admin verification and rejection, resource creation/update/replenish rules
(quantity bounds, available ≤ total, derived vs explicit status), transactional
allocation (insufficient stock, closed/missing requests, timeline event + audit entry,
atomic rollback of the full allocation unit), role-scoped inventory visibility, web PRG
flows, and the organization/resource JSON API.

> Run suites against a freshly seeded database when possible: `php -c php.ini database/seed.php`.
> Suites that mutate seed-counted rows clean up after themselves; re-seed if assertions drift.

---

## Error handling & logging

- **Development** (`APP_DEBUG=true`): detailed errors help debugging; never leaked in production.
- **Production** (`APP_DEBUG=false`): users see safe messages only; details go to logs.
- Logs are written to `storage/logs/*.log`. Passwords, tokens, and API keys are redacted.

---

## Roadmap

| Phase | Name                            | Status      |
|-------|---------------------------------|-------------|
| 1     | Project Foundation              | ✅ Complete |
| 2     | Database & Persistence          | ✅ Complete |
| 3     | Authentication & RBAC           | ✅ Complete |
| 4     | Emergency Request Core          | ✅ Complete |
| 5     | Volunteer System                | ✅ Complete |
| 6     | Organization & Resources        | ✅ Complete |
| 7     | Smart Matching Engine           | ✅ Complete |
| 8     | Location, Map & Notifications   | ✅ Complete |
| 9     | Admin Control Center            | ✅ Complete |
| 10    | AI Assistance Layer             | ✅ Complete |
| 11    | Analytics & Audit               | ✅ Complete |
| 12    | Hardening, Testing & Polish     | ✅ Complete |

---

## Known issues / notes

- `PHASES.md` is present as **`PHASES(3).md`** in this repository. The filename differs from
  the references in other documents; contents are unchanged.
- The documented target is **MySQL 8+**. Local development was verified against
  **MariaDB 10.4** (XAMPP); the schema uses only features common to both (InnoDB, utf8mb4,
  ENUM, FK, CHECK constraints, JSON).
- The PHP CLI must have `pdo_mysql` enabled. On this machine that is achieved with the
  project-local `php.ini` (`php -c php.ini …`).
- Seeder accounts share a documented local-only password and must never be used in
  production.
- `APP_URL` is the canonical base used for generated links and redirects (this deliberately
  avoids trusting the `Host` header). Keep it matching the URL you actually browse on,
  e.g. `APP_URL=http://localhost:8099` if you serve on another port.
- The admin request queue orders by a `FIELD(status, …)` expression, which MySQL cannot
  serve from an index; it does a small filesort. That is fine at demo scale (measured
  ~0.5 ms) but is the first thing to revisit if the request table grows large.
- Analytics `GROUP BY` over a time bucket also uses a temporary table + filesort after
  the `idx_requests_created` range scan (measured ~3–4 ms over the whole dashboard).
- No Content-Security-Policy is sent: the map/chart UI loads from a CDN and uses inline
  data blocks, so a policy strict enough to be meaningful risks breaking the emergency
  UI. The other hardening headers are always applied.
- The rate limiter is per-host and file-backed (no Redis in the MVP stack). It fails
  open if `storage/cache/` is not writable, so it can never lock legitimate users out.
- Several test suites create requests/matches that trigger notifications to **seeded**
  accounts, so a long test session inflates the seeded admin's notification badge. Run
  `php -c php.ini database/seed.php` before a demo to reset to the pristine dataset.
- Email delivery is optional and not enabled by default; notifications are in-app.

---

## License

Proprietary — TYBCA hackathon project.
