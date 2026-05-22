-- Smappen initial schema (MySQL 8+, spatial)
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS organizations (
    id CHAR(36) PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    stripe_customer_id VARCHAR(255) NULL,
    stripe_subscription_id VARCHAR(255) NULL,
    plan ENUM('free','starter','pro','business','enterprise') NOT NULL DEFAULT 'free',
    max_seats INT NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_org_stripe_customer (stripe_customer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS users (
    id CHAR(36) PRIMARY KEY,
    email VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    name VARCHAR(255) NOT NULL,
    organization_id CHAR(36) NULL,
    role ENUM('owner','admin','member') NOT NULL DEFAULT 'member',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    last_login_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_user_org (organization_id),
    CONSTRAINT fk_user_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS projects (
    id CHAR(36) PRIMARY KEY,
    organization_id CHAR(36) NOT NULL,
    name VARCHAR(255) NOT NULL,
    description TEXT NULL,
    center_lat DOUBLE NULL,
    center_lng DOUBLE NULL,
    zoom_level INT NOT NULL DEFAULT 10,
    is_shared TINYINT(1) NOT NULL DEFAULT 0,
    share_token VARCHAR(64) NULL,
    created_by CHAR(36) NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_project_org (organization_id),
    INDEX idx_project_share (share_token),
    CONSTRAINT fk_project_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
    CONSTRAINT fk_project_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS folders (
    id CHAR(36) PRIMARY KEY,
    project_id CHAR(36) NOT NULL,
    name VARCHAR(255) NOT NULL,
    color VARCHAR(7) NOT NULL DEFAULT '#6B4EFF',
    sort_order INT NOT NULL DEFAULT 0,
    parent_folder_id CHAR(36) NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_folder_project (project_id),
    INDEX idx_folder_parent (parent_folder_id),
    CONSTRAINT fk_folder_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    CONSTRAINT fk_folder_parent FOREIGN KEY (parent_folder_id) REFERENCES folders(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS areas (
    id CHAR(36) PRIMARY KEY,
    project_id CHAR(36) NOT NULL,
    folder_id CHAR(36) NULL,
    name VARCHAR(255) NOT NULL,
    area_type ENUM('isochrone','isodistance','manual','radius') NOT NULL DEFAULT 'isochrone',
    center_lat DOUBLE NULL,
    center_lng DOUBLE NULL,
    center_address TEXT NULL,
    travel_mode VARCHAR(50) NULL,
    travel_time_minutes INT NULL,
    travel_distance_km DOUBLE NULL,
    geometry POLYGON NOT NULL SRID 4326,
    fill_color VARCHAR(7) NOT NULL DEFAULT '#6B4EFF',
    fill_opacity DOUBLE NOT NULL DEFAULT 0.3,
    stroke_color VARCHAR(7) NOT NULL DEFAULT '#6B4EFF',
    stroke_weight INT NOT NULL DEFAULT 2,
    demographics_cache JSON NULL,
    demographics_cached_at DATETIME NULL,
    notes TEXT NULL,
    created_by CHAR(36) NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_area_project (project_id),
    INDEX idx_area_folder (folder_id),
    SPATIAL INDEX idx_area_geom (geometry),
    CONSTRAINT fk_area_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    CONSTRAINT fk_area_folder FOREIGN KEY (folder_id) REFERENCES folders(id) ON DELETE SET NULL,
    CONSTRAINT fk_area_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS imported_points (
    id CHAR(36) PRIMARY KEY,
    project_id CHAR(36) NOT NULL,
    import_batch_id CHAR(36) NOT NULL,
    label VARCHAR(255) NULL,
    address TEXT NULL,
    lat DOUBLE NULL,
    lng DOUBLE NULL,
    point POINT NOT NULL SRID 4326,
    custom_data JSON NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_ip_project (project_id),
    INDEX idx_ip_batch (import_batch_id),
    SPATIAL INDEX idx_ip_point (point),
    CONSTRAINT fk_ip_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS poi_cache (
    id CHAR(36) PRIMARY KEY,
    query_hash VARCHAR(64) NOT NULL,
    area_id CHAR(36) NULL,
    results JSON NOT NULL,
    cached_at DATETIME NOT NULL,
    expires_at DATETIME NULL,
    INDEX idx_poi_hash (query_hash),
    INDEX idx_poi_area (area_id),
    CONSTRAINT fk_poi_area FOREIGN KEY (area_id) REFERENCES areas(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS census_tracts (
    geoid VARCHAR(11) PRIMARY KEY,
    state_fips VARCHAR(2) NOT NULL,
    county_fips VARCHAR(3) NOT NULL,
    tract_id VARCHAR(6) NOT NULL,
    name VARCHAR(100) NULL,
    geometry MULTIPOLYGON NOT NULL SRID 4326,
    land_area_sqm DOUBLE NULL,
    water_area_sqm DOUBLE NULL,
    SPATIAL INDEX idx_ct_geom (geometry),
    INDEX idx_ct_state (state_fips)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS census_demographics (
    geoid VARCHAR(11) PRIMARY KEY,
    total_population INT NULL,
    median_household_income INT NULL,
    median_home_value INT NULL,
    labor_force_total INT NULL,
    unemployed_total INT NULL,
    male_total INT NULL,
    female_total INT NULL,
    housing_units_total INT NULL,
    age_under_18 INT NULL,
    age_18_to_34 INT NULL,
    age_35_to_54 INT NULL,
    age_55_to_64 INT NULL,
    age_65_plus INT NULL,
    income_under_25k INT NULL,
    income_25k_to_50k INT NULL,
    income_50k_to_75k INT NULL,
    income_75k_to_100k INT NULL,
    income_100k_plus INT NULL,
    data_year INT NULL,
    updated_at DATETIME NULL,
    CONSTRAINT fk_demo_tract FOREIGN KEY (geoid) REFERENCES census_tracts(geoid) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS reports (
    id CHAR(36) PRIMARY KEY,
    area_id CHAR(36) NULL,
    project_id CHAR(36) NOT NULL,
    report_type ENUM('area_analysis','comparison','territory_overview') NOT NULL DEFAULT 'area_analysis',
    title VARCHAR(255) NOT NULL,
    file_path TEXT NOT NULL,
    generated_at DATETIME NOT NULL,
    generated_by CHAR(36) NULL,
    INDEX idx_report_project (project_id),
    INDEX idx_report_area (area_id),
    CONSTRAINT fk_report_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    CONSTRAINT fk_report_area FOREIGN KEY (area_id) REFERENCES areas(id) ON DELETE SET NULL,
    CONSTRAINT fk_report_user FOREIGN KEY (generated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS api_usage_log (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    user_id CHAR(36) NULL,
    api_name VARCHAR(50) NOT NULL,
    endpoint VARCHAR(255) NULL,
    request_count INT NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    INDEX idx_usage_user_api (user_id, api_name, created_at),
    CONSTRAINT fk_usage_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_log (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    user_id CHAR(36) NULL,
    action VARCHAR(100) NOT NULL,
    entity_type VARCHAR(50) NULL,
    entity_id CHAR(36) NULL,
    details JSON NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_audit_user (user_id),
    INDEX idx_audit_entity (entity_type, entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
