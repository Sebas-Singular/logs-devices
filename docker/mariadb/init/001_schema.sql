CREATE DATABASE IF NOT EXISTS `logs-devices`
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `logs-devices`;

CREATE TABLE IF NOT EXISTS devices (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

  device_kind VARCHAR(20) NOT NULL,
  mac_address VARCHAR(17) NULL,
  external_id VARCHAR(50) NULL,

  name VARCHAR(255) NOT NULL,
  name_origin VARCHAR(20) NOT NULL DEFAULT 'reported',

  parent_device_id BIGINT UNSIGNED NULL,

  first_seen_at DATETIME NOT NULL,
  last_seen_at DATETIME NOT NULL,
  last_event_at DATETIME NULL,

  metadata JSON NULL,

  is_active TINYINT(1) NOT NULL DEFAULT 1,

  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (id),

  UNIQUE KEY uq_devices_mac_address (mac_address),

  KEY idx_devices_external_id (external_id),
  KEY idx_devices_parent_device_id (parent_device_id),
  KEY idx_devices_last_seen_at (last_seen_at),

  CONSTRAINT fk_devices_parent_device
    FOREIGN KEY (parent_device_id)
    REFERENCES devices (id)
    ON DELETE SET NULL,

  CONSTRAINT chk_devices_device_kind
    CHECK (device_kind IN ('bridge', 'baliza', 'standalone')),

  CONSTRAINT chk_devices_name_origin
    CHECK (name_origin IN ('reported', 'manual'))
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS log_ingests (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

  received_at DATETIME NOT NULL,

  remote_addr VARCHAR(45) NOT NULL,
  user_agent VARCHAR(255) NOT NULL,

  source_type VARCHAR(30) NOT NULL,

  source_device_id BIGINT UNSIGNED NULL,
  bridge_id_reported VARCHAR(50) NULL,

  content_hash CHAR(64) NOT NULL,
  raw_path VARCHAR(500) NOT NULL,

  payload_summary JSON NULL,

  status VARCHAR(20) NOT NULL DEFAULT 'received',

  line_count INT UNSIGNED NOT NULL DEFAULT 0,
  parsed_ok_count INT UNSIGNED NOT NULL DEFAULT 0,
  parsed_error_count INT UNSIGNED NOT NULL DEFAULT 0,

  processing_started_at DATETIME NULL,
  processing_finished_at DATETIME NULL,

  PRIMARY KEY (id),

  UNIQUE KEY uq_log_ingests_content_hash (content_hash),

  KEY idx_log_ingests_received_at (received_at),
  KEY idx_log_ingests_status (status),
  KEY idx_log_ingests_source_device_received_at (source_device_id, received_at),

  CONSTRAINT fk_log_ingests_source_device
    FOREIGN KEY (source_device_id)
    REFERENCES devices (id)
    ON DELETE SET NULL,

  CONSTRAINT chk_log_ingests_status
    CHECK (status IN ('received', 'parsing', 'processed', 'error'))
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS log_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

  ingest_id BIGINT UNSIGNED NOT NULL,

  device_id BIGINT UNSIGNED NULL,
  bridge_device_id BIGINT UNSIGNED NULL,

  event_timestamp DATETIME NULL,
  received_at DATETIME NOT NULL,

  severity VARCHAR(20) NOT NULL DEFAULT 'unknown',
  severity_origin VARCHAR(20) NOT NULL DEFAULT 'derived',

  event_type VARCHAR(50) NOT NULL DEFAULT 'unknown',
  event_category VARCHAR(50) NULL,

  device_mac_raw VARCHAR(17) NULL,

  message_text TEXT NOT NULL,

  measurements JSON NULL,
  context JSON NULL,

  parse_ok TINYINT(1) NOT NULL DEFAULT 1,
  parse_error VARCHAR(255) NULL,

  quality_status VARCHAR(20) NOT NULL DEFAULT 'valid',
  anomaly_flags JSON NULL,

  event_hash CHAR(64) NULL,

  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (id),

  UNIQUE KEY uq_log_events_event_hash (event_hash),

  KEY idx_log_events_device_timestamp (device_id, event_timestamp),
  KEY idx_log_events_severity_timestamp (severity, event_timestamp),
  KEY idx_log_events_type_timestamp (event_type, event_timestamp),
  KEY idx_log_events_ingest_id (ingest_id),
  KEY idx_log_events_bridge_device_timestamp (bridge_device_id, event_timestamp),

  CONSTRAINT fk_log_events_ingest
    FOREIGN KEY (ingest_id)
    REFERENCES log_ingests (id)
    ON DELETE CASCADE,

  CONSTRAINT fk_log_events_device
    FOREIGN KEY (device_id)
    REFERENCES devices (id)
    ON DELETE SET NULL,

  CONSTRAINT fk_log_events_bridge_device
    FOREIGN KEY (bridge_device_id)
    REFERENCES devices (id)
    ON DELETE SET NULL,

  CONSTRAINT chk_log_events_severity
    CHECK (severity IN ('info', 'warn', 'error', 'critical', 'unknown')),

  CONSTRAINT chk_log_events_severity_origin
    CHECK (severity_origin IN ('reported', 'derived')),

  CONSTRAINT chk_log_events_quality_status
    CHECK (quality_status IN ('valid', 'suspect', 'invalid', 'duplicate'))
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;