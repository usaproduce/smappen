# Smappen Clone — Claude Code Build Prompts (Sequential)

## How to Use This File

Copy each prompt below into Claude Code (in VS Code) **one at a time, in order**. Wait for each to complete before moving to the next. Each prompt builds on the previous one's output.

**Environment assumptions:**
- DigitalOcean droplet with PHP 8.1+, MySQL 8+, Composer, Node.js/npm installed
- Apache or Nginx serving from a web root
- Project root: `/smappen/`
- You have Google Maps API key, Census API key, and OpenRouteService API key ready

---

## PROMPT 1 — Project Scaffolding & Directory Structure

```
Create a PHP project at /smappen/ with the following structure. Use vanilla PHP with a simple custom router (no Laravel). Create all directories and placeholder files:

/smappen/
├── public/                    # Web root (point Apache/Nginx here)
│   ├── index.php              # Entry point, loads router
│   ├── .htaccess              # Apache rewrite rules to route all requests through index.php
│   ├── assets/
│   │   ├── css/
│   │   │   └── app.css        # Main stylesheet (empty for now)
│   │   └── js/
│   │       └── app.js         # Main JS entry point (empty for now)
│   └── uploads/               # User CSV/Excel uploads (writable)
├── src/
│   ├── Core/
│   │   ├── Router.php         # Simple regex-based API router supporting GET, POST, PUT, DELETE
│   │   ├── Database.php       # PDO MySQL singleton connection class
│   │   ├── Request.php        # Request helper (parses JSON body, query params, headers)
│   │   ├── Response.php       # JSON response helper with status codes
│   │   ├── Middleware.php     # Auth middleware (JWT validation)
│   │   └── Config.php         # Loads .env variables
│   ├── Controllers/
│   │   ├── AuthController.php
│   │   ├── ProjectController.php
│   │   ├── AreaController.php
│   │   ├── FolderController.php
│   │   ├── IsochroneController.php
│   │   ├── DemographicsController.php
│   │   ├── PlacesController.php
│   │   ├── ImportController.php
│   │   ├── ExportController.php
│   │   ├── ReportController.php
│   │   └── BillingController.php
│   ├── Models/
│   │   ├── User.php
│   │   ├── Organization.php
│   │   ├── Project.php
│   │   ├── Area.php
│   │   ├── Folder.php
│   │   ├── ImportedPoint.php
│   │   ├── POICache.php
│   │   └── Report.php
│   ├── Services/
│   │   ├── GoogleMapsService.php    # Google Geocoding + Places API calls
│   │   ├── IsochroneService.php     # OpenRouteService API calls
│   │   ├── CensusService.php        # US Census Bureau API calls
│   │   ├── CacheService.php         # Simple file-based or MySQL cache
│   │   ├── GeoUtils.php             # Spatial helper functions
│   │   └── StripeService.php        # Stripe billing integration
│   └── Migrations/
│       └── 001_initial_schema.sql   # Full database schema
├── config/
│   ├── routes.php             # All API route definitions
│   └── cors.php               # CORS headers config
├── storage/
│   ├── cache/                 # File-based cache directory
│   ├── reports/               # Generated PDF reports
│   └── logs/                  # Application logs
├── scripts/
│   ├── migrate.php            # Run SQL migrations
│   ├── seed-census.php        # Census data import script
│   └── setup.sh               # Server setup script
├── .env.example               # Environment variable template
├── composer.json              # PHP dependencies
└── README.md                  # Project documentation

For the Router.php, implement a simple but robust router that:
- Matches routes with regex patterns and extracts URL parameters (e.g., /api/areas/{id})
- Supports GET, POST, PUT, DELETE methods
- Supports middleware (auth check before controller)
- Returns JSON responses with proper Content-Type headers
- Handles CORS preflight OPTIONS requests

For Database.php, implement a PDO singleton that:
- Reads credentials from .env
- Uses MySQL 8 with utf8mb4 charset
- Has helper methods: query(), fetch(), fetchAll(), insert(), update(), delete()
- Supports prepared statements with named parameters

For the .env.example, include placeholders for:
- DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS
- GOOGLE_API_KEY
- ORS_API_KEY (OpenRouteService)
- CENSUS_API_KEY
- STRIPE_SECRET_KEY, STRIPE_WEBHOOK_SECRET
- JWT_SECRET
- APP_URL, APP_ENV (development/production)

For composer.json, require:
- firebase/php-jwt (JWT auth)
- stripe/stripe-php (billing)
- vlucas/phpdotenv (env loading)

Create all files with proper PHP namespace structure (App\Core, App\Controllers, App\Models, App\Services). Every file should have its namespace declaration and use statements ready. Controller methods can be empty stubs for now with a // TODO comment.
```

---

## PROMPT 2 — Database Schema & Migration

```
In /smappen/src/Migrations/001_initial_schema.sql, write the complete MySQL 8 database schema. MySQL 8 supports spatial data types and spatial indexes natively, so use them.

Create these tables:

1. organizations — id (CHAR(36) UUID), name, stripe_customer_id, stripe_subscription_id, plan ENUM('free','starter','pro','business','enterprise'), max_seats INT DEFAULT 1, created_at, updated_at

2. users — id (CHAR(36) UUID), email (UNIQUE), password_hash, name, organization_id (FK), role ENUM('owner','admin','member'), is_active BOOLEAN, last_login_at, created_at, updated_at

3. projects — id UUID, organization_id (FK), name, description TEXT, center_lat DOUBLE, center_lng DOUBLE, zoom_level INT DEFAULT 10, is_shared BOOLEAN DEFAULT FALSE, share_token VARCHAR(64), created_by (FK users), created_at, updated_at

4. folders — id UUID, project_id (FK CASCADE), name, color VARCHAR(7) DEFAULT '#6B4EFF', sort_order INT DEFAULT 0, parent_folder_id (FK self-referencing), created_at

5. areas — id UUID, project_id (FK CASCADE), folder_id (FK SET NULL), name, area_type ENUM('isochrone','isodistance','manual','radius'), center_lat DOUBLE, center_lng DOUBLE, center_address TEXT, travel_mode VARCHAR(50), travel_time_minutes INT, travel_distance_km DOUBLE, geometry POLYGON NOT NULL SRID 4326, fill_color VARCHAR(7), fill_opacity DOUBLE DEFAULT 0.3, stroke_color VARCHAR(7), stroke_weight INT DEFAULT 2, demographics_cache JSON, demographics_cached_at DATETIME, notes TEXT, created_by (FK users), created_at, updated_at. Add a SPATIAL INDEX on geometry.

6. imported_points — id UUID, project_id (FK CASCADE), import_batch_id CHAR(36), label VARCHAR(255), address TEXT, lat DOUBLE, lng DOUBLE, point POINT NOT NULL SRID 4326, custom_data JSON, created_at. SPATIAL INDEX on point.

7. poi_cache — id UUID, query_hash VARCHAR(64), area_id (FK areas), results JSON, cached_at DATETIME, expires_at DATETIME. INDEX on query_hash.

8. census_tracts — geoid VARCHAR(11) PRIMARY KEY, state_fips VARCHAR(2), county_fips VARCHAR(3), tract_id VARCHAR(6), name VARCHAR(100), geometry MULTIPOLYGON NOT NULL SRID 4326, land_area_sqm DOUBLE, water_area_sqm DOUBLE. SPATIAL INDEX on geometry.

9. census_demographics — geoid VARCHAR(11) PRIMARY KEY (FK census_tracts), total_population INT, median_household_income INT, median_home_value INT, labor_force_total INT, unemployed_total INT, male_total INT, female_total INT, housing_units_total INT, age_under_18 INT, age_18_to_34 INT, age_35_to_54 INT, age_55_to_64 INT, age_65_plus INT, income_under_25k INT, income_25k_to_50k INT, income_50k_to_75k INT, income_75k_to_100k INT, income_100k_plus INT, data_year INT, updated_at DATETIME

10. reports — id UUID, area_id (FK areas), project_id (FK projects), report_type ENUM('area_analysis','comparison','territory_overview'), title VARCHAR(255), file_path TEXT, generated_at DATETIME, generated_by (FK users)

11. api_usage_log — id BIGINT AUTO_INCREMENT, user_id (FK users), api_name VARCHAR(50), endpoint VARCHAR(255), request_count INT DEFAULT 1, created_at DATETIME. INDEX on (user_id, api_name, created_at) for usage tracking.

12. audit_log — id BIGINT AUTO_INCREMENT, user_id (FK users), action VARCHAR(100), entity_type VARCHAR(50), entity_id CHAR(36), details JSON, created_at DATETIME

Use UUID generation with UUID() in MySQL. All foreign keys should have appropriate ON DELETE behavior (CASCADE for child records, SET NULL for optional references). All tables use InnoDB engine.

Also update /smappen/scripts/migrate.php to:
- Load .env for DB credentials
- Read all .sql files from src/Migrations/ in order
- Execute them against the database
- Track which migrations have been run in a migrations table
- Output progress to console
```

---

## PROMPT 3 — Core Framework (Router, Auth, Request/Response)

```
In /smappen/, fully implement these core framework files:

1. src/Core/Config.php — Load .env file using vlucas/phpdotenv, provide static get() method to access any env variable.

2. src/Core/Database.php — Full PDO MySQL singleton:
   - Private constructor, getInstance() static method
   - Connection with: utf8mb4, ERRMODE_EXCEPTION, FETCH_ASSOC defaults
   - Methods: query($sql, $params), fetch($sql, $params), fetchAll($sql, $params), insert($table, $data) returning last insert id, update($table, $data, $where, $whereParams) returning affected rows, delete($table, $where, $whereParams)
   - The insert() method should auto-generate UUID for 'id' column if not provided
   - Transaction support: beginTransaction(), commit(), rollback()

3. src/Core/Request.php — Request parser:
   - getMethod(), getPath(), getBody() (JSON decoded), getQuery(), getHeader()
   - getParam($name) for route parameters (set by router)
   - getBearerToken() extracts JWT from Authorization header
   - getFile($name) for file uploads

4. src/Core/Response.php — JSON response helper:
   - json($data, $statusCode = 200)
   - error($message, $statusCode = 400, $details = null)
   - success($data, $message = null)
   - paginated($data, $total, $page, $perPage)
   - All responses set Content-Type: application/json and include proper CORS headers

5. src/Core/Router.php — Full router implementation:
   - register($method, $pattern, $handler, $middleware = [])
   - Pattern supports {param} placeholders converted to named regex groups
   - dispatch($request) matches current request to registered route
   - Runs middleware chain before handler
   - Handler format: [ControllerClass::class, 'methodName']
   - 404 response for unmatched routes, 405 for wrong method

6. src/Core/Middleware.php — Auth middleware:
   - auth() — validates JWT from Authorization header, sets user on request, returns 401 if invalid
   - optionalAuth() — same but doesn't fail if no token present
   - rateLimit($maxRequests, $windowSeconds) — per-user rate limiting using MySQL api_usage_log table
   - planCheck($requiredPlan) — checks if user's org plan meets minimum tier

7. public/index.php — Entry point:
   - Require composer autoloader
   - Load .env config
   - Handle CORS preflight (OPTIONS requests return 200 with CORS headers)
   - Create Request, Router instances
   - Load routes from config/routes.php
   - Dispatch request through router
   - Catch exceptions and return error responses

8. config/routes.php — Register all API routes:
   POST /api/auth/register
   POST /api/auth/login
   POST /api/auth/refresh
   GET /api/auth/me (auth middleware)

   GET /api/projects (auth)
   POST /api/projects (auth)
   GET /api/projects/{id} (auth)
   PUT /api/projects/{id} (auth)
   DELETE /api/projects/{id} (auth)

   GET /api/projects/{projectId}/folders (auth)
   POST /api/projects/{projectId}/folders (auth)
   PUT /api/folders/{id} (auth)
   DELETE /api/folders/{id} (auth)

   GET /api/projects/{projectId}/areas (auth)
   POST /api/projects/{projectId}/areas (auth)
   GET /api/areas/{id} (auth)
   PUT /api/areas/{id} (auth)
   DELETE /api/areas/{id} (auth)
   GET /api/areas/{id}/demographics (auth)
   GET /api/areas/{id}/pois (auth)

   POST /api/isochrone/calculate (auth)

   POST /api/geocode (auth)
   POST /api/geocode/batch (auth)

   POST /api/places/nearby (auth)
   POST /api/places/search (auth)
   GET /api/places/{placeId} (auth)

   POST /api/projects/{projectId}/import (auth)
   GET /api/projects/{projectId}/export (auth)

   POST /api/areas/{id}/report (auth)
   GET /api/reports/{id}/download (auth)

   POST /api/billing/create-checkout (auth)
   POST /api/billing/webhook
   GET /api/billing/subscription (auth)

9. config/cors.php — CORS configuration allowing configurable origins, methods, headers.

Make sure index.php handles errors gracefully with try/catch, logs errors to storage/logs/, and never exposes stack traces in production (check APP_ENV).
```

---

## PROMPT 4 — Auth System (Register, Login, JWT)

```
In /smappen/, fully implement the authentication system:

1. src/Controllers/AuthController.php:

   register($request):
   - Accept: email, password, name, organization_name (optional)
   - Validate: email format, password min 8 chars, name required
   - Check email uniqueness
   - Hash password with password_hash() using PASSWORD_BCRYPT
   - If organization_name provided, create organization first, set user as 'owner'
   - If not, create a default organization named "{name}'s Workspace"
   - Create user with generated UUID
   - Generate JWT token (24hr expiry) containing: user_id, email, organization_id, role
   - Return: user object (without password_hash) + token

   login($request):
   - Accept: email, password
   - Find user by email
   - Verify with password_verify()
   - Update last_login_at
   - Generate JWT token
   - Return: user object + token

   me($request):
   - Return current authenticated user (from middleware) with organization details
   - Include: plan, role, organization name

   refresh($request):
   - Accept existing valid token
   - Issue new token with fresh expiry
   - Return new token

2. src/Models/User.php:
   - findByEmail($email)
   - findById($id)
   - create($data) — inserts user, returns full user object
   - update($id, $data)
   - getWithOrganization($id) — joins with organizations table

3. src/Models/Organization.php:
   - create($data)
   - findById($id)
   - updatePlan($id, $plan)
   - getMemberCount($id)

Use firebase/php-jwt for token generation and validation. The JWT secret comes from .env JWT_SECRET.

The Middleware auth() method should:
- Extract token from "Authorization: Bearer {token}" header
- Decode and validate with firebase/php-jwt
- Fetch full user record from DB
- Attach user to request object so controllers can access $request->user
- Return 401 with clear error message if token is missing, expired, or invalid

Test that you can run: php scripts/migrate.php to create the database, then test register and login endpoints manually.
```

---

## PROMPT 5 — Project & Folder CRUD

```
In /smappen/, implement full CRUD for projects and folders:

1. src/Controllers/ProjectController.php:

   index($request):
   - List all projects for the authenticated user's organization
   - Support query params: ?search=term, ?page=1, ?per_page=20
   - Return paginated results with total count
   - Each project includes: area_count (COUNT of areas), last_updated

   store($request):
   - Accept: name (required), description, center_lat, center_lng, zoom_level
   - Create project linked to user's organization
   - Set created_by to current user
   - Return created project

   show($request):
   - Get project by ID
   - Verify it belongs to user's organization (return 403 if not)
   - Include: folders (nested tree), area_count, created_by user name

   update($request):
   - Accept: name, description, center_lat, center_lng, zoom_level, is_shared
   - If is_shared set to true and no share_token exists, generate one (random 32-char hex)
   - Verify ownership
   - Return updated project

   destroy($request):
   - Verify ownership
   - Delete project (CASCADE will delete folders, areas, imported points)
   - Return success message

   shared($request):
   - Public endpoint (no auth required)
   - Accept share_token as URL parameter
   - Return project with areas and folders (read-only view)

2. src/Controllers/FolderController.php:

   index($request):
   - List folders for a project as a nested tree structure
   - Each folder includes its child folders and area_count
   - Sort by sort_order

   store($request):
   - Accept: name, color, parent_folder_id (optional for nesting), sort_order
   - Validate project belongs to user's org
   - Return created folder

   update($request):
   - Accept: name, color, parent_folder_id, sort_order
   - Support reordering (update sort_order for siblings)
   - Return updated folder

   destroy($request):
   - Move any areas in this folder to "unfiled" (set folder_id to NULL)
   - Move child folders to parent (or root if no parent)
   - Delete folder
   - Return success

3. src/Models/Project.php:
   - All CRUD methods
   - getByOrganization($orgId, $search, $page, $perPage)
   - getByShareToken($token)
   - generateShareToken()

4. src/Models/Folder.php:
   - All CRUD methods
   - getTreeByProject($projectId) — returns nested folder structure
   - reorder($projectId, $orderedIds) — bulk update sort_order

Add the shared project route to config/routes.php:
   GET /api/shared/{shareToken} (no auth)

All controllers should validate that the requested resource belongs to the authenticated user's organization. Return 403 Forbidden if not.
```

---

## PROMPT 6 — Isochrone Service & Area Creation

```
In /smappen/, implement the isochrone calculation and area management:

1. src/Services/IsochroneService.php:

   calculate($lat, $lng, $timeMinutes, $travelMode = 'driving-car'):
   - Build cache key from parameters: md5("{$lat},{$lng},{$timeMinutes},{$travelMode}")
   - Check cache (CacheService) first, return cached result if < 24 hours old
   - Call OpenRouteService API:
     POST https://api.openrouteservice.org/v2/isochrones/{travelMode}
     Headers: Authorization: {ORS_API_KEY}, Content-Type: application/json
     Body: {
       "locations": [[lng, lat]],  // NOTE: ORS uses [longitude, latitude] order
       "range": [timeMinutes * 60],
       "range_type": "time",
       "smoothing": 25
     }
   - Parse response: extract features[0].geometry (GeoJSON Polygon)
   - Convert GeoJSON polygon to MySQL-compatible WKT (Well-Known Text) format
   - Cache the result
   - Return both GeoJSON (for frontend) and WKT (for database storage)
   - Handle errors: API timeout, rate limit, invalid coordinates

   calculateRadius($lat, $lng, $radiusKm):
   - Generate a circle polygon (64-point approximation) using basic trig
   - Return as GeoJSON and WKT
   - No external API needed

   Supported travel modes: 'driving-car', 'cycling-regular', 'foot-walking', 'wheelchair'

2. src/Services/GeoUtils.php:
   - geoJsonToWkt($geoJson) — convert GeoJSON Polygon to WKT string for MySQL
   - wktToGeoJson($wkt) — convert MySQL WKT back to GeoJSON
   - generateCirclePolygon($lat, $lng, $radiusKm, $points = 64) — create circle as polygon
   - calculateArea($geoJson) — area in sq km using Haversine approximation
   - pointInPolygon($lat, $lng, $polygonGeoJson) — ray casting algorithm
   - getBoundingBox($geoJson) — return [minLng, minLat, maxLng, maxLat]
   - polygonOverlap($polygon1, $polygon2) — check if two polygons intersect (use MySQL spatial query)

3. src/Controllers/IsochroneController.php:

   calculate($request):
   - Accept: lat, lng, time_minutes, travel_mode, name (optional)
   - Validate: lat/lng in valid ranges, time 1-720 minutes, valid travel_mode
   - Check rate limit based on user's plan:
     free: 5/day, starter: 50/day, pro: unlimited, business: unlimited
   - Call IsochroneService->calculate()
   - Return: GeoJSON polygon, area_sq_km, bounding_box
   - Do NOT auto-save as area (that's a separate step)
   - Log API usage

4. src/Controllers/AreaController.php:

   index($request):
   - List all areas for a project
   - Support filter by folder_id
   - Include: center coordinates, type, travel_mode, travel_time, basic demographics summary
   - Return as GeoJSON FeatureCollection (each area is a Feature with properties)

   store($request):
   - Accept: project_id, name, area_type, folder_id (optional),
     center_lat, center_lng, center_address, travel_mode, travel_time_minutes,
     travel_distance_km, geometry (GeoJSON), fill_color, fill_opacity,
     stroke_color, stroke_weight, notes
   - Convert GeoJSON geometry to WKT and store using ST_GeomFromText($wkt, 4326)
   - Return created area with geometry as GeoJSON

   show($request):
   - Get single area with full details
   - Include geometry as GeoJSON (use ST_AsGeoJSON())
   - Include cached demographics if available

   update($request):
   - Update any area fields
   - If geometry changes, invalidate demographics_cache
   - Return updated area

   destroy($request):
   - Delete area
   - Return success

   demographics($request):
   - Get demographics for an area (see Prompt 8)
   - Delegates to DemographicsController

   pois($request):
   - Get POIs within an area (see Prompt 9)
   - Delegates to PlacesController

5. src/Models/Area.php:
   - Override create/update to handle geometry conversion (GeoJSON <-> WKT)
   - getByProject($projectId, $folderId = null) with geometry as GeoJSON
   - getAsGeoJson($id) — returns area with ST_AsGeoJSON(geometry) conversion
   - findOverlapping($projectId, $geometryWkt) — find areas that intersect with given polygon
   - All spatial queries should use ST_GeomFromText() for input and ST_AsGeoJSON() for output

Make sure MySQL spatial functions are used correctly:
- INSERT: ST_GeomFromText('POLYGON((...))' , 4326)
- SELECT: ST_AsGeoJSON(geometry) as geometry_geojson
- Overlap: ST_Intersects(a.geometry, ST_GeomFromText(..., 4326))
```

---

## PROMPT 7 — Google Maps & Geocoding Services

```
In /smappen/, implement the Google Maps API integration services:

1. src/Services/GoogleMapsService.php:

   Properties:
   - $apiKey (from .env GOOGLE_API_KEY)
   - $cache (CacheService instance)

   geocode($address):
   - Cache key: md5("geocode:" . strtolower(trim($address)))
   - Cache duration: permanent (addresses don't move)
   - Call: GET https://maps.googleapis.com/maps/api/geocode/json
     Params: address={urlencoded}, key={apiKey}
   - Parse: results[0].geometry.location.lat/lng, formatted_address, place_id
   - Return: { lat, lng, formatted_address, place_id, components: { city, state, zip, country } }
   - Handle: ZERO_RESULTS, OVER_QUERY_LIMIT, REQUEST_DENIED, INVALID_REQUEST

   reverseGeocode($lat, $lng):
   - Cache key: md5("revgeo:{$lat},{$lng}")
   - Call: GET with latlng={lat},{lng}
   - Return: { formatted_address, components }

   batchGeocode($addresses, $concurrency = 5):
   - Process array of addresses in batches
   - Rate limit: max 50/second (sleep between batches)
   - Return array of results with original index preserved
   - Track successes and failures separately
   - Return: { results: [...], success_count, failure_count, failures: [{index, address, error}] }

   searchPlacesNearby($lat, $lng, $radiusMeters, $type = null, $keyword = null):
   - Cache key: md5 of all params, TTL 48 hours
   - Call: POST https://places.googleapis.com/v1/places:searchNearby
   - Headers: X-Goog-Api-Key, X-Goog-FieldMask (request only needed fields)
   - Field mask: places.id,places.displayName,places.formattedAddress,places.location,places.types,places.businessStatus,places.nationalPhoneNumber,places.websiteUri,places.rating,places.userRatingCount
   - Body: { includedTypes, keyword, locationRestriction.circle, maxResultCount: 20, languageCode: 'en' }
   - Return: array of place objects

   searchPlacesText($query, $lat, $lng, $radiusMeters):
   - Similar to above but uses Text Search endpoint
   - POST https://places.googleapis.com/v1/places:searchText
   - Body: { textQuery, locationBias.circle }

   getPlaceDetails($placeId):
   - Cache key: "place:{$placeId}", TTL 72 hours
   - GET https://places.googleapis.com/v1/places/{placeId}
   - Return full place details

   Private helper methods:
   - makeRequest($url, $method, $headers, $body) — cURL wrapper with timeout, error handling, retry on 5xx
   - logApiUsage($endpoint, $userId) — insert into api_usage_log table

2. src/Controllers/PlacesController.php (previously stubbed):

   nearby($request):
   - Accept: lat, lng, radius_meters (max 50000), type, keyword
   - Optional: area_id — if provided, filter results to points inside that area's polygon
   - Call GoogleMapsService->searchPlacesNearby()
   - If area_id provided: for each result, check if point falls inside area polygon using MySQL ST_Contains()
   - Return: filtered places array with count

   search($request):
   - Accept: query (required), lat, lng, radius_meters
   - Call GoogleMapsService->searchPlacesText()
   - Return places array

   show($request):
   - Accept: placeId from URL
   - Call GoogleMapsService->getPlaceDetails()
   - Return place details

3. src/Controllers/AuthController.php — update geocode endpoints (add these as new methods or a separate GeocodingController):

   Actually, create src/Controllers/GeocodingController.php:

   geocode($request):
   - Accept: address (string)
   - Call GoogleMapsService->geocode()
   - Log usage
   - Return geocoded result

   batchGeocode($request):
   - Accept: addresses (array of strings, max 500)
   - Plan check: free=10/batch, starter=100, pro=500, business=500
   - Call GoogleMapsService->batchGeocode()
   - Return results with success/failure counts

   Update config/routes.php to add:
   POST /api/geocode → GeocodingController@geocode (auth)
   POST /api/geocode/batch → GeocodingController@batchGeocode (auth)

4. src/Services/CacheService.php:
   - Uses MySQL table for cache (create a simple cache table: key VARCHAR(255) PRIMARY KEY, value LONGTEXT, expires_at DATETIME)
   - get($key) — returns null if expired or missing
   - set($key, $value, $ttlSeconds)
   - delete($key)
   - flush($prefix) — delete all keys matching prefix
   - Auto-cleanup: delete expired entries on 1% of get() calls randomly

Add the cache table to a new migration file: src/Migrations/002_cache_table.sql
```

---

## PROMPT 8 — Demographics (Census API Integration)

```
In /smappen/, implement the US Census Bureau demographics integration:

1. src/Services/CensusService.php:

   Properties:
   - $apiKey from .env CENSUS_API_KEY
   - $baseUrl = 'https://api.census.gov/data'
   - $acsYear = '2023'
   - $acsDataset = 'acs/acs5'
   - Variable mappings as a class constant array:
     VARIABLES = [
       'total_population' => 'B01003_001E',
       'male_total' => 'B01001_002E',
       'female_total' => 'B01001_026E',
       'median_household_income' => 'B19013_001E',
       'median_home_value' => 'B25077_001E',
       'labor_force_total' => 'B23025_002E',
       'unemployed_total' => 'B23025_005E',
       'housing_units_total' => 'B25001_001E',
       // Age brackets from B01001 table
       'age_under_5_m' => 'B01001_003E',
       'age_5_to_17_m' => 'B01001_004E',
       // ... map all needed age bracket variables for both male and female
       // Income brackets from B19001
       'income_under_10k' => 'B19001_002E',
       'income_10k_to_15k' => 'B19001_003E',
       // ... map all income brackets
     ]

   fetchDemographicsForState($stateFips):
   - Call Census API for all tracts in a state
   - GET {baseUrl}/{acsYear}/{acsDataset}?get={variables}&for=tract:*&in=state:{stateFips}&key={apiKey}
   - Parse CSV-like response (first row is headers, rest is data)
   - Return array of tract demographics

   getDemographicsForArea($areaId):
   - Get the area's polygon from database
   - Find all census tracts that intersect with the area using MySQL spatial query:
     SELECT geoid, ST_AsGeoJSON(geometry) as geom,
            ST_Area(ST_Intersection(ct.geometry, area.geometry)) /
            ST_Area(ct.geometry) AS overlap_pct
     FROM census_tracts ct, areas area
     WHERE area.id = ? AND ST_Intersects(ct.geometry, area.geometry)
   - For each overlapping tract, fetch demographics from census_demographics table
   - Weight each tract's demographics by overlap_pct
   - Aggregate weighted totals:
     * Total population = SUM(tract_pop * overlap_pct)
     * For medians (income, home value): use population-weighted average
     * For counts: SUM(count * overlap_pct)
   - Structure result into sections:
     { population: { total, male, female, density_per_sq_km },
       age: { under_18, 18_to_34, 35_to_54, 55_to_64, 65_plus },
       income: { median_household, brackets: { under_25k, 25k_to_50k, ... } },
       employment: { labor_force, unemployed, unemployment_rate },
       housing: { total_units, median_value } }
   - Cache result in area's demographics_cache JSON column
   - Return structured demographics

2. src/Controllers/DemographicsController.php:

   show($request):
   - Accept: area_id from URL parameter
   - Check if demographics_cache exists and is < 30 days old
   - If cached, return cached data
   - If not, call CensusService->getDemographicsForArea()
   - Return demographics object

   compare($request):
   - Accept: area_ids (array, max 10)
   - Fetch demographics for each area
   - Return array of demographics objects with area names for comparison

   Add routes:
   GET /api/areas/{id}/demographics → DemographicsController@show (auth)
   POST /api/demographics/compare → DemographicsController@compare (auth)

3. scripts/seed-census.php — Census data import script:
   - Downloads Census TIGER/Line tract shapefiles (or provide instructions for manual download)
   - For now, create a script that:
     a. Accepts a GeoJSON file of census tracts as input (user downloads from Census)
     b. Parses each feature and inserts into census_tracts table with geometry
     c. Then calls Census API for each state to populate census_demographics
     d. Shows progress bar in terminal
   - Add clear comments explaining how to get the data:
     // Download from: https://www2.census.gov/geo/tiger/TIGER2023/TRACT/
     // Convert .shp to GeoJSON using: ogr2ogr -f GeoJSON output.geojson input.shp
   - Rate limit Census API calls (max 50/minute)
   - Handle API errors gracefully with retry logic

4. Add migration src/Migrations/003_demographics_indexes.sql:
   - Add compound indexes for efficient spatial + demographic queries
   - CREATE INDEX idx_census_demo_pop ON census_demographics(total_population)
   - CREATE INDEX idx_census_demo_income ON census_demographics(median_household_income)
```

---

## PROMPT 9 — Data Import/Export

```
In /smappen/, implement the data import and export system:

1. src/Controllers/ImportController.php:

   upload($request):
   - Accept: multipart file upload (CSV or XLSX), project_id
   - Validate file type (csv, xlsx only), max 10MB
   - Save to storage/uploads/ with unique filename
   - Parse file:
     * CSV: use PHP's built-in fgetcsv()
     * XLSX: use PhpSpreadsheet library (add phpoffice/phpspreadsheet to composer.json)
   - Return: preview of first 10 rows + detected column headers
   - Return: import_token (temporary reference to the uploaded file for step 2)

   configure($request):
   - Accept: import_token, column_mapping object:
     { address_column: "Address", name_column: "Store Name", lat_column: null, lng_column: null, custom_columns: ["Revenue", "Type"] }
   - If lat_column AND lng_column are mapped: skip geocoding, use provided coordinates
   - If only address_column: geocode each row using GoogleMapsService->batchGeocode()
   - Create import_batch_id (UUID)
   - For each row:
     * Extract mapped fields
     * Geocode if needed (or use provided lat/lng)
     * Create POINT geometry
     * Store custom columns as JSON in custom_data field
     * Insert into imported_points table
   - Return: { batch_id, total_rows, geocoded_count, failed_count, failures: [{row, address, error}] }

   status($request):
   - Accept: batch_id
   - Return import status and point count for that batch

   deleteImport($request):
   - Accept: batch_id
   - Delete all imported_points with that batch_id
   - Return success

   Add routes:
   POST /api/projects/{projectId}/import/upload → ImportController@upload (auth)
   POST /api/projects/{projectId}/import/configure → ImportController@configure (auth)
   GET /api/imports/{batchId}/status → ImportController@status (auth)
   DELETE /api/imports/{batchId} → ImportController@deleteImport (auth)

2. src/Controllers/ExportController.php:

   exportAreas($request):
   - Accept: project_id, format (csv, xlsx, geojson, kml)
   - Query all areas for the project with demographics
   - For CSV/XLSX: columns = name, type, center_address, center_lat, center_lng, travel_time, area_sq_km, population, median_income, household_count
   - For GeoJSON: standard FeatureCollection with properties
   - For KML: generate KML XML with polygon geometries
   - Save file to storage/exports/ with timestamp filename
   - Return: download URL

   exportPOIs($request):
   - Accept: area_id, format (csv, xlsx)
   - Query cached POIs for the area
   - Columns: name, address, phone, website, rating, type, lat, lng
   - Return: download URL

   exportImportedPoints($request):
   - Accept: batch_id or project_id, format (csv, xlsx)
   - Export all imported points with custom_data flattened into columns
   - Return: download URL

   download($request):
   - Serve file for download with proper Content-Disposition header
   - Delete file after download (or after 1 hour via cleanup cron)

   Private helper methods:
   - generateCsv($headers, $rows) → file path
   - generateXlsx($headers, $rows, $sheetName) → file path (use PhpSpreadsheet)
   - generateGeoJson($features) → file path
   - generateKml($areas) → file path

   Add routes:
   GET /api/projects/{projectId}/export/areas → ExportController@exportAreas (auth)
   GET /api/areas/{areaId}/export/pois → ExportController@exportPOIs (auth)
   GET /api/projects/{projectId}/export/points → ExportController@exportImportedPoints (auth)
   GET /api/exports/{filename} → ExportController@download (auth)

3. Update composer.json to add: phpoffice/phpspreadsheet

4. Run: cd /smappen && composer update
```

---

## PROMPT 10 — Report Generation

```
In /smappen/, implement the PDF report generation system:

1. src/Controllers/ReportController.php:

   generate($request):
   - Accept: area_id, report_type ('area_analysis', 'comparison'), title (optional)
   - Fetch area details including geometry as GeoJSON
   - Fetch demographics (from cache or fresh)
   - Fetch POI summary: count by category within the area
   - Fetch imported points count within area
   - Generate a Google Static Maps image URL showing the area polygon:
     Build URL: https://maps.googleapis.com/maps/api/staticmap?
       size=800x500
       &maptype=roadmap
       &path=fillcolor:0x6B4EFF33|color:0x6B4EFF|weight:2|{polygon_points_encoded}
       &key={GOOGLE_API_KEY}
   - Download the static map image and save to storage/reports/maps/
   - Build HTML report from a template (stored in src/Templates/report_area_analysis.php)
   - Convert HTML to PDF using one of these methods (try in order):
     a. If wkhtmltopdf is installed: exec("wkhtmltopdf input.html output.pdf")
     b. Else use TCPDF library (add tecnickcom/tcpdf to composer.json)
   - Save PDF to storage/reports/
   - Insert record into reports table
   - Return: { report_id, download_url }

   download($request):
   - Accept: report_id
   - Verify report belongs to user's organization
   - Serve PDF with Content-Type: application/pdf and Content-Disposition: attachment

   list($request):
   - List all reports for a project
   - Return: id, title, type, area_name, generated_at, download_url

2. src/Templates/report_area_analysis.php — HTML template for area analysis PDF:

   Create a clean, professional HTML template that includes:
   - Header: logo placeholder, report title, date generated
   - Map section: embedded static map image
   - Area summary box: name, type, center address, travel time/mode, area in sq km/mi
   - Population section:
     * Total population in large text
     * Gender split as horizontal bar
     * Age distribution as table with percentages
   - Income section:
     * Median household income highlighted
     * Income bracket distribution as table
   - Employment section:
     * Labor force size, unemployment rate
   - Housing section:
     * Total units, median value
   - POI summary section:
     * Count by top categories (restaurants, retail, services, etc.)
     * Top 10 businesses by rating with contact info
   - Footer: "Generated by [Your App Name]" + timestamp

   Style with inline CSS (for PDF compatibility):
   - Professional layout, clean typography
   - Use a blue/gray color scheme
   - Tables with alternating row colors
   - Numbers formatted with commas and $ signs where appropriate

3. src/Templates/report_comparison.php — Comparison report template:
   - Side-by-side comparison of 2-5 areas
   - Table format: metrics as rows, areas as columns
   - Highlight best/worst values in each row

4. Add routes:
   POST /api/areas/{id}/report → ReportController@generate (auth)
   GET /api/reports → ReportController@list (auth)
   GET /api/reports/{id}/download → ReportController@download (auth)

5. Update composer.json to add: tecnickcom/tcpdf (fallback PDF generator)

6. Run: cd /smappen && composer update
```

---

## PROMPT 11 — Stripe Billing Integration

```
In /smappen/, implement the Stripe subscription billing system:

1. src/Services/StripeService.php:

   Properties:
   - Initialize Stripe with secret key from .env STRIPE_SECRET_KEY

   createCustomer($organization):
   - Create Stripe customer with org name and owner email
   - Save stripe_customer_id to organizations table
   - Return customer object

   createCheckoutSession($organizationId, $planName, $successUrl, $cancelUrl):
   - Map plan names to Stripe price IDs (store in .env or config):
     STRIPE_PRICE_STARTER, STRIPE_PRICE_PRO, STRIPE_PRICE_BUSINESS
   - If org doesn't have stripe_customer_id, create customer first
   - Create Stripe Checkout Session with:
     mode: 'subscription'
     customer: stripe_customer_id
     line_items: [{ price: priceId, quantity: 1 }]
     success_url: with {CHECKOUT_SESSION_ID}
     cancel_url
     metadata: { organization_id }
   - Return checkout session URL

   createBillingPortalSession($organizationId, $returnUrl):
   - Create Stripe Customer Portal session for managing subscription
   - Return portal URL

   handleWebhook($payload, $sigHeader):
   - Verify webhook signature using STRIPE_WEBHOOK_SECRET
   - Handle events:
     * checkout.session.completed → activate subscription, update org plan
     * customer.subscription.updated → update plan level
     * customer.subscription.deleted → downgrade to free plan
     * invoice.payment_failed → flag account, send notification
   - Map Stripe price ID back to plan name
   - Update organization's plan and stripe_subscription_id
   - Return handled event type

   getSubscription($organizationId):
   - Fetch current subscription details from Stripe
   - Return: plan, status, current_period_end, cancel_at_period_end

   cancelSubscription($organizationId):
   - Cancel at period end (don't cancel immediately)
   - Return cancellation confirmation

2. src/Controllers/BillingController.php:

   createCheckout($request):
   - Accept: plan ('starter', 'pro', 'business')
   - Validate plan name
   - Only org owners can change plan
   - Call StripeService->createCheckoutSession()
   - Return: { checkout_url }

   webhook($request):
   - No auth middleware (Stripe calls this)
   - Get raw body and Stripe-Signature header
   - Call StripeService->handleWebhook()
   - Return 200 OK

   subscription($request):
   - Get current subscription details
   - Call StripeService->getSubscription()
   - Return subscription info + current plan limits

   portal($request):
   - Create billing portal session
   - Return: { portal_url }

   cancel($request):
   - Only org owners
   - Call StripeService->cancelSubscription()
   - Return confirmation

3. Add plan limits configuration in src/Core/PlanLimits.php:
   Define a class with static arrays:
   LIMITS = [
     'free' => [
       'max_projects' => 1,
       'max_areas_per_project' => 3,
       'max_isochrones_per_day' => 5,
       'max_poi_searches_per_day' => 5,
       'max_import_rows' => 10,
       'reports' => false,
       'export' => false,
       'team_seats' => 1,
       'api_access' => false,
     ],
     'starter' => [
       'max_projects' => 5,
       'max_areas_per_project' => 25,
       'max_isochrones_per_day' => 50,
       'max_poi_searches_per_day' => 50,
       'max_import_rows' => 100,
       'reports' => true,
       'export' => true,
       'team_seats' => 1,
       'api_access' => false,
     ],
     'pro' => [
       'max_projects' => -1, // unlimited
       'max_areas_per_project' => -1,
       'max_isochrones_per_day' => -1,
       'max_poi_searches_per_day' => -1,
       'max_import_rows' => 500,
       'reports' => true,
       'export' => true,
       'team_seats' => 3,
       'api_access' => false,
     ],
     'business' => [
       'max_projects' => -1,
       'max_areas_per_project' => -1,
       'max_isochrones_per_day' => -1,
       'max_poi_searches_per_day' => -1,
       'max_import_rows' => 2000,
       'reports' => true,
       'export' => true,
       'team_seats' => 10,
       'api_access' => true,
     ],
   ]

   Static method: checkLimit($plan, $limitName, $currentUsage) → returns true/false
   Static method: getLimits($plan) → returns full limits array for the plan
   Static method: getRemainingUsage($userId, $limitName) → calculates from api_usage_log

4. Update Middleware.php to add planCheck($requiredFeature) middleware:
   - Gets user's org plan
   - Checks if the feature is allowed on that plan
   - Returns 403 with upgrade message if not allowed

5. Add routes:
   POST /api/billing/checkout → BillingController@createCheckout (auth)
   POST /api/billing/webhook → BillingController@webhook (no auth)
   GET /api/billing/subscription → BillingController@subscription (auth)
   POST /api/billing/portal → BillingController@portal (auth)
   POST /api/billing/cancel → BillingController@cancel (auth)
```

---

## PROMPT 12 — Frontend Foundation (React App)

```
In /smappen/, set up the React frontend application:

1. Create the React app in /smappen/frontend/ using Vite:
   cd /smappen && npm create vite@latest frontend -- --template react-ts
   cd frontend && npm install

2. Install dependencies:
   npm install @react-google-maps/api @turf/turf axios zustand @tanstack/react-query react-router-dom recharts papaparse xlsx react-hot-toast react-icons lucide-react tailwindcss @tailwindcss/vite clsx

3. Configure Tailwind CSS with the Vite plugin.

4. Set up the project structure:

frontend/src/
├── main.tsx                      # App entry, providers
├── App.tsx                       # Router + layout
├── api/
│   ├── client.ts                 # Axios instance with JWT interceptor + base URL
│   ├── auth.ts                   # login, register, getMe
│   ├── projects.ts               # CRUD
│   ├── areas.ts                  # CRUD + demographics + POIs
│   ├── isochrone.ts              # calculate
│   ├── places.ts                 # nearby, search, details
│   ├── geocoding.ts              # geocode, batchGeocode
│   ├── imports.ts                # upload, configure
│   ├── exports.ts                # export functions
│   └── billing.ts                # checkout, subscription
├── stores/
│   ├── authStore.ts              # Zustand: user, token, login/logout actions
│   ├── mapStore.ts               # Zustand: center, zoom, selectedArea, layers visibility
│   └── projectStore.ts           # Zustand: currentProject, areas, folders
├── components/
│   ├── layout/
│   │   ├── AppLayout.tsx         # Main layout with header, left panel, map, right panel
│   │   ├── Header.tsx            # Top bar: logo, project selector dropdown, user menu
│   │   ├── LeftPanel.tsx         # Area list, folders, action buttons
│   │   └── RightPanel.tsx        # Analytics panel (demographics, POIs, charts)
│   ├── map/
│   │   ├── MapCanvas.tsx         # Google Maps wrapper component
│   │   ├── AreaPolygon.tsx       # Renders a single area polygon on map
│   │   ├── DrawingTools.tsx      # Manual polygon drawing controls
│   │   ├── HeatmapLayer.tsx      # Data density heatmap
│   │   ├── ImportedMarkers.tsx   # Markers for imported data points
│   │   └── POIMarkers.tsx        # Markers for POI search results
│   ├── areas/
│   │   ├── AreaList.tsx          # List of areas in left panel
│   │   ├── AreaCard.tsx          # Single area item with color, name, actions
│   │   ├── AreaCreator.tsx       # Form: address, mode, time, create button
│   │   ├── AreaEditor.tsx        # Edit area properties (name, color, folder)
│   │   └── FolderTree.tsx        # Nested folder tree with drag-drop
│   ├── analytics/
│   │   ├── DemographicsPanel.tsx # Population, age, income, employment stats
│   │   ├── POISearchPanel.tsx    # Business search within area
│   │   ├── ComparisonView.tsx    # Compare multiple areas side by side
│   │   └── ChartWidgets.tsx      # Reusable chart components (bar, pie, donut)
│   ├── data/
│   │   ├── ImportWizard.tsx      # Multi-step CSV/Excel upload flow
│   │   ├── ExportDialog.tsx      # Download options modal
│   │   └── ReportButton.tsx      # Generate + download PDF report
│   ├── auth/
│   │   ├── LoginPage.tsx         # Login form
│   │   ├── RegisterPage.tsx      # Registration form
│   │   └── ProtectedRoute.tsx    # Route guard that redirects to login
│   └── billing/
│       ├── PricingPage.tsx       # Plan comparison + upgrade buttons
│       └── BillingSettings.tsx   # Current plan, usage, manage subscription
├── hooks/
│   ├── useAuth.ts               # Auth state + actions
│   ├── useMap.ts                # Map interaction helpers
│   ├── useAreas.ts              # Area CRUD with React Query
│   └── useDemographics.ts       # Demographics fetching
├── types/
│   └── index.ts                 # TypeScript interfaces: User, Project, Area, Folder, Demographics, Place, etc.
└── utils/
    ├── geo.ts                   # GeoJSON helpers, coordinate transforms
    ├── format.ts                # Number formatting (currency, thousands, percentages)
    └── colors.ts                # Color palette generation for areas

5. Implement the core files:

   api/client.ts:
   - Create Axios instance with baseURL from env (VITE_API_URL)
   - Request interceptor: attach JWT token from authStore
   - Response interceptor: on 401, clear auth and redirect to login
   - Generic error handling with toast notifications

   stores/authStore.ts (Zustand):
   - State: user, token, isAuthenticated, isLoading
   - Actions: login(email, password), register(data), logout(), loadUser()
   - Persist token in localStorage
   - On init, try to load user from stored token

   App.tsx:
   - BrowserRouter with routes:
     /login → LoginPage
     /register → RegisterPage
     / → ProtectedRoute → AppLayout (which contains the map + panels)
     /pricing → PricingPage
     /settings/billing → BillingSettings
   - Wrap in QueryClientProvider (React Query) and GoogleMapsProvider

   types/index.ts:
   - Define all interfaces: User, Organization, Project, Area, Folder, Demographics (with nested population, age, income, employment, housing), Place, ImportResult, ExportOptions

6. Configure Vite to proxy /api requests to the PHP backend during development:
   In vite.config.ts, add:
   server: { proxy: { '/api': 'http://localhost:8080' } }

7. Add a .env file for the frontend:
   VITE_API_URL=http://localhost:8080
   VITE_GOOGLE_MAPS_API_KEY=your_key_here

8. Configure the Vite build output to go to /smappen/public/app/ so the PHP backend can serve it:
   In vite.config.ts: build: { outDir: '../public/app' }

Create all the files with real, working implementations for: client.ts, authStore.ts, App.tsx, types/index.ts, LoginPage.tsx, RegisterPage.tsx, ProtectedRoute.tsx, Header.tsx. The remaining component files can have basic placeholder implementations that render the component name and accept the right props, since we'll build them out in subsequent prompts.
```

---

## PROMPT 13 — Frontend: Map Canvas & Area Rendering

```
In /smappen/frontend/, fully implement the map and area rendering components:

1. components/map/MapCanvas.tsx:
   - Use @react-google-maps/api GoogleMap component
   - Load Google Maps with libraries: ['drawing', 'visualization', 'geometry']
   - Map fills available space (flex-grow in layout)
   - Default center: US center (39.8283, -98.5795), zoom 4
   - On map load, store map instance in mapStore
   - Render all areas from projectStore as AreaPolygon components
   - Render imported points as ImportedMarkers
   - Render POI results as POIMarkers
   - Handle map click: if in "place pin" mode, set the clicked location as isochrone center
   - Include zoom controls, map type toggle (roadmap/satellite)
   - When an area is selected in the left panel, fit map bounds to that area

2. components/map/AreaPolygon.tsx:
   - Accept: area object (with GeoJSON geometry), isSelected boolean
   - Render Google Maps Polygon with area's colors and opacity
   - On click: select this area in projectStore, show its details in right panel
   - On hover: show tooltip with area name and basic stats
   - If selected: thicker border, slightly higher opacity
   - If editable: show polygon vertices for editing
   - On edit (vertex drag): call API to update area geometry

3. components/map/DrawingTools.tsx:
   - Render Google Maps DrawingManager
   - Toolbar with buttons: Draw Polygon, Draw Circle, Place Pin (for isochrone center)
   - When polygon drawing completes:
     * Extract coordinates from the drawn polygon
     * Convert to GeoJSON format
     * Open AreaEditor modal pre-filled with the geometry
     * Let user name it and save as a "manual" area type
   - When circle drawing completes:
     * Extract center and radius
     * Call isochrone API with isodistance mode OR save as radius area

4. components/map/HeatmapLayer.tsx:
   - Accept: data points array [{lat, lng, weight}]
   - Render Google Maps HeatmapLayer
   - Toggle visibility from map controls
   - Adjust radius and opacity

5. components/map/ImportedMarkers.tsx:
   - Render markers for imported data points
   - Use MarkerClusterer for large datasets (import @googlemaps/markerclusterer)
   - Custom marker icons based on custom_data categories if available
   - On click: show info window with point details

6. components/map/POIMarkers.tsx:
   - Render markers for POI search results (different icon from imported points)
   - On click: show info window with business name, address, phone, rating, website link
   - Color-code by business type

7. components/layout/AppLayout.tsx:
   - Three-column layout: LeftPanel (300px) | MapCanvas (flex grow) | RightPanel (350px, collapsible)
   - RightPanel only shows when an area is selected or a search is active
   - Use CSS grid or flexbox for responsive layout
   - On mobile: panels become bottom sheets or slide-over drawers

8. stores/mapStore.ts (expand):
   - State: center, zoom, selectedAreaId, isDrawingMode, drawingType, showHeatmap, showPOIs, showImportedPoints, mapInstance
   - Actions: setCenter, setZoom, selectArea, startDrawing, stopDrawing, toggleLayer, fitBoundsToArea

Install @googlemaps/markerclusterer:
   cd /smappen/frontend && npm install @googlemaps/markerclusterer
```

---

## PROMPT 14 — Frontend: Area Creator & Left Panel

```
In /smappen/frontend/, implement the area creation flow and left panel:

1. components/areas/AreaCreator.tsx:
   - A form/modal that opens when user clicks "Add Area" button
   - Fields:
     * Address search input with Google Places Autocomplete
       (use google.maps.places.Autocomplete attached to the input)
     * OR "Click on map" button to place a pin
     * Travel mode selector: Car, Bike, Walking (radio buttons with icons)
     * Time slider: 5 to 120 minutes, with presets (5, 10, 15, 20, 30, 45, 60)
     * Area name input (auto-suggested: "{address} - {time}min {mode}")
     * Color picker (preset palette of 10 colors + custom hex input)
     * Folder selector dropdown
   - "Calculate" button:
     * Shows loading spinner on the map
     * Calls POST /api/isochrone/calculate
     * Renders preview polygon on map (dashed border, semi-transparent)
     * Shows quick stats: area size in sq km/mi
   - "Save Area" button (appears after calculation):
     * Calls POST /api/projects/{id}/areas with all data
     * Adds area to projectStore
     * Closes the creator
   - "Cancel" button discards the preview

2. components/areas/AreaList.tsx:
   - Scrollable list of all areas in the current project
   - Grouped by folders (collapsible folder headers)
   - "Unfiled" section for areas not in any folder
   - Each area rendered as an AreaCard
   - Drag-and-drop to reorder or move between folders (use HTML5 drag API or a lightweight library)
   - Search/filter input at top
   - "Add Area" button at bottom

3. components/areas/AreaCard.tsx:
   - Shows: color swatch, area name, type icon (car/bike/walk), time/distance
   - On click: select area (highlight on map, show in right panel)
   - On hover: highlight area on map
   - Action menu (three dots): Edit, Duplicate, Move to Folder, Delete
   - Show small badge with population if demographics are cached

4. components/areas/AreaEditor.tsx:
   - Modal/slide-over for editing area properties
   - Editable fields: name, color, opacity, folder, notes
   - "Recalculate" option (change travel time/mode and regenerate isochrone)
   - "Delete Area" with confirmation dialog
   - Save button calls PUT /api/areas/{id}

5. components/areas/FolderTree.tsx:
   - Nested tree of folders
   - Each folder shows: color dot, name, area count
   - Click folder to filter area list
   - Right-click or action menu: Rename, Change Color, Delete
   - "New Folder" button
   - Supports nesting (folders within folders)

6. components/layout/LeftPanel.tsx:
   - Fixed width left sidebar (300px)
   - Sections:
     * Project name + settings icon at top
     * FolderTree component
     * AreaList component
     * Bottom toolbar: Add Area, Import Data, Draw Polygon buttons
   - Collapsible on mobile (hamburger menu)

7. hooks/useAreas.ts:
   - Use React Query for data fetching:
     * useQuery for listing areas
     * useMutation for create, update, delete
     * Invalidate queries after mutations
   - Helper functions: selectArea, deselectArea, duplicateArea
```

---

## PROMPT 15 — Frontend: Right Panel (Analytics & Demographics)

```
In /smappen/frontend/, implement the right panel analytics:

1. components/layout/RightPanel.tsx:
   - Collapsible sidebar (350px wide, slides in from right)
   - Shows when an area is selected
   - Tabs at top: "Overview" | "Demographics" | "Businesses" | "Data"
   - Overview tab: area summary card + quick stats
   - Demographics tab: DemographicsPanel
   - Businesses tab: POISearchPanel
   - Data tab: imported points in this area + export button

2. components/analytics/DemographicsPanel.tsx:
   - Fetches demographics from GET /api/areas/{id}/demographics
   - Shows loading skeleton while fetching
   - Sections:

   a. Population card:
      - Big number: total population with comma formatting
      - Population density (per sq km)
      - Gender split: horizontal stacked bar (male % | female %)
      - Use blue/pink color scheme

   b. Age distribution:
      - Horizontal bar chart (Recharts BarChart)
      - Bars: Under 18, 18-34, 35-54, 55-64, 65+
      - Show count and percentage on each bar

   c. Income:
      - Median household income in large green text with $ formatting
      - Income bracket distribution as stacked horizontal bar or pie chart
      - Brackets: <$25K, $25-50K, $50-75K, $75-100K, $100K+
      - Median home value below

   d. Employment:
      - Labor force size
      - Unemployment rate as percentage with colored indicator (green if <5%, yellow 5-8%, red >8%)

   e. Housing:
      - Total housing units
      - Median home value

3. components/analytics/POISearchPanel.tsx:
   - Search input + category filter dropdown
   - Categories: Restaurant, Retail, Healthcare, Education, Financial, Fitness, etc.
   - "Search" button calls POST /api/places/nearby with area's center + bounding radius
   - Results list:
     * Business name (bold), rating (stars), review count
     * Address, phone (clickable tel: link), website (clickable link)
     * Type/category badge
   - Map markers appear for results
   - Result count header: "23 restaurants found"
   - "Export Results" button (CSV download)

4. components/analytics/ComparisonView.tsx:
   - Triggered from area list: select multiple areas (checkboxes) + "Compare" button
   - Side-by-side table layout:
     * Rows: Population, Median Income, Unemployment Rate, Housing Units, Area Size, POI Count
     * Columns: one per selected area (max 5)
   - Highlight best value in each row (green) and worst (red)
   - Bar chart comparison using Recharts for visual comparison

5. components/analytics/ChartWidgets.tsx:
   - Reusable chart components:
     * DonutChart: accepts data array [{name, value, color}], shows total in center
     * HorizontalBarChart: accepts data array, auto-calculates percentages
     * StackedBar: for gender split, income brackets
     * StatCard: big number with label, optional trend arrow

6. utils/format.ts:
   - formatNumber(n) → "1,234,567"
   - formatCurrency(n) → "$123,456"
   - formatPercent(n, decimals) → "45.2%"
   - formatCompact(n) → "1.2M" or "456K"
   - formatArea(sqMeters, unit) → "12.5 sq mi" or "32.4 sq km"
```

---

## PROMPT 16 — Frontend: Import Wizard & Export

```
In /smappen/frontend/, implement the data import and export UI:

1. components/data/ImportWizard.tsx:
   - Multi-step modal wizard:

   Step 1 — Upload:
   - Drag-and-drop zone + file picker button
   - Accept .csv and .xlsx files only, max 10MB
   - On file select: POST to /api/projects/{id}/import/upload
   - Show upload progress bar
   - Transition to step 2 on success

   Step 2 — Map Columns:
   - Show preview table of first 5 rows from the uploaded file
   - Dropdown selectors above each column:
     * "Address", "Name/Label", "Latitude", "Longitude", "Ignore", or any custom column name
   - Auto-detect common column names (address, lat, lng, latitude, longitude, name, city, state, zip)
   - If lat+lng columns are mapped, show note: "Coordinates detected — geocoding will be skipped"
   - If only address column mapped, show note: "X rows will be geocoded (uses API credits)"
   - "Import" button

   Step 3 — Processing:
   - POST to /api/projects/{id}/import/configure with column mappings
   - Show progress: "Geocoding row 45 of 200..."
   - On completion: show summary (imported: X, failed: Y)
   - List failures with row number and error message
   - "View on Map" button to close wizard and zoom to imported points
   - "Download Failures" button to get CSV of failed rows

2. components/data/ExportDialog.tsx:
   - Modal with export options
   - Sections:
     * "Export Areas" — format selector (CSV, Excel, GeoJSON, KML) + download button
     * "Export POI Results" — only available if POI search was done, format selector + download
     * "Export Imported Data" — format selector + download
   - Each download button triggers GET to appropriate /api/exports/ endpoint
   - Shows loading spinner during file generation

3. components/data/ReportButton.tsx:
   - "Generate Report" button shown in right panel when area is selected
   - On click: POST /api/areas/{id}/report
   - Show "Generating report..." loading state (can take a few seconds)
   - On success: auto-download the PDF, show success toast
   - On error: show error message with retry option
```

---

## PROMPT 17 — Frontend: Billing & Pricing Page

```
In /smappen/frontend/, implement the billing and pricing UI:

1. components/billing/PricingPage.tsx:
   - Accessible at /pricing route (no auth required for viewing)
   - Header: "Choose Your Plan"
   - Toggle: Monthly / Annual (annual = 20% discount, show monthly equivalent)
   - 4 pricing cards in a row:

   FREE ($0/mo):
   - 1 project, 3 areas, 5 isochrones/day
   - Basic demographics
   - No export, no reports
   - "Get Started" button → goes to register

   STARTER ($49/mo):
   - 5 projects, 25 areas, 50 isochrones/day
   - Full demographics, 50 POI searches/day
   - CSV/Excel export, PDF reports
   - "Start Free Trial" button

   PRO ($149/mo):
   - Unlimited projects & areas
   - Unlimited isochrones & POI searches
   - Import up to 500 rows
   - Everything in Starter
   - "Start Free Trial" button (highlighted as "Most Popular")

   BUSINESS ($349/mo):
   - Everything in Pro
   - 10 team seats
   - API access
   - HubSpot integration
   - Priority support
   - "Contact Sales" or "Start Free Trial"

   - Feature comparison table below the cards
   - FAQ section at bottom

   - If user is logged in, show current plan badge and "Upgrade" buttons
   - Upgrade button calls POST /api/billing/checkout and redirects to Stripe Checkout

2. components/billing/BillingSettings.tsx:
   - Route: /settings/billing (auth required)
   - Current plan card: plan name, price, renewal date
   - Usage meters: areas used/limit, isochrones today/limit, POI searches today/limit
   - "Change Plan" button → goes to PricingPage
   - "Manage Billing" button → calls POST /api/billing/portal, opens Stripe Portal
   - "Cancel Subscription" button with confirmation dialog
   - Invoice history (if available from Stripe)

3. Add a plan limit enforcement hook - hooks/usePlanLimits.ts:
   - Fetches current subscription from GET /api/billing/subscription
   - Provides: currentPlan, limits, usage, canUseFeature(featureName), isAtLimit(limitName)
   - Used by AreaCreator, ImportWizard, POISearchPanel to show upgrade prompts when limits are hit
   - When a limit is reached, show a friendly upgrade prompt instead of an error
```

---

## PROMPT 18 — Build, Deploy & Server Config

```
In /smappen/, set up the production build and server configuration:

1. Create /smappen/scripts/setup.sh — Server setup script for DigitalOcean:
   #!/bin/bash
   # Smappen Server Setup Script
   # Run on a fresh Ubuntu 22.04+ DigitalOcean droplet

   # System updates
   apt update && apt upgrade -y

   # PHP 8.2 + extensions
   add-apt-repository ppa:ondrej/php -y
   apt install -y php8.2 php8.2-fpm php8.2-mysql php8.2-curl php8.2-json php8.2-mbstring php8.2-xml php8.2-zip php8.2-gd

   # MySQL 8
   apt install -y mysql-server
   mysql_secure_installation

   # Composer
   curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

   # Node.js 20 (for building frontend)
   curl -fsSL https://deb.nodesource.com/setup_20.x | bash -
   apt install -y nodejs

   # Nginx
   apt install -y nginx

   # Certbot (SSL)
   apt install -y certbot python3-certbot-nginx

   # wkhtmltopdf (for PDF generation)
   apt install -y wkhtmltopdf

   # Create MySQL database
   mysql -e "CREATE DATABASE smappen CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
   mysql -e "CREATE USER 'smappen'@'localhost' IDENTIFIED BY 'CHANGE_THIS_PASSWORD';"
   mysql -e "GRANT ALL PRIVILEGES ON smappen.* TO 'smappen'@'localhost';"
   mysql -e "FLUSH PRIVILEGES;"

   # Set directory permissions
   chown -R www-data:www-data /smappen/storage
   chmod -R 775 /smappen/storage
   chown -R www-data:www-data /smappen/public/uploads
   chmod -R 775 /smappen/public/uploads

   echo "Setup complete. Now:"
   echo "1. Copy .env.example to .env and fill in your values"
   echo "2. Run: cd /smappen && composer install"
   echo "3. Run: php scripts/migrate.php"
   echo "4. Run: cd frontend && npm install && npm run build"
   echo "5. Configure Nginx (see nginx.conf)"
   echo "6. Run: certbot --nginx -d yourdomain.com"

2. Create /smappen/nginx.conf — Nginx site config:
   server {
       listen 80;
       server_name yourdomain.com;
       root /smappen/public;
       index index.php index.html;

       # Frontend SPA — serve from /app/ build directory
       location / {
           try_files $uri $uri/ /app/index.html;
       }

       # API routes — proxy to PHP
       location /api {
           try_files $uri $uri/ /index.php?$query_string;
       }

       # PHP-FPM
       location ~ \.php$ {
           fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
           fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
           include fastcgi_params;
       }

       # Static assets
       location /assets {
           expires 30d;
           add_header Cache-Control "public, immutable";
       }

       # Uploaded files
       location /uploads {
           internal; # Only serve via PHP (X-Sendfile)
       }

       # Block dotfiles
       location ~ /\. {
           deny all;
       }

       client_max_body_size 20M;
   }

3. Create /smappen/scripts/deploy.sh — Deployment script:
   #!/bin/bash
   set -e
   echo "Deploying Smappen..."

   cd /smappen

   # Pull latest code (if using git)
   # git pull origin main

   # Install PHP dependencies
   composer install --no-dev --optimize-autoloader

   # Run migrations
   php scripts/migrate.php

   # Build frontend
   cd frontend
   npm ci
   npm run build
   cd ..

   # Clear cache
   rm -rf storage/cache/*

   # Set permissions
   chown -R www-data:www-data storage/ public/uploads/
   chmod -R 775 storage/ public/uploads/

   # Restart PHP-FPM
   systemctl restart php8.2-fpm

   echo "Deploy complete!"

4. Update /smappen/public/index.php to also serve the frontend:
   - If the request path starts with /api, route through the PHP router
   - Otherwise, let Nginx handle it (serve static files or SPA fallback)

5. Create /smappen/scripts/cleanup-cron.php — Scheduled cleanup:
   - Delete expired cache entries from cache table
   - Delete export files older than 1 hour
   - Delete orphaned upload files older than 24 hours
   - Log cleanup results

   Add to crontab:
   */15 * * * * php /smappen/scripts/cleanup-cron.php >> /smappen/storage/logs/cleanup.log 2>&1

6. Create /smappen/.gitignore:
   .env
   vendor/
   node_modules/
   frontend/dist/
   public/app/
   storage/cache/*
   storage/reports/*
   storage/logs/*
   public/uploads/*
   !.gitkeep

7. Update /smappen/README.md with:
   - Project overview
   - Prerequisites list
   - Installation steps (clone, composer install, npm install, .env setup, migrate, build)
   - API endpoint documentation summary
   - Development setup (PHP built-in server + Vite dev server)
   - Production deployment steps
   - Environment variables reference
```

---

## PROMPT 19 — API Testing & Validation

```
In /smappen/, create a comprehensive API test script and fix any issues:

1. Create /smappen/scripts/test-api.php — Sequential API test:

   This script tests every endpoint in order, using cURL internally.
   It should:
   - Set $baseUrl from .env or default to http://localhost:8080
   - Use a helper function: request($method, $path, $body, $token) that returns [statusCode, responseBody]
   - Color-coded output: green for pass, red for fail
   - Stop on first failure with detailed error output

   Test sequence:

   echo "=== AUTH TESTS ==="
   1. POST /api/auth/register — register a test user, expect 201, save token
   2. POST /api/auth/login — login with same credentials, expect 200, save token
   3. GET /api/auth/me — with token, expect 200 with user data
   4. GET /api/auth/me — without token, expect 401

   echo "=== PROJECT TESTS ==="
   5. POST /api/projects — create "Test Project", expect 201, save project_id
   6. GET /api/projects — expect 200 with array containing the project
   7. GET /api/projects/{id} — expect 200 with project details
   8. PUT /api/projects/{id} — update name, expect 200

   echo "=== FOLDER TESTS ==="
   9. POST /api/projects/{id}/folders — create "Test Folder", expect 201, save folder_id
   10. GET /api/projects/{id}/folders — expect 200 with folder

   echo "=== ISOCHRONE TESTS ==="
   11. POST /api/isochrone/calculate — lat: 38.9072, lng: -77.0369 (Washington DC), 15 min, driving-car
       Expect 200 with GeoJSON polygon

   echo "=== AREA TESTS ==="
   12. POST /api/projects/{id}/areas — save the isochrone as an area, expect 201, save area_id
   13. GET /api/projects/{id}/areas — expect 200 with array
   14. GET /api/areas/{id} — expect 200 with GeoJSON geometry
   15. PUT /api/areas/{id} — update name and color, expect 200

   echo "=== GEOCODING TESTS ==="
   16. POST /api/geocode — address: "1600 Pennsylvania Ave, Washington DC", expect lat/lng

   echo "=== PLACES TESTS ==="
   17. POST /api/places/nearby — lat/lng from above, radius 5000, type "restaurant"
       Expect 200 with array of places

   echo "=== DEMOGRAPHICS TESTS ==="
   18. GET /api/areas/{id}/demographics — expect 200 (may be empty if Census data not loaded, that's OK — check structure)

   echo "=== IMPORT TESTS ==="
   19. Create a small test CSV in /tmp with 3 rows of addresses
   20. POST /api/projects/{id}/import/upload — upload the CSV, expect 200 with preview

   echo "=== EXPORT TESTS ==="
   21. GET /api/projects/{id}/export/areas?format=csv — expect 200 with download

   echo "=== CLEANUP ==="
   22. DELETE /api/areas/{id} — expect 200
   23. DELETE /api/projects/{id} — expect 200

   Print summary: "X/23 tests passed"

2. After creating the test script, run it:
   cd /smappen && php scripts/test-api.php

3. Fix any errors that come up. Common issues to check:
   - MySQL connection errors → verify .env credentials
   - JSON parsing errors → verify Content-Type headers
   - 404 errors → verify routes in config/routes.php match controller methods
   - Spatial query errors → verify MySQL 8 spatial functions syntax
   - Foreign key errors → verify correct UUID generation
   - CORS errors → verify OPTIONS handling in index.php

4. Make sure all endpoints return consistent JSON structure:
   Success: { "success": true, "data": {...}, "message": "..." }
   Error: { "success": false, "error": "...", "details": {...} }
   List: { "success": true, "data": [...], "meta": { "total": N, "page": 1, "per_page": 20 } }
```

---

## PROMPT 20 — Final Integration & Polish

```
In /smappen/, do a final integration pass connecting frontend to backend:

1. Review and fix the API client (frontend/src/api/client.ts):
   - Verify baseURL points to correct backend
   - Verify JWT token is being sent in Authorization header
   - Add request/response logging in development mode
   - Handle network errors gracefully (show "Connection lost" toast)

2. Wire up the complete area creation flow end-to-end:
   - AreaCreator.tsx: address input → geocode API → place pin on map → select mode/time → calculate isochrone → preview on map → save area → area appears in list and on map
   - Verify the polygon renders correctly on Google Maps after saving
   - Verify demographics auto-load when area is selected
   - Verify POI search works within the area

3. Wire up the import flow:
   - ImportWizard: select file → upload → map columns → process → see points on map
   - Verify markers cluster correctly for large imports

4. Add global error boundary in App.tsx:
   - Catch React rendering errors
   - Show friendly error page with "Reload" button
   - Log errors to console

5. Add loading states everywhere:
   - Skeleton loaders in DemographicsPanel while data loads
   - Map spinner overlay while isochrone calculates
   - Button loading states during API calls
   - Disable buttons during pending mutations

6. Add toast notifications (react-hot-toast):
   - Success: "Area created", "Data exported", "Report generated"
   - Error: "Failed to calculate isochrone", "Geocoding failed for 3 addresses"
   - Warning: "You've reached your plan limit. Upgrade to continue."

7. Verify the frontend builds correctly:
   cd /smappen/frontend && npm run build
   
   Verify output goes to /smappen/public/app/
   
   Test that Nginx serves the built app correctly at the root URL
   and proxies /api/* requests to PHP-FPM.

8. Create a /smappen/public/app/index.html fallback if the Vite build creates it elsewhere.
   The SPA routing needs this file to exist for client-side routes to work.

9. Run the API tests one more time to verify nothing broke:
   php scripts/test-api.php

10. Do a final review of the .env.example file to make sure every required variable is documented with a comment explaining what it is and where to get the value.
```

---

## Post-Build Checklist

After completing all 20 prompts, verify:

- [ ] `php scripts/migrate.php` runs without errors
- [ ] `php scripts/test-api.php` passes all tests
- [ ] `cd frontend && npm run build` completes without errors
- [ ] Can register and login through the UI
- [ ] Can create a project
- [ ] Can calculate an isochrone and see it on the map
- [ ] Can search for businesses within an area
- [ ] Can view demographics for an area
- [ ] Can import a CSV file and see markers on the map
- [ ] Can export area data as CSV
- [ ] Can generate a PDF report
- [ ] Stripe checkout flow works (test mode)
- [ ] Nginx serves the app correctly in production
- [ ] SSL certificate is installed (certbot)

## API Keys You'll Need

1. **Google Maps Platform** — console.cloud.google.com → Enable: Maps JS, Geocoding, Places, Static Maps, Directions
2. **OpenRouteService** — openrouteservice.org/dev/#/signup → Free API key
3. **US Census Bureau** — api.census.gov/data/key_signup.html → Free, instant
4. **Stripe** — dashboard.stripe.com → Test mode keys + create Products/Prices for each plan
5. **JWT Secret** — Generate with: `openssl rand -hex 32`
