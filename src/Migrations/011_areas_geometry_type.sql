-- 011_areas_geometry_type.sql — allow MultiPolygon in `areas.geometry`.
--
-- The original POLYGON-only column couldn't hold a real territory boundary
-- when its source tracts weren't spatially contiguous (ST_Union returns
-- MultiPolygon then). That's the root cause of the knife-cut convex-hull
-- workaround. Switching to GEOMETRY (which accepts both Polygon and
-- MultiPolygon under the same SRID) unblocks proper ST_Union dissolves.
--
-- SPATIAL INDEX must be dropped first — it's tied to the column type and
-- re-added afterward.

-- Drop the index (safe — re-added below).
SET @idx_exists := (
  SELECT COUNT(*) FROM information_schema.statistics
  WHERE table_schema = DATABASE()
    AND table_name = 'areas'
    AND index_name = 'idx_area_geom'
);
SET @sql := IF(@idx_exists > 0,
  'ALTER TABLE areas DROP INDEX idx_area_geom',
  'SELECT "idx_area_geom missing — nothing to drop" AS noop'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Widen the column type. SRID stays 4326. NOT NULL preserved.
SET @col_type := (
  SELECT data_type FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'areas' AND column_name = 'geometry'
);
SET @sql := IF(@col_type = 'polygon',
  'ALTER TABLE areas MODIFY geometry GEOMETRY NOT NULL SRID 4326',
  'SELECT "areas.geometry already GEOMETRY or other — skipping" AS noop'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Re-add the spatial index.
ALTER TABLE areas ADD SPATIAL INDEX idx_area_geom (geometry);
