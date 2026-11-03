# Demo accounts — local use only

These shared credentials are for local demonstrations and testing only. **Do not use them in production or with real patient data.**

| Role | Email | Demo password |
|---|---|---|
| Patient | `patient@coor.test` | `Password123!` |
| Doctor | `doctor@coor.test` | `Password123!` |
| Nurse | `nurse@coor.test` | `Password123!` |
| Registration | `registration@coor.test` | `Password123!` |
| Super-admin | `super-admin@coor.test` | `Password123!` |
| Super-admin | `super.admin@coor.test` | `Password123!` |
| Super-admin | `hospital.admin@coor.test` | `Password123!` |

The separate `DemoDoctorsSeeder` adds these doctor accounts, each with the same demo-only password, `DemoDoctors!234`:

- `doctor1@example.test`
- `doctor2@example.test`
- `doctor3@example.test`
- `doctor4@example.test`
- `doctor5@example.test`
- `doctor6@example.test`

Seed the additional doctors after the normal reference/demo seeding, then generate slots:

```text
php artisan db:seed --class=Database\Seeders\DemoDoctorsSeeder
php artisan slots:generate --days=30
```

Use `php artisan slots:generate --days=60` to generate for a different number of upcoming days. The command begins tomorrow; scheduled breaks and unavailable dates are respected, existing slots are retained, and booked slots are not overwritten.
