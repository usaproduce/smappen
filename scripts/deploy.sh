#!/bin/bash
set -e
echo ">>> Deploying Smappen…"
cd "$(dirname "$0")/.."

echo "  ↳ Composer install"
composer install --no-dev --optimize-autoloader

echo "  ↳ Migrations"
php scripts/migrate.php

echo "  ↳ Building frontend"
cd frontend
npm ci
npm run build
cd ..

echo "  ↳ Clearing cache"
rm -rf storage/cache/* 2>/dev/null || true

echo "  ↳ Permissions"
chown -R www-data:www-data storage/ public/uploads/ 2>/dev/null || true
chmod -R 775 storage/ public/uploads/ 2>/dev/null || true

echo "  ↳ Reloading PHP-FPM"
systemctl reload php8.2-fpm 2>/dev/null || true

echo "✅ Deploy complete"
