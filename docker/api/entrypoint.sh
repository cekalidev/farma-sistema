#!/bin/sh
# Entrada de la imagen de la API.
#
#   serve (por defecto)  migraciones → semilla demo opcional → cachés → PHP-FPM + Nginx.
#   cualquier otro       se ejecuta tal cual (p. ej. `php artisan schedule:work` en el scheduler).
#
# PHP-FPM y Nginx corren como dos hijos de este script, sin supervisord: si uno muere, se para
# el otro y el contenedor termina con error para que el orquestador lo reinicie. tini es PID 1
# (reenvía señales y recoge zombis).
set -eu

cd /var/www/html

if [ "${1:-serve}" != "serve" ]; then
    exec "$@"
fi

PORT="${PORT:-8000}"

echo "[entrypoint] migraciones"
php artisan migrate --force --no-interaction

# Semilla de demostración (datos sintéticos): solo si SEED_DEMO=true y la tabla users está vacía.
# SEED_DEMO=true es la autorización explícita que exige la guarda de producción de los seeders.
if [ "${SEED_DEMO:-false}" = "true" ]; then
    usuarios="$(php docker/api/contar-usuarios.php)"
    if [ "$usuarios" = "0" ]; then
        echo "[entrypoint] semilla de demostración"
        FARMA_PERMITIR_SEMILLA=true php artisan db:seed --force --no-interaction
    else
        echo "[entrypoint] semilla omitida: ya hay usuarios"
    fi
fi

# Sin APP_KEY: clave efímera (cambia en cada arranque; solo cifrado/firmas de Laravel, los tokens
# de Sanctum se guardan con hash en la BD y no dependen de ella). En producción, fijarla.
if [ -z "${APP_KEY:-}" ]; then
    APP_KEY="$(php artisan key:generate --show --no-interaction)"
    export APP_KEY
    echo "[entrypoint] AVISO: APP_KEY vacía; se usa una clave efímera generada en este arranque" >&2
fi

echo "[entrypoint] cachés de configuración y rutas"
php artisan config:cache --no-interaction
php artisan route:cache --no-interaction

sed "s/\${PORT}/${PORT}/g" /etc/nginx/farma.conf.template > /tmp/nginx.conf

php-fpm --nodaemonize &
fpm=$!
nginx -e /dev/stderr -c /tmp/nginx.conf -g 'daemon off;' &
web=$!

# QUIT = apagado ordenado en ambos: Nginx termina las peticiones en curso y FPM espera a sus workers.
parar() {
    kill -QUIT "$web" "$fpm" 2>/dev/null || true
    wait "$web" "$fpm" 2>/dev/null || true
    exit 0
}
trap parar TERM INT

echo "[entrypoint] escuchando en el puerto ${PORT}"

# Si cualquiera de los dos procesos termina, se para el otro y se sale con error.
while kill -0 "$fpm" 2>/dev/null && kill -0 "$web" 2>/dev/null; do
    sleep 2
done
echo "[entrypoint] un proceso terminó; parando el contenedor" >&2
kill -QUIT "$web" "$fpm" 2>/dev/null || true
wait 2>/dev/null || true
exit 1
