# Appointment approval protocol

## Who decides

Pending appointment decisions are available to Registration, Nurse, Admin, and Super Admin. The shared `AppointmentPolicy::decide` policy enforces that role set for both the web and API decision routes. The decision request requires `confirm` or `reject` and a reason.

## States and automatic approval

- Patient online requests are `PENDING` when `APPOINTMENT_REQUIRES_APPROVAL=true` (the default).
- With `APPOINTMENT_REQUIRES_APPROVAL=false`, patient online requests are created as `CONFIRMED`.
- Staff-created online requests follow that same setting. Authorized staff walk-in and phone bookings are confirmed directly.
- A decision changes a pending request to `CONFIRMED` or `REJECTED`; confirmation and rejection are audited and the patient is notified.

The setting is read from the `APPOINTMENT_REQUIRES_APPROVAL` environment variable via `config/appointments.php`. Existing appointment data is not changed by changing the setting.

## External payer integrations

HICS letter-of-authorization status is tracked separately from the internal appointment decision. For an insured admission or outpatient care request with a coverage code and requested amount, HICS may return `PENDING`, `APPROVED`, `DENIED`, `EXPIRED`, or `CANCELLED`; only an HICS callback may change the external LOA state. An LOA result does not approve or reject an appointment, admission, or outpatient care request and does not change the existing human role allowed to make those decisions.

The partner request contains opaque patient and admission/request references, coverage code, and requested amount. It is sent only after current privacy and HICS-sharing consent pass the integration disclosure gate. Callback authentication uses the partner API key plus a timestamped HMAC-SHA256 signature and event ID for replay protection. See [INTEGRATION_ARCHITECTURE.md](./INTEGRATION_ARCHITECTURE.md) for request/response shapes, status transitions, and rollout controls.
