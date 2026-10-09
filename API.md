# ResQLink — API Specification

**Version:** 1.0  
**Project:** ResQLink — Emergency Resource & Volunteer Coordination Platform  
**API Version:** v1  
**Base URL:** `/api/v1`  
**Backend:** PHP 8+  
**Database:** MySQL 8+  
**Authentication:** PHP Session / secure cookie for web MVP  
**Response Format:** JSON

---

# 1. API Purpose

The ResQLink API provides a structured communication layer between:

- Web frontend
- Citizen dashboard
- Volunteer dashboard
- Organization dashboard
- Admin control center
- AI service
- Location/matching services

The API must remain predictable, secure, and independent from the presentation layer.

---

# 2. API Design Principles

The API follows these principles:

1. Use resource-oriented URLs.
2. Use HTTP methods correctly.
3. Return consistent JSON.
4. Validate all input server-side.
5. Authenticate protected endpoints.
6. Authorize every role-sensitive operation.
7. Never expose sensitive information unnecessarily.
8. Use transactions for critical state changes.
9. Keep AI and external APIs optional to core workflows.
10. Return useful errors without exposing internal implementation details.

---

# 3. Base URL

Development:

```text
http://localhost/resqlink/api/v1
```

Production example:

```text
https://your-domain.example/api/v1
```

The production URL must use HTTPS.

---

# 4. HTTP Methods

| Method | Purpose |
|---|---|
| GET | Retrieve data |
| POST | Create a resource or perform an action |
| PATCH | Partially update a resource |
| DELETE | Remove a resource where supported |

For workflow actions such as accepting a match, use explicit action endpoints:

```text
POST /matches/{id}/accept
```

---

# 5. Common Headers

Request:

```http
Content-Type: application/json
Accept: application/json
```

For file uploads:

```http
Content-Type: multipart/form-data
```

Authentication for the web MVP is handled through a secure session cookie.

---

# 6. Standard Success Response

All successful JSON responses should follow this structure:

```json
{
  "success": true,
  "message": "Operation completed successfully.",
  "data": {}
}
```

For collections:

```json
{
  "success": true,
  "message": "Requests retrieved successfully.",
  "data": {
    "items": [],
    "pagination": {}
  }
}
```

---

# 7. Standard Error Response

```json
{
  "success": false,
  "message": "Validation failed.",
  "errors": {
    "category": "Category is required."
  },
  "code": "VALIDATION_ERROR"
}
```

For a general server error:

```json
{
  "success": false,
  "message": "Something went wrong. Please try again.",
  "code": "INTERNAL_ERROR"
}
```

Do not expose:

```text
SQL queries
Database passwords
Stack traces
API keys
Internal filesystem paths
```

---

# 8. HTTP Status Codes

| Code | Meaning |
|---|---|
| 200 | Success |
| 201 | Resource created |
| 204 | Success with no response body |
| 400 | Bad request |
| 401 | Not authenticated |
| 403 | Not authorized |
| 404 | Resource not found |
| 409 | Conflict |
| 422 | Validation error |
| 429 | Rate limit exceeded |
| 500 | Internal server error |
| 502 | External service failure |

---

# 9. Authentication

## POST `/auth/register`

Creates a new user account.

### Access

Public.

### Request

```json
{
  "name": "Rahul Sharma",
  "email": "rahul@example.com",
  "phone": "9876543210",
  "password": "StrongPassword123",
  "role": "VOLUNTEER"
}
```

### Allowed Roles

Public registration should allow:

```text
CITIZEN
VOLUNTEER
ORGANIZATION
```

`ADMIN` must never be publicly selectable.

### Response

```json
{
  "success": true,
  "message": "Account created successfully.",
  "data": {
    "user": {
      "id": 15,
      "name": "Rahul Sharma",
      "email": "rahul@example.com",
      "role": "VOLUNTEER"
    }
  }
}
```

### Errors

```text
EMAIL_ALREADY_EXISTS
VALIDATION_ERROR
INVALID_ROLE
```

---

# 10. POST `/auth/login`

Authenticates a user.

### Request

```json
{
  "email": "rahul@example.com",
  "password": "StrongPassword123"
}
```

### Response

```json
{
  "success": true,
  "message": "Login successful.",
  "data": {
    "user": {
      "id": 15,
      "name": "Rahul Sharma",
      "email": "rahul@example.com",
      "role": "VOLUNTEER"
    }
  }
}
```

The server creates a secure session.

### Errors

```text
INVALID_CREDENTIALS
ACCOUNT_SUSPENDED
VALIDATION_ERROR
```

---

# 11. POST `/auth/logout`

Logs out the current user.

### Access

Authenticated.

### Response

```json
{
  "success": true,
  "message": "Logged out successfully.",
  "data": null
}
```

The server must destroy/invalidate the session.

---

# 12. GET `/auth/me`

Returns the current authenticated user.

### Access

Authenticated.

### Response

```json
{
  "success": true,
  "message": "Current user retrieved.",
  "data": {
    "id": 15,
    "name": "Rahul Sharma",
    "email": "rahul@example.com",
    "role": "VOLUNTEER",
    "status": "ACTIVE"
  }
}
```

---

# 12.1 CSRF token

## GET `/api/v1/csrf`

### Access

Public (starts a session if needed).

Every state-changing request (`POST` / `PATCH` / `PUT` / `DELETE`) must present the session
CSRF token, either as a header:

```http
X-CSRF-Token: <token>
```

or as a `_token` field in a form/multipart body.

### Response

```json
{
  "success": true,
  "message": "CSRF token issued.",
  "data": {
    "csrf_token": "0f2c..."
  }
}
```

Missing or invalid token:

```text
403  CSRF_MISMATCH
```

> **Implemented in Phase 3.** Browser pages embed the token in a `<meta name="csrf-token">`
> tag; API clients read it from this endpoint. Login and registration are subject to the
> same check.

---

# 13. Authentication Middleware

Protected routes pass through:

```text
Request
   ↓
Session Check
   ↓
Authenticated?
   ├── NO → 401
   │
   └── YES
        ↓
     Controller
```

Role-protected routes additionally use:

```text
Role Middleware
```

---

# 14. Emergency Requests API

Emergency requests are the primary API resource.

---

# 15. GET `/requests`

Returns emergency requests visible to the current user.

### Access

Authenticated.

### Query Parameters

```text
status
priority
category
page
limit
```

Implemented filters (`GET /api/v1/requests`) validate enum values and return
`422 VALIDATION_ERROR` for unknown values:

- `status`: CREATED, VERIFIED, MATCHING, ACCEPTED, IN_PROGRESS, RESOLVED, CANCELLED, ESCALATED
- `priority`: LOW, MEDIUM, HIGH, CRITICAL
- `category`: MEDICAL, ACCIDENT, FLOOD, FIRE, FOOD_WATER, SHELTER, TRANSPORT, MISSING_STRANDED, OTHER

Example:

```text
GET /requests?status=MATCHING&priority=CRITICAL&page=1&limit=20
```

### Citizen Visibility

Citizen should primarily see:

```text
Own requests
```

### Volunteer Visibility

Volunteer can see:

```text
Eligible/nearby requests
```

### Admin Visibility

Admin can see:

```text
All operational requests
```

### Response

```json
{
  "success": true,
  "message": "Requests retrieved successfully.",
  "data": {
    "items": [
      {
        "id": 1024,
        "category": "MEDICAL",
        "priority": "CRITICAL",
        "status": "MATCHING",
        "people_affected": 3,
        "created_at": "2026-10-02T12:10:00Z"
      }
    ],
    "pagination": {
      "page": 1,
      "limit": 20,
      "total": 1,
      "pages": 1
    }
  }
}
```

---

# 16. POST `/requests`

Creates an emergency request.

### Access

Authenticated Citizen.

### Request

```json
{
  "category": "MEDICAL",
  "description": "Three people need urgent medical assistance.",
  "people_affected": 3,
  "latitude": 20.38930000,
  "longitude": 72.91060000,
  "location_accuracy_m": 15.5,
  "address_text": "Demo location"
}
```

### Server Actions

```text
1. Authenticate user
2. Validate fields
3. Create request
4. Calculate initial priority
5. Store priority reasons
6. Create request event
7. Attempt matching
8. Generate notifications
```

AI assistance may run after the initial request is safely stored.

### Response

```json
{
  "success": true,
  "message": "Emergency request created successfully.",
  "data": {
    "request": {
      "id": 1024,
      "category": "MEDICAL",
      "priority": "CRITICAL",
      "priority_score": 82,
      "priority_reason": [
        "Medical emergency",
        "Multiple people affected"
      ],
      "status": "MATCHING"
    }
  }
}
```

### Errors

```text
VALIDATION_ERROR
UNAUTHORIZED
REQUEST_CREATION_FAILED
```

---

# 17. GET `/requests/{id}`

Returns a specific request.

### Access

Authenticated and authorized.

### Response

```json
{
  "success": true,
  "message": "Request retrieved successfully.",
  "data": {
    "id": 1024,
    "category": "MEDICAL",
    "description": "Three people need urgent medical assistance.",
    "people_affected": 3,
    "priority": "CRITICAL",
    "priority_score": 82,
    "priority_reason": [
      "Medical emergency",
      "Multiple people affected"
    ],
    "status": "MATCHING",
    "location": {
      "latitude": 20.3893,
      "longitude": 72.9106
    },
    "created_at": "2026-10-02T12:10:00Z"
  }
}
```

Exact location must only be returned to authorized users.

---

# 18. PATCH `/requests/{id}`

Updates permitted request fields.

### Access

Request owner or authorized admin.

### Editable Fields

Before matching/acceptance:

```text
description
people_affected
location
```

The citizen must not directly change:

```text
priority
priority_score
status
assigned provider
```

Those are controlled by the application workflow.

### Request

```json
{
  "description": "Updated situation description.",
  "people_affected": 4
}
```

---

# 19. POST `/requests/{id}/cancel`

Cancels an emergency request.

### Access

Request owner or authorized admin.

### Rules

Allowed only when the request is not already:

```text
RESOLVED
CANCELLED
```

### Response

```json
{
  "success": true,
  "message": "Request cancelled successfully.",
  "data": {
    "id": 1024,
    "status": "CANCELLED"
  }
}
```

---

# 20. POST `/requests/{id}/verify`

Marks a request as verified.

### Access

Admin.

### Response

```json
{
  "success": true,
  "message": "Request verified.",
  "data": {
    "id": 1024,
    "status": "VERIFIED"
  }
}
```

---

# 21. POST `/requests/{id}/escalate`

Escalates a request.

### Access

Admin or authorized operational user.

### Request

```json
{
  "reason": "No compatible resource found nearby."
}
```

### Response

```json
{
  "success": true,
  "message": "Request escalated.",
  "data": {
    "id": 1024,
    "status": "ESCALATED"
  }
}
```

---

# 22. Request Status Update

Use:

```text
POST /requests/{id}/status
```

### Access

Admin or assigned volunteer/resource operator where appropriate.

### Request

```json
{
  "status": "IN_PROGRESS",
  "note": "Volunteer has started assistance."
}
```

### Valid Transitions

```text
CREATED → VERIFIED
VERIFIED → MATCHING
MATCHING → ACCEPTED
ACCEPTED → IN_PROGRESS
IN_PROGRESS → RESOLVED
```

Invalid transitions return:

```text
409 CONFLICT
```

---

# 23. Emergency Attachments

## POST `/requests/{id}/attachments`

Uploads an image/file.

### Access

Request owner or authorized user.

### Content Type

```text
multipart/form-data
```

Fields:

```text
file
```

### Rules

- Validate MIME type.
- Validate file extension.
- Limit file size.
- Generate safe filename.
- Do not execute uploaded files.
- Store file reference in `emergency_attachments`.

### Response

```json
{
  "success": true,
  "message": "Attachment uploaded.",
  "data": {
    "id": 55,
    "file_name": "emergency-photo.jpg",
    "mime_type": "image/jpeg"
  }
}
```

---

# 24. GET `/requests/{id}/events`

Returns the request timeline.

### Response

```json
{
  "success": true,
  "message": "Request events retrieved.",
  "data": [
    {
      "event_type": "REQUEST_CREATED",
      "old_status": null,
      "new_status": "CREATED",
      "note": "Emergency request created.",
      "created_at": "2026-10-02T12:10:00Z"
    },
    {
      "event_type": "STATUS_CHANGED",
      "old_status": "MATCHING",
      "new_status": "ACCEPTED",
      "note": "Volunteer accepted request.",
      "created_at": "2026-10-02T12:14:00Z"
    }
  ]
}
```

---

# 25. Volunteer API

All paths are prefixed with the base URL (`/api/v1`). Volunteer profile endpoints
require the `VOLUNTEER` role; admin endpoints require `ADMIN`. State-changing calls
need a CSRF token (`GET /api/v1/csrf` → `X-CSRF-Token`).

---

# 26. GET `/volunteers/profile`

Returns the current volunteer profile.

### Access

Volunteer.

### Response

```json
{
  "success": true,
  "message": "Volunteer profile retrieved.",
  "data": {
    "id": 15,
    "user_id": 15,
    "skills": [
      "FIRST_AID",
      "DRIVING"
    ],
    "vehicle": "CAR",
    "equipment": [
      "FIRST_AID_KIT"
    ],
    "availability": "AVAILABLE",
    "service_radius_km": 10,
    "verification_status": "VERIFIED",
    "location": {
      "latitude": 20.3881,
      "longitude": 72.909
    },
    "has_location": true
  }
}
```

---

# 27. PATCH `/volunteers/profile`

Updates volunteer profile (partial update — only submitted fields are written).

### Access

Volunteer (own profile only). Clients cannot set `verification_status`.

### Request

```json
{
  "skills": [
    "FIRST_AID",
    "DRIVING"
  ],
  "vehicle": "CAR",
  "equipment": [
    "FIRST_AID_KIT"
  ],
  "service_radius_km": 10
}
```

### Rules

```text
skills:     controlled vocabulary (MEDICAL, TRANSPORT, FOOD_DISTRIBUTION,
            SEARCH_SUPPORT, FIRST_AID, GENERAL, DRIVING); max 10
vehicle:    NONE | CAR | VAN | BIKE | AMBULANCE | TRUCK
equipment:  free tokens (A–Z, 0–9, _); max 10
service_radius_km: 0.1 – 1000
latitude/longitude: must be provided together; valid WGS84 ranges
```

---

# 28. POST `/volunteers/availability`

Changes volunteer availability.

### Request

```json
{
  "availability": "AVAILABLE"
}
```

### Allowed Values

```text
AVAILABLE
BUSY
OFFLINE
```

### Response

```json
{
  "success": true,
  "message": "Availability updated.",
  "data": {
    "availability": "AVAILABLE"
  }
}
```

Unavailable volunteers are never treated as available for matching or request lists.

---

# 29. GET `/volunteers/requests`

Returns open requests relevant to the current volunteer.

### Access

Volunteer.

### Query Parameters

```text
radius      optional radius override (km)
category    optional emergency category filter
priority    optional priority filter
```

### Server-side filters

```text
Volunteer availability  (must be AVAILABLE)
Verification            (must be VERIFIED)
Capability              (skill ↔ category heuristic)
Service radius          (haversine when both sides have coordinates)
Request status          (open statuses only)
```

### Response `data`

```json
{
  "items": [
    {
      "id": 1024,
      "category": "MEDICAL",
      "description": "…",
      "people_affected": 1,
      "priority": "HIGH",
      "priority_score": 55,
      "status": "MATCHING",
      "address_text": "Near the market gate",
      "distance_km": 0.42,
      "created_at": "2026-10-02 10:00:00"
    }
  ],
  "meta": {
    "available": true,
    "eligible": true,
    "reason": null,
    "availability": "AVAILABLE",
    "verification": "VERIFIED",
    "service_radius_km": 10,
    "has_location": true,
    "count": 1
  }
}
```

Exact request coordinates are **not** exposed on this endpoint (Phase 7 matching /
Phase 8 maps handle navigation after acceptance).

When the volunteer is not eligible, `items` is empty and `meta.reason` is
`NOT_VERIFIED` or `NOT_AVAILABLE`.

---

# 30. GET `/volunteers/{id}`

Admin-only restricted operational endpoint.

Never exposes phone or email.

### Response

```json
{
  "success": true,
  "message": "Volunteer retrieved.",
  "data": {
    "id": 22,
    "user_id": 42,
    "name": "Vikram Rao",
    "skills": [
      "FIRST_AID",
      "DRIVING"
    ],
    "vehicle": "CAR",
    "equipment": [
      "FIRST_AID_KIT"
    ],
    "availability": "AVAILABLE",
    "service_radius_km": 10,
    "verification_status": "VERIFIED",
    "has_location": true
  }
}
```

---

# 31. Matching API

Implemented: Phase 7 — `MatchingApiController` (`app/controllers/MatchingApiController.php`).

Deterministic, explainable matching. Weights live in `config/matching.php`
(capability 0.50 · availability 0.20 · distance 0.20 · verification 0.10). Scores
are normalized 0–100. No AI.

State-changing endpoints require CSRF (`GET /api/v1/csrf` → `X-CSRF-Token`).

Match statuses: `SUGGESTED` → `NOTIFIED` → `ACCEPTED` → `COMPLETED`;
terminal: `REJECTED`, `EXPIRED`.

Acceptable request statuses for acceptance: `CREATED`, `VERIFIED`, `MATCHING`, `ESCALATED`.

### Endpoints

| Method | Path | Access |
|--------|------|--------|
| GET | `/api/v1/requests/{id}/matches` | Request owner, eligible volunteer, org with a referenced resource, admin |
| POST | `/api/v1/requests/{id}/matches/suggest` | Citizen (owner) / Admin |
| GET | `/api/v1/matches` | Volunteer (own), Organization (own resources), Admin (all) |
| GET | `/api/v1/matches/{id}` | Match participant / owner / admin |
| POST | `/api/v1/matches/{id}/accept` | Assigned volunteer / org operator / admin |
| POST | `/api/v1/matches/{id}/reject` | Assigned volunteer / org operator / admin |
| POST | `/api/v1/matches/{id}/complete` | Assigned provider / admin |

Privacy: volunteer suggestion payloads never include exact request coordinates.
Match payloads never include volunteer phone/email.

---

# 32. GET `/requests/{id}/matches`

Returns stored matches for a request (suggestions persisted as `SUGGESTED` rows).

### Access

Request owner, volunteer eligible to view, organization with a referenced resource, or admin.

### Response

```json
{
  "success": true,
  "message": "Matches retrieved.",
  "data": {
    "items": [
      {
        "id": 5001,
        "type": "VOLUNTEER",
        "request_id": 1024,
        "volunteer_id": 21,
        "resource_id": null,
        "distance_km": 2.14,
        "capability_score": 100,
        "match_score": 91.2,
        "status": "ACCEPTED",
        "reasons": ["MEDICAL + FIRST_AID skill matches MEDICAL", "AVAILABLE", "2.1 km away (within 50.0 km)", "VERIFIED"],
        "provider": {
          "name": "Asha Verma",
          "skills": ["MEDICAL", "FIRST_AID"],
          "vehicle": "CAR",
          "availability": "BUSY",
          "verification_status": "VERIFIED",
          "has_location": true
        },
        "request": {
          "id": 1024,
          "category": "MEDICAL",
          "priority": "HIGH",
          "status": "ACCEPTED",
          "latitude": 20.3883,
          "longitude": 72.9092
        },
        "created_at": "2026-10-02 10:00:00",
        "updated_at": "2026-10-02 10:05:00"
      }
    ],
    "meta": {
      "request_id": 1024,
      "request_status": "ACCEPTED",
      "count": 1
    }
  }
}
```

Notes:
- Volunteers viewing `SUGGESTED`/`NOTIFIED` matches see `request.latitude`/`longitude` as `null` until acceptance.
- `provider` never includes `phone` or `email`.

---

# 32b. POST `/requests/{id}/matches/suggest`

Computes ranked candidates and persists them as `SUGGESTED` match rows (upsert —
no duplicate open match per volunteer/resource). Suppressed once the request has
an accepted match or is closed.

### Request (optional)

```json
{ "type": "BOTH", "limit": 10 }
```

`type`: `BOTH` | `VOLUNTEER` | `RESOURCE` (default `BOTH`). `limit`: 1–50
(default `config('matching.max_suggestions')` = 10).

### Response

```json
{
  "success": true,
  "message": "Match suggestions generated.",
  "data": {
    "items": [
      {
        "id": 5001,
        "type": "VOLUNTEER",
        "name": "Asha Verma",
        "skills": ["MEDICAL", "FIRST_AID"],
        "distance_km": 2.14,
        "capability_score": 100,
        "match_score": 91.2,
        "status": "SUGGESTED",
        "reasons": ["MEDICAL + FIRST_AID skill matches MEDICAL", "AVAILABLE", "2.1 km away", "VERIFIED"],
        "request": { "id": 1024, "category": "MEDICAL", "latitude": null, "longitude": null }
      }
    ],
    "meta": {
      "request_id": 1024,
      "request_status": "CREATED",
      "type_filter": "BOTH",
      "has_accepted_match": false,
      "volunteer_candidates": 3,
      "resource_candidates": 2,
      "weights": { "capability": 0.5, "availability": 0.2, "distance": 0.2, "verification": 0.1 },
      "persisted": true,
      "count": 5
    }
  }
}
```

### Conflicts

| Code | HTTP | When |
|------|------|------|
| `INVALID_REQUEST_STATUS` | 409 | Request not in `CREATED`/`VERIFIED`/`MATCHING`/`ESCALATED` |
| `VALIDATION_ERROR` | 422 | Invalid `type` filter |
| `FORBIDDEN` | 403 | Caller is not owner/admin |
| `REQUEST_NOT_FOUND` | 404 | Unknown request |

---

# 33. GET `/matches`

Role-scoped match list.

- **ADMIN** — all matches (optional `status` filter).
- **VOLUNTEER** — matches where the caller is the volunteer.
- **ORGANIZATION** — matches whose resource belongs to the caller's organization.

### Query

`status` (optional, one of `SUGGESTED`/`NOTIFIED`/`ACCEPTED`/`REJECTED`/`EXPIRED`/`COMPLETED`),
`page`, `limit` (max 100).

### Response

```json
{
  "success": true,
  "message": "Matches retrieved.",
  "data": {
    "items": [ { "id": 5001, "type": "VOLUNTEER", "status": "ACCEPTED", "match_score": 91.2 } ],
    "pagination": { "page": 1, "limit": 20, "total": 1, "pages": 1 }
  }
}
```

---

# 33b. GET `/matches/{id}`

Single match read for the participant, request owner, or admin.

### Response

Same payload shape as §32 items (with `request` summary and `provider`).

### Conflicts

| Code | HTTP |
|------|------|
| `MATCH_NOT_FOUND` | 404 |
| `FORBIDDEN` | 403 |

---

# 34. POST `/matches/{id}/accept`

Accepts a `SUGGESTED`/`NOTIFIED` match. One atomic transaction.

### Transaction (implemented)

```text
1. Load match; authorize participant / owner / admin
2. Require match status SUGGESTED or NOTIFIED
3. Require request status in CREATED / VERIFIED / MATCHING / ESCALATED
4. Reject if another ACCEPTED match already exists for the request
5. Transaction:
   a. Re-check volunteer (VERIFIED + AVAILABLE + no other live acceptance)
      or resource (stock > 0, org VERIFIED)
   b. Set match → ACCEPTED
   c. Advance request CREATED→VERIFIED→MATCHING→ACCEPTED (or shorter path)
      with STATUS_CHANGED timeline events
   d. Set volunteer availability → BUSY
   e. Record MATCH_ACCEPTED timeline event
   f. Audit ACCEPT_MATCH
6. Return match + request summary
```

Notifications fire after the accept transaction commits (Phase 8): MATCH_ACCEPTED
to the request owner (+ resource organization when applicable).

### Response

```json
{
  "success": true,
  "message": "Match accepted.",
  "data": {
    "match": {
      "id": 5001,
      "type": "VOLUNTEER",
      "status": "ACCEPTED",
      "match_score": 91.2,
      "request": { "id": 1024, "status": "ACCEPTED" }
    },
    "request": { "id": 1024, "status": "ACCEPTED", "category": "MEDICAL" }
  }
}
```

### Conflicts

| Code | HTTP | When |
|------|------|------|
| `MATCH_CONFLICT` | 409 | Request already has a different ACCEPTED match; volunteer already assigned |
| `INVALID_REQUEST_STATUS` | 409 | Request closed / not acceptable / match not in SUGGESTED/NOTIFIED |
| `MATCH_NOT_ELIGIBLE` | 409 | Volunteer BUSY/PENDING/REJECTED; resource empty; org unverified |
| `FORBIDDEN` | 403 | Caller does not own the match target |
| `CSRF_MISMATCH` | 403 | Missing/invalid CSRF token |

---

# 35. POST `/matches/{id}/reject`

Rejects a `SUGGESTED`/`NOTIFIED` match.

### Request (optional)

```json
{ "reason": "Unable to respond right now." }
```

### Response

```json
{
  "success": true,
  "message": "Match rejected.",
  "data": {
    "match": { "id": 5001, "status": "REJECTED" },
    "request": null
  }
}
```

Records `MATCH_REJECTED` timeline event + `REJECT_MATCH` audit row.

---

# 35b. POST `/matches/{id}/complete`

Marks an `ACCEPTED` match completed and resolves the request when appropriate.

### Transaction (implemented)

```text
1. Load match; require status ACCEPTED
2. Require request status ACCEPTED or IN_PROGRESS
3. Transaction:
   a. Set match → COMPLETED
   b. If request was ACCEPTED: ACCEPTED → IN_PROGRESS → RESOLVED (events)
      If request was IN_PROGRESS: → RESOLVED (event)
   c. Record MATCH_COMPLETED timeline event
   d. Set volunteer availability → AVAILABLE
   e. Audit COMPLETE_MATCH
4. Return match + request summary
```

### Response

```json
{
  "success": true,
  "message": "Match completed.",
  "data": {
    "match": { "id": 5001, "status": "COMPLETED" },
    "request": { "id": 1024, "status": "RESOLVED" }
  }
}
```

### Conflicts

| Code | HTTP | When |
|------|------|------|
| `INVALID_REQUEST_STATUS` | 409 | Match not ACCEPTED, or request not ACCEPTED/IN_PROGRESS |
| `MATCH_NOT_FOUND` | 404 | Unknown match |
| `FORBIDDEN` | 403 | Caller is not the assigned provider / owner / admin |

---

# 36. Organization API

---

# 37. GET `/organizations/profile`

Implemented: `GET /api/v1/organizations/profile` (ORGANIZATION role).

Returns current organization profile.

### Access

Organization.

### Response

```json
{
  "success": true,
  "message": "Organization profile retrieved.",
  "data": {
    "id": 3,
    "name": "Community Support Center",
    "type": "NGO",
    "verification_status": "VERIFIED"
  }
}
```

---

# 38. PATCH `/organizations/profile`

Implemented: `PATCH /api/v1/organizations/profile` (ORGANIZATION role, CSRF).
Partial-update semantics; `verification_status` is never client-writable.

Updates organization information.

### Request

```json
{
  "name": "Community Support Center",
  "type": "NGO",
  "contact": "9999999999",
  "description": "Local emergency support group"
}
```

---

# 39. GET `/resources`

Returns resources visible to the current user.

### Query Parameters

```text
resource_type
status
organization_id
page
limit
```

### Response

```json
{
  "success": true,
  "message": "Resources retrieved.",
  "data": {
    "items": [
      {
        "id": 101,
        "resource_type": "FOOD",
        "resource_name": "Food Packets",
        "quantity": 500,
        "available_quantity": 320,
        "status": "AVAILABLE"
      }
    ],
    "pagination": {
      "page": 1,
      "limit": 20,
      "total": 1,
      "pages": 1
    }
  }
}
```

---

# 40. POST `/resources`

Creates a resource.

Implemented: `POST /api/v1/resources` (ORGANIZATION role, CSRF).

### Access

Verified organization.

### Request

```json
{
  "resource_type": "FOOD",
  "resource_name": "Food Packets",
  "quantity": 500,
  "available_quantity": 500,
  "unit": "packets",
  "latitude": 20.3893,
  "longitude": 72.9106
}
```

### Validation

```text
quantity >= 0
available_quantity >= 0
available_quantity <= quantity
```

---

# 41. PATCH `/resources/{id}`

Implemented: `PATCH /api/v1/resources/{id}` (authenticated, CSRF;
owner-or-admin enforced in the service).

Updates resource details.

### Request

```json
{
  "available_quantity": 320,
  "status": "AVAILABLE"
}
```

Only the owning organization or authorized admin can modify it.

### Visibility

- Admin: whole inventory.
- Organization: its own inventory (all statuses); requesting another
  organization's `organization_id` is rejected (`FORBIDDEN`).
- Citizens/volunteers: inventory of VERIFIED organizations only, never
  UNAVAILABLE rows, without private coordinates.

Pagination: `?page=1&limit=20` (max 100) with the standard pagination object.

---

# 42. POST `/resources/{id}/allocate`

Allocates resource quantity to a request.

Implemented: `POST /api/v1/resources/{id}/allocate` (authenticated, CSRF).
Ownership verified in `ResourceService` (owning organization must be VERIFIED;
admin bypass allowed). Allocation only targets active requests
(`RESOLVED`/`CANCELLED` rejected with `INVALID_REQUEST_STATUS`).

### Request

```json
{
  "request_id": 1024,
  "quantity": 50
}
```

### Transaction

```text
Check quantity
      ↓
Reserve quantity
      ↓
Decrease available quantity
      ↓
Update resource status
      ↓
Create audit/event
      ↓
Commit
```

### Error

```text
INSUFFICIENT_RESOURCE
```

---

# 43. Notification API

---

# 44. GET `/notifications`

Returns notifications for current user.

**Implemented:** Phase 8 — `NotificationApiController` (`app/controllers/NotificationApiController.php`).
Web centre: `GET /notifications` (`NotificationController`). Badge polling: `GET /api/v1/notifications`.

### Query Parameters

```text
unread
page
limit
```

### Response

```json
{
  "success": true,
  "message": "Notifications retrieved.",
  "data": {
    "items": [
      {
        "id": 10,
        "type": "MATCH_FOUND",
        "title": "Emergency match found",
        "message": "A nearby emergency request matches your capabilities.",
        "related_entity_type": "emergency_request",
        "related_entity_id": 42,
        "read_status": false,
        "created_at": "2026-10-02T12:15:00Z"
      }
    ],
    "meta": {
      "page": 1,
      "limit": 20,
      "total": 1,
      "pages": 1,
      "unread_count": 1,
      "unread_only": false
    }
  }
}
```

### Event types

`REQUEST_CREATED` · `MATCH_FOUND` · `MATCH_ACCEPTED` · `STATUS_CHANGED` ·
`RESOURCE_ALLOCATED` · `REQUEST_RESOLVED` · `ESCALATED`

Delivery is best-effort (never rolls back the primary domain action). Recipients are
resolved server-side from the domain graph — clients cannot choose who gets notified.
Actors are never self-notified.

---

# 45. POST `/notifications/{id}/read`

Marks notification as read (owner only). Foreign / unknown IDs return
`NOTIFICATION_NOT_FOUND` without leaking existence.

**CSRF required** (`X-CSRF-Token` header or `_token` body field).

### Response

```json
{
  "success": true,
  "message": "Notification marked as read.",
  "data": {
    "id": 10,
    "read_status": true
  }
}
```

---

# 46. POST `/notifications/read-all`

Marks all current user's notifications as read.

**CSRF required.**

### Response

```json
{
  "success": true,
  "message": "Notifications marked as read.",
  "data": null
}
```

---

# 47. Map markers API

---

# 48. GET `/map/markers`

Role-filtered operational map markers with precision metadata (Phase 8 Leaflet polling).

**Implemented:** Phase 8 — `MapApiController` / `LocationService` (`app/controllers/MapApiController.php`).
Web map: `GET /map` (`MapController`).

### Privacy (ARCHITECTURE §25 / MASTER_PROMPT §20)

| Role | Emergency markers | Volunteer homes | Org / resource depots |
|------|-------------------|-----------------|------------------------|
| ADMIN | All active, exact | Yes, exact | Exact |
| CITIZEN | Own requests only, exact | No | Approximate |
| VOLUNTEER | Accepted/completed exact; open eligible approximate | No (admin-only) | Approximate |
| ORGANIZATION | Accepted resource-match requests | No | Own exact; others approximate |

Approximate markers are rounded to 2 decimal places (~1 km).

### Response

```json
{
  "success": true,
  "message": "Map markers retrieved.",
  "data": {
    "items": [
      {
        "id": "emergency-42",
        "type": "EMERGENCY",
        "label": "MEDICAL emergency #42",
        "description": "Injured worker near the depot.",
        "latitude": 20.388213,
        "longitude": 72.909145,
        "precision": "exact",
        "priority": "HIGH",
        "status": "ACCEPTED",
        "icon": "!",
        "color": "red",
        "href": "/requests/42"
      }
    ],
    "meta": {
      "role": "ADMIN",
      "counts": { "EMERGENCY": 3, "VOLUNTEER": 6, "RESOURCE": 2, "ORGANIZATION": 2 },
      "total": 13,
      "provider": "openstreetmap",
      "poll_interval_seconds": 12,
      "precision_note": "Approximate markers are rounded to ~1 km for privacy."
    }
  }
}
```

`MAP_POLL_INTERVAL_SECONDS` (default 12) controls client polling (10–15 s recommended).

---

# 49. AI API

AI endpoints should be treated as internal application services.

They do not need to expose the provider API key to the browser.

---

# 50. POST `/ai/classify-emergency`

### Access

Authenticated.

### Request

```json
{
  "description": "Three people are injured after an accident and need medical help."
}
```

### Response

```json
{
  "success": true,
  "message": "Emergency classified.",
  "data": {
    "category": "ACCIDENT",
    "indicators": [
      "injured people",
      "medical assistance required"
    ],
    "suggested_priority": "HIGH",
    "confidence": 0.86
  }
}
```

### Important

The frontend must treat:

```text
suggested_priority
```

as an AI suggestion, not an authoritative medical/emergency decision.

---

# 51. POST `/ai/summarize-request`

### Request

```json
{
  "description": "Long emergency description..."
}
```

### Response

```json
{
  "success": true,
  "message": "Request summarized.",
  "data": {
    "summary": "Three people require urgent assistance after an accident."
  }
}
```

---

# 52. POST `/ai/extract-request`

### Request

```json
{
  "description": "We are five people stuck near the flooded road and need food and water."
}
```

### Response

```json
{
  "success": true,
  "message": "Request data extracted.",
  "data": {
    "people_affected": 5,
    "possible_category": "FLOOD",
    "needs": [
      "FOOD",
      "WATER"
    ]
  }
}
```

All extracted values must be editable/confirmable before being treated as user-submitted facts.

---

# 53. Map API (legacy sketch — superseded)

> **Implemented (Phase 8):** use `GET /api/v1/map/markers` (§48) for the operational
> map feed. Exact locations stay permission-controlled via `LocationService`.

The frontend can use Leaflet directly for map rendering.

The backend provides location data through normal application endpoints.

Example (superseded sketch):

```text
GET /requests?map=1
```

Response shape is now standardized in §48 (`items[]` + `meta` with precision,
counts and poll interval).

Exact locations must be permission-controlled.

---

# 54. Admin API

Admin APIs are strictly protected.

**Implemented (Phase 9).** Endpoints live under the `/api/v1` prefix and every
route is guarded by `RoleMiddleware(['ADMIN'])`; `AdminApiController` also
re-checks the role server-side and returns `UNAUTHORIZED` (401) for guests and
`FORBIDDEN` (403) for non-admins. Hidden UI is never the only protection.

| Method | Path | Access | Purpose |
|--------|------|--------|---------|
| GET | `/api/v1/admin/dashboard` | Admin | KPI + status/priority/category summary |
| GET | `/api/v1/admin/requests` | Admin | Filtered + paginated operational queue |
| GET | `/api/v1/admin/resources` | Admin | Resource overview + shortages |
| GET | `/api/v1/admin/audit-logs` | Admin | Paginated audit trail |
| GET | `/api/v1/admin/activity` | Admin | Recent request timeline events |

---

# 55. GET `/admin/dashboard`

Returns dashboard summary.

**Implemented:** `GET /api/v1/admin/dashboard` (admin). In addition to the five
metrics below, the response includes `high_priority`, `in_progress`,
`total_requests`, `pending_volunteers`, `verified_organizations`,
`pending_organizations` and `active_matches`, plus `by_status`, `by_priority`,
`by_category` and platform `resources` totals.

### Response

```json
{
  "success": true,
  "message": "Dashboard data retrieved.",
  "data": {
    "active_emergencies": 12,
    "critical_requests": 3,
    "available_volunteers": 46,
    "available_resources": 18,
    "resolved_requests": 94
  }
}
```

---

# 56. GET `/admin/requests`

Returns all operational requests.

**Implemented:** `GET /api/v1/admin/requests` (admin). Query parameters are
validated against their enum vocabulary; unknown values are ignored rather than
erroring. `search` matches the description, address or numeric request id.

### Query Parameters

```text
status
priority
category
date_from
date_to
search
page
limit
```

Admin may filter and sort requests. Results are ordered by operational urgency
(escalated/in-progress first, then priority, then newest).

---

# 57. GET `/admin/resources`

Returns organization/resource overview.

**Implemented:** `GET /api/v1/admin/resources` (admin). Returns `summary`
(per resource type: total + available), `stats` (platform totals and low-stock
count) and `shortages` (limited, unavailable or ≤ 20% availability).

Example:

```json
{
  "success": true,
  "message": "Resource overview retrieved.",
  "data": {
    "food": {
      "total": 2340,
      "available": 1800
    },
    "water": {
      "total": 5000,
      "available": 4200
    },
    "shelter_spaces": {
      "total": 100,
      "available": 33
    }
  }
}
```

---

# 58. GET `/admin/analytics`

**Implemented (Phase 11):** `GET /api/v1/admin/analytics` (admin) — see also the
`/admin/analytics` web dashboard.

Returns period-scoped operational analytics. The period is validated against a
whitelist; an unknown value degrades to the default (`week`) instead of erroring.
Read-only: it never mutates state and writes no audit rows.

### Query Parameters

```text
period
```

Allowed:

```text
day     last 24 hours, hourly buckets (24)
week    last 7 days, daily buckets (7)   ← default
month   last 30 days, daily buckets (30)
year    last 12 months, monthly buckets (12)
```

### Response

```json
{
  "success": true,
  "message": "Analytics retrieved.",
  "data": {
    "period": "week",
    "window": {
      "from": "2026-10-03 00:00:00",
      "to": "2026-10-09 15:57:27",
      "from_label": "03 Oct 2026, 00:00"
    },
    "requests_by_category": { "MEDICAL": 2, "FLOOD": 1 },
    "requests_by_priority": { "CRITICAL": 1, "HIGH": 2 },
    "requests_by_status": { "CREATED": 1, "RESOLVED": 1 },
    "requests_over_time": {
      "labels": ["Mon", "Tue", "Wed"],
      "values": [0, 1, 1],
      "total": 2
    },
    "requests_total": 2,
    "resolution": {
      "resolved": 4,
      "tracked": 4,
      "average_hours": 2.0,
      "fastest_hours": 0.5,
      "slowest_hours": 6.0
    },
    "resources": {
      "stats": { "total_resources": 8, "total_quantity": 3000, "available_quantity": 2600, "available": 6, "limited": 1, "unavailable": 1, "low_stock": 2 },
      "by_type": { "FOOD": { "total": 200, "available": 50 } },
      "allocated": 400,
      "availability": { "labels": ["FOOD"], "available": [50], "total": [200] }
    },
    "volunteers": { "available": 7, "pending": 1, "accepted": 3, "completed": 1, "rejected": 0 }
  }
}
```

Every request breakdown is scoped to the same window as `requests_over_time`,
so `requests_by_status` sums to `requests_total` and the series sums to
`requests_over_time.total`. Admins only — non-admins get 401 (guest) / 403
(authenticated non-admin) from the same server-side guard used across
`/api/v1/admin/*`.

---

# 59. GET `/admin/audit-logs`

Returns audit events.

**Implemented:** `GET /api/v1/admin/audit-logs` (admin), paginated.

### Query Parameters

```text
action
entity_type
page
limit
```

Only admins can access this endpoint. Every audited admin action (verify /
escalate / status change / volunteer + organization verification) is recorded
with its actor, entity and safe metadata.

---

# 60. Admin Organization Verification

## POST `/admin/organizations/{id}/verify`

Implemented: `POST /api/v1/admin/organizations/{id}/verify` (admin, CSRF).
`verification_status` defaults to `VERIFIED`; `REJECTED` also accepted.

### Response

```json
{
  "success": true,
  "message": "Organization verified.",
  "data": {
    "organization_id": 3,
    "verification_status": "VERIFIED"
  }
}
```

## POST `/admin/organizations/{id}/reject`

Implemented: `POST /api/v1/admin/organizations/{id}/reject` (admin, CSRF).
`reason` is stored in the `REJECT_ORGANIZATION` audit metadata.

Request:

```json
{
  "reason": "Verification information incomplete."
}
```

---

# 61. Admin Volunteer Verification

## POST `/admin/volunteers/{id}/verify`

Implementation path: `/api/v1/admin/volunteers/{id}/verify`.

### Access

Admin. CSRF required.

### Request

```json
{
  "verification_status": "VERIFIED"
}
```

`verification_status` may be `VERIFIED` (default) or `REJECTED`.

### Response

```json
{
  "success": true,
  "message": "Volunteer verified.",
  "data": {
    "volunteer_id": 22,
    "verification_status": "VERIFIED"
  }
}
```

## GET `/admin/volunteers`

Admin list of volunteer profiles. Optional query `verification_status=PENDING|VERIFIED|REJECTED`.
Never returns phone or email.

---

# 60. Role Permission Matrix

| Endpoint Area | Citizen | Volunteer | Organization | Admin |
|---|---:|---:|---:|---:|
| Register/Login | ✓ | ✓ | ✓ | — |
| Own Profile | ✓ | ✓ | ✓ | ✓ |
| Create Request | ✓ | Optional | Optional | ✓ |
| View Own Requests | ✓ | — | — | ✓ |
| View Eligible Requests | — | ✓ | ✓ | ✓ |
| Accept Match | — | ✓ | ✓ | ✓ |
| Manage Volunteer Profile | — | ✓ | — | ✓ |
| Manage Organization | — | — | ✓ | ✓ |
| Manage Resources | — | — | ✓ | ✓ |
| Verify Users | — | — | — | ✓ |
| Admin Dashboard | — | — | — | ✓ |
| Analytics | Limited | Limited | Limited | ✓ |
| Audit Logs | — | — | — | ✓ |

---

# 61. Validation Rules

## Authentication

```text
name: required
email: valid + unique
password: minimum 8 characters
role: allowed role only
```

## Emergency

```text
category: required
description: required
people_affected: integer >= 1
latitude: -90 to 90
longitude: -180 to 180
```

## Volunteer

```text
service_radius_km > 0
availability = valid enum
```

## Resource

```text
quantity >= 0
available_quantity >= 0
available_quantity <= quantity
```

---

# 62. Error Code Catalog

Use consistent machine-readable codes.

```text
VALIDATION_ERROR
UNAUTHORIZED
FORBIDDEN
NOT_FOUND
INVALID_CREDENTIALS
ACCOUNT_SUSPENDED
EMAIL_ALREADY_EXISTS

REQUEST_NOT_FOUND
INVALID_REQUEST_STATUS
REQUEST_ALREADY_ACCEPTED
REQUEST_ALREADY_RESOLVED
REQUEST_CANCELLED

MATCH_NOT_FOUND
MATCH_NOT_ELIGIBLE
MATCH_ALREADY_ACCEPTED

RESOURCE_NOT_FOUND
INSUFFICIENT_RESOURCE
RESOURCE_NOT_AVAILABLE

VOLUNTEER_NOT_FOUND
VOLUNTEER_UNAVAILABLE

ORGANIZATION_NOT_FOUND
ORGANIZATION_NOT_VERIFIED

AI_SERVICE_UNAVAILABLE
MAP_SERVICE_UNAVAILABLE
NOTIFICATION_FAILED

RATE_LIMIT_EXCEEDED
CSRF_MISMATCH
INTERNAL_ERROR
```

---

# 63. Pagination Standard

Collection endpoints use:

```text
?page=1&limit=20
```

Response:

```json
{
  "pagination": {
    "page": 1,
    "limit": 20,
    "total": 100,
    "pages": 5
  }
}
```

Recommended maximum:

```text
limit <= 100
```

---

# 64. Filtering Standard

Use query parameters.

Example:

```text
GET /requests?
status=MATCHING
&priority=CRITICAL
&category=MEDICAL
&page=1
&limit=20
```

Never accept arbitrary SQL expressions through query parameters.

---

# 65. Sorting Standard

Allowed fields should be whitelisted.

Example:

```text
GET /requests?sort=created_at&direction=desc
```

Allowed sort fields:

```text
created_at
priority
status
category
```

The backend must reject arbitrary SQL column names.

---

# 66. Rate Limiting

Recommended API limits for the MVP:

```text
Login:
10 attempts / 15 minutes / IP

Registration:
10 / hour / IP

AI endpoints:
20 / hour / user

General API:
300 / minute / authenticated user
```

Exact limits can be adjusted according to hosting constraints.

---

# 67. CSRF Protection

Because the MVP uses cookie-based authentication:

All state-changing browser requests should include a CSRF token.

Example:

```http
X-CSRF-Token: <token>
```

The server validates the token before:

```text
POST
PATCH
DELETE
```

operations.

---

# 68. CORS

For same-origin deployment:

```text
CORS is not required.
```

If frontend and backend are deployed separately:

```text
Allow only the known frontend origin.
```

Never use:

```text
Access-Control-Allow-Origin: *
```

for authenticated production endpoints.

---

# 69. File Upload API Security

Allowed example types:

```text
image/jpeg
image/png
image/webp
```

Recommended demo limit:

```text
5 MB per file
```

Server must verify:

```text
MIME type
File extension
File size
Actual file contents
```

Never trust the filename supplied by the client.

---

# 70. AI Integration Contract

The application should not depend directly on an AI SDK inside controllers.

Use:

```text
AIService
```

Example interface:

```php
interface AIServiceInterface
{
    public function classifyEmergency(string $description): array;

    public function summarizeRequest(string $description): string;

    public function extractRequestData(string $description): array;
}
```

Implementation:

```text
AIService
   ↓
Provider Adapter
   ↓
AI API
   ↓
Validation (allow-lists, clamped confidence)
   ↓
Result or deterministic fallback
```

This allows the AI provider to be changed later.

**Implemented (Phase 10):** `App\Services\AIService` is the single gateway.
It exposes `classifyEmergency()`, `summarizeRequest()`, `extractRequestData()`
and `normalizeRequest()`. Configuration comes from `AI_API_KEY`, `AI_API_URL`,
`AI_MODEL` and `AI_TIMEOUT_SECONDS` (see `.env.example`).

| Method | Path | Access | Purpose |
|--------|------|--------|---------|
| POST | `/api/v1/ai/classify` | Authenticated + CSRF | Category / indicators / advisory priority |
| POST | `/api/v1/ai/summarize` | Authenticated + CSRF | Short neutral summary |
| POST | `/api/v1/ai/extract` | Authenticated + CSRF | Structured fact extraction |
| POST | `/api/v1/ai/normalize` | Authenticated + CSRF | Tidied description draft |
| POST | `/requests/ai-assist` | Authenticated + CSRF | Browser equivalent (`feature` selects the above) |

### Request

```json
{ "description": "Two elderly residents are trapped on the second floor…" }
```

`description` must be 10–5000 characters (422 `VALIDATION_ERROR` otherwise).

### Response (always HTTP 200 on success, even when AI is disabled)

```json
{
  "success": true,
  "message": "Fallback suggestion ready.",
  "data": {
    "feature": "classify",
    "available": false,
    "source": "fallback",
    "result": {
      "source": "fallback",
      "category": "FIRE",
      "indicators": ["fire", "urgency"],
      "suggested_priority": "CRITICAL",
      "confidence": null,
      "summary": "…"
    },
    "disclaimer": "AI suggestion only — review before relying on it. …"
  }
}
```

- `available` — whether a provider is configured.
- `source` — `ai` (model output validated against allow-lists) or `fallback`
  (deterministic keyword logic). Clients must render them differently.
- `confidence` — 0..1 or `null` (fallback). Advisory only, never authoritative.
- `disclaimer` — required client copy: AI output is a suggestion, not a
  system or human decision.

### Guarantees

- AI output is **never** written to `emergency_requests`; the stored category
  is the one the user selects.
- `PriorityService` remains the sole priority authority.
- Missing key, timeout, provider outage, non-2xx, malformed JSON and
  out-of-vocabulary fields all degrade to `source: "fallback"` with HTTP 200.
- Prompts are minimised: emails/phone numbers are stripped and text is bounded
  (500 chars). Coordinates and account data are never sent.
- Logs record outcome + latency only — never the API key or the prompt.

---

# 71. External API Failure Contract

If an optional external service fails:

```json
{
  "success": true,
  "message": "Request created. Some optional enrichment is temporarily unavailable.",
  "data": {
    "request": {}
  }
}
```

The core request must remain usable.

**Implemented (Phase 10):** AI endpoints follow this contract — an AI failure
returns HTTP 200 with `source: "fallback"`, never a 5xx, so the form and the
emergency workflow stay usable.

---

# 72. API Security Checklist

Before deployment:

```text
☐ HTTPS enabled
☐ Secure cookies
☐ HttpOnly cookies
☐ SameSite configured
☐ CSRF protection
☐ Prepared SQL statements
☐ Input validation
☐ Output escaping
☐ File upload restrictions
☐ Role authorization
☐ Rate limiting
☐ Error information sanitized
☐ Secrets stored in environment variables
☐ Audit logging enabled
```

---

# 73. API Development Order

Implement APIs in this order:

```text
PHASE 1
Authentication
    ↓
PHASE 2
Current user / profile
    ↓
PHASE 3
Emergency requests
    ↓
PHASE 4
Request events
    ↓
PHASE 5
Volunteer profile
    ↓
PHASE 6
Organization + resources
    ↓
PHASE 7
Matching
    ↓
PHASE 8
Notifications
    ↓
PHASE 9
Admin dashboard
    ↓
PHASE 10
AI services
    ↓
PHASE 11
Analytics
    ↓
PHASE 12
Security + rate limits + testing
```

---

# 74. Complete API Map

```text
/api/v1

├── /auth
│   ├── POST /register
│   ├── POST /login
│   ├── POST /logout
│   └── GET  /me
│
├── /requests
│   ├── GET  /
│   ├── POST /
│   ├── GET  /{id}
│   ├── PATCH /{id}
│   ├── POST /{id}/cancel
│   ├── POST /{id}/verify
│   ├── POST /{id}/escalate
│   ├── POST /{id}/status
│   ├── GET  /{id}/events
│   ├── GET  /{id}/matches          (Phase 7 — stored matches)
│   ├── POST /{id}/matches/suggest  (Phase 7 — generate + persist suggestions)
│   ├── GET  /{id}/attachments
│   └── POST /{id}/attachments
│
├── /volunteers
│   ├── GET   /profile
│   ├── PATCH /profile
│   ├── POST  /availability
│   ├── GET   /requests
│   └── GET   /{id}                 (admin — restricted operational read)
│
├── /matches                        (Phase 7 — matching engine)
│   ├── GET   /
│   ├── GET   /{id}
│   ├── POST  /{id}/accept
│   ├── POST  /{id}/reject
│   └── POST  /{id}/complete
│
├── /organizations
│   ├── GET   /profile
│   └── PATCH /profile
│
├── /resources
│   ├── GET   /
│   ├── POST  /
│   ├── PATCH /{id}
│   └── POST  /{id}/allocate
│
├── /notifications              (Phase 8)
│   ├── GET  /
│   ├── POST /{id}/read
│   └── POST /read-all
│
├── /map
│   └── GET  /markers           (Phase 8 — role-aware markers + precision)
│
├── /ai
│   ├── POST /classify-emergency
│   ├── POST /summarize-request
│   └── POST /extract-request
│
└── /admin
    ├── GET  /dashboard
    ├── GET  /requests
    ├── GET  /resources
    ├── GET  /volunteers
    ├── GET  /organizations
    ├── GET  /analytics
    ├── GET  /audit-logs
    ├── POST /organizations/{id}/verify
    ├── POST /organizations/{id}/reject
    └── POST /volunteers/{id}/verify
```

---

# 75. Frontend Integration Pattern

The frontend should use a centralized API client.

Example:

```javascript
async function apiRequest(url, options = {}) {
    const response = await fetch(`/api/v1${url}`, {
        credentials: "same-origin",
        headers: {
            "Accept": "application/json",
            "Content-Type": "application/json",
            ...options.headers
        },
        ...options
    });

    const data = await response.json();

    if (!response.ok || !data.success) {
        throw new Error(data.message || "API request failed");
    }

    return data;
}
```

Usage:

```javascript
const result = await apiRequest("/requests", {
    method: "POST",
    body: JSON.stringify({
        category: "MEDICAL",
        description: "Emergency assistance required",
        people_affected: 2
    })
});
```

The actual implementation should also handle CSRF tokens where required.

---

# 76. API Contract Rule

The API contract is the agreement between frontend and backend.

Frontend developers should not assume fields that are not documented.

Backend developers should not silently rename response fields.

If a contract changes:

```text
Update API.md
      ↓
Update backend
      ↓
Update frontend
      ↓
Run integration tests
```

---

# 77. Definition of API Complete

The API layer is considered complete when:

```text
✓ Authentication works
✓ Role authorization works
✓ Emergency requests work
✓ Request lifecycle works
✓ Volunteer flow works
✓ Organization/resource flow works
✓ Matching works
✓ Notifications work
✓ Admin endpoints work
✓ AI endpoints fail gracefully
✓ Validation is consistent
✓ Error codes are consistent
✓ Pagination works
✓ CSRF protection works
✓ Rate limiting is configured
✓ API tests pass
```

---

# 78. Final API Flow

The most important end-to-end flow is:

```text
CITIZEN
   │
   │ POST /requests
   ▼
CREATE REQUEST
   │
   ▼
PRIORITY SERVICE
   │
   ▼
MATCHING SERVICE
   │
   ├───────────────┐
   ▼               ▼
VOLUNTEER       RESOURCE
   │               │
   └───────┬───────┘
           ▼
POST /matches/{id}/accept
           │
           ▼
REQUEST = ACCEPTED
           │
           ▼
POST /requests/{id}/status
           │
           ▼
IN_PROGRESS
           │
           ▼
RESOLVED
           │
           ▼
NOTIFICATION + AUDIT + ANALYTICS
```

---

# 79. Final Principle

> **The API should make the ResQLink workflow predictable: every request has a clear owner, every action has a permission check, every state change is recorded, and optional AI/external services can fail without breaking the core emergency-coordination workflow.**

---

## End of API Specification
