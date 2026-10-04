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

printf 'Listen 0.0.0.0:%s\n' "$PORT" > /etc/apache2/ports.conf
sed "s/@PORT@/${PORT}/g" \
    /etc/apache2/sites-available/pacc.conf.template \
    > /etc/apache2/sites-available/pacc.conf
a2ensite pacc

exec /usr/bin/supervisord -n -c /etc/supervisor/supervisord.conf
