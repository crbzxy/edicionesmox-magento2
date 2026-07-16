# Interfaz operativa del entorno. Ejecutar desde Git Bash o WSL (Windows no
# trae `make` nativo; ver README > Requisitos).
.PHONY: help up down build shell logs restart ps status config doctor \
	cache-flush clean-theme-css opcache-reload theme-deploy perf-setup \
	upgrade compile perf-check diff-luma-base \
	check-admin-env setup-install admin-create install-oss composer-install

.DEFAULT_GOAL := help

# Carga las variables de .env (docker compose también lo lee por su cuenta;
# aquí lo necesitamos para setup-install / admin-create).
-include .env

# Punto único hacia el contenedor de PHP y bin/magento.
# bin/magento y composer corren como www-data (mismo usuario que los workers
# de php-fpm): así los archivos que generan en var/generated/pub-static nacen
# con el dueño correcto y los chown -R posteriores dejan de ser necesarios.
# PHP_EXEC (root) queda para operaciones que lo requieren (chown, kill, rm).
PHP_EXEC := docker compose exec php-fpm
PHP_WWW  := docker compose exec -u www-data php-fpm
MAGENTO  := $(PHP_WWW) bin/magento

##@ Ayuda

help: ## Muestra esta ayuda
	@awk 'BEGIN {FS = ":.*##"; printf "\nUso: make \033[36m<target>\033[0m\n"} \
		/^[a-zA-Z_-]+:.*?##/ { printf "  \033[36m%-18s\033[0m %s\n", $$1, $$2 } \
		/^##@/ { printf "\n\033[1m%s\033[0m\n", substr($$0, 5) }' $(MAKEFILE_LIST)

##@ Entorno

up: ## Levanta todos los contenedores en segundo plano
	docker compose up -d

down: ## Detiene y elimina los contenedores (conserva volúmenes/datos)
	docker compose down

build: ## Reconstruye la imagen de php-fpm (después de tocar el Dockerfile)
	docker compose build php-fpm

shell: ## Entra al contenedor de PHP como shell interactivo
	$(PHP_EXEC) bash

logs: ## Sigue los logs de nginx y php-fpm
	docker compose logs -f nginx php-fpm

restart: ## Reinicia todos los contenedores
	docker compose restart

ps: ## Estado de los contenedores
	docker compose ps

status: ps ## Alias documentado de `ps`

config: ## Muestra la config de compose ya interpolada (valida .env)
	docker compose config

##@ Diagnóstico

doctor: ## Chequeo rápido: contenedores, DB, Redis, OpenSearch, nginx, Magento
	@bash docker/scripts/doctor.sh

# Diagnóstico rápido de rendimiento (developer mode en Windows/Docker).
perf-check: ## Diagnóstico rápido de rendimiento (generated, static, tiempos)
	$(PHP_EXEC) bash -c '\
		echo "=== Magento perf-check ==="; \
		bin/magento deploy:mode:show; \
		test -f generated/code/Magento/Framework/App/Http/Interceptor.php && echo "generated: OK" || echo "generated: FAIL — ejecuta: make compile"; \
		STATIC=$$(find pub/static/frontend/EdicionesMox/default/es_MX -type f 2>/dev/null | wc -l); \
		echo "static files (EdicionesMox/default/es_MX): $$STATIC"; \
		[ "$$STATIC" -ge 100 ] 2>/dev/null && echo "static: OK" || echo "static: FAIL — ejecuta: make theme-deploy (o make upgrade)"; \
		JS=$$(curl -sk -o /dev/null -w "%{time_total}" https://nginx/static/frontend/EdicionesMox/default/es_MX/requirejs/require.js); \
		echo "js sample time: $${JS}s (objetivo: < 0.05s)"; \
	'

# Detecta si _luma-base.less (copia manual de Luma _theme.less) se desincronizó
# tras un upgrade de Magento/Luma. Correr después de `composer update`.
diff-luma-base: ## Detecta drift entre _luma-base.less y Luma tras un upgrade
	$(PHP_EXEC) diff \
		app/design/frontend/EdicionesMox/default/web/css/source/_luma-base.less \
		vendor/magento/theme-frontend-luma/web/css/source/_theme.less \
		&& echo "diff-luma-base: sin cambios" \
		|| echo "diff-luma-base: _luma-base.less desincronizado — revisar diff arriba y actualizar"

##@ Desarrollo cotidiano

cache-flush: ## Limpia cache (tras cambios en .phtml, layout XML, PHP, o LESS)
	$(MAGENTO) cache:flush

# En developer mode, setup:static-content:deploy NO recompila los .css ya
# materializados: styles-m.css/styles-l.css se generan una sola vez, al vuelo,
# la primera vez que el navegador los pide (vía static.php), y nginx los sigue
# sirviendo desde disco después aunque cambie el LESS fuente. Por eso hay que
# borrar los .css compilados del tema antes de redeployar, para forzar que la
# próxima petición los recompile con los cambios de LESS.
clean-theme-css: ## (interno) Borra los CSS materializados del tema EdicionesMox
	$(PHP_EXEC) bash -c 'rm -rf var/view_preprocessed/pub/static/frontend/EdicionesMox pub/static/frontend/EdicionesMox/default/*/css/styles-*.css pub/static/frontend/EdicionesMox/default/*/css/critical.css'

# OPcache corre con validate_timestamps=0 (ver docker/php/php.ini): PHP nunca
# relee archivos ya cacheados. Tras regenerar generated/ hay que recargar
# php-fpm o seguirá sirviendo el código viejo. USR2 = reload graceful
# (docker-init PID 1 reenvía la señal al master de php-fpm).
opcache-reload: ## Recarga php-fpm para vaciar OPcache (tras compile/upgrade)
	$(PHP_EXEC) bash -c 'kill -USR2 1'

# El chown es red de seguridad: con bin/magento corriendo como www-data ya no
# debería encontrar nada que corregir (make doctor lo verifica). Si doctor
# reporta 0 archivos root durante un tiempo, se puede retirar.
theme-deploy: ## Redeploya estáticos del tema EdicionesMox (tras cambios en LESS/CSS)
	$(MAKE) clean-theme-css
	$(MAGENTO) setup:static-content:deploy es_MX -f --theme EdicionesMox/default --jobs=4
	$(PHP_EXEC) chown -R www-data:www-data pub/static
	$(MAGENTO) cache:flush

# Regenera generated/ (fix: "Http\Interceptor does not exist"). Tarda ~8 min.
compile: ## Regenera generated/ y recarga OPcache
	$(MAGENTO) setup:di:compile
	$(MAGENTO) cache:flush
	$(MAKE) opcache-reload

##@ Workflows

# Optimización de rendimiento (Windows): despliega estáticos, compila DI y limpia cache.
# Ejecutar tras el primer up o cuando var/generated/pub/static queden vacíos por volúmenes Docker.
# Tarda ~15 min la primera vez; las siguientes son más rápidas.
perf-setup: ## Despliega estáticos + compila DI (tras primer up o volúmenes vacíos)
	$(MAGENTO) setup:static-content:deploy es_MX en_US -f --jobs=4
	$(MAGENTO) setup:di:compile
	$(MAGENTO) cache:flush
	$(MAKE) opcache-reload

# Script único post-upgrade / post-volumen-borrado: deja el sitio operativo de punta a punta.
# Ejecutar tras `composer update`, `setup:upgrade` manual, cambios en di.xml/módulos,
# o cuando `docker compose down -v` borró generated/pub-static/var.
upgrade: ## setup:upgrade + di:compile + static deploy + reindex (post composer update)
	$(MAGENTO) setup:upgrade
	$(MAGENTO) setup:di:compile
	$(MAKE) clean-theme-css
	$(MAGENTO) setup:static-content:deploy es_MX en_US -f --area adminhtml --jobs=4
	$(MAGENTO) setup:static-content:deploy es_MX en_US -f --theme EdicionesMox/default --jobs=4
	$(MAGENTO) indexer:reindex
	$(PHP_EXEC) chown -R www-data:www-data var generated pub/static
	$(MAGENTO) cache:flush
	$(MAKE) opcache-reload

##@ Instalación

# Verifica que la sección Admin de .env esté completa antes de usarla.
check-admin-env: ## Valida que ADMIN_* estén definidas en .env (no imprime valores)
	@test -n "$(ADMIN_USER)" -a -n "$(ADMIN_PASSWORD)" -a -n "$(ADMIN_EMAIL)" \
		|| { echo "Faltan ADMIN_USER / ADMIN_PASSWORD / ADMIN_EMAIL en .env — copia la seccion Admin de .env.example y completala"; exit 1; }

# Instala Magento de punta a punta con las variables de .env (db, redis,
# opensearch y usuario admin). Ejecutar después de `make install-oss` y `make up`.
# Redis: cache (db 0), full-page cache (db 1) y sesiones (db 2) separados para
# que cache:flush no borre las sesiones de los usuarios.
setup-install: check-admin-env ## bin/magento setup:install con las variables de .env
	$(MAGENTO) setup:install \
		--base-url=https://localhost:$(NGINX_HTTPS_PORT)/ \
		--base-url-secure=https://localhost:$(NGINX_HTTPS_PORT)/ \
		--use-secure=1 \
		--use-secure-admin=1 \
		--backend-frontname=admin \
		--db-host=db \
		--db-name=$(DB_NAME) \
		--db-user=$(DB_USER) \
		--db-password=$(DB_PASSWORD) \
		--admin-firstname="$(ADMIN_FIRSTNAME)" \
		--admin-lastname="$(ADMIN_LASTNAME)" \
		--admin-email="$(ADMIN_EMAIL)" \
		--admin-user="$(ADMIN_USER)" \
		--admin-password="$(ADMIN_PASSWORD)" \
		--language=es_MX \
		--currency=MXN \
		--timezone=America/Mexico_City \
		--use-rewrites=1 \
		--search-engine=opensearch \
		--opensearch-host=opensearch \
		--opensearch-port=9200 \
		--cache-backend=redis \
		--cache-backend-redis-server=redis \
		--cache-backend-redis-db=0 \
		--page-cache=redis \
		--page-cache-redis-server=redis \
		--page-cache-redis-db=1 \
		--session-save=redis \
		--session-save-redis-host=redis \
		--session-save-redis-db=2

# Crea el usuario admin declarado en .env sobre una instancia ya instalada
# (útil tras borrar la base o para regenerar credenciales sin reinstalar).
admin-create: check-admin-env ## Crea el usuario admin de .env sin reinstalar
	$(MAGENTO) admin:user:create \
		--admin-user="$(ADMIN_USER)" \
		--admin-password="$(ADMIN_PASSWORD)" \
		--admin-email="$(ADMIN_EMAIL)" \
		--admin-firstname="$(ADMIN_FIRSTNAME)" \
		--admin-lastname="$(ADMIN_LASTNAME)"

# Recupera vendor/ tras perder el volumen (docker compose down -v) usando
# src/composer.json + src/composer.lock. No toca la base de datos.
composer-install: ## Reinstala vendor/ desde composer.lock (tras borrar el volumen)
	$(PHP_WWW) composer install --no-interaction

# Descarga Magento Open Source dentro de ./src usando composer.
# No se puede hacer create-project directo a /var/www/html: los volúmenes
# montados (var, vendor, ...) hacen que el directorio no esté vacío y composer
# lo rechaza. Se descarga a /tmp y se copia con tar por encima de los mounts.
# Uso: make install-oss [VERSION=2.4.8]  (default: MAGENTO_VERSION de .env)
install-oss: ## Descarga Magento OSS en ./src (VERSION=x.y.z, default .env)
	docker compose run --rm php-fpm bash -c '\
		set -e; \
		composer create-project --no-interaction \
			--repository-url=https://mirror.mage-os.org/ \
			magento/project-community-edition=$(or $(VERSION),$(MAGENTO_VERSION)) /tmp/magento; \
		(cd /tmp/magento && tar -cf - .) | (cd /var/www/html && tar -xf -); \
		rm -rf /tmp/magento; \
		chown -R www-data:www-data /var/www/html /var/www/.composer'
