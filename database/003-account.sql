CREATE TABLE IF NOT EXISTS browser_audit_choices (
    user_id BIGINT UNSIGNED NOT NULL,
    visitor_hash CHAR(64) NOT NULL,
    decision VARCHAR(16) NOT NULL,
    decided_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id,visitor_hash),
    CONSTRAINT browser_choice_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT IGNORE INTO schema_versions (version) VALUES (3);
