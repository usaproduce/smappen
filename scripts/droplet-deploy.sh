#!/bin/bash
# Smappen one-shot droplet deploy (Apache + Cloudflare DNS).
# Designed to coexist with an existing GreenDock install on the same Ubuntu 22.04+/24.04 droplet.
#
# USAGE (on the droplet, as root):
#   GITHUB_REPO=https://github.com/usaproduce/smappen.git \
#   DOMAIN=smappen.mygreendock.com \
#   EMAIL=adam.smith1735@gmail.com \
#   GOOGLE_API_KEY=... ORS_API_KEY=... CENSUS_API_KEY=... \
#   bash /tmp/deploy.sh

set -e

: "${GITHUB_REPO:?Set GITHUB_REPO}"
: "${DOMAIN:?Set DOMAIN}"
: "${EMAIL:?Set EMAIL}"

APP_DIR=/var/www/smappen
DB_NAME=smappen
DB_USER=smappen
DB_PASS_FILE=/root/.smappen_db_pass

log() { echo -e "\n\033[1;36m>>> $*\033[0m"; }
warn() { echo -e "\033[1;33m⚠️  $*\033[0m"; }
ok() { echo -e "\033[1;32m✅ $*\033[0m"; }

if [ "$EUID" -ne 0 ]; then
  echo "Run as root (sudo bash $0)"
  exit 1
fi

log "Installing system packages (PHP 8.2, Apache modules, MySQL client, etc)…"
export DEBIAN_FRONTEND=noninteractive
apt-get update -y
apt-get install -y software-properties-common curl ca-certificates gnupg unzip git openssl

# PHP 8.3 via Ondrej PPA (some composer deps require >= 8.3).
# Installed alongside whatever greendock uses — Apache picks PHP per-vhost via FPM socket.
if ! ls /etc/apt/sources.list.d/ondrej*php* >/dev/null 2>&1; then
  add-apt-repository -y ppa:ondrej/php
  apt-get update -y
fi
apt-get install -y \
  php8.3 php8.3-cli php8.3-fpm php8.3-mysql php8.3-curl php8.3-mbstring \
  php8.3-xml php8.3-zip php8.3-gd php8.3-bcmath
systemctl enable --now php8.3-fpm

# Use 8.3 as the default CLI php for this script
PHP_BIN=/usr/bin/php8.3
COMPOSER_BIN="$PHP_BIN /usr/local/bin/composer"

# Apache (should already be there if greendock is running) + mod_rewrite
apt-get install -y apache2
a2enmod rewrite headers ssl proxy_fcgi setenvif >/dev/null

# Certbot (Apache plugin)
apt-get install -y certbot python3-certbot-apache

# MySQL client (server should already be installed for greendock)
apt-get install -y mysql-client wkhtmltopdf
if ! command -v mysql >/dev/null 2>&1; then
  apt-get install -y mysql-server
fi

# Composer
if ! command -v composer >/dev/null 2>&1; then
  log "Installing Composer…"
  curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
fi

# Node 20
if ! command -v node >/dev/null 2>&1 || ! node --version | grep -qE '^v(2[0-9]|[3-9][0-9])'; then
  log "Installing Node 20…"
  curl -fsSL https://deb.nodesource.com/setup_20.x | bash -
  apt-get install -y nodejs
fi

# MySQL root access — try multiple auth methods in order:
#   1. MYSQL_ROOT_PASSWORD env var
#   2. /etc/mysql/debian.cnf (Ubuntu's maintenance account, has full privileges)
#   3. socket auth (plain `mysql` as root user)
MYSQL_AUTH_MODE=""
mysql_root() {
  case "$MYSQL_AUTH_MODE" in
    env) MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot "$@" ;;
    debian) mysql --defaults-extra-file=/etc/mysql/debian.cnf "$@" ;;
    socket) mysql "$@" ;;
    *) return 1 ;;
  esac
}

detect_mysql_auth() {
  if [ -n "${MYSQL_ROOT_PASSWORD:-}" ] && MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot -e "SELECT 1" >/dev/null 2>&1; then
    MYSQL_AUTH_MODE=env
    log "MySQL: using MYSQL_ROOT_PASSWORD"
  elif [ -r /etc/mysql/debian.cnf ] && mysql --defaults-extra-file=/etc/mysql/debian.cnf -e "SELECT 1" >/dev/null 2>&1; then
    MYSQL_AUTH_MODE=debian
    log "MySQL: using /etc/mysql/debian.cnf (debian-sys-maint)"
  elif mysql -e "SELECT 1" >/dev/null 2>&1; then
    MYSQL_AUTH_MODE=socket
    log "MySQL: using socket auth (root)"
  else
    warn "Cannot reach MySQL with any auth method."
    warn "Find your MySQL root password (try: grep -i pass /root/.my.cnf or check greendock's connection.php),"
    warn "then re-run with:  MYSQL_ROOT_PASSWORD='your_password' ... bash /tmp/d.sh"
    exit 1
  fi
}

# DB credentials — always (re)provision idempotently so prior half-runs heal
log "Ensuring MySQL database + user (idempotent)…"
detect_mysql_auth
if [ -f "$DB_PASS_FILE" ]; then
  DB_PASS=$(cat "$DB_PASS_FILE")
else
  DB_PASS=$(openssl rand -hex 16)
  echo "$DB_PASS" > "$DB_PASS_FILE"
  chmod 600 "$DB_PASS_FILE"
fi
mysql_root -e "CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql_root -e "CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';"
mysql_root -e "ALTER USER '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';"
mysql_root -e "GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost'; FLUSH PRIVILEGES;"

# Verify the smappen user can actually connect with the stored password
if ! MYSQL_PWD="$DB_PASS" mysql -u"$DB_USER" "$DB_NAME" -e "SELECT 1" >/dev/null 2>&1; then
  warn "smappen MySQL user still can't connect with stored password. Aborting."
  exit 1
fi

# Clone or pull
if [ ! -d "$APP_DIR/.git" ]; then
  log "Cloning $GITHUB_REPO → $APP_DIR…"
  rm -rf "$APP_DIR"
  git clone "$GITHUB_REPO" "$APP_DIR"
else
  log "Updating existing repo…"
  cd "$APP_DIR" && git fetch --all && git reset --hard origin/main
fi
cd "$APP_DIR"

log "Installing PHP dependencies (via PHP 8.3)…"
$COMPOSER_BIN install --no-dev --optimize-autoloader --no-interaction

# .env: fresh if missing, else patch DB_PASS
if [ ! -f "$APP_DIR/.env" ]; then
  log "Creating .env…"
  JWT_SECRET=$(openssl rand -hex 32)
  cat > "$APP_DIR/.env" <<EOF
APP_URL=https://$DOMAIN
APP_ENV=production

DB_HOST=localhost
DB_PORT=3306
DB_NAME=$DB_NAME
DB_USER=$DB_USER
DB_PASS=$DB_PASS

JWT_SECRET=$JWT_SECRET

GOOGLE_API_KEY=${GOOGLE_API_KEY:-__SET_ME__}
ORS_API_KEY=${ORS_API_KEY:-__SET_ME__}
CENSUS_API_KEY=${CENSUS_API_KEY:-__SET_ME__}

STRIPE_SECRET_KEY=${STRIPE_SECRET_KEY:-}
STRIPE_WEBHOOK_SECRET=${STRIPE_WEBHOOK_SECRET:-}
STRIPE_PRICE_STARTER=${STRIPE_PRICE_STARTER:-}
STRIPE_PRICE_PRO=${STRIPE_PRICE_PRO:-}
STRIPE_PRICE_BUSINESS=${STRIPE_PRICE_BUSINESS:-}

FRONTEND_URL=https://$DOMAIN
EOF
else
  log "Patching DB_PASS into existing .env…"
  sed -i "s|^DB_PASS=.*|DB_PASS=$DB_PASS|" "$APP_DIR/.env"
fi
chmod 600 "$APP_DIR/.env"

if grep -q '__SET_ME__' "$APP_DIR/.env"; then
  warn ".env still contains __SET_ME__ — set GOOGLE_API_KEY/ORS_API_KEY/CENSUS_API_KEY env vars and rerun, or nano $APP_DIR/.env"
  exit 1
fi

log "Running database migrations…"
$PHP_BIN scripts/migrate.php

log "Setting permissions…"
mkdir -p storage/cache storage/reports/maps storage/logs storage/exports storage/uploads public/uploads public/app
chown -R www-data:www-data storage/ public/uploads/ public/app/ .env
chmod -R 775 storage/ public/uploads/

# Frontend build — always rebuild so config/vite changes propagate.
# Set SMAPPEN_SKIP_FRONTEND=1 to skip this step on hot redeploys.
if [ -z "${SMAPPEN_SKIP_FRONTEND:-}" ]; then
  log "Building frontend…"
  cd frontend
  cat > .env <<EOF
VITE_API_URL=https://$DOMAIN
VITE_GOOGLE_MAPS_API_KEY=$(grep '^GOOGLE_API_KEY=' "$APP_DIR/.env" | cut -d'=' -f2)
EOF
  npm ci --no-audit --no-fund
  npm run build
  cd ..
  chown -R www-data:www-data public/app/
fi

# Apache vhost (NOT nginx — keeps greendock untouched)
log "Configuring Apache vhost for $DOMAIN…"
cat > "/etc/apache2/sites-available/smappen.conf" <<EOF
<VirtualHost *:80>
    ServerName $DOMAIN
    DocumentRoot $APP_DIR/public

    <Directory $APP_DIR/public>
        AllowOverride All
        Require all granted
        Options -Indexes +FollowSymLinks
    </Directory>

    <FilesMatch \.php\$>
        SetHandler "proxy:unix:/var/run/php/php8.3-fpm.sock|fcgi://localhost"
    </FilesMatch>

    # Let's Encrypt HTTP-01 challenge — must be served as static files, not rewritten
    Alias /.well-known/acme-challenge /var/www/html/.well-known/acme-challenge
    <Directory /var/www/html/.well-known/acme-challenge>
        Require all granted
    </Directory>

    # API → index.php
    RewriteEngine On
    RewriteCond %{REQUEST_URI} ^/api
    RewriteRule ^ /index.php [L]

    # Frontend SPA fallback — use DOCUMENT_ROOT+REQUEST_URI because in
    # VirtualHost context REQUEST_FILENAME equals REQUEST_URI (not the
    # filesystem path), so its -f / -d checks always say "doesn't exist".
    RewriteCond %{DOCUMENT_ROOT}%{REQUEST_URI} !-f
    RewriteCond %{DOCUMENT_ROOT}%{REQUEST_URI} !-d
    RewriteCond %{REQUEST_URI} !^/api
    RewriteRule ^ /app/index.html [L]

    ErrorLog \${APACHE_LOG_DIR}/smappen-error.log
    CustomLog \${APACHE_LOG_DIR}/smappen-access.log combined

    <Directory $APP_DIR/public/app/assets>
        Header set Cache-Control "public, max-age=31536000, immutable"
    </Directory>
</VirtualHost>
EOF
a2ensite smappen.conf >/dev/null

# If certbot's SSL vhost exists from a previous run, delete it so we can
# regenerate it from the updated HTTP vhost (otherwise rewrite fixes only
# apply to :80, not :443).
if [ -f /etc/apache2/sites-enabled/smappen-le-ssl.conf ]; then
  log "Removing stale SSL vhost so it gets regenerated from current HTTP vhost…"
  a2dissite smappen-le-ssl.conf >/dev/null 2>&1 || true
  rm -f /etc/apache2/sites-available/smappen-le-ssl.conf /etc/apache2/sites-enabled/smappen-le-ssl.conf
fi

apache2ctl configtest
systemctl reload apache2
systemctl reload php8.3-fpm 2>/dev/null || systemctl restart php8.3-fpm

# SSL via Cloudflare-aware check
log "Checking DNS for $DOMAIN…"
SERVER_IP=$(curl -fsSL ifconfig.me)
DOMAIN_IP=$(dig +short "$DOMAIN" @1.1.1.1 | grep -E '^[0-9.]+$' | tail -1)

if [ -z "$DOMAIN_IP" ]; then
  warn "$DOMAIN does not resolve yet. Add a Cloudflare DNS A-record (grey cloud / DNS-only): $DOMAIN → $SERVER_IP"
  warn "Once DNS propagates, run: certbot --apache -d $DOMAIN --redirect -m $EMAIL --agree-tos --non-interactive"
elif [ "$SERVER_IP" != "$DOMAIN_IP" ]; then
  warn "$DOMAIN resolves to $DOMAIN_IP (likely Cloudflare proxy), but droplet IP is $SERVER_IP."
  warn "Let's Encrypt won't work through Cloudflare's proxy."
  warn "  Option A (easiest): In Cloudflare DNS, set the smappen A-record to DNS only (grey cloud) → rerun certbot → re-enable proxy with Full (Strict) SSL"
  warn "  Option B: Use Cloudflare Origin CA cert instead"
  warn "Skipping SSL for now. After fixing DNS, run: certbot --apache -d $DOMAIN --redirect -m $EMAIL --agree-tos --non-interactive"
else
  log "DNS OK — requesting Let's Encrypt cert via Apache…"
  if certbot --apache -d "$DOMAIN" --redirect -m "$EMAIL" --agree-tos --non-interactive; then
    ok "SSL cert installed for $DOMAIN"
  else
    warn "certbot failed. Common causes:"
    warn "  - Cloudflare proxy enabled (orange cloud) — set to DNS-only (grey) and retry"
    warn "  - Apache config error — check: apache2ctl -S | grep $DOMAIN"
    warn "  - Hit Let's Encrypt rate limit (5 certs/week per domain)"
    warn "Site is still reachable on http://$DOMAIN"
  fi
fi

# Sanity-check the cert actually covers our subdomain
SERVED_CN=$(echo | openssl s_client -servername "$DOMAIN" -connect "$DOMAIN:443" 2>/dev/null | openssl x509 -noout -subject 2>/dev/null | tr -d '\n')
if [ -n "$SERVED_CN" ] && ! echo "$SERVED_CN" | grep -q "$DOMAIN"; then
  warn "Live SSL cert subject is: $SERVED_CN — does NOT cover $DOMAIN"
  warn "Apache may be falling through to greendock's *:443 vhost. Run: apache2ctl -S"
fi

# Cron
if ! crontab -l 2>/dev/null | grep -q "smappen/scripts/cleanup-cron.php"; then
  log "Installing cleanup cron…"
  (crontab -l 2>/dev/null; echo "*/15 * * * * php $APP_DIR/scripts/cleanup-cron.php >> $APP_DIR/storage/logs/cleanup.log 2>&1") | crontab -
fi

ok "Smappen deployed."
echo
echo "Smoke test:"
echo "  curl -i http://$DOMAIN/api/auth/me            # expect 401 (no auth)"
echo "  curl -i https://$DOMAIN/                      # expect 200 (frontend HTML)"
echo
echo "Open in browser: https://$DOMAIN"
