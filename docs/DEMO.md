# ResQLink — Hackathon Demo Runbook

**Goal:** reproduce a reliable 2–3 minute end-to-end emergency-coordination demo
from a known state, and verify it automatically.

> ResQLink is a **coordination and information platform**. It does not replace
> police, fire, ambulance, or disaster-management authorities.

---

## 1. Reset to the controlled demo state

Always start from a clean, deterministic dataset (the seeders truncate the
application tables and load fixed fictional data).

```bash
# Drop + recreate the schema, then load the demo dataset
php -c php.ini database/migrate.php --fresh
php -c php.ini database/seed.php
```

Verify what was loaded:

```bash
php -c php.ini database/migrate.php --status
php -c php.ini database/seed.php      # prints a seed summary
```

Start the app:

```bash
php -S localhost:8000 -t public public/router.php
# open http://localhost:8000
```

### Demo accounts

All seeded accounts share the local-only password **`Password123!`**
(never reuse outside a demo). Emails use the reserved `.test` TLD.

| Role         | Sign in as                                              |
|--------------|---------------------------------------------------------|
| Admin        | `admin@resqlink.test`                                   |
| Citizen      | `citizen1@resqlink.test` … `citizen5@resqlink.test`     |
| Volunteer    | `volunteer1@resqlink.test` … `volunteer10@resqlink.test`|
| Organization | `org1@resqlink.test` … `org3@resqlink.test`             |

### Seeded volume

| Entity              | Count |
|---------------------|-------|
| Admin               | 1     |
| Citizens            | 5     |
| Volunteers          | 10 (8 verified, 2 pending; mixed availability) |
| Organizations       | 3 (2 verified, 1 pending) |
| Resources           | 8 (available / limited / unavailable) |
| Emergency requests  | 10 (CRITICAL/HIGH/MEDIUM/LOW across CREATED→RESOLVED) |
| Matches             | 10    |
| Request timeline events | 39+ |
| Notifications       | delivered per event |

> **Deviation from the Phase 12.6 suggestion** (1 admin / 3 citizens /
> 5 volunteers / 2 orgs / 5 resources / 5 active / 3 resolved): the canonical
> dataset is a deterministic **superset** (5 / 10 / 3 / 8 / 7 active / 2
> resolved) because it doubles as the fixture set that the database and
> matching test suites assert against (`tests/database.php`). It still contains
> every required priority band and lifecycle state, including resolved
> requests, which is what the demo scenario needs.

---

## 2. The 10-step demo scenario

| # | Actor | Action | Expected result |
|---|-------|--------|-----------------|
| 1 | Citizen | Sign in as `citizen1@resqlink.test`, open **Request Help** | Emergency form loads with AI assist (optional) |
| 2 | Citizen | Create a **Medical** emergency, 3 people affected, capture location | Request is created; **priority is calculated** (rule-based, explainable) |
| 3 | System | — | Matching runs automatically; capable nearby volunteers get a **match** |
| 4 | Volunteer | Sign in as `volunteer2@resqlink.test`, open **Nearby** / **Matches** | The request is listed with distance, priority and people affected |
| 5 | Volunteer | **Accept** the request | Match → `ACCEPTED`, request → `ACCEPTED`, volunteer → `BUSY` |
| 6 | Citizen | Reload the request | Status shows **"Help accepted"** + an in-app notification |
| 7 | Admin | Sign in as `admin@resqlink.test`, open **Control center** and **Map** | KPI cards + live map show the active request at **exact** coordinates |
| 8 | Admin | In the **request queue**, advance the request to `IN_PROGRESS` | Status updates, timeline event + audit row written |
| 9 | Volunteer | In **Matches**, mark the task **complete** | Match → `COMPLETED`, request → `RESOLVED`, volunteer → `AVAILABLE` |
| 10 | Admin | Open the **Control center** and **Analytics** | Resolved KPI and the analytics dashboard/resolution stats update |

**Automated equivalent** (verifies all 10 steps through the real service layer
and cleans up after itself):

```bash
php -c php.ini tests/demo.php
```

---

## 3. What to show (fast path, 2–3 minutes)

1. **Citizen view** — create a request; point out the transparent priority
   reasons (no black box) and the stress-friendly form.
2. **Volunteer view** — nearby request with distance/priority, then accept.
3. **Citizen view** — "help accepted" notification.
4. **Admin control center** — KPI cards, live map, priority queue.
5. **Analytics** — charts (requests over time, by category, status doughnut,
   resource availability) with the accessible data tables behind them.
6. **Audit trail** — every admin action traced under **Activity & audit**.

Optional depth: organization inventory + transactional allocation
(`org1@resqlink.test`), and the AI assist panel (works with or without a key —
falls back to deterministic keyword logic).

---

## 4. Pre-demo checklist

- [ ] `database/migrate.php --fresh` + `database/seed.php` run **from this repo**
- [ ] `.env` points at a reachable MySQL and does **not** set `APP_DEBUG=false`
      for a dev machine (false is correct for anything public)
- [ ] `php -c php.ini tests/demo.php` passes (see §2)
- [ ] No stale notifications: re-run `database/seed.php` if the admin badge looks noisy
- [ ] Browser has geolocation permission, or enter coordinates manually
- [ ] Map/chart CDNs reachable (Leaflet / Chart.js) — the UI degrades gracefully if not

---

## 5. Troubleshooting

| Symptom | Fix |
|---------|-----|
| `pdo_mysql` missing / "database unavailable" | `copy php.ini.example php.ini`, enable `pdo_mysql`, and use `php -c php.ini …` |
| MySQL not running | Start your MySQL/XAMPP service, confirm `DB_HOST`/`DB_PORT` |
| "Unable to connect to the database" | Check `DB_*` in `.env`; the app boots regardless and reports status at `/system-status` |
| Map tiles blank | CDN/network issue — the rest of the page still works |
| Charts empty | Chart.js CDN blocked — the accessible data tables below still show every number |
| Too many sign-in attempts | The brute-force guard blocks after 10 failures / 5 min per host — wait or restart the app; `storage/cache/rate_limit/` holds the counters |

---

## 6. Edge cases to mention (already handled)

No internet, AI unavailable, map unavailable, no matching volunteer, no
resources, two volunteers accepting at once (only one wins), request already
resolved/cancelled, invalid location, invalid/oversized file upload,
unauthorized API call, expired session. Coverage lives in `tests/` — see the
test section of [`README.md`](../README.md).
