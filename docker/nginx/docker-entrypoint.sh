#!/bin/sh
set -e

# Certificado self-signed para desarrollo local. Vive en un volumen nombrado
# (nginx_certs) para no regenerarse en cada `docker compose up` — solo la
# primera vez o si el volumen se borra con `down -v`. El navegador mostrará
# advertencia de certificado no confiable: es esperado, es autofirmado.
CERT_DIR=/etc/nginx/certs
CERT_FILE="$CERT_DIR/localhost.crt"
KEY_FILE="$CERT_DIR/localhost.key"

if [ ! -f "$CERT_FILE" ] || [ ! -f "$KEY_FILE" ]; then
    mkdir -p "$CERT_DIR"
    openssl req -x509 -nodes -days 825 -newkey rsa:2048 \
        -keyout "$KEY_FILE" \
        -out "$CERT_FILE" \
        -subj "/CN=localhost" \
        -addext "subjectAltName=DNS:localhost,IP:127.0.0.1"
fi

exec "$@"
