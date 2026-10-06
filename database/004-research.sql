CREATE TABLE IF NOT EXISTS user_profiles (
    user_id BIGINT UNSIGNED PRIMARY KEY,
    professional_role VARCHAR(40) NOT NULL,
    registration_ip VARCHAR(45) NULL,
    user_agent VARCHAR(512) NULL,
    os_name VARCHAR(40) NULL,
    browser_name VARCHAR(40) NULL,
    device_type VARCHAR(20) NULL,
    language VARCHAR(80) NULL,
    recorded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT profile_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS super_admins (
    user_id BIGINT UNSIGNED PRIMARY KEY,
    granted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT super_admin_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS audit_context (
    audit_id CHAR(32) PRIMARY KEY,
    connection_ip VARCHAR(45) NULL,
    user_agent VARCHAR(512) NULL,
    os_name VARCHAR(40) NULL,
    browser_name VARCHAR(40) NULL,
    device_type VARCHAR(20) NULL,
    language VARCHAR(80) NULL,
    CONSTRAINT context_audit FOREIGN KEY (audit_id) REFERENCES audits(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS usage_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    occurred_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    event_type VARCHAR(40) NOT NULL,
    user_id BIGINT UNSIGNED NULL,
    visitor_hash CHAR(64) NOT NULL,
    audit_id CHAR(32) NULL,
    page_url VARCHAR(2048) NULL,
    connection_ip VARCHAR(45) NULL,
    user_agent VARCHAR(512) NULL,
    os_name VARCHAR(40) NULL,
    browser_name VARCHAR(40) NULL,
    device_type VARCHAR(20) NULL,
    language VARCHAR(80) NULL,
    metadata_json TEXT NULL,
    INDEX event_time(occurred_at,id),
    INDEX event_user(user_id,occurred_at),
    INDEX event_visitor(visitor_hash,occurred_at),
    INDEX event_audit(audit_id,event_type),
    CONSTRAINT event_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT IGNORE INTO schema_versions(version) VALUES(4);
