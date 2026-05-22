# Smappen — Territory Mapping & Demographics

A self-hosted Smappen-style tool for drawing travel-time/distance polygons, layering demographics, and finding nearby businesses.

## Stack
- **Backend:** PHP 8.1+, MySQL 8 (spatial), no framework (custom router + PDO)
- **Frontend:** React 18 + Vite + TypeScript + Tailwind
- **Maps:** Google Maps JS API
- **Isochrones:** OpenRouteService
- **Demographics:** US Census Bureau ACS 5-year
- **Billing:** Stripe Checkout + Customer Portal

## Prerequisites
- PHP 8.1+ with `pdo_mysql`, `curl`, `mbstring`, `gd`, `zip`, `xml`
- MySQL 8+ (spatial indexes required)
- Composer
- Node.js 20 + npm
- API keys: Google Maps Platform, OpenRouteService, US Census Bureau, Stripe

## Quick Start (dev)

```bash
# 1. Backend deps
composer install

# 2. Configure
cp .env.example .env
# → fill in DB credentials and API keys

# 3. Database
php scripts/migrate.php

# 4. (Optional) Seed Census data
#   Download TIGER/Line tract shapefiles → convert to GeoJSON → run:
php scripts/seed-census.php tracts /path/to/tracts.geojson
php scripts/seed-census.php all-states

# 5. Backend dev server
php -S localhost:8080 -t public

# 6. Frontend
cd frontend
cp .env.example .env
npm install
npm run dev
# → http://localhost:5173 (proxies /api → :8080)
```

## Production Deploy

```bash
# On a fresh Ubuntu 22.04 droplet, with code at /smappen:
sudo bash scripts/setup.sh
# Then fill in /smappen/.env and run:
sudo bash scripts/deploy.sh
# Configure Nginx:
sudo cp nginx.conf /etc/nginx/sites-available/smappen
sudo ln -s /etc/nginx/sites-available/smappen /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
sudo certbot --nginx -d yourdomain.com
```

Cron (every 15 min) for cleanup:
```
*/15 * * * * php /smappen/scripts/cleanup-cron.php >> /smappen/storage/logs/cleanup.log 2>&1
```

## API Endpoints

| Method | Path | Notes |
|---|---|---|
| POST | `/api/auth/register` | { email, password, name, organization_name? } |
| POST | `/api/auth/login` | { email, password } → token |
| GET | `/api/auth/me` | Current user |
| GET/POST | `/api/projects` | List/create projects |
| GET/PUT/DELETE | `/api/projects/{id}` | Single project CRUD |
| GET | `/api/shared/{token}` | Public read-only project |
| GET/POST | `/api/projects/{id}/folders` | Folder tree |
| GET/POST | `/api/projects/{id}/areas` | Areas in project |
| GET/PUT/DELETE | `/api/areas/{id}` | Single area |
| GET | `/api/areas/{id}/demographics` | ACS-aggregated demographics |
| GET | `/api/areas/{id}/pois` | Cached POIs in area |
| POST | `/api/isochrone/calculate` | ORS isochrone |
| POST | `/api/geocode` / `/batch` | Google geocoding |
| POST | `/api/places/nearby` / `/search` | Google Places |
| POST | `/api/projects/{id}/import/upload` | CSV/XLSX upload |
| POST | `/api/projects/{id}/import/configure` | Apply column mapping |
| GET | `/api/projects/{id}/export/areas` | Export areas |
| POST | `/api/areas/{id}/report` | Generate PDF report |
| POST | `/api/billing/checkout` | Start Stripe checkout |
| GET | `/api/billing/subscription` | Current plan + usage |

## Environment Variables

See `.env.example` — fill every key.

## License

Proprietary. All rights reserved.
