#!/usr/bin/env bash
# ============================================================
#  nginx boot hook — runs before nginx master starts.
#  Responsibilities:
#    1. Substitute ${MAGENTO_DOMAIN} into the server config
#    2. If no Let's Encrypt cert exists yet, generate a temporary
#       self-signed one so nginx can start (otherwise the ssl_*
#       directives fail and nginx refuses to launch, and certbot
#       can't run because port 80 needs nginx running first).
# ============================================================
set -euo pipefail

DOMAIN="${MAGENTO_DOMAIN:?MAGENTO_DOMAIN must be set}"
LE_DIR="/etc/letsencrypt/live/${DOMAIN}"

# 1. Template the server config (idempotent — safe to re-run on every
#    container restart). We do NOT delete magento.conf.template — that
#    would break the second start, when the file is gone but this script
#    still tries to envsubst from it.
export MAGENTO_DOMAIN
if [ -f /etc/nginx/conf.d/magento.conf.template ]; then
    envsubst '${MAGENTO_DOMAIN}' \
        < /etc/nginx/conf.d/magento.conf.template \
        > /etc/nginx/conf.d/magento.conf
fi
rm -f /etc/nginx/conf.d/default.conf

# 2. Self-signed bootstrap cert if Let's Encrypt hasn't issued one yet.
if [ ! -f "${LE_DIR}/fullchain.pem" ]; then
    echo "[nginx-bootstrap] No Let's Encrypt cert yet — generating self-signed for boot."
    mkdir -p "${LE_DIR}"
    openssl req -x509 -nodes -newkey rsa:2048 -days 1 \
        -keyout "${LE_DIR}/privkey.pem" \
        -out "${LE_DIR}/fullchain.pem" \
        -subj "/CN=${DOMAIN}" 2>/dev/null
    echo "[nginx-bootstrap] Self-signed cert generated. Run 'make ssl' on the host to request real cert."
fi
