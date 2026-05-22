#!/bin/bash
# Smappen one-shot droplet deploy.
# Run on a fresh Ubuntu 22.04+ droplet as root (or with sudo).
#
# USAGE:
#   curl -fsSL https://raw.githubusercontent.com/<USER>/smappen/main/scripts/droplet-deploy.sh -o deploy.sh
#   chmod +x deploy.sh
#   GITHUB_REPO=https://github.com/<USER>/smappen.git \
#   DOMAIN=smappen.mygreendock.com \
#   EMAIL=adam.smith1735@gmail.com \
#   ./deploy.sh

set -e

: "${GITHUB_REPO:?Set GITHUB_REPO env var (e.g. https://github.com/you/smappen.git)}"
: "${DOMAIN:?Set DOMAIN env var (e.g. smappen.mygreendock.com)}"
: "${EMAIL:?Set EMAIL env var (for Let's Encrypt)}"

APP_DIR=/var/www/smappen
DB_NAME=smappen
DB_USER=smappen
DB_PASS_FILE=/root/.smappen_db_pass

log() { echo -e "\n\033[1;36m>>> $*\033[0m"; }

if [ "$EUID" -ne 0 ]; then
  echo "Run as root (sudo)."
  exit 1
fi

log "Installing system packages…"
export DEBIAN_FRONTEND=noninteractive
apt-get update -y
apt-get install -y software-properties-common curl ca-certificates lsb-release apt-transport-https gnupg unzip git
add-apt-repository -y ppa:ondrej/php
apt-get update -y
apt-get install -y \
  php8.2 php8.2-fpm php8.2-mysql php8.2-curl php8.2-mbstring php8.2-xml php8.2-zip php8.2-gd php8.2-bcmath \
  nginx certbot python3-certbot-nginx wkhtmltopdf

# MySQL (skip if greendock already installed it)
if ! command -v mysql >/dev/null 2>&1; then
  apt-get install -y mysql-server
fi

# Composer
if ! command -v composer >/dev/null 2>&1; then
  log "Installing Composer…"
  curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
fi

# Node 20 (only if not present)
if ! command -v node >/dev/null 2>&1; then
  log "Installing Node 20…"
  curl -fsSL https://deb.nodesource.com/setup_20.x | bash -
  apt-get install -y nodejs
fi

# DB credentials
if [ ! -f "$DB_PASS_FILE" ]; then
  log "Creating MySQL database + user…"
  DB_PASS=$(openssl rand -hex 16)
  echo "$DB_PASS" > "$DB_PASS_FILE"
  chmod 600 "$DB_PASS_FILE"
  mysql -e "CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
  mysql -e "CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';"
  mysql -e "GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost'; FLUSH PRIVILEGES;"
else
  DB_PASS=$(cat "$DB_PASS_FILE")
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

# Composer
log "Installing PHP dependencies…"
composer install --no-dev --optimize-autoloader --no-interaction

# .env — build fresh if missing, else patch DB_PASS into existing
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
chown www-data:www-data "$APP_DIR/.env"

# Sanity check: bail if any API key is still a placeholder
if grep -q '__SET_ME__' "$APP_DIR/.env"; then
  echo
  echo "⚠️  .env still contains __SET_ME__ placeholders."
  echo "    Either: re-run with GOOGLE_API_KEY=… ORS_API_KEY=… CENSUS_API_KEY=… ./deploy.sh"
  echo "    Or:     nano $APP_DIR/.env"
  exit 1
fi

# Migrations
log "Running database migrations…"
php scripts/migrate.php

# Permissions
log "Setting permissions…"
mkdir -p storage/cache storage/reports/maps storage/logs storage/exports storage/uploads public/uploads public/app
chown -R www-data:www-data storage/ public/uploads/ public/app/
chmod -R 775 storage/ public/uploads/

# Frontend build (if dist not present in repo)
if [ ! -f "$APP_DIR/public/app/index.html" ]; then
  log "Building frontend…"
  cd frontend
  if [ ! -f .env ]; then
    cat > .env <<EOF
VITE_API_URL=https://$DOMAIN
VITE_GOOGLE_MAPS_API_KEY=$(grep '^GOOGLE_API_KEY=' "$APP_DIR/.env" | cut -d'=' -f2)
EOF
  fi
  npm ci
  npm run build
  cd ..
fi

# Nginx config
log "Configuring nginx for $DOMAIN…"
cat > "/etc/nginx/sites-available/smappen" <<EOF
server {
    listen 80;
    server_name $DOMAIN;
    root $APP_DIR/public;
    index index.php index.html;

    location /api {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location / {
        try_files \$uri \$uri/ /app/index.html;
    }

    location ~ \.php\$ {
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_read_timeout 60;
    }

    location ~ /\. { deny all; }

    location /app/assets {
        expires 30d;
        add_header Cache-Control "public, immutable";
    }

    client_max_body_size 20M;
    gzip on;
    gzip_types text/plain text/css application/json application/javascript application/geo+json image/svg+xml;
}
EOF
ln -sf /etc/nginx/sites-available/smappen /etc/nginx/sites-enabled/smappen
nginx -t
systemctl reload nginx

# SSL — only attempt if DOMAIN resolves to this server
log "Checking DNS for $DOMAIN…"
SERVER_IP=$(curl -fsSL ifconfig.me)
DOMAIN_IP=$(dig +short "$DOMAIN" | tail -1)
if [ "$SERVER_IP" = "$DOMAIN_IP" ]; then
  log "DNS OK — requesting Let's Encrypt cert…"
  certbot --nginx -d "$DOMAIN" --non-interactive --agree-tos -m "$EMAIL" --redirect || true
else
  echo "⚠️  $DOMAIN does not yet resolve to $SERVER_IP (got: $DOMAIN_IP)."
  echo "    Add a DNS A-record: $DOMAIN → $SERVER_IP"
  echo "    Then run:  certbot --nginx -d $DOMAIN --redirect -m $EMAIL --agree-tos"
fi

# PHP-FPM reload
systemctl reload php8.2-fpm

# Cron for cleanup
if ! crontab -l 2>/dev/null | grep -q "smappen/scripts/cleanup-cron.php"; then
  log "Installing cleanup cron…"
  (crontab -l 2>/dev/null; echo "*/15 * * * * php $APP_DIR/scripts/cleanup-cron.php >> $APP_DIR/storage/logs/cleanup.log 2>&1") | crontab -
fi

log "✅ Smappen deployed to https://$DOMAIN"
echo
echo "Smoke test:"
echo "  curl -i https://$DOMAIN/api/auth/me     # expect 401"
echo "  open https://$DOMAIN in browser"
