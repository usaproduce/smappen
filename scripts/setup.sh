#!/bin/bash
# Smappen Server Setup — Ubuntu 22.04+
# Run as root on a fresh DigitalOcean droplet.
set -e

echo ">>> Updating system…"
apt update && apt upgrade -y

echo ">>> Installing PHP 8.2 + extensions…"
apt install -y software-properties-common
add-apt-repository ppa:ondrej/php -y
apt update
apt install -y php8.2 php8.2-fpm php8.2-mysql php8.2-curl php8.2-mbstring php8.2-xml php8.2-zip php8.2-gd php8.2-bcmath

echo ">>> Installing MySQL 8…"
apt install -y mysql-server

echo ">>> Installing Composer…"
curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

echo ">>> Installing Node.js 20…"
curl -fsSL https://deb.nodesource.com/setup_20.x | bash -
apt install -y nodejs

echo ">>> Installing Nginx + Certbot + wkhtmltopdf…"
apt install -y nginx certbot python3-certbot-nginx wkhtmltopdf

echo ">>> Creating MySQL database…"
DB_NAME=${DB_NAME:-smappen}
DB_USER=${DB_USER:-smappen}
DB_PASS=${DB_PASS:-$(openssl rand -hex 12)}
mysql -e "CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -e "CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';"
mysql -e "GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost'; FLUSH PRIVILEGES;"
echo "  ↳ DB password: $DB_PASS"

echo ">>> Setting permissions…"
chown -R www-data:www-data /smappen/storage /smappen/public/uploads 2>/dev/null || true
chmod -R 775 /smappen/storage /smappen/public/uploads 2>/dev/null || true

echo
echo "✅ Setup complete. Next steps:"
echo "  1. cp /smappen/.env.example /smappen/.env  → fill in values (DB_PASS above)"
echo "  2. cd /smappen && composer install --no-dev --optimize-autoloader"
echo "  3. php scripts/migrate.php"
echo "  4. cd frontend && npm install && npm run build"
echo "  5. cp /smappen/nginx.conf /etc/nginx/sites-available/smappen && ln -s ../sites-available/smappen /etc/nginx/sites-enabled/"
echo "  6. nginx -t && systemctl reload nginx"
echo "  7. certbot --nginx -d yourdomain.com"
