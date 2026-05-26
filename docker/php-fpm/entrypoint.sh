#!/usr/bin/env bash
# ============================================================
#  PVModern Magento 2 — first-boot + per-restart entrypoint
# ============================================================
#  Runs every time the container starts. Idempotent:
#    - Waits for MySQL to be reachable
#    - On first ever boot, runs setup:install
#    - On subsequent boots, runs setup:upgrade if a marker says
#      the codebase has new modules/migrations
#    - Compiles DI + deploys static content if missing
#    - Ensures correct file ownership
#    - exec's the CMD (php-fpm)
# ============================================================
set -euo pipefail

MAGE_ROOT="/srv/magento"
MARKER_DIR="${MAGE_ROOT}/var/.docker-marker"
mkdir -p "${MARKER_DIR}"

log() { printf '\033[1;36m[entrypoint] %s\033[0m\n' "$*"; }
err() { printf '\033[1;31m[entrypoint] %s\033[0m\n' "$*" >&2; }

# 1. Wait for MySQL — every container restart races the DB.
log "Waiting for MySQL at ${MYSQL_HOST:-db}:${MYSQL_PORT:-3306}..."
for i in $(seq 1 60); do
    if mysqladmin ping -h "${MYSQL_HOST:-db}" -P "${MYSQL_PORT:-3306}" -u "${MYSQL_USER}" -p"${MYSQL_PASSWORD}" --silent 2>/dev/null; then
        log "MySQL is up."
        break
    fi
    if [ "$i" -eq 60 ]; then
        err "MySQL never came up after 60s. Giving up."
        exit 1
    fi
    sleep 1
done

# 2. Wait for OpenSearch.
log "Waiting for OpenSearch at ${OPENSEARCH_HOST:-opensearch}:9200..."
for i in $(seq 1 60); do
    if curl -fs "http://${OPENSEARCH_HOST:-opensearch}:9200/_cluster/health?wait_for_status=yellow&timeout=5s" >/dev/null 2>&1; then
        log "OpenSearch is up."
        break
    fi
    [ "$i" -eq 60 ] && { err "OpenSearch never came up."; exit 1; }
    sleep 1
done

cd "${MAGE_ROOT}"

# 3. Detect first-ever boot vs upgrade. Marker file is on the var/ volume
#    so it survives container recreation but not "fresh install".
if [ ! -f "${MARKER_DIR}/installed" ]; then
    log "FIRST BOOT — running Magento setup:install"

    # Wait until DB exists (compose creates it; first boot might race).
    until mysql -h "${MYSQL_HOST}" -P "${MYSQL_PORT:-3306}" -u "${MYSQL_USER}" -p"${MYSQL_PASSWORD}" -e "USE ${MYSQL_DATABASE}" 2>/dev/null; do
        log "Waiting for database ${MYSQL_DATABASE} to be ready..."
        sleep 2
    done

    # NOTE: we only pass the port-suffix when the port is non-default;
    # for the default 3306 Magento + Zend_Db's PDO handling is happier
    # with a bare hostname/IP.
    DB_HOST_ARG="${MYSQL_HOST}"
    if [ "${MYSQL_PORT:-3306}" != "3306" ]; then
        DB_HOST_ARG="${MYSQL_HOST}:${MYSQL_PORT}"
    fi

    php bin/magento setup:install \
        --base-url="${MAGENTO_BASE_URL}" \
        --base-url-secure="${MAGENTO_BASE_URL}" \
        --db-host="${DB_HOST_ARG}" \
        --db-name="${MYSQL_DATABASE}" \
        --db-user="${MYSQL_USER}" \
        --db-password="${MYSQL_PASSWORD}" \
        --admin-firstname="${ADMIN_FIRSTNAME:-Admin}" \
        --admin-lastname="${ADMIN_LASTNAME:-User}" \
        --admin-email="${ADMIN_EMAIL}" \
        --admin-user="${ADMIN_USER}" \
        --admin-password="${ADMIN_PASSWORD}" \
        --language=vi_VN \
        --currency=VND \
        --timezone=Asia/Ho_Chi_Minh \
        --use-rewrites=1 \
        --search-engine=opensearch \
        --opensearch-host="${OPENSEARCH_HOST:-opensearch}" \
        --opensearch-port=9200 \
        --session-save=redis \
        --session-save-redis-host="${REDIS_HOST:-redis}" \
        --session-save-redis-port="${REDIS_PORT:-6379}" \
        --session-save-redis-db=2 \
        --cache-backend=redis \
        --cache-backend-redis-server="${REDIS_HOST:-redis}" \
        --cache-backend-redis-port="${REDIS_PORT:-6379}" \
        --cache-backend-redis-db=0 \
        --page-cache=redis \
        --page-cache-redis-server="${REDIS_HOST:-redis}" \
        --page-cache-redis-port="${REDIS_PORT:-6379}" \
        --page-cache-redis-db=1 \
        --backend-frontname="${ADMIN_FRONTNAME:-admin}"

    touch "${MARKER_DIR}/installed"
    log "Initial setup complete."
fi

# 4. Idempotent: every boot, ensure modules + schema are up to date.
log "Running setup:upgrade..."
php bin/magento setup:upgrade --no-interaction

# 5. DI compile only when not yet present (image build did this, but if
#    user added a custom module via volume mount, recompile).
if [ ! -d generated/code/Magento ]; then
    log "DI compilation..."
    php bin/magento setup:di:compile
fi

# 6. Static content. Once per image deploy.
if [ ! -d pub/static/frontend/YourVendor ]; then
    log "Deploying static content (this takes a few minutes)..."
    php bin/magento setup:static-content:deploy -f vi_VN en_US --no-interaction || true
fi

# 7. Indexers + mode.
php bin/magento deploy:mode:set "${MAGE_MODE:-production}" --skip-compilation --no-interaction || true
php bin/magento indexer:reindex --no-interaction || true
php bin/magento cache:flush || true

# 8. Permissions in case volumes were re-created.
chown -R www-data:www-data var pub/media pub/static generated app/etc 2>/dev/null || true
find var pub/media pub/static generated -type d -exec chmod 775 {} + 2>/dev/null || true
find var pub/media pub/static generated -type f -exec chmod 664 {} + 2>/dev/null || true

log "Ready. Exec'ing CMD: $*"
exec "$@"
