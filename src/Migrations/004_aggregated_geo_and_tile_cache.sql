-- County-level aggregation table for zoom-out LOD.
-- Populated from existing census_tracts via aggregation seed script.
CREATE TABLE IF NOT EXISTS census_counties (
    geoid CHAR(5) NOT NULL PRIMARY KEY,         -- state(2) + county(3) FIPS
    state_fips CHAR(2) NOT NULL,
    county_fips CHAR(3) NOT NULL,
    name VARCHAR(120) NULL,
    geometry MULTIPOLYGON NOT NULL SRID 4326,
    total_population INT NULL,
    median_household_income INT NULL,
    median_home_value INT NULL,
    labor_force_total INT NULL,
    unemployed_total INT NULL,
    housing_units_total INT NULL,
    land_area_sqm DOUBLE NULL,
    tract_count INT NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL,
    SPATIAL INDEX idx_counties_geom (geometry),
    INDEX idx_counties_state (state_fips)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- State-level aggregation table for max zoom-out.
CREATE TABLE IF NOT EXISTS census_states (
    state_fips CHAR(2) NOT NULL PRIMARY KEY,
    name VARCHAR(120) NULL,
    geometry MULTIPOLYGON NOT NULL SRID 4326,
    total_population INT NULL,
    median_household_income INT NULL,
    median_home_value INT NULL,
    labor_force_total INT NULL,
    unemployed_total INT NULL,
    housing_units_total INT NULL,
    land_area_sqm DOUBLE NULL,
    tract_count INT NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL,
    SPATIAL INDEX idx_states_geom (geometry)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Server-side response cache keyed by quantized bbox + metric + zoom tier.
-- Separate from the generic `cache` table so we can purge heatmap entries independently.
CREATE TABLE IF NOT EXISTS heatmap_tile_cache (
    cache_key VARCHAR(160) NOT NULL PRIMARY KEY,
    response LONGTEXT NOT NULL,
    metric VARCHAR(64) NOT NULL,
    level ENUM('tract','county','state') NOT NULL,
    hits INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    expires_at DATETIME NOT NULL,
    INDEX idx_hmtc_expires (expires_at),
    INDEX idx_hmtc_metric_level (metric, level)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Reach-calculation cache (center + target → result).
-- Center is rounded to 3 decimals (~110m) so nearby clicks share cache.
CREATE TABLE IF NOT EXISTS reach_cache (
    cache_key VARCHAR(80) NOT NULL PRIMARY KEY,
    response LONGTEXT NOT NULL,
    hits INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    expires_at DATETIME NOT NULL,
    INDEX idx_reach_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
