-- =============================================================================
-- Índices para el panel de control dinámico
-- =============================================================================
--
-- El dashboard acota todo a una ventana temporal (WHERE event_timestamp >= ?)
-- y agrupa por categoría. Hasta ahora log_events solo tenía event_timestamp
-- como SEGUNDA columna de índices compuestos (device_id, severity, event_type),
-- así que ningún filtro por fecha suelto podía usarlos: cada carga del panel
-- hacía un recorrido completo de la tabla más un filesort.
--
-- Ejecutar en phpMyAdmin sobre la base de datos de producción.
-- Las tres sentencias son aditivas y no bloquean lecturas en InnoDB.
--
-- Verificar después con:
--   SHOW INDEX FROM log_events;
-- =============================================================================

ALTER TABLE log_events
  ADD INDEX idx_log_events_event_timestamp (event_timestamp);

ALTER TABLE log_events
  ADD INDEX idx_log_events_category_timestamp (event_category, event_timestamp);

ALTER TABLE log_events
  ADD INDEX idx_log_events_quality_timestamp (quality_status, event_timestamp);
