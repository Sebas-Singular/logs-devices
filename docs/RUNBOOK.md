# RUNBOOK — logs-devices

Guía operativa para monitorización, diagnóstico y mantenimiento del sistema
en producción (CDMon Senior hosting).

Accesos disponibles:

- **FTP**: usuario `deploylogs`, puerto 21 (sin SSH)
- **phpMyAdmin**: acceso web a MariaDB
- **GitHub Actions**: deploy manual por workflow dispatch

---

## Health check

```bash
curl -i "https://logs.singularthings.io/api/health.php"
```

Respuesta esperada:

```json
HTTP 200
{ "ok": true, ... }
```

---

## Post-deploy checklist

Ejecutar después de cada deploy:

**1. Health check**

```bash
curl -i "https://logs.singularthings.io/api/health.php"
```

**2. Verificar que los directorios privados devuelven 403**

https://logs.singularthings.io/src/
https://logs.singularthings.io/vendor/
https://logs.singularthings.io/storage/
https://logs.singularthings.io/services/logs/storage/
https://logs.singularthings.io/tests/

**3. Test ingesta legacy directa**

```bash
curl -i "https://logs.singularthings.io/api/ingest.php" \
  -H "Content-Type: application/json" \
  -H "User-Agent: WalkerPisa-Bridge-Logs" \
  -H "X-Log-Auth: $SECRET" \
  --data-binary "@test-legacy-api.json"
# Esperado: ok=true, processing_mode=legacy_log_text
```

**4. Test ingesta estructurada directa**

```bash
curl -i "https://logs.singularthings.io/api/ingest.php" \
  -H "Content-Type: application/json" \
  -H "User-Agent: WalkerPisa-Bridge-Logs" \
  -H "X-Log-Auth: $SECRET" \
  --data-binary "@test-structured-api.json"
# Esperado: ok=true, processing_mode=structured_events
```

**5. Test endpoint legacy (bridge path)**

```bash
curl -i "https://logs.singularthings.io/services/logs/index.php" \
  -H "Content-Type: application/json" \
  -H "User-Agent: WalkerPisa-Bridge-Logs" \
  -H "X-Log-Auth: $SECRET" \
  --data-binary "@test-structured-api.json"
# Esperado: ok=true, stored=true, forward_attempted=true
```

---

## SQL de monitorización

Ejecutar en phpMyAdmin.

### Estado general

```sql
-- Últimos 10 ingests
SELECT
  id,
  received_at,
  bridge_id_reported,
  status,
  line_count,
  parsed_ok_count,
  parsed_error_count,
  JSON_EXTRACT(payload_summary, '$.processedMode') AS processing_mode,
  processing_started_at,
  processing_finished_at
FROM log_ingests
ORDER BY id DESC
LIMIT 10;

-- Distribución de estados
SELECT status, COUNT(*) AS total
FROM log_ingests
GROUP BY status;

-- Distribución de modos de procesamiento
SELECT
  JSON_EXTRACT(payload_summary, '$.processedMode') AS mode,
  COUNT(*) AS total
FROM log_ingests
GROUP BY mode;
```

### Monitorización de errores

```sql
-- Ingests en error
SELECT id, received_at, bridge_id_reported, status, processing_finished_at
FROM log_ingests
WHERE status = 'error'
ORDER BY id DESC
LIMIT 20;

-- ALERTA: ingests atascados en 'parsing' (posible zombie)
-- Si aparecen filas con processing_started_at hace más de 5 min, son zombies.
SELECT id, received_at, bridge_id_reported, processing_started_at
FROM log_ingests
WHERE status = 'parsing'
ORDER BY id DESC;

-- Últimos 20 eventos
SELECT
  id, ingest_id, event_category, event_type,
  device_mac_raw, parse_ok, parse_error,
  quality_status, event_timestamp
FROM log_events
ORDER BY id DESC
LIMIT 20;

-- Eventos con parse_error
SELECT id, ingest_id, parse_error, event_timestamp
FROM log_events
WHERE parse_ok = 0
ORDER BY id DESC
LIMIT 20;
```

### Catálogo de categorías y tipos (visor dinámico)

```sql
SELECT event_category, COUNT(*) AS total
FROM log_events
GROUP BY event_category
ORDER BY total DESC;

SELECT event_type, COUNT(*) AS total
FROM log_events
GROUP BY event_type
ORDER BY total DESC;
```

### Dispositivos

```sql
SELECT
  id, device_kind, external_id, mac_address, name,
  first_seen_at, last_seen_at
FROM devices
ORDER BY last_seen_at DESC;
```

---

## Procedimientos de incidencia

### P1 — Bridge deja de enviar logs

**Diagnóstico:**

1. Verificar que el bridge está encendido y con red
2. Comprobar en phpMyAdmin si hay ingests recientes de ese `bridge_id_reported`
3. Verificar que el secreto `LOG_INGEST_SECRET` coincide con el configurado en el bridge
4. Comprobar el fichero semanal en `services/logs/storage/bridge_logs_YYYY_WXX.json` (accesible por FTP)
5. Revisar `storage/rejected/YYYY/MM/DD/rejected_YYYY-MM-DD.ndjson` para ver si hay rechazos

```sql
-- Último ingest por bridge
SELECT bridge_id_reported, MAX(received_at) AS ultimo_ingest
FROM log_ingests
GROUP BY bridge_id_reported
ORDER BY ultimo_ingest DESC;
```

### P2 — Ingests en `status = 'error'`

**Diagnóstico:**

```sql
SELECT id, bridge_id_reported, received_at, raw_path
FROM log_ingests
WHERE status = 'error'
ORDER BY id DESC LIMIT 10;
```

**Resolución** — reprocesar via endpoint admin:

```bash
curl -i -X POST "https://logs.singularthings.io/api/admin/reprocess.php" \
  -H "X-Admin-Token: $ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"ingest_id": 123}'
```

O reprocesar en batch (activar `REPROCESS_ENABLED=true` en runtime.php primero):

```bash
curl -i -X POST "https://logs.singularthings.io/api/admin/reprocess.php" \
  -H "X-Admin-Token: $ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"batch": true, "retry_errors": true, "limit": 50}'
```

### P3 — Ingests atascados en `status = 'parsing'`

Ocurre cuando PHP-FPM interrumpe el proceso durante el parsing (timeout u OOM).
El reprocesador normal no los recoge porque solo procesa `received` y `error`.

**Resolución** — forzar el reprocesado:

```bash
curl -i -X POST "https://logs.singularthings.io/api/admin/reprocess.php" \
  -H "X-Admin-Token: $ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"ingest_id": 123, "force": true}'
```

`force: true` ignora el status actual y borra los eventos previos del ingest antes de reinsertar.

**Resolución manual en SQL** (si el endpoint admin no está disponible):

```sql
-- Resetear a 'received' para que el reprocesador los recoja
UPDATE log_ingests
SET status = 'received',
    processing_started_at = NULL,
    processing_finished_at = NULL
WHERE status = 'parsing'
  AND processing_started_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE);
```

### P4 — Forward desde legacy endpoint falla

Síntoma: bridge responde con `ok: true` pero no aparecen registros nuevos en `log_ingests`.

**Diagnóstico:**

1. Revisar `services/logs/storage/bridge_logs_YYYY_WXX.json` — si está ahí, el legacy guardó correctamente
2. El forward falló: puede ser curl no disponible o error de red interno
3. Los datos están en el JSON semanal y pueden importarse manualmente

**Resolución** — importar desde JSON semanal:

```bash
curl -i -X POST "https://logs.singularthings.io/api/admin/import_services_logs.php" \
  -H "X-Admin-Token: $ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"week": "2026_W21"}'
```

### P5 — Duplicados en `log_events`

No deberían existir duplicados con event_hash no-nulo dado el UNIQUE KEY.
Verificar:

```sql
SELECT event_hash, COUNT(*) AS c
FROM log_events
WHERE event_hash IS NOT NULL
GROUP BY event_hash
HAVING c > 1;
-- No debe devolver filas
```

Si hay eventos sin event_hash (parse_ok=0 o legacy), múltiples filas con event_hash NULL
son normales y permitidas en MariaDB.

---

## Deploy

**Requisitos previos:**

- Tests pasan localmente: `vendor/bin/phpunit`
- No hay cambios sin commitear

**Ejecutar:**

1. Push a rama `david` (o la que corresponda)
2. En GitHub → Actions → `Deploy to cdmon` → `Run workflow`
3. El workflow ejecuta PHPUnit con MariaDB CI antes de subir
4. Si PHPUnit falla, el FTP deploy no se ejecuta

**Lo que NO sube el workflow:**

- `src/Config/runtime.php` (secretos de producción)
- `storage/` (datos NDJSON)
- `services/logs/storage/` (JSONs semanales)
- `private/`, `tests/`, `docs/`, `scripts/`
- `docker/`, `docker-compose.yml`
- Ficheros `test-*.json`

---

## Rate limiting

Configurado por IP + User-Agent + hash del secreto.
Por defecto: 1000 peticiones por ventana de 600 segundos.

Los ficheros de estado se guardan en `storage/rate-limit/` como JSON por hash de clave.
El janitor limpia ficheros expirados automáticamente en cada hit.

Para desactivar temporalmente (mantenimiento o migración):

```php
// En runtime.php
'INGEST_RATE_LIMIT_ENABLED' => 'false',
```

---

## Ficheros de configuración clave

| Fichero                              | Descripción                  | ¿Se versiona? | ¿Se sube al deploy? |
| ------------------------------------ | ---------------------------- | ------------- | ------------------- |
| `src/Config/runtime.php`             | Secretos y config de entorno | ❌ No         | ❌ No               |
| `src/Config/runtime.php.dist`        | Plantilla del runtime        | ✅ Sí         | ✅ Sí               |
| `.htaccess`                          | Reglas de acceso y rewrite   | ✅ Sí         | ✅ Sí               |
| `docker/mariadb/init/001_schema.sql` | Esquema de BD                | ✅ Sí         | ✅ Sí               |
| `.github/workflows/deploy-cdmon.yml` | Pipeline de deploy           | ✅ Sí         | —                   |
