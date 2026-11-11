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

No external Maxicare integration or payer-side approval protocol is implemented. Appointment approval in this application is the internal workflow described above.
