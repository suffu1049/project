# Contributing to ResQLink

Thanks for helping with ResQLink — an emergency resource and volunteer
coordination platform. This is a short guide to getting the project running and
to the conventions we follow. The full docs live in
[`README.md`](./README.md), [`ARCHITECTURE.md`](./ARCHITECTURE.md),
[`DATABASE.md`](./DATABASE.md), [`API.md`](./API.md) and
[`docs/DEMO.md`](./docs/DEMO.md).

> ResQLink is a **coordination and information platform**. It does not replace
> police, fire, ambulance, or disaster-management authorities.

---

## 1. Set up locally (5 minutes)

**Requirements:** PHP 8.1+ with `pdo` + `pdo_mysql`, MySQL 8+ / MariaDB 10.4+.
No build step; Composer is optional.

```bash
# 1. Clone and enter the project
git clone https://github.com/suffu1049/project.git
cd project

# 2. Create your environment file
cp .env.example .env      # then set APP_URL and the DB_* values

# 3. Create the database + least-privilege user (see README "Database setup")
#    then create the schema and load the deterministic demo data
php -c php.ini database/migrate.php --fresh
php -c php.ini database/seed.php

# 4. Run it
php -S localhost:8000 -t public public/router.php
# open http://localhost:8000
```

**Windows / missing `pdo_mysql`:** copy `php.ini.example` to `php.ini`, enable
`pdo_mysql`, and prefix every command with `php -c php.ini …` (the app still
boots without a database and reports status at `/system-status`).

**Demo accounts** (local only, never reuse): password `Password123!` —
`admin@resqlink.test`, `citizen1@resqlink.test` … `citizen5@resqlink.test`,
`volunteer1@resqlink.test` … `volunteer10@resqlink.test`,
`org1@resqlink.test` … `org3@resqlink.test`.

See [`docs/DEMO.md`](./docs/DEMO.md) for the full demo runbook and scenario.

---

## 2. Run the tests

Every change should keep the whole suite green. These are dependency-free PHP
scripts (exit code `0` = pass, `1` = failure); all of them except
`tests/smoke.php` need a migrated + seeded database:

```bash
for t in smoke database auth emergency volunteer organization matching \
         notifications admin ai analytics security demo; do
  php -c php.ini "tests/$t.php" || exit 1
done
```

Add or extend a test for any behaviour you change, and fix the cause of a
failure — never weaken an assertion or skip a test to make it pass.

---

## 3. Conventions

**Architecture** — keep the layers honest:

```text
Controller → validation/auth → Service → Model/Repository → Database
View       → presentation only (escape with e())
Middleware → auth / role / CSRF / request guards
AIService  → the only place that talks to an AI provider
```

**Code**
- Repositories do persistence only: **prepared statements**, no business rules, no HTML.
- Services hold the business rules; controllers stay thin.
- Validate on the **server**; never trust client-side validation or hidden fields.
- Escape every user-controlled value in a view with `e()`. Never build SQL by string interpolation.
- State-changing routes require `CsrfMiddleware`; authorization is enforced server-side (never by hiding UI).
- Add an index with any new lookup column; avoid N+1 queries.
- Keep AI optional: the core workflow must work when it is unavailable (deterministic fallback).

**Never commit** `.env` or anything containing real credentials, and never use
real people's data — seeds and tests use fictional data on the reserved `.test`
TLD.

---

## 4. Branching & commits

```text
main                     stable, demonstrable
feature/phase-NN-<name>  one phase or feature per branch
```

Commit messages are imperative and focused on the *why*, e.g.
`feat: complete phase 04 emergency request core`. Keep each commit to one
coherent change.

---

## 5. Pull request checklist

- [ ] Follows the layered architecture above; no business logic in controllers or views
- [ ] Server-side validation, authorization and CSRF on every mutation
- [ ] New code escaped with `e()`; queries use prepared statements and indexes
- [ ] AI (if touched) still degrades gracefully without a provider
- [ ] Relevant docs updated (`README`/`API.md`/`DATABASE.md`/`ARCHITECTURE.md`)
- [ ] Full test suite passes (§2) and new behaviour is covered

---

## 6. Reporting issues

Include what you expected, what happened, and how to reproduce it (steps,
role/account, request or page). For security issues, do **not** open a public
issue — contact the maintainer directly.
