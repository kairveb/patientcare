# Partner integration architecture

## Scope and implementation status

The shared integration foundation supports BDMS (billing/payment status), HICS (insurance/letter of authorization), LIS (laboratory), RIS (radiology/imaging), PMS (pharmacy), EMRS (clinical record exchange), HRMS (provider availability), HADS (aggregate analytics exports), SAS (account status, approved role mapping, and audit events), and VRDS (patient transport dispatch) in mock mode. Live mode remains available only after endpoint and contract approval. These partner integrations sit beneath the existing SPRS, ASS, TOCS, EERTS, and IBMS modules; no top-level module is added or reordered.

The local application is the system of record for patients, encounters, admissions, billing statements, and care requests. Partner payloads use keyed opaque references derived from internal IDs; names and full patient records are not sent. Use the `mock` mode for local development and tests. Do not select `live` until partner endpoints, secrets, privacy notices, consent, TLS, and the agreed contracts have been reviewed.

## Machine identity and request security

- Partner identities are stored in `integration_partners`. API keys are bcrypt hashes; webhook signing secrets and outbound bearer tokens use Laravel encrypted casts. `bdms`, `hics`, `lis`, `ris`, `pms`, `emrs`, `hrms`, `hads`, `sas`, and `vrds` are provisioned by the seeder.
- Partner API requests require `X-Partner-Code` and `X-Integration-Key`. Human Sanctum bearer tokens and resolved human sessions are rejected by partner middleware. Permissions are restricted to each partner's allowlist.
- Webhooks require the same partner API-key headers plus `X-Webhook-Timestamp`, `X-Webhook-Signature`, and `X-Event-Id`. The signature is lowercase/uppercase-insensitive hexadecimal HMAC-SHA256 of `timestamp + "." + exact_raw_request_body`. Comparison is constant-time. Timestamps outside `INTEGRATION_WEBHOOK_TOLERANCE_SECONDS` are rejected. Event IDs are unique per partner; a repeat returns the stored HTTP status/body and does not apply the change again.
- Errors from partner middleware/callback processing use `{ "success": false, "code": "...", "message": "...", "request_id": "..." }`. Never put PHI, keys, or signatures in errors or log metadata.
- Inbound partner API requests write to `api_logs` and `integration_logs`; outbound delivery attempts write to `integration_logs`. Logs contain opaque request/outbox identifiers and status only.

## Outbox, retries, and privacy

`integration_outbox` records a partner, entity reference, payload reference, idempotency key, state, attempts, retry time, and safe error detail. Jobs send `Idempotency-Key` and use exponential delays capped at one hour. Repeated enqueue of the same partner/entity/version reuses the outbox row. A disclosure/consent validation failure becomes `DEAD` for review rather than repeatedly sending. Operators can request a manual retry from the admin integrations API; that reuses the original idempotency key.

Patient-level outbound payloads require a current `DATA_PRIVACY` grant and the applicable partner-specific sharing grant via `PatientConsentService`. The system records the allowed field names and purpose in append-only `integration_disclosures`. Consent types are `BDMS_SHARING`, `HICS_SHARING`, `LIS_SHARING`, `RIS_SHARING`, `PMS_SHARING`, `EMRS_SHARING`, `HRMS_SHARING`, `HADS_SHARING`, `SAS_SHARING`, and `VRDS_SHARING`. HADS is restricted to aggregate operational metrics and writes a disclosure with no patient subject. HRMS and SAS exchange only provider availability or opaque account/security metadata. No name, contact data, MRN, clinical reason, clinical notes, or full record is sent.

VRDS sends generic transport flags and hospital zone codes only. Each outbound attempt records a disclosure with `patient_id = null`, purpose `patient transport dispatch`, and the transmitted field names. The transport request has no patient identifier in its partner payload, so the patient consent gate is not used.

The current data-sharing consent text and its version require privacy/legal approval before production. A consent grant is not, by itself, a legal determination that a specific disclosure is permitted.

## BDMS contract

### Outbound billing statement / clearance

When a billing statement or billing-clearance request is created, the outbox sends:

`POST /billing-statements`

```json
{
  "patient_ref": "opaque-reference",
  "statement_ref": "opaque-reference",
  "encounter_ref": "opaque-reference",
  "admission_ref": null,
  "total": "1250.00",
  "currency": "PHP"
}
```

The admission and encounter reference are mutually exclusive where present. The partner should accept the `Idempotency-Key` header and return an external ID and state:

```json
{"success": true, "external_id": "bdms-statement-123", "status": "RECEIVED"}
```

The `external_id` and normalized partner status are persisted on the associated bill or clearance request.

### Inbound payment/clearance webhook

`POST /api/v1/integrations/bdms/webhooks/payment-status`

```json
{
  "event_id": "event-123",
  "external_id": "bdms-statement-123",
  "status": "PAID",
  "payment_ref": "partner-payment-123"
}
```

Recognized statuses: `REQUESTED`, `RECEIVED`, `UNPAID`, `PAID`, `CLEARED`, `REJECTED`. Allowed bill transitions: unset → `REQUESTED`/`RECEIVED`/`UNPAID`; `REQUESTED` → `RECEIVED`/`UNPAID`/`REJECTED`; `RECEIVED` → `UNPAID`/`PAID`/`REJECTED`; `UNPAID` → `PAID`/`REJECTED`; `PAID` → `CLEARED`. Clearance requests may transition from `REQUESTED`, `RECEIVED`, `UNPAID`, or `PAID` to `CLEARED`/`REJECTED`. Repeated same-state notifications are accepted. Invalid transitions return 409 and are audited. `PAID` requires a payment reference and records the statement total as paid; an internal billing review is still required to resolve exceptions.

An authorized human billing decision uses the existing `manage-billing` permission, limited to `super-admin`, and can mark a requested clearance `CLEARED` or `REJECTED`. No other human-role access was widened. The admission payment-status endpoint now includes partner and clearance status.

## HICS contract

LOA tracking is attached to admissions and covered outpatient care requests. Internal state is `PENDING`, `APPROVED`, `DENIED`, `EXPIRED`, or `CANCELLED`. The existing encrypted `loa_reference` remains encrypted. New status details include coverage code, requested/approved amounts, decision time, payer reference, external ID, and integration status.

### Outbound request

`POST /loa-requests`

```json
{
  "patient_ref": "opaque-reference",
  "admission_ref": "opaque-reference",
  "request_ref": null,
  "coverage_code": "PLAN-CODE",
  "requested_amount": "5000.00"
}
```

Outpatient requests send `request_ref` instead of `admission_ref`. The partner response follows the shared `external_id`/`status` shape and must be idempotent.

### Inbound approval webhook

`POST /api/v1/integrations/hics/webhooks/loa-status`

```json
{
  "event_id": "loa-event-123",
  "external_id": "hics-loa-123",
  "status": "APPROVED",
  "approved_amount": "4500.00",
  "payer_reference": "payer-case-123"
}
```

Allowed transitions: unset → `PENDING`; `PENDING` → `APPROVED`, `DENIED`, `EXPIRED`, or `CANCELLED`. Final states cannot transition to a different state. `APPROVED` requires a non-negative approved amount. Decision and payer data appear on the existing admission and care-request screens for roles already authorized to view those records. Internal appointment decisions remain unchanged; HICS approval does not grant a role permission to approve an appointment or care request.

## Round 2 implemented partner workflows

The following workflows reuse the Round 1 outbox, signed webhook verification, partner identity, event-idempotency, disclosure service, and mock client. Status appears inside existing care-request, patient approval, encounter, and prescription views; no module or sidebar section is added.

### LIS and RIS orders/results

Approved `lab` and `imaging` care requests enqueue `POST /lab-orders` for LIS and `POST /imaging-orders` for RIS. The LIS payload is deliberately limited to:

```json
{"patient_ref":"opaque-reference","request_ref":"opaque-reference","tests":["laboratory"],"urgency":"routine"}
```

The RIS payload uses opaque references with `modality` (`imaging`) and `urgency`. The current care-request record has no structured catalog code, so these requests identify only the service category rather than exposing free-text clinical rationale. A validated catalog-code field is needed before exchanging specific tests or imaging procedures.

Signed result callbacks are `POST /api/v1/integrations/lis/webhooks/results` and `POST /api/v1/integrations/ris/webhooks/results`:

```json
{"event_id":"result-event-123","external_id":"partner-order-123","status":"RESULTED","result_ref":"opaque-document-reference"}
```

Both use `REQUESTED`, `ACCEPTED`, `IN_PROGRESS`, `RESULTED`, `CANCELLED`, `REJECTED`. Allowed transitions are unset → `REQUESTED`/`ACCEPTED`/`CANCELLED`/`REJECTED`; `REQUESTED` → `ACCEPTED`/`CANCELLED`/`REJECTED`; `ACCEPTED` → `IN_PROGRESS`/`RESULTED`/`CANCELLED`/`REJECTED`; `IN_PROGRESS` → `RESULTED`/`CANCELLED`/`REJECTED`. Same-state callbacks are accepted. Terminal states cannot move elsewhere. `RESULTED` requires a result/document reference. Conflicts with local result text or a previously linked, different reference return 409 and leave local results unchanged. Remote references are displayed as metadata only, not treated as local files or clickable URLs.

### PMS prescriptions and dispense status

An issued prescription enqueues `POST /prescriptions` with a minimal item contract:

```json
{"patient_ref":"opaque-reference","prescription_ref":"opaque-reference","items":[{"drug_code":"approved-drug-code","quantity":5,"instructions":"Take after meals."}]}
```

`medication_name` is the available source for `drug_code` in this schema; production use should map to an approved formulary code rather than assume free-text names are codes.

`POST /api/v1/integrations/pms/webhooks/dispense-status` accepts `SENT`, `ACCEPTED`, `REJECTED`, `PARTIALLY_DISPENSED`, `DISPENSED`, `CANCELLED`. `SENT` and `ACCEPTED` never set `dispensed_at`. Only a partial/full dispense callback or the existing super-admin in-house dispense action does. Transitions: unset → `SENT`/`ACCEPTED`/`REJECTED`/`CANCELLED`; `SENT` → `ACCEPTED`/`REJECTED`/`CANCELLED`; `ACCEPTED` → `PARTIALLY_DISPENSED`/`DISPENSED`/`REJECTED`/`CANCELLED`; `PARTIALLY_DISPENSED` → `DISPENSED`. Dispense and terminal states cannot regress. The local dispense action writes `DISPENSED` to partner status as well as `dispensed_at`.

### EMRS encounter and discharge exchange

Encounter completion enqueues `POST /encounters`; discharge enqueues `POST /discharge-summaries`. Patient and encounter references are stable UUIDs in `integration_reference_maps`, scoped to each partner and unique for the resource and partner. Internal database IDs are never emitted. Payloads contain only opaque references, encounter type/timestamps, and an ICD diagnosis code when available and disclosed. Clinical narrative, final diagnosis text, instructions, and other clinical prose are not sent. Discharge exchange includes opaque patient/discharge references and a timestamp.

`POST /api/v1/integrations/emrs/webhooks/clinical-document` accepts:

```json
{"event_id":"document-event-123","encounter_ref":"partner-opaque-encounter-ref","document_ref":"partner-document-ref","status":"AVAILABLE"}
```

The encounter reference must resolve to an existing encounter mapping for the authenticated EMRS partner. Document metadata is deduplicated by partner and document reference in `integration_clinical_documents`; reuse of a reference for a different encounter returns 409 and is audited. The callback never overwrites local encounter notes or imports a remote file. Until a trusted document-access mechanism is agreed and validated, the reference is metadata only.

## Round 3 implemented partner workflows

### HRMS provider availability

Signed `PUT` or `POST /api/v1/integrations/hrms/providers/{externalId}/availability` accepts:

```json
{
  "event_id": "hrms-change-104",
  "provider_ref": "partner-opaque-provider-ref",
  "version": 104,
  "schedule": [{"day": 1, "start": "09:00", "end": "17:00"}],
  "unavailable": [{"starts_at": "2026-11-16T09:00:00+08:00", "ends_at": "2026-11-16T17:00:00+08:00", "reason_code": "SICK_LEAVE"}]
}
```

`day` uses 0–6 (Sunday–Saturday). References resolve through partner-scoped `integration_reference_maps`; no local provider IDs or employee records are exposed. A provider-scoped source version is stored in `integration_provider_versions`; equal or older versions return 409. The schedule list is synchronized against HRMS-owned rows in `provider_schedules`; manually maintained schedule rows are retained. Each unavailable period calls the same `AppointmentReassignmentService` used for manual reports, including slot blocking, appointment review, patient notification, reassignment suggestions, and staff waitlisting. Appointments are never directly deleted. Applied and rejected events are written to `integration_logs`. No salary, address, employee identifier, or HR record is imported.

### HADS aggregate analytics exports

Authorized reports users can queue an export from the existing Reports page. Scheduled jobs can invoke `php artisan hims:request-hads-export {dataset} {from} {to} {format}`. Datasets are limited to `appointments`, `encounters`, `triage_levels`, `er_visits`, `bed_occupancy`, and `billing_totals`; formats are `csv`, `pdf`, `txt`, and `xls`. The payload contains the dataset, date range, format, row count, and a base64-encoded rendering of aggregate rows only. Rows contain only dates, categories/statuses, aggregate counts/totals, and—in bed occupancy—the current snapshot time. `triage_levels` includes `Severe` and `Not Severe`. There are no patient identifiers, names, contact details, or free-text clinical notes.

Example request (the `content` field is encoded in the queued message):

```json
{"export_id":"opaque-export-reference","dataset":"triage_levels","from":"2026-11-01","to":"2026-11-14","format":"csv","row_count":5,"content_encoding":"base64","content":"..."}
```

Local status is `QUEUED`, `PROCESSING`, `READY`, `FAILED`, or `EXPIRED`. Signed `POST /api/v1/integrations/hads/webhooks/export-status` accepts `QUEUED`, `PROCESSING`, `READY`, `FAILED`, and `EXPIRED`; valid transitions are `QUEUED` → `PROCESSING`/`READY`/`FAILED`/`EXPIRED`, `PROCESSING` → `READY`/`FAILED`/`EXPIRED`, and `READY` → `EXPIRED`. Invalid transitions return 409. Each request is audited in `audit_logs` and `integration_disclosures`; the disclosure has a null patient subject and aggregate-only field names. Snapshots expire after `INTEGRATION_EXPORT_RETENTION_DAYS` (30 by default) and are cleared by the daily scheduler.

### SAS account status, role mapping, and audit feed

Signed `POST /api/v1/integrations/sas/webhooks/account-status` accepts `ACTIVE`, `SUSPENDED`, or `DISABLED` and resolves the opaque external reference to an existing user. Suspension/disabling sets the existing `is_active` flag and revokes Sanctum tokens. Only an account suspended by SAS can be reactivated by SAS; SAS-disabled accounts and locally disabled accounts cannot be reactivated by it. Local super-admin account controls retain authority, and SAS cannot suspend local admin/super-admin accounts.

Signed `PUT /api/v1/integrations/sas/users/{externalId}` maps an existing account to an explicitly configured role only. The default allow-list is `doctor`, `nurse`, and `registration`; user creation, `admin`, `super-admin`, and arbitrary role names are rejected. Role and account changes are audited; the local active-user middleware remains authoritative.

The shared `AuditLogService` queues a minimized `POST /audit-events` feed for login failures, OTP lockouts, local role changes, and exports. The payload is limited to `event_type`, opaque `actor_ref` (nullable for unknown actors), timestamp, and outcome. It carries no user or patient details.

### VRDS patient transport dispatch

The hospital is the requester and local source of truth. `transport_requests` and `transport_request_histories` store local requests and status history; VRDS does not provide driver, vehicle-fleet, fuel, or route records. A request may originate from an ER visit or admission. ER priority is derived from the latest triage severity/level; admission priority is selected when requesting transport.

Outbound requests use the shared outbox, retry policy, and `Idempotency-Key` header:

`POST /dispatch-requests`

```json
{
  "request_ref": "opaque-uuid",
  "priority": "URGENT",
  "pickup_zone": "ER",
  "destination_zone": "WARD",
  "needs": {"stretcher_required": true, "oxygen_support": false, "wheelchair": false},
  "passenger_count": 1
}
```

No patient name/reference, contact details, diagnosis, address, or free text is included. Each send attempt is recorded in `integration_disclosures` with a null patient subject, purpose `patient transport dispatch`, and the fields shared. A local cancellation with a known partner ID queues `POST /dispatch-requests/{external_id}/cancel` with the opaque `request_ref`; cancelling before a partner ID is returned suppresses the pending dispatch. Both request and cancellation deliveries reuse the outbox retry/dead-letter behavior.

Configured zone codes are `ER` (Emergency Department), `WARD` (Inpatient Ward), `MAIN_ENTRANCE` (Main Entrance), and `OUTSIDE` (outside address zone). The user selects only these codes; an outside street address is not collected or sent.

Signed `POST /api/v1/integrations/vrds/webhooks/dispatch-status` accepts:

```json
{"event_id":"dispatch-event-123","external_id":"vrds-request-123","status":"DISPATCHED","vehicle_ref":"vehicle-opaque-ref","eta_at":"2026-11-18T13:30:00+08:00"}
```

Statuses are `REQUESTED`, `ACCEPTED`, `DISPATCHED`, `ARRIVED`, `COMPLETED`, and `CANCELLED`. The allowed transitions are:

| From | To |
|---|---|
| unset | `REQUESTED`, `ACCEPTED` |
| `REQUESTED` | `ACCEPTED`, `CANCELLED` |
| `ACCEPTED` | `DISPATCHED`, `CANCELLED` |
| `DISPATCHED` | `ARRIVED`, `CANCELLED` |
| `ARRIVED` | `COMPLETED` |
| `COMPLETED`, `CANCELLED` | none |

Same-state callbacks are idempotent; invalid transitions are audited and return 409, unknown external IDs return 404, and a replayed event returns its stored response. Only the VRDS partner key with `dispatch-status` ability and a valid webhook signature can post callbacks; human tokens/sessions are rejected. The requester receives a database notification on dispatch/arrival; emergency requests also notify active nurse-role accounts. Notification text contains no patient data. Mock mode returns a synthetic external ID and simulates accepted, dispatched, arrived, and completed states.

The ER visit and admission detail pages show transport status to doctor, nurse, registration, and super-admin under the corresponding existing view permission. The request/cancel controls require `request-patient-transport`, granted to nurse, registration, and super-admin. API listing/showing uses the same source-specific view permissions; API creation/cancellation uses the same creation/cancellation policy. Patient and admin users are denied on both web and API surfaces.

### Round 3 access matrix

| Surface | Access |
|---|---|
| HRMS availability, HADS export-status, SAS account-status, and SAS user-mapping API routes | Only the matching signed partner identity with its specific ability; all six human roles are rejected |
| VRDS dispatch-status webhook | Only the signed VRDS partner identity with `dispatch-status`; all six human roles are rejected |
| Reports page and HADS export request | `nurse`, `registration`, `admin`, and `super-admin`, subject to existing `view-reports` permission and recent password confirmation for export requests |
| Admin integrations page and read-only API | `admin` and `super-admin` with `view-integration-logs` |
| Integration-delivery retry | `super-admin` only with `retry-integration-delivery` |
| ER visit page/status and transport list/show API | `doctor`, `nurse`, `registration`, `super-admin` with `view-er`; admin and patient denied |
| Admission page/status and transport list/show API | `doctor`, `nurse`, `registration`, `super-admin` with `view-admissions`; admin and patient denied |
| Transport create/cancel controls and web/API routes | `nurse`, `registration`, `super-admin` with `request-patient-transport` and the corresponding source view permission; doctor/admin/patient denied |

The narrow `request-patient-transport` permission does not add a top-level navigation module.

## Admin utility and permissions

The read-only Integration deliveries page is an admin utility linked within SPRS's existing admin/navigation area, not a new module. `view-integration-logs` is granted to `admin` and `super-admin`; `manage-integrations` and `retry-integration-delivery` are granted only to `super-admin`. The matching API read endpoint follows the same view permission. Retry is super-admin only. The five other roles receive no integration administration permissions.

The application role name for front-desk/staff is `registration`; a generic `staff` role is not introduced.

## Runtime configuration

Add these values in HostForge's **Environment Variables** tab; do not create or upload a production `.env` file:

| Variable | Purpose |
|---|---|
| `INTEGRATION_MODE` | `mock` (default) or `live` |
| `INTEGRATION_WEBHOOK_TOLERANCE_SECONDS` | Allowed webhook clock skew (default 300) |
| `INTEGRATION_MAX_ATTEMPTS` | Maximum automatic outbox attempts (default 8) |
| `INTEGRATION_EXPORT_RETENTION_DAYS` | HADS export snapshot retention (default 30 days) |
| `INTEGRATION_{BDMS,HICS,LIS,RIS,PMS,EMRS,HRMS,HADS,SAS,VRDS}_API_KEY` | High-entropy inbound partner keys |
| `INTEGRATION_{BDMS,HICS,LIS,RIS,PMS,EMRS,HRMS,HADS,SAS,VRDS}_WEBHOOK_SECRET` | High-entropy HMAC secrets |
| `INTEGRATION_{BDMS,HICS,LIS,RIS,PMS,EMRS,HRMS,HADS,SAS,VRDS}_BASE_URL` | Approved partner API base URLs (live mode) |
| `INTEGRATION_{BDMS,HICS,LIS,RIS,PMS,EMRS,HRMS,HADS,SAS,VRDS}_OUTBOUND_TOKEN` | Partner-issued outbound bearer credentials (live mode) |
| `{BDMS,HICS,LIS,RIS,PMS,EMRS,HRMS,HADS,SAS}_SHARING_CONSENT_VERSION` | Consent notice versions, only changed after approval |
| `VRDS_SHARING_CONSENT_VERSION` | VRDS transport notice version; no patient-level consent gate applies to the identifier-free payload |

`QUEUE_CONNECTION=database` is already the repository's deployment recommendation. Use the existing HostForge queue worker; this feature does not require another service. Configure outbound allowlisting/TLS and time synchronization on the host as operational prerequisites.

## Production rollout

For a partner display-name refresh only, after deploying the application code, run only this command; it updates the `name` for each existing partner by unique code and leaves credentials, abilities, mode, and `last_used_at` unchanged. No migration or other seeder is needed:

```powershell
php artisan db:seed --class="Database\Seeders\IntegrationPartnerSeeder" --force
```

For a full integration rollout, back up the database and verify the maintenance/rollback window before deploying. After the application code is deployed and the environment variables have been set, run:

```powershell
php artisan optimize:clear
php artisan migrate --force
php artisan db:seed --class="Database\Seeders\ReferenceDataSeeder" --force
php artisan db:seed --class="Database\Seeders\RbacReconciliationSeeder" --force
php artisan db:seed --class="Database\Seeders\AdminRoleSeeder" --force
php artisan db:seed --class="Database\Seeders\CareRequestRbacSeeder" --force
php artisan db:seed --class="Database\Seeders\IntegrationPartnerSeeder" --force
php artisan db:seed --class="Database\Seeders\IntegrationRbacSeeder" --force
php artisan config:cache
php artisan queue:restart
```

Do not run plain `php artisan db:seed` or `DatabaseSeeder` in production. The explicit ordered classes above cover the shared reference/RBAC foundation, Admin's idempotent removal of only `view-er` and `view-admissions`, care-request RBAC, and all integration partner/RBAC configuration. These seeders are repeat-safe: they use `firstOrCreate`/`updateOrCreate`, additive `syncWithoutDetaching`, or a targeted detach of only the two denied Admin permissions. For existing integration partners, the partner seeder updates only the display name; newly created partners receive the configured initial settings. The migrations guard table creation. Configure the host scheduler to run `php artisan schedule:run` each minute; use the `hims:request-hads-export` command for a recurring approved dataset. Review migration status and seeded partner configuration before enabling live mode. Deploy with `INTEGRATION_MODE=mock` first; switch to `live` only after a signed test callback and consent review.

## Integration status summary

| Partner | Purpose | Status |
|---|---|---|
| Billing and Discharge Management System (BDMS) | Billing and payment status | Implemented (mock mode) |
| HMO and Insurance Claims System (HICS) | Insurance and LOA | Implemented (mock mode) |
| Laboratory Information System (LIS) | Laboratory orders and results | Implemented (mock mode) |
| Radiology and Imaging System (RIS) | Imaging orders and results | Implemented (mock mode) |
| Pharmacy Management System (PMS) | Prescription and dispense status | Implemented (mock mode) |
| Electronic Medical Records System (EMRS) | Clinical-record exchange | Implemented (mock mode) |
| Human Resource Management System (HRMS) | Provider availability and leave | Implemented (mock mode) |
| Healthcare Analytics and Dashboard System (HADS) | Aggregate analytics exports | Implemented (mock mode) |
| Security and Administration System (SAS) | Account status, role mapping, audit events | Implemented (mock mode) |
| Vehicle Reservation and Dispatch System (VRDS) | Patient transport dispatch/status | Implemented (mock mode) |
| SORS | Surgical and OR scheduling | Contract-only |
| DNMS | Diet and nutrition management | Contract-only |

The partner summary contains 12 codes: 10 implemented in mock mode and 2 contract-only (SORS and DNMS).

## Limitations and live-mode prerequisites

- Live partner endpoints, credentials, signatures, retry behavior, external catalog mappings, network/TLS policy, and partner responses were not verified. Keep `INTEGRATION_MODE=mock` until each partner contract and security review is complete.
- HRMS schedule day values are 0–6; provider mappings must be provisioned by trusted local setup before inbound sync. Schedule updates affect HRMS-owned schedule rows only. Existing appointment coverage uses the standard reassignment workflow for the explicit unavailable periods.
- Bed occupancy is a current snapshot because the application does not maintain historical bed-state intervals. HADS `row_count` is the number of aggregate rows, not the number of patient records.
- HADS local snapshots are aggregate-only and expire through the scheduler. Confirm the HostForge scheduler is active and agree any HADS-specific retention requirements before live use.
- SAS external references must be mapped by trusted local setup. The configured role allow-list and protected local account behavior require partner security review before live provisioning.
- Existing LIS/RIS catalog and PMS formulary limitations and the EMRS remote-document access limitation remain as noted above; no live clinical document fetching is enabled.
- VRDS live endpoints, credentials, signing details, response behavior, and nurse on-duty targeting must be confirmed before live operation; mock mode is the default.

## Contract-only proposals for later rounds (not implemented)

The following are design placeholders only. No endpoint, model, event, or permission for these partners is created in Round 3.

### SORS

Proposed procedure/OR request and schedule-status exchange with opaque patient/request references, procedure code, urgency, time, and status. Statuses and cancellation ownership require partner agreement.

### DNMS

Proposed diet request and care-plan status exchange with only explicitly approved diet/allergy fields and opaque patient/encounter references. This requires a separate data model and clinician review policy.

