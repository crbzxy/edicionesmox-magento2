ARG NGINX_VERSION=1.28-alpine
FROM nginx:${NGINX_VERSION}

# openssl no viene en la imagen alpine de nginx; lo necesita el entrypoint
# para generar el certificado self-signed de desarrollo.
RUN apk add --no-cache openssl

COPY docker-entrypoint.sh /usr/local/bin/nginx-entrypoint.sh
RUN chmod +x /usr/local/bin/nginx-entrypoint.sh

ENTRYPOINT ["nginx-entrypoint.sh"]
CMD ["nginx", "-g", "daemon off;"]
