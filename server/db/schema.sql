-- TeamworkShow multi-tenant backend schema (MariaDB / MySQL).
-- Idempotent: safe to re-apply. Charset utf8mb4 throughout.

CREATE TABLE IF NOT EXISTS tenants (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name       VARCHAR(120) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS presentations (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id  INT UNSIGNED NOT NULL,
    name       VARCHAR(160) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_presentations_tenant (tenant_id),
    CONSTRAINT fk_presentations_tenant FOREIGN KEY (tenant_id)
        REFERENCES tenants (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS devices (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id       INT UNSIGNED NOT NULL,
    presentation_id INT UNSIGNED NULL,
    pairing_code    VARCHAR(16) NOT NULL,
    name            VARCHAR(120) NOT NULL DEFAULT '',
    standort        VARCHAR(160) NOT NULL DEFAULT '',
    projektnummer   VARCHAR(32) NOT NULL DEFAULT '',
    anzeige_info    VARCHAR(255) NOT NULL DEFAULT '',
    display_format  VARCHAR(16) NOT NULL DEFAULT 'portrait',
    -- Screen zones (Phase 5.3): 'single' = one full-screen slideshow;
    -- 'split' = company zone (company_presentation_id) + customer zone
    -- (presentation_id — the only one a customer may set). zone_axis: rows|cols;
    -- zone_split = the company zone's share in percent.
    zone_mode       VARCHAR(8) NOT NULL DEFAULT 'single',
    zone_axis       VARCHAR(8) NOT NULL DEFAULT 'rows',
    zone_split      TINYINT UNSIGNED NOT NULL DEFAULT 70,
    company_presentation_id INT UNSIGNED NULL DEFAULT NULL,
    last_seen       TIMESTAMP NULL DEFAULT NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_devices_pairing (pairing_code),
    KEY idx_devices_tenant (tenant_id),
    KEY idx_devices_presentation (presentation_id),
    KEY idx_devices_company_presentation (company_presentation_id),
    CONSTRAINT fk_devices_tenant FOREIGN KEY (tenant_id)
        REFERENCES tenants (id) ON DELETE CASCADE,
    CONSTRAINT fk_devices_presentation FOREIGN KEY (presentation_id)
        REFERENCES presentations (id) ON DELETE SET NULL,
    CONSTRAINT fk_devices_company_presentation FOREIGN KEY (company_presentation_id)
        REFERENCES presentations (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS slides (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    presentation_id INT UNSIGNED NOT NULL,
    media_name      VARCHAR(255) NOT NULL,
    -- 'weather' and 'news' are file-less slides; a news slide carries its own text.
    kind            ENUM('media','weather','news') NOT NULL DEFAULT 'media',
    text_title      VARCHAR(200) NOT NULL DEFAULT '',
    text_body       TEXT NULL DEFAULT NULL,
    position        INT NOT NULL DEFAULT 0,
    duration_ms     INT NOT NULL DEFAULT 8000,
    PRIMARY KEY (id),
    KEY idx_slides_presentation (presentation_id, position),
    CONSTRAINT fk_slides_presentation FOREIGN KEY (presentation_id)
        REFERENCES presentations (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Single global template for the weather interstitial (shared by all weather slides).
-- One row (id = 1); `config` is a JSON blob: background, scrim, and per-element
-- grid position/size for city / forecast / clock plus free-text blocks.
CREATE TABLE IF NOT EXISTS weather_layout (
    id     TINYINT UNSIGNED NOT NULL DEFAULT 1,
    config TEXT NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS widget_settings (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    device_id        INT UNSIGNED NOT NULL,
    weather_enabled  TINYINT(1) NOT NULL DEFAULT 0,
    weather_location VARCHAR(120) NOT NULL DEFAULT '',
    notices_enabled  TINYINT(1) NOT NULL DEFAULT 0,
    notices_text     TEXT NULL,
    notices_size     SMALLINT UNSIGNED NOT NULL DEFAULT 15,
    notices_bg       VARCHAR(9) NOT NULL DEFAULT '#66000000',
    notices_height   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    schedule         TEXT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_widget_device (device_id),
    CONSTRAINT fk_widget_device FOREIGN KEY (device_id)
        REFERENCES devices (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Last known online/offline state per device (device_monitor.php). Lets the
-- monitor log only transitions and raise an alarm once per offline episode.
CREATE TABLE IF NOT EXISTS device_status (
    device_id INT UNSIGNED NOT NULL,
    status    VARCHAR(16) NOT NULL DEFAULT 'never',
    since     DATETIME NOT NULL,
    alerted   TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (device_id),
    CONSTRAINT fk_device_status_device FOREIGN KEY (device_id)
        REFERENCES devices (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
