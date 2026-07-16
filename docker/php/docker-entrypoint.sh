#!/bin/bash
set -e

# Volúmenes Docker se crean como root; php-fpm corre como www-data.
# Solo se corrige una vez por volumen (marker file) para no repetir un chown -R
# completo en cada arranque/restart. Nota: este entrypoint solo corre en
# php-fpm (y en `docker compose run php-fpm`); cron y cli lo sobreescriben en
# docker-compose.yml con su propio entrypoint bash, así que dependen de que
# php-fpm haya corregido los volúmenes primero.
# Si vuelven a aparecer errores de permisos en var/ (p.ej. algo escribió como
# root dentro del volumen), borra el marker y reinicia:
#   docker compose exec php-fpm rm var/.chowned generated/.chowned pub/static/.chowned vendor/.chowned lib/.chowned
#   docker compose restart php-fpm cron
for dir in var generated pub/static vendor lib; do
    marker="/var/www/html/$dir/.chowned"
    if [ -d "/var/www/html/$dir" ] && [ ! -f "$marker" ]; then
        chown -R www-data:www-data "/var/www/html/$dir"
        chmod -R ug+rwx "/var/www/html/$dir"
        touch "$marker"
        chown www-data:www-data "$marker"
    fi
done

# El caché de Composer también es un volumen (creado root) y composer corre
# como www-data desde el Makefile (make composer-install / install-oss).
if [ -d /var/www/.composer ] && [ ! -f /var/www/.composer/.chowned ]; then
    chown -R www-data:www-data /var/www/.composer
    touch /var/www/.composer/.chowned
fi

if [ ! -f /var/www/html/generated/code/Magento/Framework/App/Http/Interceptor.php ]; then
    echo "WARN: generated/ vacío — ejecuta: make compile" >&2
fi

# Tema activo del proyecto (perf-check usa el mismo path).
staticCount=$(find /var/www/html/pub/static/frontend/EdicionesMox/default/es_MX -type f 2>/dev/null | wc -l)
if [ "$staticCount" -lt 100 ]; then
    echo "WARN: pub/static casi vacío ($staticCount archivos) — ejecuta: make perf-setup" >&2
fi

if [ -f /var/www/html/bin/magento ]; then
    chmod +x /var/www/html/bin/magento
fi

exec docker-php-entrypoint "$@"
