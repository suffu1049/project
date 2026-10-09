CREATE TABLE request_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    request_id BIGINT UNSIGNED NOT NULL,

    actor_id BIGINT UNSIGNED NULL,

    old_status VARCHAR(30) NULL,
    new_status VARCHAR(30) NULL,

    event_type VARCHAR(50) NOT NULL,

    note TEXT NULL,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_event_request
        FOREIGN KEY (request_id)
        REFERENCES emergency_requests(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,

    CONSTRAINT fk_event_actor
        FOREIGN KEY (actor_id)
        REFERENCES users(id)
        ON DELETE SET NULL
        ON UPDATE CASCADE,

    INDEX idx_events_request (request_id),
    INDEX idx_events_actor (actor_id),
    INDEX idx_events_created (created_at)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;
