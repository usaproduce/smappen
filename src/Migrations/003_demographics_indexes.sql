-- Idempotent index creation using metadata lookup (MySQL 8.0 supports IF NOT EXISTS only on tables/DBs, not indexes).
SET @stmt = (SELECT IF(
    (SELECT COUNT(*) FROM information_schema.statistics
     WHERE table_schema = DATABASE() AND table_name = 'census_demographics' AND index_name = 'idx_census_demo_pop') = 0,
    'CREATE INDEX idx_census_demo_pop ON census_demographics(total_population)',
    'SELECT 1'
));
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;

SET @stmt = (SELECT IF(
    (SELECT COUNT(*) FROM information_schema.statistics
     WHERE table_schema = DATABASE() AND table_name = 'census_demographics' AND index_name = 'idx_census_demo_income') = 0,
    'CREATE INDEX idx_census_demo_income ON census_demographics(median_household_income)',
    'SELECT 1'
));
PREPARE s FROM @stmt; EXECUTE s; DEALLOCATE PREPARE s;
