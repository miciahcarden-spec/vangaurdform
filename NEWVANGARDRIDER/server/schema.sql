-- Vanguard Velocity MySQL/MariaDB schema. Import this file into an empty database with phpMyAdmin.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS schema_migrations (
  version VARCHAR(100) NOT NULL PRIMARY KEY,
  applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_users (
  id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
  email VARCHAR(254) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_sessions (
  session_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
  admin_user_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at DATETIME NOT NULL,
  KEY admin_sessions_expiry_idx (expires_at),
  CONSTRAINT admin_sessions_user_fk FOREIGN KEY (admin_user_id) REFERENCES admin_users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS application_files (
  id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
  filename VARCHAR(255) NOT NULL,
  content_type VARCHAR(127) NOT NULL,
  size_bytes BIGINT UNSIGNED NOT NULL,
  file_data LONGBLOB NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS riders (
  id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  status VARCHAR(32) NOT NULL DEFAULT 'pending',
  full_name VARCHAR(255) NOT NULL,
  date_of_birth DATE NULL,
  place_of_birth TEXT NOT NULL,
  state_of_origin VARCHAR(100) NOT NULL DEFAULT '',
  state_of_origin_city VARCHAR(150) NOT NULL DEFAULT '',
  state_of_origin_address TEXT NOT NULL,
  residential_address TEXT NOT NULL,
  phone_number VARCHAR(80) NOT NULL,
  email_address VARCHAR(254) NOT NULL DEFAULT '',
  marital_status VARCHAR(100) NOT NULL DEFAULT '',
  residential_state VARCHAR(100) NOT NULL DEFAULT '',
  residential_city VARCHAR(150) NOT NULL DEFAULT '',
  residential_postal_code VARCHAR(32) NOT NULL DEFAULT '',
  emergency_contact_name VARCHAR(255) NOT NULL DEFAULT '',
  emergency_contact_phone VARCHAR(80) NOT NULL DEFAULT '',
  relationship_to_emergency_contact VARCHAR(100) NOT NULL DEFAULT '',
  has_delivery_experience TINYINT(1) NOT NULL DEFAULT 0,
  employment_history JSON NOT NULL,
  communication_skills TINYINT(1) NOT NULL DEFAULT 0,
  motorcycle_safety TINYINT(1) NOT NULL DEFAULT 0,
  motorcycle_maintenance TINYINT(1) NOT NULL DEFAULT 0,
  health_challenge TINYINT(1) NOT NULL DEFAULT 0,
  health_details TEXT NOT NULL,
  skills_experience TEXT NOT NULL,
  nin VARCHAR(11) NOT NULL DEFAULT '',
  nin_document_path CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  identity_card_front_path CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  identity_card_back_path CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  video_path CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  location_latitude DOUBLE NULL,
  location_longitude DOUBLE NULL,
  location_accuracy_meters DOUBLE NULL,
  location_captured_at DATETIME NULL,
  work_policy_accepted TINYINT(1) NOT NULL DEFAULT 0,
  terms_accepted TINYINT(1) NOT NULL DEFAULT 0,
  terms_signature TEXT NOT NULL,
  terms_signed_at DATETIME NULL,
  company_representative_signature TEXT NOT NULL,
  KEY riders_created_at_idx (created_at),
  KEY riders_status_idx (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS guarantors (
  id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
  rider_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  name VARCHAR(255) NOT NULL,
  phone_number VARCHAR(80) NOT NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'pending',
  verification_token CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
  submitted_at DATETIME NULL,
  guarantor_address TEXT NULL,
  guarantor_state VARCHAR(100) NOT NULL DEFAULT '',
  guarantor_city VARCHAR(150) NOT NULL DEFAULT '',
  guarantor_postal_code VARCHAR(32) NOT NULL DEFAULT '',
  state_of_origin VARCHAR(100) NOT NULL DEFAULT '',
  state_of_origin_city VARCHAR(150) NOT NULL DEFAULT '',
  state_of_origin_address TEXT NULL,
  guarantor_phone_confirmed VARCHAR(80) NOT NULL DEFAULT '',
  relationship VARCHAR(100) NOT NULL DEFAULT '',
  occupation VARCHAR(150) NOT NULL DEFAULT '',
  nin VARCHAR(11) NOT NULL DEFAULT '',
  nin_document_path CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  identity_card_front_path CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  identity_card_back_path CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  video_path CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  digital_signature TEXT NULL,
  confirmation TINYINT(1) NOT NULL DEFAULT 0,
  work_policy_accepted TINYINT(1) NOT NULL DEFAULT 0,
  location_latitude DOUBLE NULL,
  location_longitude DOUBLE NULL,
  location_accuracy_meters DOUBLE NULL,
  location_captured_at DATETIME NULL,
  KEY guarantors_rider_id_idx (rider_id),
  CONSTRAINT guarantors_rider_fk FOREIGN KEY (rider_id) REFERENCES riders (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS form_content (
  id SMALLINT UNSIGNED NOT NULL PRIMARY KEY,
  content JSON NOT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_by CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  CONSTRAINT form_content_admin_fk FOREIGN KEY (updated_by) REFERENCES admin_users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS company_branding (
  id SMALLINT UNSIGNED NOT NULL PRIMARY KEY,
  content_type VARCHAR(32) NOT NULL,
  image_data LONGBLOB NOT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_by CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  CONSTRAINT company_branding_admin_fk FOREIGN KEY (updated_by) REFERENCES admin_users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS telegram_settings (
  id SMALLINT UNSIGNED NOT NULL PRIMARY KEY,
  encrypted_credentials LONGBLOB NOT NULL,
  nonce VARBINARY(12) NOT NULL,
  auth_tag VARBINARY(16) NOT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_by CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  CONSTRAINT telegram_settings_admin_fk FOREIGN KEY (updated_by) REFERENCES admin_users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO form_content (id, content) VALUES (1, '{}');
INSERT IGNORE INTO schema_migrations (version) VALUES ('mysql_full_schema_001');
