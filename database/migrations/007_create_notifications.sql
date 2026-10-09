CREATE TABLE notifications (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    user_id BIGINT UNSIGNED NOT NULL,

    type VARCHAR(50) NOT NULL,

    title VARCHAR(180) NOT NULL,

    message TEXT NOT NULL,

    related_entity_type VARCHAR(50) NULL,
    related_entity_id BIGINT UNSIGNED NULL,

    read_status BOOLEAN NOT NULL DEFAULT FALSE,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_notification_user
        FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,

    INDEX idx_notifications_user (user_id),
    INDEX idx_notifications_read (read_status),
    INDEX idx_notifications_created (created_at)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;
