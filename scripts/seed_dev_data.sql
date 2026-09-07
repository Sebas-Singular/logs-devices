-- =============================================================================
-- Datos de ejemplo para desarrollo local
-- =============================================================================
--
-- Cubre a propósito las DOS generaciones de formato que conviven en el
-- histórico, que es lo que hay que poder ver en el visor:
--
--   - Eventos estructurados actuales  -> claves camelCase (speedKmh, socPct...)
--   - Eventos de parsers legacy       -> claves snake_case (speed_kmh, soc_pct...)
--
-- Y los casos que el panel tiene que saber distinguir:
--   - ingest zombie en 'parsing', ingest en 'error', ingest 'received'
--   - evento con parse_ok = 0 y evento con quality_status = 'suspect'
--   - tipos de evento dormidos (200 días sin reportar)
--   - baliza en silencio y balizas que emitieron por un bridge que no es su padre
--
-- Uso:
--   mysql -h 127.0.0.1 -u logs_user -p"logs_pass_dev" "logs-devices" < scripts/seed_dev_data.sql
--
-- ATENCIÓN: los tests de integración asumen una base de datos limpia. Vacía las
-- tablas antes de lanzar PHPUnit o fallarán por el ingest zombie que crea este
-- fichero:
--   TRUNCATE log_events; TRUNCATE log_ingests; TRUNCATE devices;
-- =============================================================================

USE `logs-devices`;

SET FOREIGN_KEY_CHECKS = 0;
TRUNCATE TABLE log_events;
TRUNCATE TABLE log_ingests;
TRUNCATE TABLE devices;
SET FOREIGN_KEY_CHECKS = 1;

-- Dispositivos: 2 bridges, 3 balizas activas, 1 baliza en silencio
INSERT INTO devices (id, device_kind, mac_address, external_id, name, name_origin, parent_device_id, first_seen_at, last_seen_at) VALUES
 (1, 'bridge', NULL,                '120', 'WalkerPisa Bridge',        'reported', NULL, UTC_TIMESTAMP() - INTERVAL 200 DAY, UTC_TIMESTAMP()),
 (2, 'bridge', NULL,                '121', 'Las Rozas Bridge',         'reported', NULL, UTC_TIMESTAMP() - INTERVAL 150 DAY, UTC_TIMESTAMP()),
 (3, 'baliza', '34:85:18:46:e3:1c', '03',  'Baliza 03 - Las Rozas',    'reported', 1,    UTC_TIMESTAMP() - INTERVAL 200 DAY, UTC_TIMESTAMP()),
 (4, 'baliza', '34:85:18:46:e3:20', '06',  'Baliza 06 - Las Rozas',    'reported', 1,    UTC_TIMESTAMP() - INTERVAL 190 DAY, UTC_TIMESTAMP()),
 (5, 'baliza', '34:85:18:46:e3:70', '08',  'Baliza 08 - Las Rozas',    'reported', 2,    UTC_TIMESTAMP() - INTERVAL 100 DAY, UTC_TIMESTAMP()),
 (6, 'baliza', '34:85:18:46:e3:99', '11',  'Baliza 11 - Desconectada', 'reported', 2,    UTC_TIMESTAMP() - INTERVAL 300 DAY, UTC_TIMESTAMP() - INTERVAL 90 DAY);

-- Ingests: procesados recientes, uno en error, uno recibido, uno zombie en parsing
INSERT INTO log_ingests (id, received_at, remote_addr, user_agent, source_type, source_device_id, bridge_id_reported, content_hash, raw_path, payload_summary, status, line_count, parsed_ok_count, parsed_error_count, processing_started_at, processing_finished_at) VALUES
 (1, UTC_TIMESTAMP() - INTERVAL 20 MINUTE, '10.0.0.1', 'WalkerPisa-Bridge-Logs', 'walkerpisa_bridge', 1, '120', REPEAT('a',64), '/raw/1.ndjson', '{"processedMode":"structured_events"}', 'processed', 12, 12, 0, UTC_TIMESTAMP() - INTERVAL 20 MINUTE, UTC_TIMESTAMP() - INTERVAL 19 MINUTE),
 (2, UTC_TIMESTAMP() - INTERVAL 2 HOUR,    '10.0.0.1', 'WalkerPisa-Bridge-Logs', 'walkerpisa_bridge', 2, '121', REPEAT('b',64), '/raw/2.ndjson', '{"processedMode":"structured_events"}', 'processed', 8,  7,  1, UTC_TIMESTAMP() - INTERVAL 2 HOUR,    UTC_TIMESTAMP() - INTERVAL 2 HOUR),
 (3, UTC_TIMESTAMP() - INTERVAL 3 HOUR,    '10.0.0.2', 'WalkerPisa-Bridge-Logs', 'walkerpisa_bridge', 1, '120', REPEAT('c',64), '/raw/3.ndjson', '{"processedMode":"legacy_log_text"}',   'error',     4,  0,  4, UTC_TIMESTAMP() - INTERVAL 3 HOUR,    NULL),
 (4, UTC_TIMESTAMP() - INTERVAL 5 MINUTE,  '10.0.0.1', 'WalkerPisa-Bridge-Logs', 'walkerpisa_bridge', 1, '120', REPEAT('d',64), '/raw/4.ndjson', '{}',                                   'received',  0,  0,  0, NULL, NULL),
 (5, UTC_TIMESTAMP() - INTERVAL 45 MINUTE, '10.0.0.1', 'WalkerPisa-Bridge-Logs', 'walkerpisa_bridge', 2, '121', REPEAT('e',64), '/raw/5.ndjson', '{}',                                   'parsing',   6,  0,  0, UTC_TIMESTAMP() - INTERVAL 45 MINUTE, NULL),
 (6, UTC_TIMESTAMP() - INTERVAL 200 DAY,   '10.0.0.1', 'WalkerPisa-Bridge-Logs', 'walkerpisa_bridge', 1, '120', REPEAT('f',64), '/raw/6.ndjson', '{"processedMode":"legacy_log_text"}',   'processed', 20, 20, 0, UTC_TIMESTAMP() - INTERVAL 200 DAY,   UTC_TIMESTAMP() - INTERVAL 200 DAY);

-- ============================================================================
-- GENERACIÓN ACTUAL: eventos estructurados con claves camelCase
-- ============================================================================
INSERT INTO log_events (ingest_id, device_id, bridge_device_id, event_timestamp, received_at, severity, severity_origin, event_type, event_category, device_mac_raw, message_text, measurements, context, parse_ok, parse_error, quality_status, anomaly_flags, event_hash) VALUES
 (1, 3, 1, UTC_TIMESTAMP() - INTERVAL 20 MINUTE, UTC_TIMESTAMP() - INTERVAL 20 MINUTE, 'info', 'reported', 'telemetry_snapshot', 'telemetry', '34:85:18:46:e3:1c',
  'Telemetry snapshot · T=21.4C · H=45.8%',
  '{"temperatureC":21.4,"humidityPct":45.8,"pressureHpa":1013.2,"airQuality":82.0,"socPct":88,"parentRssi":-64,"lidarDistanceMm":1822,"altitudeM":612,"lane":1,"locationId":4}',
  '{"tags":{"iaqState":"READY","chargingState":"CHARGING","powerSource":"AC"},"source":{"project":"walkerpisa"}}',
  1, NULL, 'valid', '[]', SHA2('e1',256)),

 (1, 3, 1, UTC_TIMESTAMP() - INTERVAL 18 MINUTE, UTC_TIMESTAMP() - INTERVAL 18 MINUTE, 'info', 'reported', 'speed_calculated', 'speed', '34:85:18:46:e3:1c',
  'Speed calculated · Vel=34.8km/h',
  '{"speedKmh":34.8,"lane":1,"locationId":4,"distanceMm":229,"positionM":1.2}',
  '{"tags":{}}',
  1, NULL, 'valid', '[]', SHA2('e2',256)),

 (1, 5, 2, UTC_TIMESTAMP() - INTERVAL 12 MINUTE, UTC_TIMESTAMP() - INTERVAL 12 MINUTE, 'info', 'reported', 'vehicle_detected', 'vehicle', '34:85:18:46:e3:70',
  'Vehicle detected · Vel final=31.4km/h',
  '{"distanceMm":229,"previousSpeedKmh":28.1,"finalSpeedKmh":31.4,"accelerationMps2":-0.6}',
  '{"tags":{"mode":"speed","dynamicState":"BRAKING","quality":"HIGH"}}',
  1, NULL, 'valid', '[]', SHA2('e3',256)),

 (2, 4, 1, UTC_TIMESTAMP() - INTERVAL 2 HOUR, UTC_TIMESTAMP() - INTERVAL 2 HOUR, 'error', 'reported', 'http_result', 'https', '34:85:18:46:e3:20',
  'HTTPS POST result failed · HTTP=500 · Notif=ping',
  '{"status":500,"ok":0}',
  '{"tags":{"protocol":"HTTPS","notificationType":"ping"}}',
  1, NULL, 'valid', '[]', SHA2('e4',256)),

 (2, 4, 1, UTC_TIMESTAMP() - INTERVAL 3 HOUR, UTC_TIMESTAMP() - INTERVAL 3 HOUR, 'warn', 'reported', 'telemetry_snapshot', 'telemetry', '34:85:18:46:e3:20',
  'Telemetry snapshot · SOC=12% · GPS=1',
  '{"temperatureC":19.0,"humidityPct":51.0,"socPct":12,"parentRssi":-91,"gps":{"error":1}}',
  '{"tags":{"chargingState":"DISCHARGING"}}',
  1, NULL, 'suspect', '["low_soc","weak_rssi","gps_no_signal"]', SHA2('e5',256)),

 (2, NULL, 1, UTC_TIMESTAMP() - INTERVAL 4 HOUR, UTC_TIMESTAMP() - INTERVAL 4 HOUR, 'error', 'derived', 'unknown', 'unknown', NULL,
  'Invalid structured event at index 3',
  '{}', '{"rawEvent":"boom"}',
  0, 'structured_event_not_object', 'invalid', '["invalid_structured_event"]', SHA2('e6',256)),

 (2, 5, 2, UTC_TIMESTAMP() - INTERVAL 30 HOUR, UTC_TIMESTAMP() - INTERVAL 30 HOUR, 'critical', 'reported', 'bridge_error', 'errors', '34:85:18:46:e3:70',
  'Bridge error: watchdog reset',
  '{}', '{"code":"WDT"}',
  1, NULL, 'valid', '[]', SHA2('e7',256)),

-- ============================================================================
-- GENERACIÓN ANTERIOR: parsers legacy sobre logText, claves snake_case
-- Dentro de la ventana de 7 días para comprobar que se leen igual.
-- ============================================================================
 (1, 4, 1, UTC_TIMESTAMP() - INTERVAL 2 DAY, UTC_TIMESTAMP() - INTERVAL 2 DAY, 'info', 'derived', 'telemetry', 'telemetry', '34:85:18:46:e3:20',
  '[TELEMETRY] INFO: T=17.1C H=23.90% P=928.1hPa AQ=186.2 SOC=100% RSSI=-78',
  '{"temperature_c":17.1,"humidity_pct":23.9,"pressure_hpa":928.1,"aq_index":186.2,"altitude_m":734,"lidar_mm":5405,"soc_pct":100,"rssi_dbm":-78}',
  '{"lane":1,"loc":3,"pos":74000,"ref":5975}',
  1, NULL, 'valid', '[]', SHA2('e8',256)),

 (1, 4, 1, UTC_TIMESTAMP() - INTERVAL 2 DAY - INTERVAL 5 MINUTE, UTC_TIMESTAMP() - INTERVAL 2 DAY, 'info', 'derived', 'speed', 'speed', '34:85:18:46:e3:20',
  '[SPEED] INFO: dist=5329mm pos=14.00m speed=24.87km/h',
  '{"distance_mm":5329.0,"position_m":14.0,"speed_kmh":24.87}',
  '{"lane":1,"loc":1}',
  1, NULL, 'valid', '[]', SHA2('e9',256)),

-- ============================================================================
-- TIPO DORMIDO: existe en el histórico pero lleva 200 días sin reportar
-- ============================================================================
 (6, 3, 1, UTC_TIMESTAMP() - INTERVAL 200 DAY, UTC_TIMESTAMP() - INTERVAL 200 DAY, 'info', 'derived', 'legacy_heartbeat', 'heartbeat', '34:85:18:46:e3:1c',
  '[HEARTBEAT] INFO: alive',
  '{}', '{}',
  1, NULL, 'valid', '[]', SHA2('e10',256)),

 (6, 3, 1, UTC_TIMESTAMP() - INTERVAL 201 DAY, UTC_TIMESTAMP() - INTERVAL 201 DAY, 'info', 'derived', 'telemetry_header', 'telemetry', '34:85:18:46:e3:1c',
  '[TELEMETRY] header',
  '{}', '{}',
  1, NULL, 'valid', '[]', SHA2('e11',256));

-- Balizas que emitieron a través de un bridge que hoy no es su padre.
-- Reproduce el caso de la vista árbol de dispositivos: cada bridge debe mostrar
-- las suyas, no solo el último de la lista.
INSERT INTO log_events (ingest_id, device_id, bridge_device_id, event_timestamp, received_at, severity, severity_origin, event_type, event_category, device_mac_raw, message_text, measurements, context, parse_ok, quality_status, anomaly_flags, event_hash) VALUES
 (2, 3, 2, UTC_TIMESTAMP() - INTERVAL 6 HOUR, UTC_TIMESTAMP() - INTERVAL 6 HOUR, 'info','reported','telemetry_snapshot','telemetry','34:85:18:46:e3:1c','Telemetry a través de otro bridge','{"temperatureC":18.2}','{"tags":{}}',1,'valid','[]',SHA2('m1',256)),
 (1, 5, 1, UTC_TIMESTAMP() - INTERVAL 8 HOUR, UTC_TIMESTAMP() - INTERVAL 8 HOUR, 'info','reported','telemetry_snapshot','telemetry','34:85:18:46:e3:70','Telemetry a través de otro bridge','{"temperatureC":19.9}','{"tags":{}}',1,'valid','[]',SHA2('m2',256));
