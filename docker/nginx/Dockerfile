# =====================================================================
#  PVModern Magento 2 — nginx edge image
# =====================================================================
#  Bundles the Magento sample nginx config + our production overrides.
#  At runtime this container needs:
#    - read access to the Magento pub/ tree (mounted from the app volume)
#    - access to php-fpm at app:9000
#    - TLS certs from /etc/letsencrypt (mounted from host)
# =====================================================================

FROM nginx:1.27-alpine

# Tools we use in the entrypoint (envsubst, openssl for the self-signed
# bootstrap cert that lets nginx start before certbot has run for the
# very first time).
#
# NOTE on the mirror dance: dl-cdn.alpinelinux.org resolves to IPv6-only
# from Fastly. Docker's default bridge network has IPv4 only, so an apk
# fetch dies with "temporary error (try again later)". We point apk at
# the official IPv4-reachable mirror at uk.alpinelinux.org instead, with
# a one-time retry just in case the first attempt still flakes.
RUN set -eux; \
    sed -i 's|dl-cdn.alpinelinux.org|uk.alpinelinux.org|g' /etc/apk/repositories; \
    for i in 1 2 3; do \
        apk add --no-cache bash openssl gettext curl && break || sleep 5; \
    done

# Production server config — listens on 80 (ACME challenge + redirect) and
# 443 (the real site). Variables are substituted at container boot from
# environment.
COPY docker/nginx/conf.d/magento.conf.template /etc/nginx/conf.d/magento.conf.template
COPY docker/nginx/conf.d/security.conf         /etc/nginx/conf.d/security.conf
COPY docker/nginx/entrypoint.sh                /docker-entrypoint.d/40-magento-bootstrap.sh
RUN chmod +x /docker-entrypoint.d/40-magento-bootstrap.sh

# We pull the Magento-shipped nginx.conf.sample at runtime from the app
# volume — that file ships with Magento itself and we shouldn't fork it.

EXPOSE 80 443
