CREATE TABLE organizations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    user_id BIGINT UNSIGNED NOT NULL UNIQUE,

    name VARCHAR(180) NOT NULL,

    type ENUM(
        'NGO',
        'HOSPITAL',
        'SHELTER',
        'FOOD_PROVIDER',
        'COMMUNITY_GROUP',
        'OTHER'
    ) NOT NULL,

    contact VARCHAR(100) NULL,

    description TEXT NULL,

    verification_status ENUM(
        'PENDING',
        'VERIFIED',
        'REJECTED'
    ) NOT NULL DEFAULT 'PENDING',

    latitude DECIMAL(10,8) NULL,
    longitude DECIMAL(11,8) NULL,

    address_text VARCHAR(500) NULL,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_organization_user
        FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,

    INDEX idx_org_type (type),
    INDEX idx_org_verification (verification_status),
    INDEX idx_org_location (latitude, longitude)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;
