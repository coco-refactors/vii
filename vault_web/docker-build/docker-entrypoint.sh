#!/bin/sh
set -eu

# Railway injects PORT and routes the service's primary domain to it; the admin UI
# gets that one. A second HTTP listener is detected separately and given its own
# domain. With no PORT (plain docker compose) the historical 80/89 split is kept.
if [ -n "${PORT:-}" ]; then
	BACKEND_PORT="$PORT"
	FRONTEND_PORT="${API_PORT:-8080}"
else
	BACKEND_PORT=89
	FRONTEND_PORT="${API_PORT:-80}"
fi

if [ "$BACKEND_PORT" = "$FRONTEND_PORT" ]; then
	echo "vault-entrypoint: backend and frontend cannot share port $BACKEND_PORT; set API_PORT to something else" >&2
	exit 1
fi

printf 'Listen %s\nListen %s\n' "$BACKEND_PORT" "$FRONTEND_PORT" > /etc/apache2/ports.conf

sed -e "s/__BACKEND_PORT__/${BACKEND_PORT}/g" \
	-e "s/__FRONTEND_PORT__/${FRONTEND_PORT}/g" \
	/etc/apache2/001-vault.conf.tpl > /etc/apache2/sites-enabled/001-vault.conf

# A freshly attached volume mounts empty and root-owned. Both upload paths report
# failure by return value rather than throwing, so bad permissions here are silent.
mkdir -p /var/www/html/common/web/uploads
chown www-data:www-data /var/www/html/common/web/uploads

exec docker-php-entrypoint "$@"
