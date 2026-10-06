CREATE TABLE IF NOT EXISTS daily_usage (
 subject_hash CHAR(64) NOT NULL,
 kind VARCHAR(12) NOT NULL,
 usage_day DATE NOT NULL,
 used INT UNSIGNED NOT NULL DEFAULT 0,
 PRIMARY KEY (subject_hash,kind,usage_day)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS advanced_audits (
 audit_id CHAR(32) PRIMARY KEY,
 user_id BIGINT UNSIGNED NOT NULL,
 state VARCHAR(16) NOT NULL DEFAULT 'running',
 result_json LONGTEXT NULL,
 error_message VARCHAR(500) NULL,
 charged_day DATE NOT NULL,
 refunded TINYINT(1) NOT NULL DEFAULT 0,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 completed_at TIMESTAMP NULL,
 INDEX advanced_owner (user_id,state,created_at),
 CONSTRAINT advanced_audit FOREIGN KEY (audit_id) REFERENCES audits(id) ON DELETE CASCADE,
 CONSTRAINT advanced_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT IGNORE INTO schema_versions (version) VALUES (2);
