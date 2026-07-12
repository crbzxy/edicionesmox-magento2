#!/usr/bin/env bash
# Diagnóstico rápido del entorno (make doctor). Solo lee; nunca imprime secretos.
# Cada check es independiente: un FAIL no detiene los demás.
set -u

FAILS=0
ok()   { printf '  \033[32mOK\033[0m   %s\n' "$1"; }
fail() { printf '  \033[31mFAIL\033[0m %s\n' "$1"; FAILS=$((FAILS + 1)); }

echo "== contenedores =="
docker compose ps --format 'table {{.Name}}\t{{.Status}}'

echo
echo "== conectividad =="
docker compose exec -T db healthcheck.sh --connect --innodb_initialized >/dev/null 2>&1 \
    && ok "mariadb responde" || fail "mariadb no responde"
docker compose exec -T redis redis-cli ping 2>/dev/null | grep -q PONG \
    && ok "redis responde" || fail "redis no responde"
docker compose exec -T php-fpm curl -fsS http://opensearch:9200/_cluster/health >/dev/null 2>&1 \
    && ok "opensearch responde" || fail "opensearch no responde"
docker compose exec -T php-fpm curl -fsS -o /dev/null http://nginx/ \
    && ok "nginx responde" || fail "nginx no responde (¿Magento instalado?)"

echo
echo "== magento =="
if docker compose exec -T php-fpm test -f app/etc/env.php; then
    ok "app/etc/env.php presente"
else
    fail "app/etc/env.php ausente — ejecuta: make setup-install"
fi

if docker compose exec -T php-fpm test -f vendor/autoload.php; then
    ok "vendor/ presente"
else
    fail "vendor/ vacío (¿volumen borrado?) — ejecuta: make composer-install"
fi

MODE=$(docker compose exec -T php-fpm bin/magento deploy:mode:show 2>/dev/null)
if [ -n "$MODE" ]; then
    ok "bin/magento responde — $MODE"
else
    fail "bin/magento no responde"
fi

docker compose exec -T php-fpm test -f generated/code/Magento/Framework/App/Http/Interceptor.php \
    && ok "generated/ compilado" || fail "generated/ vacío — ejecuta: make compile"

if docker compose exec -T php-fpm bash -c '[ "$(find pub/static/frontend/EdicionesMox -type f 2>/dev/null | wc -l)" -ge 100 ]'; then
    ok "pub/static con estáticos del tema"
else
    fail "pub/static (EdicionesMox) casi vacío — ejecuta: make theme-deploy (o make perf-setup)"
fi

ROOT_FILES=$(docker compose exec -T php-fpm bash -c 'find var generated pub/static -user root -type f 2>/dev/null | head -5 | wc -l')
if [ "${ROOT_FILES:-0}" -eq 0 ] 2>/dev/null; then
    ok "sin archivos de root en var/generated/pub-static"
else
    fail "hay archivos de root en var/generated/pub-static — borra los markers .chowned y reinicia (ver docker/php/docker-entrypoint.sh)"
fi

echo
echo "== indexadores y caches =="
docker compose exec -T php-fpm bin/magento indexer:status 2>/dev/null | grep -Ei 'reindex required|processing' \
    && echo "  (hay indexadores pendientes — el cron los procesa, o ejecuta indexer:reindex)" \
    || ok "indexadores al día"
docker compose exec -T php-fpm bin/magento cache:status 2>/dev/null | grep -q ': 0' \
    && echo "  (hay caches deshabilitadas — normal en developer mode si es intencional)" \
    || ok "todas las caches habilitadas"

echo
echo "== disco =="
docker system df 2>/dev/null | head -6

echo
if [ "$FAILS" -eq 0 ]; then
    echo "doctor: todo OK"
else
    echo "doctor: $FAILS problema(s) — revisa los FAIL de arriba"
    exit 1
fi
