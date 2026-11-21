# Deployment configuration

Use synthetic patient data for demos. Never copy secrets into this document, source control, screenshots, or support logs. Values below are safe examples/placeholders; replace only placeholders in the private deployment environment.

## HostForge single-container deployment

The repository includes a root Dockerfile for a `php:8.3-apache` runtime, a Node/Vite build stage, and Supervisor as the foreground process manager. Apache serves `/var/www/html/public`. The entrypoint reads the platform-provided `PORT`, creates `public/storage` if missing, configures Apache to listen on `0.0.0.0:$PORT`, optionally runs pending migrations when `RUN_MIGRATIONS=true`, and starts Supervisor. Migrations are not run by default; startup logs a warning while they are disabled, and an enabled migration failure prevents the container from starting. Enable this only for a serialized deployment with one instance, or leave it disabled and run migrations as a release task. The entrypoint also warns if production email OTP is enabled with `MAIL_MAILER=log`. The Docker health check requests `/up`; the PHP image installs and build-checks the application extensions. Docker is not assumed to expose persistent storage: clinical document and identity uploads use a private S3-compatible disk, while logs go to stderr and session/cache/queue storage use the managed MariaDB database. Provider photos still use the local public disk, and uploaded files, provider photos, and local logs can be lost on redeploy unless storage is persistent.

Set `DB_CONNECTION=mariadb`, `BROADCAST_CONNECTION=null`, `QUEUE_CONNECTION=database`, `SESSION_DRIVER=database`, `CACHE_STORE=database`, `FILESYSTEM_DISK=s3`, `LOG_CHANNEL=stderr`, `SESSION_LIFETIME=120`, `SESSION_ENCRYPT=true`, `SESSION_SECURE_COOKIE=true`, `INACTIVITY_ENABLED=true`, and `SESSION_IDLE_MINUTES=5`. The idle timeout is in minutes; session lifetime is also in minutes. Configure a private bucket with provider-side encryption at rest, private access policy, and the object-store endpoint, bucket, region, and credentials from HostForge's secret/environment facilities. The `league/flysystem-aws-s3-v3` package is required for the S3 disk. Keep Reverb off unless WebSocket hosting is separately configured; enabling it requires `BROADCAST_CONNECTION=reverb`, the `REVERB_*` settings, and a Reverb process/routed WebSocket endpoint.

### First-deploy checklist

1. Set production secrets and managed MariaDB/object-storage settings. Keep `APP_DEBUG=false`, set `APP_URL` to `https://patientaccess.djnrmhs.com`, and use `APP_TIMEZONE=Asia/Manila`. Keep `OTP_ENABLED=true` and configure a real mail transport; the `log` and `array` mailers are not suitable for OTP delivery. The entrypoint warns about `MAIL_MAILER=log`, but does not disable OTP.
2. Ask HostForge for the exact proxy source CIDRs and forwarded-scheme behavior. Until confirmed, do not guess `TRUSTED_PROXIES`. Laravel's `TrustProxies` is the only proxy-trust mechanism configured here; Apache `mod_remoteip` is intentionally not enabled. Incorrect or missing trust configuration behind an HTTPS-terminating proxy can make Laravel treat HTTPS requests as HTTP and cause a `ForceHttps` redirect loop.
3. Before public traffic, test HTTPS handling: the proxied `/login` request should return normally (not repeatedly redirect), HTTPS URLs should be generated, and the session cookie should be Secure. The existing tests cover trusted and untrusted proxy behavior, but not HostForge's actual CIDRs.
4. Keep exactly **ONE container or instance running the scheduler**. The scheduler is supervised in the same container as Apache and the queue worker. More than one scheduler can duplicate appointment reminders until scheduler `onOneServer()` protection using a shared cache lock is added and verified.
5. On a fresh server, from the project root and using a serialized release task—not concurrently on multiple instances—run this order:

   ```sh
   composer install --no-dev --optimize-autoloader --no-interaction
   php artisan key:generate
   php artisan migrate --force
   php artisan db:seed --class="Database\Seeders\ReferenceDataSeeder" --force
   php artisan db:seed --class="Database\Seeders\RbacReconciliationSeeder" --force
   php artisan db:seed --class="Database\Seeders\AdminRoleSeeder" --force
   php artisan db:seed --class="Database\Seeders\CareRequestRbacSeeder" --force
   php artisan db:seed --class="Database\Seeders\IntegrationPartnerSeeder" --force
   php artisan db:seed --class="Database\Seeders\IntegrationRbacSeeder" --force
   php artisan app:bootstrap-admin
   php artisan storage:link
   php artisan config:cache
   php artisan route:cache
   php artisan view:cache
   php artisan hims:preflight
   php artisan slots:generate
   ```

   Generate `APP_KEY` only when first setting up an environment; do not generate a new key during ordinary releases. `app:bootstrap-admin` reads `BOOTSTRAP_ADMIN_NAME`, `BOOTSTRAP_ADMIN_EMAIL`, and `BOOTSTRAP_ADMIN_PASSWORD` from the process environment only when no Super Admin exists. It creates a new account once, never changes an existing account or roles, and is safe to rerun; after successful bootstrap, remove `BOOTSTRAP_ADMIN_PASSWORD` from HostForge. It does not print or store the password in Laravel's config cache. Production must use the explicitly listed seeder classes; do not run plain `php artisan db:seed`/`DatabaseSeeder`, `HimsSeeder`, or `DemoDataSeeder`. These production seeders are idempotent and repeat-safe. `app:create-admin` remains an interactive legacy command and can update existing Super Admin credentials and roles; use the new bootstrap command instead. `hims:preflight` reads migration state from the database, so it must run after migrations. Run `slots:generate` after provider schedules have been configured; otherwise it may create no slots.

   For startup-managed migrations, set `RUN_MIGRATIONS=true` only for a serialized rollout with one instance; the entrypoint runs `php artisan migrate --force` and stops on failure. Otherwise leave it false and run that migration command as the release task before starting the new application version. Do not enable startup migrations simultaneously across replicas.
6. Confirm the platform health check uses `/up`, the Apache listener/vhost use the assigned `$PORT`, `pdo_mysql` is present in the built image, and the `resources/data/psgc_2026_q2.csv` dataset is packaged and readable. The address picker streams this bundled CSV and needs no PSGC seeder, dataset cache, cache-table migration, or warm-up command. Its API routes are still rate-limited at 60 requests per minute through Laravel's configured cache backend; keep that backend available for throttling independently of dataset loading.
7. Configure provider-managed database and object-storage backups, retention, and access controls; perform and record a restore test before any real patient data is stored. `DB_BACKUP_ENABLED=false` prevents the local `db:backup` task from being scheduled in production; it does not configure managed backups.
8. Do not use `hims:demo-accounts`, `app:create-demo-staff`, or `DemoDataSeeder` for production demo provisioning; those legacy paths include Super Admin accounts or unsafe shared-password behavior. If synthetic demo data is explicitly required, run `php artisan app:seed-demo --force` only with `DEMO_MODE=true` supplied through the deployment's secret/environment facility. This creates four non-admin `@example.com` accounts and marked synthetic data; provide distinct account secrets with `DEMO_REGISTRATION_PASSWORD`, `DEMO_DOCTOR_PASSWORD`, `DEMO_NURSE_PASSWORD`, and `DEMO_PATIENT_PASSWORD`, or securely save the generated passwords shown once. Existing account passwords are not reset. To remove the marked records, run `php artisan app:purge-demo --force` with `DEMO_MODE=true`; the command reports and refuses to delete when unmarked records depend on demo data, requires an additional interactive confirmation, and never deletes audit logs. Review the resulting records and blockers before proceeding.

   If synthetic appointment choices are specifically required, the optional provider seeder can be run after migrations and reference data are installed:

   ```sh
   php artisan db:seed --class='Database\Seeders\DemoProvidersAndSlotsSeeder' --force
   ```

   It creates only missing Cardiology, Pediatrics, and Orthopedics demo provider records, weekday schedules, and future appointment slots. Demo accounts use reserved `.invalid` email addresses and random, undisclosed passwords. The seeder is idempotent, does not delete or overwrite existing field values, and stops if a target department is inactive. Do not use these synthetic providers as real clinicians.

The startup command is one foreground `supervisord -n` process managing Apache, `php artisan queue:work --tries=3 --timeout=60`, and `php artisan schedule:work`. Reverb is not started. Run only one instance of this combined container because each instance runs the scheduler. Platform behavior for Dockerfile builds, proxy CIDRs, managed DB TLS, object storage, health check routing, and serialized release tasks must be confirmed with the selected host; they have not been independently verified.

The public pre-registration ticket and MFA enrollment QR codes are generated locally as SVG data URIs; no third-party QR image service is used by those flows.

### Patient-data readiness requirements

This deployment configuration alone is not approval to host real patient data. Before that, verify private encrypted object storage and access logs; provider-managed database and object-storage backup retention with successful restore exercises; HTTPS end-to-end and tested proxy trust; log/error-reporting redaction and restricted access; encryption at rest for clinical notes and any sensitive fields that do not already use Laravel encrypted casts; and append-only, tamper-resistant audit storage with monitoring and retention. Complete security, privacy, operational, and legal review for the intended deployment.

Before public access, add and test rate limits for web login, registration, password-reset, MFA challenge, and other public endpoints that currently lack explicit throttling. API and selected public routes already have route-level throttles, but that does not cover every authentication route.

## Cloudflare Tunnel demo

Set these values in the private `.env` used by the demo. Keep `SESSION_DOMAIN` blank so the browser receives a host-only cookie for the current tunnel URL. Any uploaded documents, provider photos, and local log files on an ephemeral filesystem can be lost on redeploy; use persistent object storage or a mounted volume for uploads and platform log collection for logs.

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_KEY=<generate privately with php artisan key:generate>
APP_TIMEZONE=Asia/Manila
APP_URL=https://example.trycloudflare.com
ASSET_URL=https://example.trycloudflare.com
TRUSTED_PROXIES=127.0.0.1,::1
SESSION_DRIVER=database
SESSION_ENCRYPT=true
SESSION_DOMAIN=
SESSION_SECURE_COOKIE=true
CORS_ALLOWED_ORIGINS=
QUEUE_CONNECTION=database
MAIL_MAILER=smtp
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_SCHEME=smtp
MAIL_USERNAME=demo-account@gmail.com
MAIL_PASSWORD=<Google App Password stored privately>
MAIL_FROM_ADDRESS=demo-account@gmail.com
MAIL_FROM_NAME="HIMS Demo"
ZOOM_ENABLED=false
ZOOM_ACCOUNT_ID=
ZOOM_CLIENT_ID=
ZOOM_CLIENT_SECRET=
```

`ASSET_URL` is optional when the app's asset URLs are already generated correctly from `APP_URL`; set it to the same HTTPS tunnel origin if compiled assets otherwise use the wrong host. An empty `CORS_ALLOWED_ORIGINS` grants no cross-origin access; same-origin browser requests do not need CORS permission. If a separate frontend origin is required, list its exact origin, including scheme and port if nonstandard, separated by commas.

The app trusts forwarded scheme/host headers only from configured proxies. The `127.0.0.1,::1` example is for a local tunnel agent forwarding to the local web server; never change it to `*`. Use the proxy source addresses actually observed by the PHP application.

When the tunnel URL changes, replace `APP_URL` and (if set) `ASSET_URL`; update `CORS_ALLOWED_ORIGINS` only if it names a different frontend origin. Keep `SESSION_DOMAIN` blank. Rebuild Laravel's cached configuration and restart long-lived PHP/queue processes so generated links, signed links, assets, and cookies use the new host. Existing browser cookies for the previous host will not transfer.

## Production VPS

These examples assume HTTPS terminates at a reverse proxy on the same VPS. If the PHP application is reached directly, use `TRUSTED_PROXIES=REMOTE_ADDR`. If another proxy/CDN is in front, configure only its current documented IP/CIDR ranges as seen by the app; do not trust arbitrary forwarded headers.

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_KEY=<generate privately with php artisan key:generate>
APP_TIMEZONE=Asia/Manila
APP_URL=https://hims.example.ph
ASSET_URL=
TRUSTED_PROXIES=127.0.0.1,::1
SESSION_DRIVER=database
SESSION_ENCRYPT=true
SESSION_DOMAIN=
SESSION_SECURE_COOKIE=true
CORS_ALLOWED_ORIGINS=
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=hims_production
DB_USERNAME=<least-privilege database user>
DB_PASSWORD=<database password stored privately>
QUEUE_CONNECTION=database
MAIL_MAILER=smtp
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_SCHEME=smtp
MAIL_USERNAME=hims-notifications@gmail.com
MAIL_PASSWORD=<Google App Password stored privately>
MAIL_FROM_ADDRESS=hims-notifications@gmail.com
MAIL_FROM_NAME="Hospital Information System"
ZOOM_ENABLED=false
ZOOM_ACCOUNT_ID=
ZOOM_CLIENT_ID=
ZOOM_CLIENT_SECRET=
```

For Gmail SMTP use `smtp.gmail.com`, port `587`, and `MAIL_SCHEME=smtp`; use a Google App Password, not the mailbox's regular password. `config/mail.php` accepts the legacy `MAIL_ENCRYPTION` fallback, but `MAIL_SCHEME` is the documented setting. Set `MAIL_FROM_ADDRESS` to an address permitted by the provider and `MAIL_FROM_NAME` to the hospital's approved sender name.

To enable Zoom, set `TELEHEALTH_PROVIDER=zoom`, `ZOOM_ENABLED=true`, and privately configure `ZOOM_ACCOUNT_ID`, `ZOOM_CLIENT_ID`, and `ZOOM_CLIENT_SECRET`. Without a configured Zoom provider, session creation and join fail closed with HTTP 503; the application does not silently switch to a demo room. For an explicitly opted-in demonstration only, set `TELEHEALTH_PROVIDER=jitsi_demo`; the interface identifies this as a demo that is not for real consultations. Zoom response bodies, tokens, client secrets, meeting passcodes, and join/start URLs are not logged. Meeting start URLs are returned only to the assigned Doctor or Super Admin; the patient view receives a protected join URL, and Nurse/Registration views receive neither meeting URL.

The key used for the local telehealth-room token must be generated and kept private. The session cookie is HTTPS-only when `SESSION_SECURE_COOKIE=true`; `ForceHttps` runs in production and recognizes the forwarded HTTPS scheme only after `TrustProxies` accepts the proxy source.

## Mail, queue, and scheduler processes

Run a worker for queued appointment reminders when `QUEUE_CONNECTION=database`:

```sh
php artisan queue:work --tries=3
```

Run the Laravel scheduler continuously for a demo:

```sh
php artisan schedule:work
```

For a VPS cron installation, invoke `php artisan schedule:run` every minute from the project directory, for example:

```cron
* * * * * cd /path/to/hims && php artisan schedule:run >> /dev/null 2>&1
```

The scheduled appointment-reminder command queues `AppointmentReminderNotification` jobs on the default queue. With the database queue, a worker must process them; exhausted delivery failures are logged with an HMAC recipient hash and exception class only. With the `sync` queue driver, SMTP delivery occurs inline while the reminder command runs; a transport failure is caught, logged safely, and does not mark the reminder as sent. Because reminder selection uses a narrow scheduled window, a failed send may need an operator-triggered retry if that window has passed.

Email-verification and password-reset notifications are synchronous Laravel notifications and do not implement `ShouldQueue`; they attempt SMTP delivery immediately with either `QUEUE_CONNECTION=database` or `sync`. A delivery failure returns a friendly message rather than a server error. The telehealth reminder is also synchronous and reports a retryable failure while leaving the session available. All of these failure paths log an operation name, an HMAC of the recipient address, and the exception class—never the address, message, transport response, or credentials.

Check SMTP without exposing credentials with:

```sh
php artisan hims:mail:test demo-account@gmail.com
```

It sends a plain-text message through the configured mailer and prints a success/failure result. Tests use `Mail::fake()`; no real email is needed for test runs.

## Patient discharge summaries

The patient portal's **Discharge summaries** navigation and `/api/v1/me/discharge-summaries` endpoints expose only the authenticated patient's summaries with `APPROVED` status, approval timestamp, and approver. The detail API returns 404 for another patient's summary or a draft. Audit entries record the summary resource ID and viewing user, without diagnosis, course, instructions, or billing amounts.

Care requests and patient privacy requests, including privacy-request exports, are web-only. Telehealth session join is also web-only; it is not an API join flow. API endpoints for other telehealth operations do not provide a substitute for the consent-checked web join experience.

Appointment rescheduling is represented by status history and the replacement appointment/slot; `RESCHEDULED` is not a persisted appointment status.
