#!/usr/bin/env bash
# =====================================================================
#  PVModern → techieworld.site — one-shot VPS bootstrap
# =====================================================================
#  This script provisions a fresh Ubuntu 24.04 VPS to run the docker-
#  compose stack. Idempotent: safe to re-run if any step fails.
#
#  Run as root on 74.81.39.6:
#    bash bootstrap.sh
# =====================================================================
set -euo pipefail

DEPLOY_USER="deploy"
DEPLOY_HOME="/home/${DEPLOY_USER}"
REPO_DIR="${DEPLOY_HOME}/techieworld"

log() { printf '\033[1;36m[bootstrap] %s\033[0m\n' "$*"; }
err() { printf '\033[1;31m[bootstrap] ERROR: %s\033[0m\n' "$*" >&2; }

# Refuse to run if we're not root
if [ "$(id -u)" -ne 0 ]; then
    err "This script must run as root. Try: sudo bash bootstrap.sh"
    exit 1
fi

# -------- 1. Base system + security ---------------------------------
log "[1/9] Updating apt and installing base packages..."
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
apt-get install -y -qq ca-certificates curl gnupg make git rsync ufw fail2ban htop nano vim tmux unzip
apt-get autoremove -y -qq

# -------- 2. Firewall (SSH + HTTP + HTTPS only) ---------------------
log "[2/9] Configuring UFW firewall..."
ufw --force reset >/dev/null
ufw default deny incoming
ufw default allow outgoing
ufw allow OpenSSH
ufw allow 80/tcp comment 'HTTP - certbot + redirect'
ufw allow 443/tcp comment 'HTTPS'
ufw --force enable
ufw status verbose

# -------- 3. Create non-root deploy user ----------------------------
log "[3/9] Creating deploy user..."
if ! id "${DEPLOY_USER}" >/dev/null 2>&1; then
    adduser --gecos "" --disabled-password "${DEPLOY_USER}"
fi
usermod -aG sudo "${DEPLOY_USER}"

# Copy root's authorized_keys so deploy can SSH too
mkdir -p "${DEPLOY_HOME}/.ssh"
if [ -f /root/.ssh/authorized_keys ]; then
    cp /root/.ssh/authorized_keys "${DEPLOY_HOME}/.ssh/"
    chown -R "${DEPLOY_USER}:${DEPLOY_USER}" "${DEPLOY_HOME}/.ssh"
    chmod 700 "${DEPLOY_HOME}/.ssh"
    chmod 600 "${DEPLOY_HOME}/.ssh/authorized_keys"
fi

# Passwordless sudo for deploy (since we use SSH keys, password isn't a thing)
echo "${DEPLOY_USER} ALL=(ALL) NOPASSWD:ALL" > "/etc/sudoers.d/90-${DEPLOY_USER}"
chmod 0440 "/etc/sudoers.d/90-${DEPLOY_USER}"

# -------- 4. Harden SSH ---------------------------------------------
# NOTE: we deliberately do NOT disable password auth automatically here.
# ckey.vn uses port 10000 with password as the initial auth method, and
# disabling it before the user has verified key auth works would lock
# them out. After the user confirms they can SSH with their key, run:
#   sudo bash /root/disable-password-auth.sh
log "[4/9] Preparing SSH hardening (manual confirmation step below)..."
cat > /root/disable-password-auth.sh <<'HARDEN_EOF'
#!/usr/bin/env bash
# Run this ONLY after you've verified your SSH key works:
#   ssh -p 10000 -i ~/.ssh/id_ed25519 root@74.81.39.6
# Lock down password auth so the VPS is bruteforce-resistant.
set -e
sed -i 's/^#*PermitRootLogin.*/PermitRootLogin prohibit-password/' /etc/ssh/sshd_config
sed -i 's/^#*PasswordAuthentication.*/PasswordAuthentication no/'  /etc/ssh/sshd_config
sed -i 's/^#*PubkeyAuthentication.*/PubkeyAuthentication yes/'     /etc/ssh/sshd_config
systemctl reload ssh || systemctl reload sshd || true
echo "✓ Password auth disabled. Test in a NEW terminal first before closing this session."
HARDEN_EOF
chmod +x /root/disable-password-auth.sh
log "    Created /root/disable-password-auth.sh — run AFTER verifying SSH key auth works."

# -------- 5. OpenSearch sysctl ------------------------------------
log "[5/9] Setting vm.max_map_count for OpenSearch..."
sysctl -w vm.max_map_count=262144 >/dev/null
grep -q 'vm.max_map_count' /etc/sysctl.d/99-opensearch.conf 2>/dev/null \
    || echo 'vm.max_map_count=262144' > /etc/sysctl.d/99-opensearch.conf

# -------- 6. Swap (4GB) ---------------------------------------------
# If the VPS is short on RAM, a small swap saves us from OOM during
# composer install. Idempotent.
log "[6/9] Ensuring 4GB swap exists..."
if [ ! -f /swapfile ]; then
    fallocate -l 4G /swapfile
    chmod 600 /swapfile
    mkswap /swapfile >/dev/null
    swapon /swapfile
    grep -q '^/swapfile' /etc/fstab || echo '/swapfile none swap sw 0 0' >> /etc/fstab
    sysctl -w vm.swappiness=10 >/dev/null
    echo 'vm.swappiness=10' > /etc/sysctl.d/99-swappiness.conf
fi
free -h

# -------- 7. Docker -------------------------------------------------
log "[7/9] Installing Docker..."
if ! command -v docker >/dev/null 2>&1; then
    install -m 0755 -d /etc/apt/keyrings
    curl -fsSL https://download.docker.com/linux/ubuntu/gpg | gpg --dearmor -o /etc/apt/keyrings/docker.gpg
    chmod a+r /etc/apt/keyrings/docker.gpg
    UBUNTU_CODENAME="$(. /etc/os-release && echo "${VERSION_CODENAME}")"
    echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] https://download.docker.com/linux/ubuntu ${UBUNTU_CODENAME} stable" \
        > /etc/apt/sources.list.d/docker.list
    apt-get update -qq
    apt-get install -y -qq docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
fi
usermod -aG docker "${DEPLOY_USER}"
systemctl enable --now docker
docker --version
docker compose version

# -------- 8. fail2ban (light SSH bruteforce protection) -------------
log "[8/9] Enabling fail2ban for SSH..."
cat > /etc/fail2ban/jail.local <<'EOF'
[sshd]
enabled = true
maxretry = 5
findtime = 600
bantime = 3600
EOF
systemctl enable --now fail2ban

# -------- 9. Done ---------------------------------------------------
log "[9/9] Provisioning complete."
echo
echo "==================== NEXT STEPS ===================="
echo "Run as DEPLOY user (not root):"
echo
echo "  su - ${DEPLOY_USER}"
echo "  # Then upload your repo to ${REPO_DIR} via rsync or git clone"
echo "  # Then:"
echo "  cd ${REPO_DIR}"
echo "  make build       # ~15 min"
echo "  make up          # ~25 min (first boot runs setup:install)"
echo "  make logs        # watch progress"
echo "  make ssl-init    # issue real Let's Encrypt cert once site is up"
echo "===================================================="
