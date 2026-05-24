#!/usr/bin/env bash
# Iterate all 50 US states + DC, downloading TIGER 2023 tract shapefiles,
# converting to GeoJSON, ingesting tract geometries, then pulling ACS
# demographics. Resumable — skips states that already have tracts loaded.
#
# Run from droplet:
#   cd /var/www/smappen && nohup bash scripts/seed-all-states.sh > /var/log/smappen-census-seed.log 2>&1 &
set -e

cd "$(dirname "$0")/.."
ROOT="$(pwd)"
TIGER_DIR="${ROOT}/storage/tiger"
mkdir -p "$TIGER_DIR"

# All 50 states + DC FIPS codes.
STATES=(01 02 04 05 06 08 09 10 11 12 13 15 16 17 18 19 20 \
        21 22 23 24 25 26 27 28 29 30 31 32 33 34 35 36 37 \
        38 39 40 41 42 44 45 46 47 48 49 50 51 53 54 55 56)

DB_NAME="$(grep '^DB_NAME=' .env | cut -d= -f2)"
DB_USER="$(grep '^DB_USER=' .env | cut -d= -f2)"
DB_PASS="$(grep '^DB_PASS=' .env | cut -d= -f2)"

log() { printf '[%s] %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$*"; }

# Check that a state already has tracts ingested (resume support).
has_tracts() {
  local fips="$1"
  local n
  n=$(mysql -u"$DB_USER" -p"$DB_PASS" -N -B -e \
    "SELECT COUNT(*) FROM census_tracts WHERE state_fips='$fips'" "$DB_NAME" 2>/dev/null \
    | tail -1)
  [[ "${n:-0}" -gt 0 ]]
}

# Check that a state already has demographics ingested.
has_demographics() {
  local fips="$1"
  local n
  n=$(mysql -u"$DB_USER" -p"$DB_PASS" -N -B -e \
    "SELECT COUNT(*) FROM census_demographics d JOIN census_tracts t ON t.geoid=d.geoid WHERE t.state_fips='$fips'" "$DB_NAME" 2>/dev/null \
    | tail -1)
  [[ "${n:-0}" -gt 0 ]]
}

# Download + convert one state's tract shapefile, ingest, demographics.
seed_one_state() {
  local fips="$1"
  local zipname="tl_2023_${fips}_tract.zip"
  local zipurl="https://www2.census.gov/geo/tiger/TIGER2023/TRACT/${zipname}"
  local statedir="${TIGER_DIR}/${fips}"
  mkdir -p "$statedir"
  cd "$statedir"

  if has_tracts "$fips"; then
    log "STATE ${fips}: tracts already present — skipping geometry ingest"
  else
    if [[ ! -f "$zipname" ]]; then
      log "STATE ${fips}: downloading ${zipurl}"
      curl -fsSL -o "$zipname" "$zipurl"
    fi
    if [[ ! -f "tl_2023_${fips}_tract.shp" ]]; then
      log "STATE ${fips}: unzipping"
      unzip -oq "$zipname"
    fi
    local gjs="tracts_${fips}.geojson"
    if [[ ! -f "$gjs" ]]; then
      log "STATE ${fips}: ogr2ogr → GeoJSON"
      ogr2ogr -f GeoJSON -t_srs EPSG:4326 "$gjs" "tl_2023_${fips}_tract.shp"
    fi
    log "STATE ${fips}: ingesting tracts"
    cd "$ROOT"
    php scripts/seed-census.php tracts "${statedir}/${gjs}"
  fi

  cd "$ROOT"
  if has_demographics "$fips"; then
    log "STATE ${fips}: demographics already present — skipping ACS pull"
  else
    log "STATE ${fips}: pulling ACS demographics"
    php scripts/seed-census.php demographics "$fips"
  fi

  # Reclaim disk — TIGER zips + GeoJSON eat ~3 GB across 50 states.
  log "STATE ${fips}: cleaning up TIGER staging"
  rm -rf "$statedir"
}

# Sanity: ensure ogr2ogr exists.
if ! command -v ogr2ogr >/dev/null 2>&1; then
  log "ERROR: ogr2ogr not installed. Run: apt-get install -y gdal-bin"
  exit 1
fi

log "Starting 50-state census ingestion. Target: ~85,000 tracts."
TOTAL_STATES="${#STATES[@]}"
INDEX=0
FAILED=()
for fips in "${STATES[@]}"; do
  INDEX=$((INDEX + 1))
  log "================== [${INDEX}/${TOTAL_STATES}] STATE ${fips} =================="
  if seed_one_state "$fips"; then
    log "STATE ${fips}: ✓ done"
  else
    log "STATE ${fips}: ✗ FAILED — continuing with next"
    FAILED+=("$fips")
  fi
done

# Aggregate counties + states after all tract rows are in place.
log "Running county/state geometry + demographics aggregation"
if [[ ! -f "${TIGER_DIR}/counties.geojson" ]]; then
  cd "$TIGER_DIR"
  curl -fsSL -o tl_2023_us_county.zip https://www2.census.gov/geo/tiger/TIGER2023/COUNTY/tl_2023_us_county.zip
  unzip -oq tl_2023_us_county.zip
  ogr2ogr -f GeoJSON -t_srs EPSG:4326 counties.geojson tl_2023_us_county.shp
  cd "$ROOT"
fi
php scripts/aggregate-geographies.php counties "${TIGER_DIR}/counties.geojson" || log "county geometry agg failed"

if [[ ! -f "${TIGER_DIR}/states.geojson" ]]; then
  cd "$TIGER_DIR"
  curl -fsSL -o tl_2023_us_state.zip https://www2.census.gov/geo/tiger/TIGER2023/STATE/tl_2023_us_state.zip
  unzip -oq tl_2023_us_state.zip
  ogr2ogr -f GeoJSON -t_srs EPSG:4326 states.geojson tl_2023_us_state.shp
  cd "$ROOT"
fi
php scripts/aggregate-geographies.php states "${TIGER_DIR}/states.geojson" || log "state geometry agg failed"

php scripts/aggregate-geographies.php demographics || log "demographics rollup failed"

# Final report.
log "================================================================"
log "INGESTION COMPLETE"
log "================================================================"
mysql -u"$DB_USER" -p"$DB_PASS" -e \
  "SELECT 'tracts' AS what, COUNT(*) AS n FROM census_tracts UNION ALL \
   SELECT 'demographics', COUNT(*) FROM census_demographics UNION ALL \
   SELECT 'counties', COUNT(*) FROM census_counties UNION ALL \
   SELECT 'states', COUNT(*) FROM census_states;" "$DB_NAME" 2>/dev/null

if [[ ${#FAILED[@]} -gt 0 ]]; then
  log "FAILED STATES: ${FAILED[*]} — rerun this script to retry, resumable"
  exit 2
fi
log "All 50 states + DC ingested successfully."
