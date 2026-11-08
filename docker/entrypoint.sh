set -eu

PORT="${PORT:-8080}"
export PORT

case "$PORT" in
    ''|*[!0-9]*)
        echo "PORT must be an integer from 1 through 65535." >&2
        exit 1
        ;;
esac

if [ "$PORT" -lt 1 ] || [ "$PORT" -gt 65535 ]; then
    echo "PORT must be an integer from 1 through 65535." >&2
    exit 1
fi

if [ "${APP_ENV:-production}" = "production" ]; then
    mailer="$(printf '%s' "${MAIL_MAILER:-log}" | tr '[:upper:]' '[:lower:]')"
    otp_enabled="$(printf '%s' "${OTP_ENABLED:-true}" | tr '[:upper:]' '[:lower:]')"

    if [ "$mailer" = "log" ] && [ -n "$otp_enabled" ] \
        && [ "$otp_enabled" != "false" ] && [ "$otp_enabled" != "0" ] \
        && [ "$otp_enabled" != "off" ] && [ "$otp_enabled" != "no" ]; then
        echo "WARNING: MAIL_MAILER=log with email OTP enabled; users will not receive sign-in codes." >&2
    fi
fi

run_migrations="$(printf '%s' "${RUN_MIGRATIONS:-false}" | tr '[:upper:]' '[:lower:]')"
case "$run_migrations" in
    true|1|yes|on)
        echo "Applying pending database migrations..."
        php artisan migrate --force
        ;;
    false|0|no|off|'')
        echo "WARNING: Startup migrations are disabled; pending migrations will remain unapplied. Set RUN_MIGRATIONS=true for a serialized deployment." >&2
        ;;
    *)
        echo "RUN_MIGRATIONS must be a boolean value." >&2
        exit 1
        ;;
esac

if [ ! -e /var/www/html/public/storage ] && [ ! -L /var/www/html/public/storage ]; then
    ln -s /var/www/html/storage/app/public /var/www/html/public/storage
fi

printf 'Listen 0.0.0.0:%s\n' "$PORT" > /etc/apache2/ports.conf
sed "s/@PORT@/${PORT}/g" \
    /etc/apache2/sites-available/pacc.conf.template \
    > /etc/apache2/sites-available/pacc.conf
a2ensite pacc

exec /usr/bin/supervisord -n -c /etc/supervisor/supervisord.conf
