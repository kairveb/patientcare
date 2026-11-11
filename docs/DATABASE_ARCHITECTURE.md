# Database architecture

## Environment-driven connection

Both the local Laragon development environment and the HostForge deployment use the same Laravel codebase. The default connection and connection details are read from `DB_CONNECTION`, `DB_URL`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, `DB_SOCKET`, `DB_CHARSET`, `DB_COLLATION`, and optional `MYSQL_ATTR_SSL_CA` in `config/database.php`. Set `DB_CONNECTION=mysql` for Laragon MySQL or `DB_CONNECTION=mariadb` for HostForge MariaDB, then set the host, port, database, username, and password for that environment. No application query should contain environment-specific host or database credentials.

The configured and deployment-targeted engines are MySQL and MariaDB. Laravel's configuration file also contains framework connection templates for SQLite, PostgreSQL, and SQL Server; this project does not claim that its full migrations, queries, backup process, or production operation have been validated on those engines.

## Portability

Application queries should use Eloquent/query-builder operations that work on both MySQL and MariaDB. The telehealth patient-name search uses separate first-name/last-name token predicates rather than a vendor-specific `CONCAT` expression.

## Backups

The `db:backup` command uses `mysqldump` with the active MySQL/MariaDB connection settings and writes a database dump to the configured backup path. The scheduler registers a daily 02:00 run when database backup scheduling is enabled. On managed HostForge MariaDB, prefer and verify the host's managed backup/snapshot service; a local application `mysqldump` process is not a substitute for provider backups, and the host must provide a compatible client binary and writable persistent destination before enabling it. Backups and restore tests should follow the hospital's retention and recovery policy.
