CREATE TABLE resources (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    organization_id BIGINT UNSIGNED NOT NULL,

    resource_type ENUM(
        'FOOD',
        'WATER',
        'SHELTER',
        'TRANSPORT',
        'MEDICAL_SUPPORT',
        'BLANKETS',
        'OTHER'
    ) NOT NULL,

    resource_name VARCHAR(150) NOT NULL,

    quantity INT UNSIGNED NOT NULL DEFAULT 0,

    available_quantity INT UNSIGNED NOT NULL DEFAULT 0,

    unit VARCHAR(50) NULL,

    status ENUM(
        'AVAILABLE',
        'LIMITED',
        'UNAVAILABLE'
    ) NOT NULL DEFAULT 'AVAILABLE',

    latitude DECIMAL(10,8) NULL,
    longitude DECIMAL(11,8) NULL,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_resource_organization
        FOREIGN KEY (organization_id)
        REFERENCES organizations(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,

    CONSTRAINT chk_resource_available
        CHECK (available_quantity <= quantity),

    INDEX idx_resources_org (organization_id),
    INDEX idx_resources_type (resource_type),
    INDEX idx_resources_status (status),
    INDEX idx_resources_location (latitude, longitude)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;
