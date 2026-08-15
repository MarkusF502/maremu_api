#!/usr/bin/env sh
set -e

# Substitui a porta que o Apache escuta pela que o Render injeta em runtime
if [ -n "$PORT" ]; then
    sed -ri "s/Listen [0-9]+/Listen ${PORT}/" /etc/apache2/ports.conf
    sed -ri "s/:[0-9]+>/:${PORT}>/" /etc/apache2/sites-available/000-default.conf
fi

# Cache de config/views usando as env vars já injetadas pelo Render.
# route:cache fica de fora de propósito: routes/web.php tem uma rota com
# closure (Route::get('/', function () {...})) e o Artisan recusa cachear
# rotas com closure ("Unable to prepare route [/] for serialization. Uses
# Closure."), derrubando o container no start. O ganho de performance de
# cachear rotas é irrelevante pro volume de tráfego desta API.
php artisan config:cache
php artisan view:cache

# Migrations em produção (RUN_MIGRATIONS=false para desativar em algum deploy pontual)
if [ "${RUN_MIGRATIONS:-true}" = "true" ]; then
    php artisan migrate --force
fi

exec "$@"
