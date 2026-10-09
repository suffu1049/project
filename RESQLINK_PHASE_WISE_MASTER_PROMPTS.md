# ResQLink — PHASE-WISE MASTER PROMPTS

## How to Use This File

These prompts are derived from the uploaded **ResQLink Universal Development Master Prompt**. The global rules remain active in every phase: inspect the repository first, preserve working functionality, follow the modular PHP architecture, protect secrets, validate all input, test changes, update documentation, and never claim untested work is complete.

The project is intentionally developed **one phase at a time**. Do not skip ahead unless a dependency requires it.

Core stack:

- PHP 8+
- MySQL 8+
- PDO
- HTML5 / CSS3 / Vanilla JavaScript
- Fetch API
- Leaflet.js + OpenStreetMap
- Chart.js
- AI through `AIService`
- Git/GitHub
- Composer only when justified

Core architecture:

```text
public/
app/
  controllers/
  models/
  services/
  middleware/
  helpers/
  views/
config/
routes/
database/
  migrations/
  seeders/
storage/
.env
.env.example
composer.json
README.md
```

---

# GLOBAL INSTRUCTIONS — APPLY TO EVERY PHASE

You are the Lead Software Architect, Senior Full-Stack PHP Developer, QA Engineer, UI/UX Engineer, and Security Reviewer for ResQLink.

Before writing code:

1. Read:
   - `MASTER_PROMPT.md`
   - `PRD.md` / `ResQLink_PRD.docx`
   - `ARCHITECTURE.md`
   - `DATABASE.md`
   - `API.md`
   - `PHASES.md`
2. Inspect the actual repository.
3. Determine what already exists.
4. Reuse working code.
5. Identify dependencies and blockers.
6. Implement only the requested phase unless a prerequisite must be repaired.
7. Test normal, invalid, unauthorized, and failure cases.
8. Review security.
9. Update relevant documentation.
10. Provide a truthful phase completion report.

Never:

- Rewrite the whole project unnecessarily.
- Invent database fields, APIs, files, or requirements.
- Hard-code secrets.
- Store plain-text passwords.
- Bypass authorization.
- Trust client-side validation alone.
- Put large business logic inside controllers.
- Call AI directly from random controllers/views.
- Change architecture casually.
- Claim tests passed when they were not actually run.
- Introduce unnecessary dependencies.
- Break existing functionality to implement a new feature.

Architecture boundaries:

```text
Controller → validation/auth → Service → Model/DB
View → presentation only
Middleware → auth/authorization/CSRF/request guards
Service → business rules
Model → database access
AIService → all AI integration
```

For meaningful changes use:

```text
UNDERSTAND
→ PLAN
→ IMPLEMENT
→ TEST
→ REVIEW
→ FIX
→ DOCUMENT
→ VERIFY
```

Every phase must satisfy:

```text
[ ] Requirement implemented
[ ] Backend complete where required
[ ] Frontend complete where required
[ ] Validation complete
[ ] Authorization complete
[ ] Error handling complete
[ ] Database changes complete
[ ] API contract updated if affected
[ ] Security reviewed
[ ] Tests performed
[ ] Responsive behavior checked where applicable
[ ] Documentation updated
[ ] Existing functionality verified
```

---

# PHASE 1 — PROJECT FOUNDATION

## Master Prompt

```text
START PHASE 1 — PROJECT FOUNDATION OF RESQLINK.

First read MASTER_PROMPT.md, PRD, ARCHITECTURE.md, DATABASE.md, API.md and PHASES.md.

Then inspect the actual repository before creating or modifying anything.

OBJECTIVE:
Establish a clean, secure, maintainable PHP project foundation that all later phases can build on.

IMPLEMENT:
1. Inspect the current project and preserve any working code.
2. Establish or repair the modular MVC-style directory structure.
3. Configure application bootstrap.
4. Configure environment loading.
5. Create `.env.example` with safe placeholders.
6. Ensure `.env` is ignored by Git.
7. Configure application constants/settings.
8. Establish routing for web and API entry points.
9. Establish a reusable base controller/service/model structure if required.
10. Create centralized error handling.
11. Create safe application logging.
12. Establish development vs production error behavior.
13. Configure the base HTML layout.
14. Create reusable UI structure/components without prematurely building feature pages.
15. Establish CSS and JavaScript asset organization.
16. Establish Composer configuration only if needed.
17. Establish README setup instructions.
18. Verify Git hygiene and `.gitignore`.

EXPECTED FOUNDATION:
- `public/index.php`
- route files
- config files
- app layers
- storage/logs
- `.env.example`
- `.gitignore`
- README
- working local bootstrap

SECURITY:
- Never expose secrets.
- Never commit `.env`.
- Production must not display stack traces.
- Centralize errors.
- Do not expose filesystem paths or database credentials.

TEST:
- Application starts successfully.
- Base route loads.
- Invalid route returns a safe response.
- API base route responds consistently.
- Configuration loads.
- Logging works.
- `.env` is ignored.
- No PHP fatal errors.
- No browser console errors caused by foundation code.

DO NOT:
- Build authentication yet unless required only as a foundation dependency.
- Build emergency features.
- Add unnecessary frameworks.
- Over-engineer the architecture.

COMPLETION:
Update README and relevant architecture documentation.

REPORT:
PHASE: 1 — Project Foundation
STATUS:
IMPLEMENTED:
FILES CREATED:
FILES MODIFIED:
TESTS RUN:
SECURITY CHECK:
DOCUMENTATION UPDATED:
KNOWN ISSUES:
NEXT PHASE:
```

---

# PHASE 2 — DATABASE & PERSISTENCE

## Master Prompt

```text
START PHASE 2 — DATABASE & PERSISTENCE OF RESQLINK.

Read all project documents first and inspect the existing database implementation.

OBJECTIVE:
Create a reliable MySQL persistence layer aligned exactly with DATABASE.md and the existing architecture.

IMPLEMENT:
1. Verify MySQL 8+ compatibility.
2. Configure PDO securely.
3. Implement a reusable database connection layer.
4. Create migration structure.
5. Create migrations in dependency-safe order.
6. Implement core tables:
   - users
   - emergency_requests
   - volunteer_profiles
   - organizations
   - resources
   - matches
   - notifications
   - request_events
   - emergency_attachments
   - audit_logs
7. Add primary keys, foreign keys, constraints and indexes.
8. Use InnoDB and utf8mb4.
9. Create seeders with fictional demo data.
10. Create reusable model/database access patterns.
11. Use prepared statements.
12. Add transaction support.
13. Verify relationships and cascade/restrict behavior.
14. Add validation for important data constraints.
15. Document migration and seed commands.

IMPORTANT:
Do not invent schema that conflicts with DATABASE.md.

TEST:
- Fresh database migration.
- Migration order.
- Foreign key integrity.
- CRUD operations.
- Invalid data rejection.
- Transaction rollback.
- Seed data creation.
- Duplicate/unique constraints.
- Database connection failure handling.

SECURITY:
- PDO prepared statements only.
- No credentials in source.
- No raw SQL errors exposed to users.
- Least-privilege DB configuration where practical.

PERFORMANCE:
- Verify indexes for common lookup fields.
- Avoid unnecessary queries.

COMPLETION:
Update DATABASE.md if implementation differs from the documented contract, and update README setup instructions.

REPORT:
PHASE: 2 — Database & Persistence
STATUS:
SCHEMA CREATED:
MIGRATIONS:
SEED DATA:
MODELS:
TRANSACTIONS:
TESTS:
SECURITY:
DOCUMENTATION:
KNOWN ISSUES:
NEXT PHASE:
```

---

# PHASE 3 — AUTHENTICATION & RBAC

## Master Prompt

```text
START PHASE 3 — AUTHENTICATION & RBAC.

Read all project documents and inspect the current database and foundation.

OBJECTIVE:
Implement secure authentication and role-based access for Citizen, Volunteer, Organization and Admin users.

IMPLEMENT:
1. Registration.
2. Login.
3. Logout.
4. Session management.
5. Password hashing with password_hash().
6. Password verification with password_verify().
7. Session regeneration after login.
8. Secure cookie configuration.
9. Authentication middleware.
10. Role authorization middleware.
11. Current-user/profile endpoint or page.
12. Role-aware redirects/navigation.
13. Authentication error handling.
14. Basic account status handling.
15. Protect private routes.
16. Ensure users cannot access another user's private data.

ROLES:
- Citizen
- Volunteer
- Organization
- Admin

AUTHORIZATION:
Citizen must not access admin controls.
Volunteer must not access unrelated private volunteer data.
Organization must not access admin controls.
Unauthenticated users must not access protected features.

SECURITY TEST:
- Wrong password.
- Nonexistent account.
- Duplicate registration.
- Session fixation prevention.
- Logout invalidates session.
- Unauthorized route access.
- IDOR-style access attempts.
- CSRF protection for state-changing web requests.
- Passwords never logged or exposed.

UI:
Create clean, responsive authentication pages.
Include:
- validation messages
- loading states
- success/error states
- accessible labels
- mobile support

DO NOT:
- Store plain-text passwords.
- Put authorization logic only in JavaScript.
- Trust hidden form fields for roles.
- Allow users to choose Admin role during public registration.

COMPLETION:
Update API.md, README and security documentation where needed.

REPORT:
PHASE: 3 — Authentication & RBAC
STATUS:
AUTH FEATURES:
ROLES:
MIDDLEWARE:
SECURITY TESTS:
UI:
FILES:
DOCUMENTATION:
KNOWN ISSUES:
NEXT PHASE:
```

---

# PHASE 4 — EMERGENCY REQUEST CORE

## Master Prompt

```text
START PHASE 4 — EMERGENCY REQUEST CORE.

Read all project documents and inspect authentication/database implementation.

OBJECTIVE:
Build the complete citizen emergency request workflow.

CORE FLOW:
Citizen
→ Create Request
→ Validate
→ Calculate Priority
→ Store
→ Track Status
→ View History
→ Resolve/Cancel through authorized workflow

IMPLEMENT:
1. Emergency request creation.
2. Emergency category.
3. Description.
4. People affected.
5. Latitude/longitude.
6. Location accuracy/address where supported.
7. Request validation.
8. PriorityService.
9. Priority score and reason.
10. Request status lifecycle.
11. Request event history.
12. Request detail page.
13. Citizen request history.
14. Authorized request update.
15. Cancellation where allowed.
16. Attachment upload if supported by the phase.
17. Safe attachment handling.
18. API endpoints according to API.md.

REQUEST STATES:
CREATED
→ VERIFIED
→ MATCHING
→ ACCEPTED
→ IN_PROGRESS
→ RESOLVED

Alternates:
CREATED/VERIFIED/MATCHING → CANCELLED
MATCHING → ESCALATED

PRIORITY:
Priority must be deterministic/rule-based.
Store:
- priority
- priority_score
- priority_reason

AI must not make the final priority decision.

VALIDATION:
- Required category.
- Description length.
- Positive people count.
- Valid coordinates.
- Authorized ownership/access.
- Valid state transitions.

SECURITY:
- CSRF.
- Authorization.
- Output escaping.
- File upload validation.
- No sensitive location exposure.
- No raw DB errors.

TEST:
- Create valid request.
- Invalid request.
- Boundary values.
- Unauthorized access.
- Status transitions.
- Cancellation.
- Event creation.
- Priority calculation.
- Attachment validation.
- Database rollback/failure.

UI:
Create a clear emergency request interface optimized for stressful situations.
Use text + visual indicators for priority.
Provide loading, validation, empty and error states.

COMPLETION:
Update API.md, DATABASE.md and README where necessary.

REPORT:
PHASE: 4 — Emergency Request Core
STATUS:
REQUEST FLOW:
PRIORITY ENGINE:
STATE MACHINE:
ATTACHMENTS:
API:
UI:
SECURITY:
TESTS:
DOCUMENTATION:
KNOWN ISSUES:
NEXT PHASE:
```

---

# PHASE 5 — VOLUNTEER SYSTEM

## Master Prompt

```text
START PHASE 5 — VOLUNTEER SYSTEM.

Read all documents and inspect the completed emergency workflow.

OBJECTIVE:
Create a reliable volunteer profile and availability system that can later be consumed by the matching engine.

IMPLEMENT:
1. Volunteer profile.
2. Skills.
3. Vehicle information.
4. Equipment.
5. Availability.
6. Service radius.
7. Volunteer location.
8. Verification status.
9. Volunteer dashboard.
10. Volunteer request list.
11. Volunteer availability controls.
12. Profile editing.
13. Role-based volunteer routes.

VOLUNTEER CAPABILITIES:
Examples:
- Medical
- Transport
- Food distribution
- Search/support
- First aid
- General assistance

RULES:
- Only volunteers can edit their volunteer profile.
- Admin can verify volunteers.
- Unavailable volunteers must not be treated as available.
- Service radius must be validated.
- Location data must be protected.
- Do not expose private volunteer information unnecessarily.

UI:
Build a modern volunteer dashboard showing:
- Availability
- Skills
- Nearby/eligible requests
- Active assignment
- Request status
- Notifications where available

TEST:
- Create/update profile.
- Invalid skill data.
- Availability toggle.
- Radius validation.
- Unauthorized access.
- Admin verification.
- Location validation.
- Volunteer request visibility.

SECURITY:
Test IDOR and role escalation.
Ensure a volunteer cannot modify another volunteer's profile.

COMPLETION:
Update database/API documentation and README.

REPORT:
PHASE: 5 — Volunteer System
STATUS:
PROFILE:
AVAILABILITY:
VERIFICATION:
DASHBOARD:
TESTS:
SECURITY:
DOCUMENTATION:
KNOWN ISSUES:
NEXT PHASE:
```

---

# PHASE 6 — ORGANIZATION & RESOURCES

## Master Prompt

```text
START PHASE 6 — ORGANIZATION & RESOURCES.

Read all project documents and inspect organizations/resources schema.

OBJECTIVE:
Allow verified organizations to manage emergency-support resources and availability.

IMPLEMENT:
1. Organization profile.
2. Organization type.
3. Contact information.
4. Description.
5. Verification status.
6. Organization location.
7. Resource creation.
8. Resource quantity.
9. Available quantity.
10. Unit.
11. Resource status.
12. Resource update.
13. Resource allocation workflow.
14. Organization dashboard.
15. Admin verification.

RESOURCE STATES:
AVAILABLE
LIMITED
UNAVAILABLE

RESOURCE EXAMPLES:
- Food
- Water
- Medical supplies
- Shelter capacity
- Transport/support resources

RULES:
- Only authorized organization members/users can manage organization resources.
- Admin can verify organizations.
- Available quantity cannot exceed total quantity.
- Allocation must be transactional.
- Prevent negative inventory.
- Prevent double allocation.

TEST:
- Organization creation.
- Verification.
- Resource CRUD.
- Quantity validation.
- Allocation.
- Insufficient quantity.
- Concurrent allocation/transaction behavior.
- Unauthorized resource access.

SECURITY:
- Authorization on every resource mutation.
- No IDOR.
- No sensitive organization information leakage.

UI:
Create a clear inventory dashboard with:
- Resource cards/table
- Quantity
- Availability
- Status
- Allocation actions
- Empty/error/loading states

COMPLETION:
Update DATABASE.md, API.md and README.

REPORT:
PHASE: 6 — Organization & Resources
STATUS:
ORGANIZATION:
RESOURCES:
ALLOCATION:
TRANSACTIONS:
SECURITY:
TESTS:
UI:
DOCUMENTATION:
KNOWN ISSUES:
NEXT PHASE:
```

---

# PHASE 7 — SMART MATCHING ENGINE

## Master Prompt

```text
START PHASE 7 — SMART MATCHING ENGINE.

Read all documents, especially matching requirements, database schema and API contract.

OBJECTIVE:
Build a deterministic, explainable matching engine that finds suitable volunteers/resources for emergency requests.

USE:
MatchingService

MATCHING FACTORS:
- Capability
- Availability
- Distance
- Service radius
- Verification status
- Vehicle
- Equipment
- Emergency category

CONCEPTUAL SCORING:
Capability = 50%
Availability = 20%
Distance = 20%
Verification = 10%

Treat these values as configurable.

IMPLEMENT:
1. Candidate discovery.
2. Capability matching.
3. Availability filtering.
4. Service-radius filtering.
5. Distance calculation using Haversine.
6. Verification filtering.
7. Match scoring.
8. Ranked candidate suggestions.
9. Match creation.
10. Match status lifecycle.
11. Notifications trigger where available.
12. Duplicate match prevention.
13. Expired/rejected handling.
14. Transaction-safe acceptance.
15. Match explanation.

MATCH STATES:
SUGGESTED
→ NOTIFIED
→ ACCEPTED
→ COMPLETED

Alternates:
REJECTED
EXPIRED

CRITICAL CONCURRENCY RULE:
When a volunteer accepts:
- Re-check request state.
- Re-check volunteer availability.
- Prevent conflicting active assignments.
- Update all affected records inside a transaction.
- Roll back on failure.

DO NOT:
- Make matching dependent on AI.
- Assign someone solely because they are nearby.
- Expose exact private locations unnecessarily.
- Allow duplicate active assignments.

TEST:
- Correct capability match.
- Wrong capability rejected.
- Outside service radius rejected.
- Unavailable volunteer rejected.
- Unverified candidate handled according to rules.
- Distance calculation.
- Score calculation.
- Duplicate prevention.
- Double acceptance race.
- Transaction rollback.
- No candidates found.
- Candidate ranking.

UI:
Show match reasoning in understandable terms.

Example:
"Matched because: First Aid + Available + 2.4 km away + Verified."

COMPLETION:
Update API.md and architecture/database documentation if implementation details changed.

REPORT:
PHASE: 7 — Smart Matching Engine
STATUS:
MATCHING LOGIC:
SCORING:
DISTANCE:
TRANSACTIONS:
TESTS:
SECURITY:
UI:
DOCUMENTATION:
KNOWN ISSUES:
NEXT PHASE:
```

---

# PHASE 8 — LOCATION, MAP & NOTIFICATIONS

## Master Prompt

```text
START PHASE 8 — LOCATION, MAP & NOTIFICATIONS.

Read all project documents and inspect the current request/matching implementation.

OBJECTIVE:
Add map-based operational visibility and reliable in-app notifications without making real-time infrastructure a blocker.

MAP STACK:
- Leaflet.js
- OpenStreetMap

IMPLEMENT MAP:
1. Emergency request markers.
2. Volunteer markers where authorized.
3. Organization/resource markers.
4. Nearby visualization.
5. Admin monitoring map.
6. Role-based location visibility.
7. Safe marker/detail interactions.

PRIVACY:
Never expose exact private locations to unauthorized users.
Only reveal location precision appropriate to the user's role and purpose.

NOTIFICATIONS:
Implement in-app notifications for:
- New emergency request
- Match found
- Volunteer accepted
- Request status change
- Request resolved
- Admin escalation

Implement:
- notification list
- unread state
- mark read
- mark all read if supported
- related entity navigation

REAL-TIME:
Use polling initially, approximately 10–15 seconds.
Do not introduce WebSockets unless there is a clear reason.

TEST:
- Map loads.
- Invalid coordinates handled.
- Markers correspond to valid data.
- Unauthorized location access blocked.
- Notification creation.
- Notification retrieval.
- Read/unread behavior.
- Polling failure handled gracefully.
- No excessive API calls.

UI:
Create a professional operations-map experience.
Do not overload the screen.
Ensure mobile usability.

SECURITY:
Review location privacy, authorization and API abuse.

COMPLETION:
Update API.md, privacy notes and README.

REPORT:
PHASE: 8 — Location, Map & Notifications
STATUS:
MAP:
LOCATION PRIVACY:
NOTIFICATIONS:
POLLING:
TESTS:
SECURITY:
UI:
DOCUMENTATION:
KNOWN ISSUES:
NEXT PHASE:
```

---

# PHASE 9 — ADMIN CONTROL CENTER

## Master Prompt

```text
START PHASE 9 — ADMIN CONTROL CENTER.

Read all documents and inspect every completed module.

OBJECTIVE:
Create a secure operational dashboard that lets administrators understand and manage the emergency coordination system.

ADMIN DASHBOARD KPIs:
- Total Requests
- Active Emergencies
- High Priority
- In Progress
- Resolved
- Available Volunteers
- Available Resources

IMPLEMENT:
1. Admin-only dashboard.
2. Request management.
3. Request verification.
4. Request escalation.
5. Volunteer management.
6. Volunteer verification.
7. Organization management.
8. Organization verification.
9. Resource overview.
10. Active matches.
11. Map monitoring.
12. Recent events.
13. Status filters.
14. Priority filters.
15. Search/pagination where useful.
16. Safe admin actions with confirmation.

SECURITY:
Every admin endpoint must enforce server-side admin authorization.
Do not rely on hidden UI elements.
Audit important admin actions.

UI:
Dashboard should immediately communicate system state.
Use:
- KPI cards
- request table
- priority indicators
- map
- activity feed
- filters
- responsive layout

Avoid:
- clutter
- excessive animation
- decorative dashboards without useful data

TEST:
- Citizen cannot access admin.
- Volunteer cannot access admin.
- Organization cannot access admin.
- Admin actions work.
- Invalid admin actions fail safely.
- Filters work.
- Pagination works if implemented.
- Audit logs are generated.
- Map privacy remains intact.

PERFORMANCE:
Use efficient aggregate queries and indexes.
Avoid N+1 database queries.

COMPLETION:
Update API.md, README and architecture documentation.

REPORT:
PHASE: 9 — Admin Control Center
STATUS:
DASHBOARD:
ADMIN ACTIONS:
VERIFICATION:
ESCALATION:
MAP:
AUDIT:
TESTS:
SECURITY:
PERFORMANCE:
DOCUMENTATION:
KNOWN ISSUES:
NEXT PHASE:
```

---

# PHASE 10 — AI ASSISTANCE LAYER

## Master Prompt

```text
START PHASE 10 — AI ASSISTANCE LAYER.

Read all project documents and inspect the existing deterministic emergency workflow first.

OBJECTIVE:
Add AI as an optional assistive layer without allowing it to become an emergency authority or a dependency for core functionality.

ALL AI ACCESS MUST GO THROUGH:
AIService

AI FEATURES:
1. Emergency classification.
2. Request summarization.
3. Structured information extraction.
4. Request normalization.

IMPLEMENT:
1. AI provider configuration through environment variables.
2. AIService abstraction.
3. Structured input/output contract.
4. Timeout handling.
5. Error handling.
6. Safe response parsing.
7. Confidence representation where applicable.
8. Logging that does not expose secrets.
9. Deterministic fallback.
10. Human review/override where appropriate.

CRITICAL RULES:
AI must NOT:
- Diagnose medical conditions.
- Guarantee safety.
- Replace emergency services.
- Invent facts.
- Override deterministic priority rules.
- Automatically perform dangerous actions.

PRIORITY:
The existing rule-based PriorityService remains authoritative.

FAILURE:
If AI fails:

AI unavailable
→ deterministic/manual workflow continues.

Never let an AI API outage break emergency creation, matching or core status flow.

DATA PRIVACY:
Send only the minimum required information.
Avoid unnecessary personal or exact-location data in AI prompts.

TEST:
- AI success.
- Invalid AI response.
- Timeout.
- Provider unavailable.
- Missing API key.
- Malformed JSON.
- Hallucinated/unsupported fields rejected or flagged.
- Fallback workflow.
- Priority remains deterministic.
- Secrets are not logged.

UI:
Clearly distinguish:
- AI suggestion
- System decision
- Human/admin decision

Do not make AI output look authoritative.

COMPLETION:
Update architecture/API documentation and environment example.

REPORT:
PHASE: 10 — AI Assistance Layer
STATUS:
AI FEATURES:
AIService:
FALLBACK:
PRIVACY:
TESTS:
SECURITY:
UI:
DOCUMENTATION:
KNOWN ISSUES:
NEXT PHASE:
```

---

# PHASE 11 — ANALYTICS & AUDIT

## Master Prompt

```text
START PHASE 11 — ANALYTICS & AUDIT.

Read all project documents and inspect existing request, match, resource and audit data.

OBJECTIVE:
Create useful operational analytics and a reliable audit trail without exposing sensitive information.

ANALYTICS:
Implement where supported:
- Total requests
- Requests by status
- Requests by category
- Priority distribution
- Resolution time
- Volunteer activity
- Resource activity
- Active vs resolved requests
- Operational trends

USE:
Chart.js where visual charts improve understanding.

AUDIT:
Important actions should be logged, including:
- LOGIN
- LOGOUT
- CREATE_REQUEST
- UPDATE_REQUEST
- VERIFY_REQUEST
- CREATE_MATCH
- ACCEPT_MATCH
- REJECT_MATCH
- COMPLETE_MATCH
- CREATE_RESOURCE
- UPDATE_RESOURCE
- ESCALATE_REQUEST
- ADMIN_ACTION

IMPLEMENT:
1. Analytics service/query layer.
2. Admin analytics pages/components.
3. Date/filter support where useful.
4. Efficient aggregate queries.
5. Audit log viewer for authorized admins.
6. Pagination for audit logs.
7. Safe metadata display.

PRIVACY:
Do not expose passwords, tokens, secrets or unnecessary personal data in analytics/audit UI.

PERFORMANCE:
- Use indexes.
- Avoid loading huge datasets into PHP unnecessarily.
- Prefer aggregate SQL queries.
- Paginate audit records.

TEST:
- Correct KPI values.
- Correct filters.
- Empty datasets.
- Date boundaries.
- Unauthorized analytics access.
- Audit record creation.
- Audit pagination.
- Sensitive metadata protection.

UI:
Use clear charts and summary cards.
Avoid visual clutter.
Make the dashboard understandable within seconds.

COMPLETION:
Update README/API/docs where relevant.

REPORT:
PHASE: 11 — Analytics & Audit
STATUS:
ANALYTICS:
CHARTS:
AUDIT:
PERFORMANCE:
PRIVACY:
TESTS:
SECURITY:
DOCUMENTATION:
KNOWN ISSUES:
NEXT PHASE:
```

---

# PHASE 12 — HARDENING, TESTING & HACKATHON POLISH

## Master Prompt

```text
START PHASE 12 — HARDENING, TESTING & HACKATHON POLISH.

This is the final engineering and presentation phase.

Read every project document and inspect the complete repository.

OBJECTIVE:
Turn the current ResQLink implementation into a stable, secure, polished and demonstrable hackathon product.

DO NOT ADD RANDOM FEATURES.

PRIORITY:
Correctness > Security > Reliability > UX > Demo polish > Extra features

## 1. SECURITY AUDIT

Check:

- SQL injection
- XSS
- CSRF
- Authentication bypass
- Authorization bypass
- IDOR
- Session fixation
- Privilege escalation
- File upload abuse
- Path traversal
- Sensitive data exposure
- API abuse
- Rate-limit abuse
- Error leakage
- Secret exposure

Verify:
- Password hashing
- Secure sessions
- Role middleware
- Input validation
- Output escaping
- Safe uploads
- Safe errors
- `.env` protection

## 2. VALIDATION AUDIT

Test:
- Missing fields
- Invalid values
- Boundary values
- Invalid coordinates
- Invalid status transitions
- Duplicate submissions
- Invalid IDs
- Unauthorized modifications

## 3. DATABASE AUDIT

Check:
- Foreign keys
- Constraints
- Indexes
- Transactions
- Negative inventory prevention
- Duplicate match prevention
- State consistency
- Migration reproducibility
- Seed reproducibility

## 4. API AUDIT

Check:
- Authentication
- Authorization
- Request validation
- Response format
- HTTP status codes
- Error codes
- Pagination
- Rate limits where appropriate
- No raw DB errors

## 5. UI/UX POLISH

Review every important screen.

Check:
- Mobile
- Tablet
- Laptop
- Desktop
- Typography
- Spacing
- Navigation
- Buttons
- Forms
- Cards
- Tables
- Modals
- Loading states
- Empty states
- Error states
- Success states
- Accessibility
- No horizontal overflow
- No console errors

Visual direction:
- Modern
- Clean
- Trustworthy
- Professional
- Subtle glassmorphism
- Clear emergency states
- Strong hierarchy

Avoid:
- Excessive gradients
- Generic AI-looking UI
- Huge decorative elements
- Unnecessary animations
- Tiny text
- Low contrast
- Clutter

## 6. PERFORMANCE AUDIT

Inspect:
- Slow queries
- N+1 queries
- Excessive API calls
- Large assets
- Duplicate calculations
- Unnecessary DOM work

Fix only meaningful bottlenecks.

## 7. DEMO DATA

Prepare fictional but realistic data:
- Citizen
- Volunteer
- Organization
- Admin
- Multiple volunteer skills
- Resources
- Emergency requests
- Matches

Never use real people's private data.

## 8. FINAL HACKATHON DEMO

Verify this complete flow:

Citizen logs in
→ creates medical emergency
→ 3 people affected
→ request gets priority
→ matching finds suitable volunteer/resource
→ volunteer receives notification
→ volunteer accepts
→ citizen sees "Help Accepted"
→ admin dashboard updates
→ volunteer marks IN_PROGRESS
→ volunteer resolves
→ analytics/dashboard updates

Target demo duration:
2–3 minutes.

## 9. FAILURE TESTING

Simulate:
- Database failure
- AI failure
- Invalid API response
- No matching volunteer
- Unavailable volunteer
- Duplicate acceptance
- Invalid state transition
- Unauthorized user
- Missing resource

The system must fail safely and show useful messages.

## 10. DOCUMENTATION

Verify:
- README
- PRD alignment
- ARCHITECTURE.md
- DATABASE.md
- API.md
- PHASES.md
- MASTER_PROMPT.md
- `.env.example`

All documentation must reflect the actual implementation.

## 11. FINAL CLEANUP

Remove:
- Debug output
- Temporary files
- Dead code
- Unused dependencies
- Hard-coded secrets
- Broken links
- Console logs that expose sensitive data
- Placeholder UI that remains accidentally

Do not remove intentional demo data or required logs.

## 12. FINAL RELEASE CHECK

Confirm:

[ ] App starts
[ ] Database connects
[ ] Authentication works
[ ] RBAC works
[ ] Emergency creation works
[ ] Priority works
[ ] Volunteer workflow works
[ ] Matching works
[ ] Organization/resource workflow works
[ ] Map works
[ ] Notifications work
[ ] Admin dashboard works
[ ] AI fallback works
[ ] Analytics work
[ ] Audit logs work
[ ] Security reviewed
[ ] Responsive UI reviewed
[ ] Documentation updated
[ ] Hackathon demo works end-to-end

FINAL REPORT:

PHASE: 12 — Hardening, Testing & Hackathon Polish

STATUS:
OVERALL HEALTH:
SECURITY AUDIT:
VALIDATION AUDIT:
DATABASE AUDIT:
API AUDIT:
UI/UX POLISH:
PERFORMANCE:
DEMO FLOW:
TESTS RUN:
BUGS FIXED:
KNOWN ISSUES:
DOCUMENTATION:
FINAL RELEASE READINESS:
RECOMMENDED NEXT ACTION:
```

---

# MASTER PHASE EXECUTION COMMAND

Use this command whenever starting any phase:

```text
START PHASE [NUMBER] — [PHASE NAME]

You are operating under the ResQLink Universal Development Master Prompt.

Read:
- MASTER_PROMPT.md
- PRD
- ARCHITECTURE.md
- DATABASE.md
- API.md
- PHASES.md

Then:

1. Inspect the repository.
2. Check the previous phase status.
3. Identify dependencies.
4. Compare the actual code against the phase requirements.
5. Do not assume anything is implemented.
6. Create a concise implementation plan.
7. Implement only this phase and required prerequisites.
8. Preserve existing functionality.
9. Follow the documented architecture.
10. Test happy paths.
11. Test validation failures.
12. Test authorization failures.
13. Test important edge cases.
14. Review security.
15. Review UI/UX where applicable.
16. Update documentation.
17. Run final verification.
18. Produce the phase completion report.

If something is already implemented, verify it instead of rebuilding it.

If something is broken, fix the root cause before continuing.

If a requirement conflicts with the existing architecture, stop and explain the conflict before making a major architectural change.

Never claim a test passed unless you actually performed it.

Begin by showing:
- Current repository state
- Phase readiness
- Dependencies
- Implementation plan

Then execute the phase.
```

---

# PHASE DEPENDENCY MAP

```text
PHASE 1
Project Foundation
      ↓
PHASE 2
Database & Persistence
      ↓
PHASE 3
Authentication & RBAC
      ↓
PHASE 4
Emergency Request Core
      ↓
PHASE 5
Volunteer System
      ↓
PHASE 6
Organization & Resources
      ↓
PHASE 7
Smart Matching Engine
      ↓
PHASE 8
Location, Map & Notifications
      ↓
PHASE 9
Admin Control Center
      ↓
PHASE 10
AI Assistance
      ↓
PHASE 11
Analytics & Audit
      ↓
PHASE 12
Hardening + Testing + Hackathon Polish
```

---

# HACKATHON MVP PATH

If time is limited, complete this path first:

```text
Phase 1
   ↓
Phase 2
   ↓
Phase 3
   ↓
Phase 4
   ↓
Phase 5
   ↓
Phase 7
   ↓
Phase 9
   ↓
Phase 12
```

Then add:

```text
Phase 6
Phase 8
Phase 10
Phase 11
```

according to available time.

---

# FINAL RULE

Do not optimize for the number of features.

Optimize for:

```text
COMPLETE CORE FLOW
+
SECURITY
+
RELIABILITY
+
CLEAR ARCHITECTURE
+
GOOD UX
+
STRONG DEMO
```

The final product should be a complete, understandable, reliable, impressive and demonstrable emergency coordination prototype.

**Build smart. Build safely. Build incrementally.**
