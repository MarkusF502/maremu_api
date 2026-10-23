#!/usr/bin/env sh
set -e

# Se a plataforma injetar $PORT, o Apache passa a escutar nela. O Container Apps
# não injeta: sem $PORT o Apache fica na 80, que é o --target-port do ingress.
if [ -n "$PORT" ]; then
    sed -ri "s/Listen [0-9]+/Listen ${PORT}/" /etc/apache2/ports.conf
    sed -ri "s/:[0-9]+>/:${PORT}>/" /etc/apache2/sites-available/000-default.conf
fi

# Cache de config/views usando as env vars já injetadas pelo Container Apps.
# route:cache fica de fora de propósito: routes/web.php tem uma rota com
# closure (Route::get('/', function () {...})) e o Artisan recusa cachear
# rotas com closure ("Unable to prepare route [/] for serialization. Uses
# Closure."), derrubando o container no start. O ganho de performance de
# cachear rotas é irrelevante pro volume de tráfego desta API.
php artisan config:cache
php artisan view:cache

# Migrations em produção (RUN_MIGRATIONS=false para desativar em algum deploy pontual).
# --isolated: com mais de uma réplica subindo junto, só uma roda as migrations (lock via cache).
if [ "${RUN_MIGRATIONS:-true}" = "true" ]; then
    php artisan migrate --force --isolated
fi

exec "$@"
