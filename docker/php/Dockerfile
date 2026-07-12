ARG PHP_VERSION=8.3
FROM php:${PHP_VERSION}-fpm

# --- Dependencias del sistema requeridas por las extensiones de PHP de Magento ---
RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip libicu-dev libxml2-dev libxslt1-dev libzip-dev \
        libpng-dev libjpeg62-turbo-dev libfreetype6-dev libwebp-dev \
        libonig-dev default-mysql-client ca-certificates \
    && rm -rf /var/lib/apt/lists/*

# --- Certificado raíz corporativo (Netskope SSL inspection) ---
# Copiamos cualquier .crt que exista en docker/php/certs/ al almacén de
# confianza del contenedor. Si la carpeta está vacía, este paso no hace nada.
# El switch es la PRESENCIA del archivo, no una variable (ver .env.example).
# Nota: esta capa va ANTES de pecl/composer a propósito — detrás del proxy
# corporativo, pecl install necesita confiar en el CA para descargar.
COPY certs/ /usr/local/share/ca-certificates/corporate/
RUN update-ca-certificates || true

# PHP y Composer confían en el mismo almacén del sistema.
# Esto es el equivalente, dentro del contenedor, de lo que ya haces en
# Windows con NODE_EXTRA_CA_CERTS para Node/Claude Code.
ENV SSL_CERT_FILE=/etc/ssl/certs/ca-certificates.crt
ENV COMPOSER_CAFILE=/etc/ssl/certs/ca-certificates.crt

# --- Extensiones de PHP requeridas por Magento 2.4.8 ---
RUN docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j$(nproc) \
        bcmath \
        ftp \
        gd \
        intl \
        pdo_mysql \
        soap \
        sockets \
        xsl \
        zip \
        opcache \
    && pecl install redis-6.3.0 \
    && docker-php-ext-enable redis

# --- Composer ---
COPY --from=composer:2.9 /usr/bin/composer /usr/bin/composer

# --- OPcache: dimensionamiento (solo capacidad, no invalidación) ---
# Valores alineados a la recomendación de Adobe para producción (memory_consumption=2048,
# max_accelerated_files=130021): con vendor/ + generated/ + lib/ + tema, una instalación
# completa de Magento fácilmente ronda o supera 60000 archivos PHP. Pasado ese límite,
# OPcache deja de cachear archivos nuevos silenciosamente (sin error visible) y degrada
# el rendimiento. La RAM extra es barata comparada con ese riesgo.
# La política de invalidación (validate_timestamps / revalidate_freq) vive en un solo
# lugar: docker/php/php.ini (montado como zz-magento.ini) — no la dupliques aquí.
RUN { \
        echo 'opcache.memory_consumption=2048'; \
        echo 'opcache.max_accelerated_files=130021'; \
    } > /usr/local/etc/php/conf.d/opcache-recommended.ini

WORKDIR /var/www/html

COPY docker-entrypoint.sh /usr/local/bin/magento-entrypoint.sh
RUN chmod +x /usr/local/bin/magento-entrypoint.sh

ENTRYPOINT ["magento-entrypoint.sh"]
CMD ["php-fpm"]

EXPOSE 9000
