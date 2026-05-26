# PVModern Magento 2 — Production Deployment Runbook

This document walks you through taking the PVModern storefront from "running on
my laptop" to "live at a real domain on the public internet." Read it top to
bottom **before** spending money on a server or domain.

---

## 1. What you're about to do

You'll provision a Linux VPS, point a domain at it, install Docker, clone this
repository, fill in the `.env` file, and run `make build && make up`. The
docker-compose stack brings up six containers:

| Container    | What it does                                               |
| ------------ | ---------------------------------------------------------- |
| `app`        | PHP 8.3-FPM running Magento 2 + PVModern module            |
| `nginx`      | TLS termination + static assets + reverse-proxy to `app`   |
| `cron`       | Sidecar running Magento cron + async queue consumers       |
| `db`         | MySQL 8.0                                                  |
| `redis`      | Sessions + default cache + page cache                      |
| `opensearch` | Magento 2.4.7+ search engine                               |
| `certbot`    | One-shot service for Let's Encrypt issuance + renewal      |

---

## 2. Pre-deployment checklist

Before you touch a server, gather the following:

- [ ] **A domain name** you own (e.g. `pvmodern.example.com`). Buy from any
      registrar (Namecheap, GoDaddy, Cloudflare Registrar, Mắt Bão, etc.).
      Vietnamese `.vn` domains require a business registration; `.com`/`.shop`
      do not.
- [ ] **A VPS** with these minimums:
  - 4 GB RAM (8 GB recommended — OpenSearch + MySQL together comfortably eat
    2.5 GB at rest, and PHP workers add 1-3 GB under load)
  - 2 vCPU
  - 40 GB SSD (Magento + DB + indexes + media easily reaches 15 GB)
  - Ubuntu 22.04 LTS or 24.04 LTS
  - Static public IPv4
- [ ] **An admin email address** — used both for the Magento admin user and
      Let's Encrypt expiry notifications.
- [ ] **All third-party API keys you actually use** (SePay token, SePay
      webhook API keys, MoMo partner code, VNPay TMN, OpenWeatherMap key).
      News uses VnExpress RSS and currency uses Vietcombank XML without keys;
      `OPENWEATHER_API_KEY` is required for live weather.

**Recommended VPS providers** (in rough order of price/quality for Vietnam-
adjacent traffic):

- VinaHost / Mắt Bão / Bizfly (VN, low ping for VN users, ~250-400k VND/mo)
- DigitalOcean Singapore (10 USD/mo for 4GB, ~25ms VN ping)
- Hetzner Helsinki (4-5 EUR/mo for 4GB, ~150ms but very cheap)
- Vultr Tokyo (10 USD/mo, ~45ms VN)

---

## 3. DNS setup (do this FIRST — propagation takes 5 min to 24 hr)

In your domain registrar's DNS panel, create these records:

| Type  | Name             | Value (your VPS public IP)  | TTL  |
| ----- | ---------------- | --------------------------- | ---- |
| A     | `@`              | `203.0.113.42` (example)    | 300  |
| A     | `www`            | `203.0.113.42`              | 300  |
| AAAA  | `@`              | `2001:db8::1` (if you have v6) | 300 |

Wait until `dig +short pvmodern.example.com` returns your VPS IP from your
local machine before continuing. Without this, Let's Encrypt cannot verify
ownership and certbot will fail.

---

## 4. Provision the server

SSH into the fresh VPS as root and run:

```bash
# Create a non-root user (replace 'deploy' with whatever you like)
adduser --gecos "" deploy
usermod -aG sudo deploy

# Lock down SSH
sed -i 's/^#*PermitRootLogin.*/PermitRootLogin no/' /etc/ssh/sshd_config
sed -i 's/^#*PasswordAuthentication.*/PasswordAuthentication no/' /etc/ssh/sshd_config
mkdir -p /home/deploy/.ssh && cp ~/.ssh/authorized_keys /home/deploy/.ssh/
chown -R deploy:deploy /home/deploy/.ssh && chmod 700 /home/deploy/.ssh
systemctl restart ssh

# Firewall
ufw allow OpenSSH
ufw allow 80/tcp
ufw allow 443/tcp
ufw --force enable

# Docker
apt-get update && apt-get install -y ca-certificates curl gnupg make git
install -m 0755 -d /etc/apt/keyrings
curl -fsSL https://download.docker.com/linux/ubuntu/gpg | gpg --dearmor -o /etc/apt/keyrings/docker.gpg
chmod a+r /etc/apt/keyrings/docker.gpg
echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] \
    https://download.docker.com/linux/ubuntu $(. /etc/os-release && echo "$VERSION_CODENAME") stable" \
    > /etc/apt/sources.list.d/docker.list
apt-get update
apt-get install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
usermod -aG docker deploy
```

Log out and back in as `deploy` so the docker group takes effect.

---

## 5. Clone, configure, build

```bash
# From the deploy user's home directory:
git clone <YOUR_REPO_URL> pvmodern
cd pvmodern

# Generate the .env from the template
cp .env.docker.example .env

# Edit and fill in EVERY value. Generate secrets with: openssl rand -base64 32
nano .env

# (Optional) If you use paid Magento Marketplace extensions:
cp docker/auth.json.example auth.json
nano auth.json

# Build images. First time takes ~10-15 minutes.
make build
```

---

## 6. First boot

```bash
make up
```

The first `up` does heavy lifting:

1. MySQL initializes the database (~30 s)
2. OpenSearch becomes healthy (~30 s)
3. App container's entrypoint runs `setup:install` (~3-5 min)
4. App runs `setup:upgrade` and `setup:di:compile` (~2-4 min)
5. App deploys static content for `vi_VN` and `en_US` (~3-6 min)
6. App reindexes everything (~1-2 min)

**Watch progress** with:

```bash
make logs
```

Expect the `app` container to show "Ready. Exec'ing CMD: php-fpm -F" before the
site responds.

---

## 7. Issue the real Let's Encrypt cert

The site is now responding on HTTP, redirecting to HTTPS with a self-signed
bootstrap cert (which browsers reject). To get a trusted cert:

```bash
# Confirm DNS still points correctly
dig +short $(grep ^MAGENTO_DOMAIN= .env | cut -d= -f2)

# Request the real cert
make ssl-init
```

This runs certbot against the live nginx; nginx restarts to pick up the new
cert. Verify with:

```bash
curl -I https://your-domain.com
```

You should see `HTTP/2 200` with no TLS warnings.

---

## 8. Schedule cert renewal

Let's Encrypt certs expire every 90 days. Add this to root's crontab on the host:

```cron
# Renew TLS certs every Monday at 03:30 (idempotent — only acts if <30d left)
30 3 * * 1 cd /home/deploy/pvmodern && /usr/bin/make ssl-renew >> /var/log/pvmodern-ssl.log 2>&1
```

---

## 9. Post-deploy smoke tests

Run these manually after first boot:

```bash
# Site loads
curl -fsSL https://your-domain.com >/dev/null && echo "OK: homepage"

# Admin login works (check the URL you set in ADMIN_FRONTNAME)
curl -fsSL "https://your-domain.com/$(grep ^ADMIN_FRONTNAME= .env | cut -d= -f2)" >/dev/null && echo "OK: admin reachable"

# A product page renders
make mage CMD="catalog:product:list" 2>/dev/null | head

# Indexers all green
make mage CMD="indexer:status"

# Cron is firing
docker compose logs cron --tail=20

# PVModern realtime dashboards use the requested external providers
curl -fsSL "https://your-domain.com/pvmodern/api/news" | grep -i "VnExpress"
curl -fsSL "https://your-domain.com/pvmodern/api/currency" | grep -i "Vietcombank"
curl -fsSL "https://your-domain.com/pvmodern/api/weather?city=Hanoi" | grep -i "OpenWeatherMap"
```

If any of these fail, see Troubleshooting at the bottom.

---

## 10. Day-2 operations

| What                            | How                                          |
| ------------------------------- | -------------------------------------------- |
| Tail all logs                   | `make logs`                                  |
| Open shell in app container     | `make shell`                                 |
| Run any `bin/magento` command   | `make mage CMD="cache:flush"`                |
| Flush caches                    | `make flush`                                 |
| Reindex everything              | `make reindex`                               |
| Apply new migrations after pull | `make setup-upgrade`                         |
| Backup DB now                   | `make db-backup`                             |
| Restore DB                      | `make db-restore FILE=backups/db-XXXX.sql.gz` |
| Renew TLS                       | `make ssl-renew`                             |
| Stop everything                 | `make down`                                  |

---

## 11. Updating the code

```bash
git pull
make build              # rebuild images with the new code
make up                 # recreate containers with the new images
make setup-upgrade      # apply any new schema/data patches
make flush
```

The named volumes (`db-data`, `magento-media`, `magento-static`, `magento-var`,
etc.) **survive** rebuild and `down/up`. Only `docker compose down -v` wipes
them — never run that on production unless you intentionally want a clean slate.

---

## 12. Backups — DO THIS BEFORE GOING LIVE

Set up automated daily backups. On the VPS host, add to root's crontab:

```cron
# Daily MySQL dump at 02:30
30 2 * * * cd /home/deploy/pvmodern && /usr/bin/make db-backup >/dev/null 2>&1

# Daily media volume snapshot at 02:45
45 2 * * * tar -czf /home/deploy/pvmodern/backups/media-$(date +\%Y-\%m-\%d).tgz -C /var/lib/docker/volumes/pvmodern_magento-media/_data . 2>/dev/null

# Keep 14 days of backups
0 4 * * * find /home/deploy/pvmodern/backups -name "*.gz" -mtime +14 -delete
0 4 * * * find /home/deploy/pvmodern/backups -name "*.tgz" -mtime +14 -delete
```

**Better still**: ship `backups/` to off-server storage daily (rclone to
Backblaze B2, AWS S3, or another VPS). A local backup that dies with the
server is no backup.

---

## 13. Webhook URLs — update third parties

After the domain is live, log into each payment provider's dashboard and point
their webhook URL at the new public address:

| Provider | Old URL                                                                                            | New URL                                                          |
| -------- | -------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------- |
| SePay    | `https://<your-cloudflared-tunnel>.trycloudflare.com/api/webhooks/sepay`                            | `https://pvmodern.example.com/api/webhooks/sepay`                |
| Casso    | `https://<your-cloudflared-tunnel>.trycloudflare.com/api/webhooks/casso`                            | `https://pvmodern.example.com/api/webhooks/casso`                |
| MoMo IPN | `https://<dev>/payments/momo/ipn`                                                                   | `https://pvmodern.example.com/payments/momo/ipn`                 |
| VNPay    | `https://<dev>/payments/vnpay/return`                                                               | `https://pvmodern.example.com/payments/vnpay/return`             |

The old Cloudflare quick-tunnel can be killed; production runs without it.

---

## 14. Security checklist before going public

- [ ] `.env` has strong random passwords for `MYSQL_ROOT_PASSWORD`, `MYSQL_PASSWORD`, `ADMIN_PASSWORD`
- [ ] `ADMIN_FRONTNAME` is **not** `admin` — pick something random
- [ ] `MAGE_MODE=production` (set in compose env)
- [ ] Firewall blocks everything except 22/80/443
- [ ] SSH password auth disabled, only key auth
- [ ] `auth.json` (if present) is `chmod 600` and owned by the deploy user
- [ ] Let's Encrypt cert is real (not self-signed bootstrap)
- [ ] HSTS header confirmed: `curl -I https://your-domain.com | grep -i strict`
- [ ] Magento `Stores → Configuration → Web → Default Cookie Settings → Use HTTP Only` = Yes
- [ ] Magento admin 2FA enabled (Stores → Configuration → Security → 2FA)
- [ ] `PVMODERN_PAYMENT_DEMO=false` (or the demo banner shows on a real site)

---

## 15. Troubleshooting

### `nginx` keeps restarting

```bash
docker compose logs nginx --tail=30
```

99% of the time this is a missing or malformed `${MAGENTO_DOMAIN}` substitution
or a permission issue on the LE cert. Check `make ssl-init` succeeded.

### `app` exits with "MySQL never came up"

The MySQL container failed its health check. Check:

```bash
docker compose logs db --tail=50
```

Common cause: insufficient RAM. The mysql container needs ~1.2 GB just for the
buffer pool. Tune `--innodb_buffer_pool_size` in `docker-compose.yml` if you're
on a 2 GB VPS (not recommended, but works).

### `app` boots but homepage is 502

PHP-FPM is up but nginx can't reach it. Verify:

```bash
docker compose exec nginx ping -c 2 app
docker compose exec app nc -zv app 9000  # should connect
```

If the network is broken, `docker compose down && make up` usually fixes it.

### OpenSearch refuses to start

Likely vm.max_map_count is too low. On the host:

```bash
sudo sysctl -w vm.max_map_count=262144
echo 'vm.max_map_count=262144' | sudo tee /etc/sysctl.d/99-opensearch.conf
```

Then restart: `make down && make up`.

### Static content is 404

The first deploy may have skipped static-content:deploy. Force it:

```bash
make static
make flush
```

---

## 16. Future scaling

This single-host docker-compose setup comfortably serves up to ~30 concurrent
checkouts per second on a 4 GB / 2 vCPU VPS. Beyond that, the path is:

1. Move MySQL to a managed DB (DigitalOcean Managed MySQL, AWS RDS, Vultr Managed DB)
2. Move Redis to a managed Redis
3. Move OpenSearch to a managed service or a dedicated VPS
4. Run multiple `app` replicas behind a load balancer (HAProxy / nginx Plus / cloud LB)
5. Move `pub/media` to S3-compatible object storage (Magento has a built-in adapter)
6. Add a CDN in front of nginx (Cloudflare in proxy mode is free and works fine)

But none of that is needed for a launch — get the single-host setup running
first, watch real traffic, and only scale when something starts to creak.

---

## 17. Rollback plan

If a deploy goes wrong:

```bash
# Roll the code back
git reset --hard <previous-commit-sha>

# Rebuild and redeploy
make build
make up

# If schema migrations ran and need rolling back, restore from the last backup:
make db-restore FILE=backups/db-<the-last-good-one>.sql.gz
make flush
make reindex
```

Always `make db-backup` immediately before applying a `setup:upgrade` that
includes new migrations. Once a Magento data patch runs, undoing it cleanly
requires the backup.
