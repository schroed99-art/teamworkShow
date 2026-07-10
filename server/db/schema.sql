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
    anzeige_info    VARCHAR(255) NOT NULL DEFAULT '',
    last_seen       TIMESTAMP NULL DEFAULT NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_devices_pairing (pairing_code),
    KEY idx_devices_tenant (tenant_id),
    KEY idx_devices_presentation (presentation_id),
    CONSTRAINT fk_devices_tenant FOREIGN KEY (tenant_id)
        REFERENCES tenants (id) ON DELETE CASCADE,
    CONSTRAINT fk_devices_presentation FOREIGN KEY (presentation_id)
        REFERENCES presentations (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS slides (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    presentation_id INT UNSIGNED NOT NULL,
    media_name      VARCHAR(255) NOT NULL,
    position        INT NOT NULL DEFAULT 0,
    duration_ms     INT NOT NULL DEFAULT 8000,
    PRIMARY KEY (id),
    KEY idx_slides_presentation (presentation_id, position),
    CONSTRAINT fk_slides_presentation FOREIGN KEY (presentation_id)
        REFERENCES presentations (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS widget_settings (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    device_id        INT UNSIGNED NOT NULL,
    weather_enabled  TINYINT(1) NOT NULL DEFAULT 0,
    weather_location VARCHAR(120) NOT NULL DEFAULT '',
    notices_enabled  TINYINT(1) NOT NULL DEFAULT 0,
    notices_text     TEXT NULL,
    schedule         TEXT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_widget_device (device_id),
    CONSTRAINT fk_widget_device FOREIGN KEY (device_id)
        REFERENCES devices (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
