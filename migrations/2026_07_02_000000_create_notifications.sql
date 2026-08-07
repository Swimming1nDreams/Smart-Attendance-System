CREATE TABLE IF NOT EXISTS notifications (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    type VARCHAR(80) NOT NULL,
    title_key VARCHAR(150) NOT NULL,
    message_key VARCHAR(150) NOT NULL,
    link_url VARCHAR(255) NULL,
    related_user_id INT UNSIGNED NULL,
    related_leave_request_id INT UNSIGNED NULL,
    read_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_notifications_user_read_created (user_id, read_at, created_at),
    INDEX idx_notifications_related_leave (related_leave_request_id),
    CONSTRAINT fk_notifications_user_id FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_notifications_related_user_id FOREIGN KEY (related_user_id) REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_notifications_related_leave_request_id FOREIGN KEY (related_leave_request_id) REFERENCES leave_requests (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
