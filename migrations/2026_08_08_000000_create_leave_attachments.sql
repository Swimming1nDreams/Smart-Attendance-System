-- Add leave attachments for SAPAS Phase 2B

CREATE TABLE IF NOT EXISTS leave_request_attachments (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    leave_request_id INT UNSIGNED NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    mime_type VARCHAR(100) NOT NULL,
    file_size INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_leave_attachments_leave_id (leave_request_id),
    CONSTRAINT fk_leave_attachments_leave_id FOREIGN KEY (leave_request_id) REFERENCES leave_requests(id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
