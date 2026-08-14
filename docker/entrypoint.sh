#!/usr/bin/env sh
set -e

# Substitui a porta que o Apache escuta pela que o Render injeta em runtime
if [ -n "$PORT" ]; then
    sed -ri "s/Listen [0-9]+/Listen ${PORT}/" /etc/apache2/ports.conf
    sed -ri "s/:[0-9]+>/:${PORT}>/" /etc/apache2/sites-available/000-default.conf
fi

# Cache de config/rotas/views usando as env vars já injetadas pelo Render
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Migrations em produção (RUN_MIGRATIONS=false para desativar em algum deploy pontual)
if [ "${RUN_MIGRATIONS:-true}" = "true" ]; then
    php artisan migrate --force
fi

exec "$@"
