CREATE TABLE matches (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    request_id BIGINT UNSIGNED NOT NULL,

    volunteer_id BIGINT UNSIGNED NULL,

    resource_id BIGINT UNSIGNED NULL,

    distance_km DECIMAL(8,3) NULL,

    capability_score DECIMAL(6,2) NOT NULL DEFAULT 0,

    match_score DECIMAL(8,2) NOT NULL DEFAULT 0,

    status ENUM(
        'SUGGESTED',
        'NOTIFIED',
        'ACCEPTED',
        'REJECTED',
        'EXPIRED',
        'COMPLETED'
    ) NOT NULL DEFAULT 'SUGGESTED',

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_match_request
        FOREIGN KEY (request_id)
        REFERENCES emergency_requests(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,

    CONSTRAINT fk_match_volunteer
        FOREIGN KEY (volunteer_id)
        REFERENCES volunteer_profiles(id)
        ON DELETE SET NULL
        ON UPDATE CASCADE,

    CONSTRAINT fk_match_resource
        FOREIGN KEY (resource_id)
        REFERENCES resources(id)
        ON DELETE SET NULL
        ON UPDATE CASCADE,

    INDEX idx_matches_request (request_id),
    INDEX idx_matches_volunteer (volunteer_id),
    INDEX idx_matches_resource (resource_id),
    INDEX idx_matches_status (status)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;
