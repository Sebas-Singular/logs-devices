# logs-devices

Sistema de ingesta, almacenamiento y visualización de logs de dispositivos IoT de Singular Things.

Recibe logs vía HTTP POST, los valida, parsea y almacena en
MariaDB y en ficheros NDJSON. Incluye un visor web con autenticación básica.

---

## Stack

| Capa          | Tecnología                                           |
| ------------- | ---------------------------------------------------- |
| Lenguaje      | PHP 8.3 vanilla (sin framework)                      |
| Base de datos | MariaDB — acceso vía PDO                             |
| Autoload      | Composer PSR-4                                       |
| Tests         | PHPUnit 11                                           |
| Frontend      | HTML + Tailwind CDN + Alpine.js CDN                  |
| Auth visor    | HTTP Basic Auth (PHP)                                |
| Auth ingesta  | Header `X-Log-Auth` + `User-Agent`                   |
| CI/CD         | GitHub Actions + FTP Deploy                          |
| Dev local     | Docker Compose (PHP 8.3-fpm + MariaDB 10.11)         |
| Producción    | CDMon Senior (shared hosting, solo FTP + phpMyAdmin) |

---

## Arquitectura general

Bridge (dispositivo físico)
│
▼
POST /services/logs/index.php ← endpoint legacy (bridges actuales)
│ valida + guarda JSON semanal
│
▼
POST /api/ingest.php ← endpoint canónico
│ valida · deduplica · guarda NDJSON · parsea · inserta en BD
│
▼
MariaDB (devices + log_ingests + log_events)
│
▼
GET /events.php, /ingests.php... ← visor web (HTTP Basic Auth)

---

### Dos rutas de entrada

**Endpoint canónico** — `POST https://logs.singularthings.io/api/ingest.php`

Ruta física: `public/api/ingest.php`

Responsabilidades:

- Valida método, User-Agent y secreto (`X-Log-Auth`)
- Deduplica por `content_hash` (SHA-256 del body)
- Guarda raw payload como línea NDJSON en `storage/raw/YYYY/MM/DD/bridge_N.ndjson`
- Inserta fila en `log_ingests`
- Parsea eventos y los inserta en `log_events`
- Responde con JSON incluyendo `processing_mode`

**Endpoint legacy** — `POST https://logs.singularthings.io/services/logs/index.php`

Ruta física: `services/logs/index.php`

Responsabilidades:

- Valida User-Agent y secreto
- Guarda el payload completo en `services/logs/storage/bridge_logs_YYYY_WXX.json`
- Hace forward por curl al endpoint canónico
- **No parsea. No toca la BD.**

---

## Formatos de log soportados

### Legacy puro

```json
{
  "message": "bridge_logs",
  "bridgeId": 120,
  "bridgeName": "WalkerPisa Bridge",
  "sentAt": "2026-05-18 07:39:27",
  "logText": "[2026-05-18 ...] [TELEMETRY] ..."
}
```

### Nuevo formato estructurado (actual)

```json
{
  "message": "bridge_logs",
  "bridgeId": 120,
  "schemaVersion": 1,
  "source": { "project": "walkerpisa", "product": "bridge", "deviceId": 120, ... },
  "upload": { "sentAt": "...", "bytes": 6335, ... },
  "raw": { "format": "plain-text", "logText": "..." },
  "eventCount": 20,
  "events": [ ... ],
  "vehicleEventCount": 0
}
```

### Prioridad de procesamiento

1. events[] → structured_events (fuente principal)
2. vehicleEvents[] → vehicle_events (fallback si no hay events[])
3. logText / raw.logText → legacy_log_text (fallback legacy)

Nunca se procesan dos fuentes a la vez para evitar duplicados.

---

## Base de datos

Tres tablas principales:

| Tabla         | Propósito                                                              |
| ------------- | ---------------------------------------------------------------------- |
| `devices`     | Registro de bridges y balizas (kind: `bridge`, `baliza`, `standalone`) |
| `log_ingests` | Una fila por payload recibido. Tracking de estado de procesamiento.    |
| `log_events`  | Un evento por fila. Incluye `measurements` y `context` como JSON.      |

Fichero de esquema: `docker/mariadb/init/001_schema.sql`

---

## Estructura de directorios

.github/workflows/ CI/CD (PHPUnit + FTP deploy)
.docker/ Config Docker Apache
docker/mariadb/init/ Schema SQL de MariaDB
docs/ Documentación técnica
public/ DocumentRoot (ficheros servidos por Apache)
api/
ingest.php Endpoint canónico de ingesta
health.php Health check
admin/ Endpoints de administración (token protegido)
\*.php Páginas del visor web
services/logs/
index.php Endpoint legacy (bridges actuales)
storage/ JSONs semanales de backup
src/
Config/ runtime.php (secretos, no versionado)
Database/ Conexión PDO
Device/ DeviceResolver (upsert bridge/baliza)
Http/ RateLimiter, JsonResponse, SecurityHeaders
Ingest/ Lógica de ingesta y normalización
Parsers/ Parser de líneas de logText legacy
Storage/ NdjsonWriter, Paths
Support/ Bootstrap, Env
Viewer/ ViewerQueries, ViewerAuth, ViewerFormatter
storage/
raw/ NDJSON por device y día
rejected/ Payloads rechazados
rate-limit/ Ficheros de rate limiting por IP
tests/ PHPUnit (no se sube a producción)

---

## Configuración

Copiar `src/Config/runtime.php.dist` a `src/Config/runtime.php` y rellenar:

| Variable                                                  | Descripción                                           |
| --------------------------------------------------------- | ----------------------------------------------------- |
| `APP_ENV`                                                 | `production` o `development`                          |
| `APP_URL`                                                 | URL base del sistema                                  |
| `LOG_INGEST_SECRET`                                       | Secreto compartido con los bridges                    |
| `LOG_INGEST_USER_AGENT`                                   | User-Agent esperado de los bridges                    |
| `DB_HOST` / `DB_DATABASE` / `DB_USERNAME` / `DB_PASSWORD` | Conexión MariaDB                                      |
| `VIEWER_AUTH_ENABLED`                                     | `true` para activar Basic Auth en el visor            |
| `VIEWER_AUTH_USERNAME` / `VIEWER_AUTH_PASSWORD`           | Credenciales del visor                                |
| `REPROCESS_ENABLED`                                       | `false` por defecto. Solo activar para mantenimiento. |
| `ADMIN_REPROCESS_TOKEN`                                   | Token para endpoints de administración                |
| `INGEST_RATE_LIMIT_ENABLED`                               | `true` por defecto                                    |
| `INGEST_RATE_LIMIT_MAX`                                   | Peticiones máximas por ventana (default 1000)         |
| `INGEST_RATE_LIMIT_WINDOW_SECONDS`                        | Ventana temporal (default 600s)                       |

`runtime.php` **no se versiona y no se sube por el workflow de deploy**.
Cada entorno mantiene su propio fichero.

En producción, `src/Config/runtime.php` debe existir antes de servir la app.
Si falta, `Bootstrap::init()` no puede cargar la configuración y la aplicación
entra en error. El deploy por GitHub Actions no lo crea ni lo actualiza.

---

## Desarrollo local

```bash
# Arrancar entorno
docker compose up -d

# Instalar dependencias
docker compose exec app composer install

# Ejecutar tests
docker compose exec app vendor/bin/phpunit

# Crear runtime local
cp src/Config/runtime.php.dist src/Config/runtime.php
# editar runtime.php con DB_HOST=db (nombre del servicio Docker)
```

---

## Deploy a producción

El deploy es manual (workflow dispatch): GitHub Actions → PHPUnit (con MariaDB CI) → FTP Deploy a CDMon

El workflow **nunca sube**:

- `src/Config/runtime.php`
- `storage/`
- `services/logs/storage/`
- `private/`
- `tests/`, `docs/`, `scripts/`
- `docker/`, `docker-compose.yml`
- `test-*.json`

Antes de hacer dispatch, confirmar que los tests pasan localmente:

```bash
vendor/bin/phpunit
```

---

## Seguridad

- `src/`, `vendor/`, `storage/`, `services/logs/storage/` bloqueados por `.htaccess` (403)
- Dotfiles bloqueados (`.git`, `.env`, `.htaccess`)
- `composer.json`, `composer.lock`, `phpunit.xml.dist` bloqueados
- Visor web protegido por HTTP Basic Auth
- Ingesta protegida por secreto en header + User-Agent fijo
- Deduplicación por SHA-256 del body

---

## Tests

```bash
vendor/bin/phpunit
# Esperado: 69 tests, 317 assertions, OK
```

Cobertura principal:

- `PayloadNormalizer` — todos los modos de procesamiento
- `StructuredEventNormalizer` — UNSYNCED, GPS error, tipos desconocidos
- `VehicleEventNormalizer` — fallback y prioridad sobre events[]
- `IngestValidator` — validación flexible legacy y nuevo formato
- `LogEventWriter`, `LogParser`, parsers, rate limiter
