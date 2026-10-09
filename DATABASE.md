# ResQLink — Database Design

**Version:** 1.0  
**Project:** ResQLink — Emergency Resource & Volunteer Coordination Platform  
**Database:** MySQL 8+  
**Engine:** InnoDB  
**Character Set:** utf8mb4  
**Collation:** utf8mb4_unicode_ci  
**Backend:** PHP + PDO

---

# 1. Database Goals

The ResQLink database must:

- Store users and role-specific profiles.
- Store emergency requests and their lifecycle.
- Store volunteer capabilities and availability.
- Store organizations and their resources.
- Support location-based matching.
- Track request/resource assignments.
- Store notifications.
- Maintain request history and audit logs.
- Support dashboard analytics.
- Preserve data integrity through foreign keys and constraints.
- Remain simple enough for a TYBCA hackathon MVP.

The database should prioritize **clarity, integrity, and maintainability** over premature optimization.

---

# 2. Database Architecture

```text
                         ┌─────────────────┐
                         │      USERS      │
                         └────────┬────────┘
                                  │
              ┌───────────────────┼───────────────────┐
              │                   │                   │
              ▼                   ▼                   ▼
      ┌──────────────┐    ┌────────────────┐   ┌──────────────┐
      │ VOLUNTEERS   │    │   ORGANIZATION │   │ NOTIFICATIONS│
      └──────┬───────┘    └───────┬────────┘   └──────────────┘
             │                     │
             │                     ▼
             │              ┌──────────────┐
             │              │  RESOURCES   │
             │              └──────┬───────┘
             │                     │
             └──────────┐   ┌──────┘
                        ▼   ▼
                 ┌───────────────┐
                 │    MATCHES    │
                 └───────┬───────┘
                         │
                         ▼
                 ┌──────────────────┐
                 │ EMERGENCY        │
                 │ REQUESTS         │
                 └────────┬─────────┘
                          │
                    ┌─────┴─────┐
                    ▼           ▼
             REQUEST_EVENTS   AUDIT_LOGS
```

---

# 3. Database Naming Conventions

Use the following conventions consistently.

### Tables

Use lowercase snake_case:

```text
users
emergency_requests
volunteer_profiles
```

### Columns

Use lowercase snake_case:

```text
created_at
service_radius_km
available_quantity
```

### Primary Keys

```text
id
```

### Foreign Keys

```text
user_id
request_id
organization_id
```

### Timestamps

Use:

```text
created_at
updated_at
```

Use UTC at the database/application layer where practical, then convert to the user's local timezone for display.

---

# 4. Entity Relationship Diagram

```text
USERS
  │
  ├───────────────┐
  │               │
  │ 1:1           │ 1:1
  ▼               ▼
VOLUNTEER_     ORGANIZATIONS
PROFILES           │
  │                │ 1:N
  │                ▼
  │            RESOURCES
  │                │
  │                │
  └───────┐   ┌────┘
          │   │
          ▼   ▼
          MATCHES
             │
             │ N:1
             ▼
     EMERGENCY_REQUESTS
             │
       ┌─────┴─────┐
       │           │
       ▼           ▼
REQUEST_EVENTS  ATTACHMENTS
       │
       ▼
   AUDIT_LOGS

USERS ──────────── N:1 / 1:N ─────────── EMERGENCY_REQUESTS
USERS ──────────── 1:N ───────────────── NOTIFICATIONS
USERS ──────────── 1:N ───────────────── AUDIT_LOGS
```

---

# 5. Core Tables

The MVP uses these core tables:

```text
1. users
2. emergency_requests
3. volunteer_profiles
4. organizations
5. resources
6. matches
7. notifications
8. request_events
9. audit_logs
10. emergency_attachments
```

Optional future tables:

```text
11. organization_members
12. resource_transactions
13. refresh_tokens
14. saved_locations
15. volunteer_skills
16. resource_types
```

---

# 6. Table: users

Stores the common identity/account record.

### Purpose

All users exist in this table regardless of role.

### Schema

```sql
CREATE TABLE users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    name VARCHAR(120) NOT NULL,
    email VARCHAR(190) NOT NULL UNIQUE,
    phone VARCHAR(20) NULL,

    password_hash VARCHAR(255) NOT NULL,

    role ENUM(
        'CITIZEN',
        'VOLUNTEER',
        'ORGANIZATION',
        'ADMIN'
    ) NOT NULL DEFAULT 'CITIZEN',

    status ENUM(
        'ACTIVE',
        'INACTIVE',
        'SUSPENDED'
    ) NOT NULL DEFAULT 'ACTIVE',

    email_verified_at DATETIME NULL,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_users_role (role),
    INDEX idx_users_status (status)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;
```

### Rules

- Email must be unique.
- Password must never be stored in plaintext.
- `role` controls access.
- Suspended users cannot perform protected actions.

---

# 7. Table: emergency_requests

This is the primary business table.

### Purpose

Stores every emergency/help request created by a citizen or authorized operator.

### Schema

```sql
CREATE TABLE emergency_requests (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    user_id BIGINT UNSIGNED NOT NULL,

    category ENUM(
        'MEDICAL',
        'ACCIDENT',
        'FLOOD',
        'FIRE',
        'FOOD_WATER',
        'SHELTER',
        'TRANSPORT',
        'MISSING_STRANDED',
        'OTHER'
    ) NOT NULL,

    description TEXT NOT NULL,

    people_affected INT UNSIGNED NOT NULL DEFAULT 1,

    priority ENUM(
        'LOW',
        'MEDIUM',
        'HIGH',
        'CRITICAL'
    ) NOT NULL DEFAULT 'MEDIUM',

    priority_score INT UNSIGNED NOT NULL DEFAULT 0,

    priority_reason TEXT NULL,

    status ENUM(
        'CREATED',
        'VERIFIED',
        'MATCHING',
        'ACCEPTED',
        'IN_PROGRESS',
        'RESOLVED',
        'ESCALATED',
        'CANCELLED'
    ) NOT NULL DEFAULT 'CREATED',

    latitude DECIMAL(10,8) NULL,
    longitude DECIMAL(11,8) NULL,

    location_accuracy_m DECIMAL(10,2) NULL,

    address_text VARCHAR(500) NULL,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    resolved_at DATETIME NULL,

    CONSTRAINT fk_requests_user
        FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON DELETE RESTRICT
        ON UPDATE CASCADE,

    INDEX idx_requests_user (user_id),
    INDEX idx_requests_status (status),
    INDEX idx_requests_priority (priority),
    INDEX idx_requests_category (category),
    INDEX idx_requests_created (created_at),
    INDEX idx_requests_location (latitude, longitude)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;
```

### Important Rules

- `people_affected` must be greater than zero.
- `priority_score` is generated by the priority service.
- `priority_reason` explains why the priority was assigned.
- Exact private coordinates must not be publicly exposed.
- `resolved_at` is set only when status becomes `RESOLVED`.

---

# 8. Table: volunteer_profiles

Stores volunteer-specific information.

### Schema

```sql
CREATE TABLE volunteer_profiles (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    user_id BIGINT UNSIGNED NOT NULL UNIQUE,

    skills TEXT NULL,
    vehicle VARCHAR(100) NULL,
    equipment TEXT NULL,

    availability ENUM(
        'AVAILABLE',
        'BUSY',
        'OFFLINE'
    ) NOT NULL DEFAULT 'OFFLINE',

    service_radius_km DECIMAL(6,2) NOT NULL DEFAULT 10.00,

    latitude DECIMAL(10,8) NULL,
    longitude DECIMAL(11,8) NULL,

    verification_status ENUM(
        'PENDING',
        'VERIFIED',
        'REJECTED'
    ) NOT NULL DEFAULT 'PENDING',

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_volunteer_user
        FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,

    INDEX idx_volunteer_availability (availability),
    INDEX idx_volunteer_verification (verification_status),
    INDEX idx_volunteer_location (latitude, longitude)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;
```

### Example

```text
User: Rahul

Skills:
First Aid, Driving

Vehicle:
Car

Availability:
AVAILABLE

Radius:
10 km
```

---

# 9. Table: organizations

Stores NGO, shelter, hospital, food provider, or community organization records.

### Schema

```sql
CREATE TABLE organizations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    user_id BIGINT UNSIGNED NOT NULL UNIQUE,

    name VARCHAR(180) NOT NULL,

    type ENUM(
        'NGO',
        'HOSPITAL',
        'SHELTER',
        'FOOD_PROVIDER',
        'COMMUNITY_GROUP',
        'OTHER'
    ) NOT NULL,

    contact VARCHAR(100) NULL,

    description TEXT NULL,

    verification_status ENUM(
        'PENDING',
        'VERIFIED',
        'REJECTED'
    ) NOT NULL DEFAULT 'PENDING',

    latitude DECIMAL(10,8) NULL,
    longitude DECIMAL(11,8) NULL,

    address_text VARCHAR(500) NULL,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_organization_user
        FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,

    INDEX idx_org_type (type),
    INDEX idx_org_verification (verification_status),
    INDEX idx_org_location (latitude, longitude)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;
```

---

# 10. Table: resources

Stores resources provided by organizations.

### Resource Examples

```text
Food
Water
Shelter Spaces
Ambulance/Transport
Blankets
Medical Support
Other
```

### Schema

```sql
CREATE TABLE resources (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    organization_id BIGINT UNSIGNED NOT NULL,

    resource_type ENUM(
        'FOOD',
        'WATER',
        'SHELTER',
        'TRANSPORT',
        'MEDICAL_SUPPORT',
        'BLANKETS',
        'OTHER'
    ) NOT NULL,

    resource_name VARCHAR(150) NOT NULL,

    quantity INT UNSIGNED NOT NULL DEFAULT 0,

    available_quantity INT UNSIGNED NOT NULL DEFAULT 0,

    unit VARCHAR(50) NULL,

    status ENUM(
        'AVAILABLE',
        'LIMITED',
        'UNAVAILABLE'
    ) NOT NULL DEFAULT 'AVAILABLE',

    latitude DECIMAL(10,8) NULL,
    longitude DECIMAL(11,8) NULL,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_resource_organization
        FOREIGN KEY (organization_id)
        REFERENCES organizations(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,

    CONSTRAINT chk_resource_available
        CHECK (available_quantity <= quantity),

    INDEX idx_resources_org (organization_id),
    INDEX idx_resources_type (resource_type),
    INDEX idx_resources_status (status),
    INDEX idx_resources_location (latitude, longitude)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;
```

---

# 11. Table: matches

Connects an emergency request with a volunteer or organization resource.

A match may point to:

```text
Volunteer
OR
Organization Resource
```

### Schema

```sql
CREATE TABLE matches (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    request_id BIGINT UNSIGNED NOT NULL,

    volunteer_id BIGINT UNSIGNED NULL,

    resource_id BIGINT UNSIGNED NULL,

    distance_km DECIMAL(8,3) NULL,

    capability_score DECIMAL(6,2) NOT NULL DEFAULT 0,

    match_score DECIMAL(8,2) NOT NULL DEFAULT 0,

    status ENUM(
        'SUGGESTED',
        'NOTIFIED',
        'ACCEPTED',
        'REJECTED',
        'EXPIRED',
        'COMPLETED'
    ) NOT NULL DEFAULT 'SUGGESTED',

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_match_request
        FOREIGN KEY (request_id)
        REFERENCES emergency_requests(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,

    CONSTRAINT fk_match_volunteer
        FOREIGN KEY (volunteer_id)
        REFERENCES volunteer_profiles(id)
        ON DELETE SET NULL
        ON UPDATE CASCADE,

    CONSTRAINT fk_match_resource
        FOREIGN KEY (resource_id)
        REFERENCES resources(id)
        ON DELETE SET NULL
        ON UPDATE CASCADE,

    INDEX idx_matches_request (request_id),
    INDEX idx_matches_volunteer (volunteer_id),
    INDEX idx_matches_resource (resource_id),
    INDEX idx_matches_status (status)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;
```

### Application Rule

A match must have at least one target:

```text
volunteer_id IS NOT NULL
OR
resource_id IS NOT NULL
```

The application service should enforce this.

---

# 12. Table: notifications

Stores in-app notifications.

### Schema

```sql
CREATE TABLE notifications (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    user_id BIGINT UNSIGNED NOT NULL,

    type VARCHAR(50) NOT NULL,

    title VARCHAR(180) NOT NULL,

    message TEXT NOT NULL,

    related_entity_type VARCHAR(50) NULL,
    related_entity_id BIGINT UNSIGNED NULL,

    read_status BOOLEAN NOT NULL DEFAULT FALSE,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_notification_user
        FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,

    INDEX idx_notifications_user (user_id),
    INDEX idx_notifications_read (read_status),
    INDEX idx_notifications_created (created_at)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;
```

---

# 13. Table: request_events

Stores the status timeline of emergency requests.

### Purpose

This table powers:

```text
Request Timeline
```

Example:

```text
10:31 Request Created
10:31 Priority Calculated
10:32 Match Found
10:33 Volunteer Accepted
10:39 Help In Progress
10:58 Resolved
```

### Schema

```sql
CREATE TABLE request_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    request_id BIGINT UNSIGNED NOT NULL,

    actor_id BIGINT UNSIGNED NULL,

    old_status VARCHAR(30) NULL,
    new_status VARCHAR(30) NULL,

    event_type VARCHAR(50) NOT NULL,

    note TEXT NULL,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_event_request
        FOREIGN KEY (request_id)
        REFERENCES emergency_requests(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,

    CONSTRAINT fk_event_actor
        FOREIGN KEY (actor_id)
        REFERENCES users(id)
        ON DELETE SET NULL
        ON UPDATE CASCADE,

    INDEX idx_events_request (request_id),
    INDEX idx_events_actor (actor_id),
    INDEX idx_events_created (created_at)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;
```

---

# 14. Table: emergency_attachments

Stores references to uploaded images/files.

### Schema

```sql
CREATE TABLE emergency_attachments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    request_id BIGINT UNSIGNED NOT NULL,

    uploaded_by BIGINT UNSIGNED NOT NULL,

    file_name VARCHAR(255) NOT NULL,
    storage_path VARCHAR(500) NOT NULL,

    mime_type VARCHAR(100) NOT NULL,
    file_size BIGINT UNSIGNED NOT NULL,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_attachment_request
        FOREIGN KEY (request_id)
        REFERENCES emergency_requests(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,

    CONSTRAINT fk_attachment_user
        FOREIGN KEY (uploaded_by)
        REFERENCES users(id)
        ON DELETE RESTRICT
        ON UPDATE CASCADE,

    INDEX idx_attachments_request (request_id)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;
```

Never store executable uploads.

---

# 15. Table: audit_logs

Stores security and administrative actions.

### Schema

```sql
CREATE TABLE audit_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    actor_id BIGINT UNSIGNED NULL,

    action VARCHAR(100) NOT NULL,

    entity_type VARCHAR(80) NULL,
    entity_id BIGINT UNSIGNED NULL,

    metadata JSON NULL,

    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(500) NULL,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_audit_actor
        FOREIGN KEY (actor_id)
        REFERENCES users(id)
        ON DELETE SET NULL
        ON UPDATE CASCADE,

    INDEX idx_audit_actor (actor_id),
    INDEX idx_audit_action (action),
    INDEX idx_audit_entity (entity_type, entity_id),
    INDEX idx_audit_created (created_at)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;
```

---

# 16. Optional Future Table: organization_members

The MVP can initially use one organization account per organization.

For future multi-user organizations:

```sql
CREATE TABLE organization_members (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    organization_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,

    role ENUM(
        'OWNER',
        'MANAGER',
        'OPERATOR'
    ) NOT NULL DEFAULT 'OPERATOR',

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uq_org_member (organization_id, user_id),

    FOREIGN KEY (organization_id)
        REFERENCES organizations(id)
        ON DELETE CASCADE,

    FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON DELETE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;
```

This is **not required for the first MVP**.

---

# 17. Status State Machines

## Emergency Request

```text
CREATED
   │
   ▼
VERIFIED
   │
   ▼
MATCHING
   │
   ├───────────────┐
   ▼               ▼
ACCEPTED        ESCALATED
   │
   ▼
IN_PROGRESS
   │
   ▼
RESOLVED
```

Cancellation:

```text
CREATED ─────────► CANCELLED
VERIFIED ────────► CANCELLED
MATCHING ────────► CANCELLED
```

Application code must prevent invalid transitions.

---

# 18. Match State Machine

```text
SUGGESTED
    ↓
NOTIFIED
    ↓
ACCEPTED
    ↓
COMPLETED
```

Alternative:

```text
SUGGESTED → REJECTED
NOTIFIED  → EXPIRED
```

---

# 19. Resource State Machine

```text
AVAILABLE
    ↓
LIMITED
    ↓
UNAVAILABLE
```

When quantity changes:

```text
available_quantity > threshold
        ↓
AVAILABLE

available_quantity > 0
        ↓
LIMITED

available_quantity = 0
        ↓
UNAVAILABLE
```

Exact thresholds can be configured at application level.

---

# 20. Foreign Key Rules

| Relationship | Delete Behavior |
|---|---|
| User → Emergency Request | RESTRICT |
| User → Volunteer Profile | CASCADE |
| User → Organization | CASCADE |
| Organization → Resource | CASCADE |
| Request → Match | CASCADE |
| Request → Request Event | CASCADE |
| Request → Attachment | CASCADE |
| User → Notification | CASCADE |
| User → Audit Log | SET NULL |

### Principle

Delete account-owned dependent data only where safe.

Historical operational records should not disappear unexpectedly.

---

# 21. Index Strategy

Indexes are required on frequently filtered columns.

## Emergency Requests

```sql
INDEX(user_id)
INDEX(status)
INDEX(priority)
INDEX(category)
INDEX(created_at)
INDEX(latitude, longitude)
```

## Volunteers

```sql
INDEX(availability)
INDEX(verification_status)
INDEX(latitude, longitude)
```

## Resources

```sql
INDEX(organization_id)
INDEX(resource_type)
INDEX(status)
INDEX(latitude, longitude)
```

## Matches

```sql
INDEX(request_id)
INDEX(volunteer_id)
INDEX(resource_id)
INDEX(status)
```

---

# 22. Location Data

For the MVP:

```text
latitude  DECIMAL(10,8)
longitude DECIMAL(11,8)
```

Example:

```text
latitude  = 20.3893
longitude = 72.9106
```

Do not expose exact private coordinates to unauthorized users.

---

# 23. Location Matching Query Strategy

For a small MVP, distance can be calculated in PHP using the Haversine formula after retrieving reasonable candidates.

For larger datasets, use a bounding-box query first.

Concept:

```text
User Location
     ↓
Bounding Box
     ↓
Potential Candidates
     ↓
Haversine Distance
     ↓
Service Radius
     ↓
Final Matches
```

This avoids calculating distance against every record.

---

# 24. Example Matching Query

Conceptual SQL:

```sql
SELECT
    vp.id,
    vp.user_id,
    vp.latitude,
    vp.longitude,
    vp.availability
FROM volunteer_profiles vp
WHERE vp.availability = 'AVAILABLE'
  AND vp.verification_status = 'VERIFIED'
  AND vp.latitude IS NOT NULL
  AND vp.longitude IS NOT NULL;
```

The service layer then checks:

```text
Skill compatibility
+
Distance
+
Service radius
```

---

# 25. Transaction Requirements

Some operations modify multiple tables and must be transactional.

## Accept Match

```text
BEGIN

1. Lock/check request
2. Confirm request is still MATCHING
3. Confirm volunteer is AVAILABLE
4. Set match = ACCEPTED
5. Set request = ACCEPTED
6. Set volunteer = BUSY
7. Create request_event
8. Create notification

COMMIT
```

If anything fails:

```text
ROLLBACK
```

This prevents two volunteers from accepting the same request.

---

# 26. Resource Allocation Transaction

Example:

```text
BEGIN

1. Check available_quantity
2. Reserve requested quantity
3. Decrease available_quantity
4. Update resource status
5. Create resource event/audit record
6. Create request event

COMMIT
```

Never allow:

```text
available_quantity < 0
```

### 26.1 Implemented: atomic resource allocation (Phase 6)

```php
Database::transaction(function () {
    ResourceRepository::allocate($resourceId, $quantity); // SELECT ... FOR UPDATE
    RequestEventRepository::record($requestId, 'RESOURCE_ALLOCATED', ...);
    AuditRepository::record($actorId, 'ALLOCATE_RESOURCE', 'resource', $resourceId, ...);
});
```

- `ResourceRepository::allocate()` locks the row (`SELECT ... FOR UPDATE`),
  verifies availability, decrements, and re-derives status — so concurrent
  allocations cannot exceed stock or take it negative.
- Nested `Database::transaction()` calls join the outer transaction instead of
  opening a second one, so the decrement, the `RESOURCE_ALLOCATED` timeline
  event, and the `ALLOCATE_RESOURCE` audit entry commit or roll back together.
- The DB-level `CHECK (available_quantity <= quantity)` guards every write.

---

# 27. Data Validation Rules

## Users

```text
name:
2–120 characters

email:
valid email format

password:
minimum 8 characters

phone:
valid expected format
```

## Emergency Requests

```text
category:
required

description:
required

people_affected:
>= 1

latitude:
-90 to 90

longitude:
-180 to 180
```

## Volunteer

```text
service_radius_km:
> 0

availability:
valid enum

verification_status:
valid enum
```

## Resources

```text
quantity:
>= 0

available_quantity:
>= 0
available_quantity <= quantity
```

---

# 28. Soft Delete Policy

For the MVP, avoid widespread soft-delete complexity.

Recommended:

- User accounts → deactivate using `status`.
- Organizations → use `verification_status`.
- Resources → use `status`.
- Emergency requests → preserve history.
- Audit logs → never delete during normal operation.

If soft delete is required later, add:

```text
deleted_at DATETIME NULL
```

to appropriate tables.

---

# 29. Privacy Rules

The database may contain sensitive operational information.

### Do not expose publicly:

- User phone numbers
- User email addresses
- Exact private home locations
- Internal organization contact details
- Admin audit metadata
- Private request attachments

### Public data should be aggregated or generalized.

---

# 30. Seed Data Strategy

The hackathon environment should have deterministic demo data.

Recommended:

```text
ADMIN
1

CITIZENS
5

VOLUNTEERS
10

ORGANIZATIONS
3

RESOURCES
8

EMERGENCY REQUESTS
10
```

Seed data should cover:

```text
CRITICAL
HIGH
MEDIUM
LOW
```

and:

```text
CREATED
MATCHING
ACCEPTED
IN_PROGRESS
RESOLVED
```

This makes the admin dashboard visually meaningful during the demo.

---

# 31. Example Seed Users

Use fictional data.

```text
Admin
admin@resqlink.test

Citizen
citizen1@resqlink.test

Volunteer
volunteer1@resqlink.test

Organization
org1@resqlink.test
```

Development passwords should be documented only in local seed instructions and must never be reused in production.

---

# 32. Migration Structure

Recommended migration files:

```text
database/
└── migrations/
    ├── 001_create_users.sql
    ├── 002_create_emergency_requests.sql
    ├── 003_create_volunteer_profiles.sql
    ├── 004_create_organizations.sql
    ├── 005_create_resources.sql
    ├── 006_create_matches.sql
    ├── 007_create_notifications.sql
    ├── 008_create_request_events.sql
    ├── 009_create_emergency_attachments.sql
    └── 010_create_audit_logs.sql
```

Seeders:

```text
database/
└── seeders/
    ├── users.sql
    ├── organizations.sql
    ├── volunteers.sql
    ├── resources.sql
    └── demo_requests.sql
```

---

# 32.1 Implementation Notes (Phase 2)

The documented schema is implemented exactly as specified. Implementation details:

- **Migration runner:** `app/core/Migrator.php`, invoked via
  `php -c php.ini database/migrate.php` (flags: `--fresh` drops all tables first,
  `--status` lists applied/pending).
- **Tracking table:** `migrations` (`migration`, `batch`, `executed_at`) records what ran.
- **Migration files:** each `database/migrations/NNN_*.sql` contains a single statement and
  is applied in filename order (`001` … `010`).
- **Seeders:** `database/seeders/*.php`, invoked via `php -c php.ini database/seed.php`.
  Seeders are written in **PHP rather than raw `.sql`** so passwords are created with
  `password_hash()` — plain SQL cannot hash passwords safely.
- **Repositories:** `app/models/*Repository.php` extend `App\Core\Repository`, which
  provides prepared-statement CRUD, safe pagination and transaction helpers.
- **Allocation:** `ResourceRepository::allocate()` performs the transactional,
  over-allocation-safe decrement described in §26 (locks the row, verifies availability,
  never allows `available_quantity < 0`).
- **Verification:** migrations, seeders and constraints were tested against
  **MariaDB 10.4**; the SQL targets **MySQL 8+ / MariaDB 10.4+** and uses only common
  features (InnoDB, utf8mb4, ENUM, foreign keys, CHECK constraints, JSON).

---

# 33. Recommended Database Setup Order

```text
1. users
      ↓
2. emergency_requests
      ↓
3. volunteer_profiles
      ↓
4. organizations
      ↓
5. resources
      ↓
6. matches
      ↓
7. notifications
      ↓
8. request_events
      ↓
9. emergency_attachments
      ↓
10. audit_logs
```

Foreign-key dependencies must be respected.

---

# 34. Analytics Queries

## Active Emergencies

```sql
SELECT COUNT(*)
FROM emergency_requests
WHERE status IN (
    'CREATED',
    'VERIFIED',
    'MATCHING',
    'ACCEPTED',
    'IN_PROGRESS',
    'ESCALATED'
);
```

## Critical Requests

```sql
SELECT COUNT(*)
FROM emergency_requests
WHERE priority = 'CRITICAL'
  AND status NOT IN ('RESOLVED', 'CANCELLED');
```

## Available Volunteers

```sql
SELECT COUNT(*)
FROM volunteer_profiles
WHERE availability = 'AVAILABLE'
  AND verification_status = 'VERIFIED';
```

## Requests by Category

```sql
SELECT category, COUNT(*) AS total
FROM emergency_requests
GROUP BY category
ORDER BY total DESC;
```

## Requests by Status

```sql
SELECT status, COUNT(*) AS total
FROM emergency_requests
GROUP BY status;
```

---

# 35. Data Integrity Rules

The application must enforce:

```text
1. Every emergency request belongs to a valid user.
2. Every volunteer profile belongs to one user.
3. Every organization belongs to one account.
4. Every resource belongs to one organization.
5. Every match belongs to one request.
6. A match cannot point to a deleted active resource.
7. Available quantity cannot exceed total quantity.
8. Negative quantities are forbidden.
9. Invalid request status transitions are rejected.
10. Historical request events remain available.
```

---

# 36. Database Security

The PHP application must:

- Use PDO.
- Use prepared statements.
- Never concatenate raw user input into SQL.
- Validate all enum-like inputs.
- Limit database user privileges.
- Keep database credentials in `.env`.
- Never commit `.env`.
- Disable unnecessary remote database access.
- Use HTTPS in deployment.
- Back up production data where applicable.

---

# 37. Recommended Database User

Do not run the application using a MySQL root account.

Example:

```text
Database:
resqlink

Application user:
resqlink_app
```

Grant only required permissions:

```text
SELECT
INSERT
UPDATE
DELETE
CREATE (development only)
ALTER (development only)
```

Production permissions should be more restrictive where possible.

---

# 38. Backup Strategy

For development:

```text
mysqldump
```

For production:

```text
Daily backup
+
Retention policy
+
Test restoration periodically
```

The hackathon version does not need a complex backup platform.

---

# 39. Database Performance Rules

For the MVP:

- Avoid unnecessary joins on every dashboard request.
- Paginate emergency lists.
- Index filtering columns.
- Select only required columns.
- Avoid storing large images in MySQL.
- Store attachment metadata and filesystem/object-storage paths.
- Use transactions for multi-step state changes.
- Use database constraints for important invariants.

---

# 40. Database-to-Application Responsibility

## Database should handle

```text
Primary keys
Foreign keys
Unique constraints
Basic check constraints
Indexes
Persistence
Transactions
```

## Application should handle

```text
Business rules
Priority calculation
Matching logic
Authorization
Workflow transitions
AI processing
Notifications
User-facing validation
```

Do not move all business logic into SQL.

---

# 41. Future Scalability

If ResQLink grows beyond the hackathon:

### Phase 2 Database Improvements

- MySQL spatial types.
- Spatial indexes.
- Dedicated resource types table.
- Dedicated volunteer skills table.
- Organization membership.
- Resource transaction ledger.
- Notification delivery records.
- Search indexes.
- Read replicas if required.

### Example Future Tables

```text
skills
volunteer_skills
resource_types
resource_transactions
organization_members
notification_deliveries
```

---

# 42. Optional Spatial Upgrade

Future version:

```sql
POINT(latitude, longitude)
```

instead of separate coordinates.

Then:

```text
SPATIAL INDEX
      ↓
Nearby search
      ↓
Distance calculation
```

For the TYBCA MVP, separate decimal latitude/longitude fields are simpler and sufficient.

---

# 43. Complete Database Relationship Summary

```text
┌───────────────┐
│     USERS     │
└───────┬───────┘
        │
        ├───────────────┐
        │               │
        ▼               ▼
┌───────────────┐ ┌───────────────┐
│  VOLUNTEERS   │ │ ORGANIZATIONS │
└───────┬───────┘ └───────┬───────┘
        │                 │
        │                 ▼
        │          ┌───────────────┐
        │          │   RESOURCES   │
        │          └───────┬───────┘
        │                  │
        └────────┐    ┌────┘
                 ▼    ▼
             ┌───────────┐
             │  MATCHES  │
             └─────┬─────┘
                   │
                   ▼
         ┌────────────────────┐
         │ EMERGENCY_REQUESTS │
         └─────────┬──────────┘
                   │
          ┌────────┼────────┐
          ▼        ▼        ▼
       EVENTS  ATTACHMENTS  AUDIT
          │                 LOGS
          │
          └───────────────┐
                          ▼
                   NOTIFICATIONS
```

---

# 44. Final Database Contract

The database layer is considered complete when:

```text
✓ All required tables exist
✓ Foreign keys are working
✓ Indexes are added
✓ Constraints are enforced
✓ Migrations run from a clean database
✓ Seed data loads successfully
✓ Request lifecycle is supported
✓ Matching records can be created
✓ Resource quantities remain consistent
✓ Request events are preserved
✓ Audit logs are created
✓ Sensitive data is protected
✓ Dashboard queries return correct results
```

---

# 45. Implementation Priority

Build database functionality in this order:

```text
PHASE 1
users

        ↓

PHASE 2
emergency_requests

        ↓

PHASE 3
volunteer_profiles
organizations

        ↓

PHASE 4
resources

        ↓

PHASE 5
matches

        ↓

PHASE 6
notifications
request_events

        ↓

PHASE 7
attachments
audit_logs

        ↓

PHASE 8
indexes + constraints + seed data

        ↓

PHASE 9
analytics queries

        ↓

PHASE 10
testing + optimization
```

---

# 46. Final Database Principle

ResQLink's database should be:

> **Simple enough for a TYBCA student to understand, structured enough for a real software project, and reliable enough to demonstrate the complete emergency coordination workflow.**

The critical data flow is:

```text
USER
  ↓
EMERGENCY REQUEST
  ↓
PRIORITY
  ↓
MATCH
  ↓
VOLUNTEER / RESOURCE
  ↓
STATUS EVENTS
  ↓
RESOLUTION
  ↓
ANALYTICS + AUDIT
```

---

## End of Database Design
