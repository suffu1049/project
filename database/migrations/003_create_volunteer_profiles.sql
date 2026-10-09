CREATE TABLE volunteer_profiles (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    user_id BIGINT UNSIGNED NOT NULL UNIQUE,

    skills TEXT NULL,
    vehicle VARCHAR(100) NULL,
    equipment TEXT NULL,

    availability ENUM(
        'AVAILABLE',
        'BUSY',
        'OFFLINE'
    ) NOT NULL DEFAULT 'OFFLINE',

    service_radius_km DECIMAL(6,2) NOT NULL DEFAULT 10.00,

    latitude DECIMAL(10,8) NULL,
    longitude DECIMAL(11,8) NULL,

    verification_status ENUM(
        'PENDING',
        'VERIFIED',
        'REJECTED'
    ) NOT NULL DEFAULT 'PENDING',

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_volunteer_user
        FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,

    INDEX idx_volunteer_availability (availability),
    INDEX idx_volunteer_verification (verification_status),
    INDEX idx_volunteer_location (latitude, longitude)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;
