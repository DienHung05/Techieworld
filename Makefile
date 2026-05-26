# =====================================================================
#  PVModern Magento 2 — operations Makefile
# =====================================================================
#  Common day-2 commands. Run `make help` to see everything.
# =====================================================================

DC := docker compose
APP := $(DC) exec -T app
MAGE := $(APP) php bin/magento

.DEFAULT_GOAL := help
.PHONY: help build up down logs ps shell mage cache flush reindex \
        ssl-init ssl-renew db-backup db-restore status

help:                ## Print this help
	@awk 'BEGIN {FS = ":.*##"} /^[a-zA-Z0-9_-]+:.*##/ {printf "  \033[36m%-15s\033[0m %s\n", $$1, $$2}' $(MAKEFILE_LIST)

# ─── Lifecycle ──────────────────────────────────────────────────
# NOTE: On ckey.vn (and similar VN VPS providers) the default Docker
# bridge network is blocked from reaching the public internet during
# builds. We work around this by running `docker compose build` with
# BUILDX_BAKE_ENTITLEMENTS+host-network. Compose-v2 doesn't accept
# --network on the build subcommand, so we instead use a one-shot
# build target that re-issues each Dockerfile through `docker build
# --network=host` directly. The resulting images are tagged with the
# names docker-compose expects (pvmodern-app, pvmodern-nginx) so the
# rest of the pipeline (make up, etc.) works unchanged.
build:               ## Build (or rebuild) all images
	@echo "==> Building pvmodern-app (with host network for apt/composer)..."
	DOCKER_BUILDKIT=1 docker build --network=host --pull \
	    -f docker/php-fpm/Dockerfile -t pvmodern-app:latest .
	@echo "==> Building pvmodern-nginx..."
	DOCKER_BUILDKIT=1 docker build --network=host --pull \
	    -f docker/nginx/Dockerfile -t pvmodern-nginx:latest .
	@echo "==> Done. Images:"
	@docker images | grep pvmodern

up:                  ## Start everything in the background
	$(DC) up -d

down:                ## Stop everything (data preserved)
	$(DC) down

logs:                ## Tail logs from all services
	$(DC) logs -f --tail=200

ps:                  ## List running services
	$(DC) ps

shell:               ## Open a bash inside the app container
	$(DC) exec app bash

status:              ## Quick health overview
	@echo "--- containers ---"; $(DC) ps
	@echo "--- magento mode ---"; $(MAGE) deploy:mode:show
	@echo "--- indexer status ---"; $(MAGE) indexer:status

# ─── Magento operations ─────────────────────────────────────────
mage:                ## Run any bin/magento command, e.g. make mage CMD="info:adminuri"
	$(MAGE) $(CMD)

cache:               ## Show cache status
	$(MAGE) cache:status

flush:               ## Flush all Magento caches
	$(MAGE) cache:flush

reindex:             ## Reindex everything
	$(MAGE) indexer:reindex

setup-upgrade:       ## Apply pending schema/data patches
	$(MAGE) setup:upgrade --no-interaction
	$(MAGE) setup:di:compile

static:              ## Re-deploy static content
	$(MAGE) setup:static-content:deploy -f vi_VN en_US

# ─── TLS / Let's Encrypt ────────────────────────────────────────
ssl-init:            ## Issue the FIRST Let's Encrypt cert (run once, after DNS is live)
	@test -n "$$(grep ^MAGENTO_DOMAIN= .env | cut -d= -f2)" || (echo "MAGENTO_DOMAIN missing in .env"; exit 1)
	$(DC) run --rm certbot certonly --webroot -w /var/www/certbot \
	    -d $$(grep ^MAGENTO_DOMAIN= .env | cut -d= -f2) \
	    -d www.$$(grep ^MAGENTO_DOMAIN= .env | cut -d= -f2) \
	    --email $$(grep ^LETSENCRYPT_EMAIL= .env | cut -d= -f2) \
	    --agree-tos --no-eff-email --non-interactive
	$(DC) restart nginx

ssl-renew:           ## Renew certs (idempotent — safe to run from cron)
	$(DC) run --rm certbot renew --webroot -w /var/www/certbot --quiet
	$(DC) exec nginx nginx -s reload

# ─── Backup / restore ───────────────────────────────────────────
db-backup:           ## Dump MySQL to ./backups/db-YYYY-MM-DD.sql.gz
	@mkdir -p backups
	$(DC) exec -T db sh -c 'exec mysqldump -uroot -p"$$MYSQL_ROOT_PASSWORD" --single-transaction --routines --triggers $$MYSQL_DATABASE' \
	    | gzip > backups/db-$$(date +%Y-%m-%d-%H%M).sql.gz
	@ls -lh backups | tail -3

db-restore:          ## Restore MySQL from FILE=./backups/db-...sql.gz
	@test -n "$(FILE)" || (echo "Usage: make db-restore FILE=./backups/db-XXXX.sql.gz"; exit 1)
	gunzip -c $(FILE) | $(DC) exec -T db sh -c 'exec mysql -uroot -p"$$MYSQL_ROOT_PASSWORD" $$MYSQL_DATABASE'
