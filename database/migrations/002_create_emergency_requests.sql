CREATE TABLE emergency_requests (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    user_id BIGINT UNSIGNED NOT NULL,

    category ENUM(
        'MEDICAL',
        'ACCIDENT',
        'FLOOD',
        'FIRE',
        'FOOD_WATER',
        'SHELTER',
        'TRANSPORT',
        'MISSING_STRANDED',
        'OTHER'
    ) NOT NULL,

    description TEXT NOT NULL,

    people_affected INT UNSIGNED NOT NULL DEFAULT 1,

    priority ENUM(
        'LOW',
        'MEDIUM',
        'HIGH',
        'CRITICAL'
    ) NOT NULL DEFAULT 'MEDIUM',

    priority_score INT UNSIGNED NOT NULL DEFAULT 0,

    priority_reason TEXT NULL,

    status ENUM(
        'CREATED',
        'VERIFIED',
        'MATCHING',
        'ACCEPTED',
        'IN_PROGRESS',
        'RESOLVED',
        'ESCALATED',
        'CANCELLED'
    ) NOT NULL DEFAULT 'CREATED',

    latitude DECIMAL(10,8) NULL,
    longitude DECIMAL(11,8) NULL,

    location_accuracy_m DECIMAL(10,2) NULL,

    address_text VARCHAR(500) NULL,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    resolved_at DATETIME NULL,

    CONSTRAINT fk_requests_user
        FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON DELETE RESTRICT
        ON UPDATE CASCADE,

    INDEX idx_requests_user (user_id),
    INDEX idx_requests_status (status),
    INDEX idx_requests_priority (priority),
    INDEX idx_requests_category (category),
    INDEX idx_requests_created (created_at),
    INDEX idx_requests_location (latitude, longitude)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;
