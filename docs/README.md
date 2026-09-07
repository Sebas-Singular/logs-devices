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
Migraciones posteriores: `docs/migrations/`

> **Columna sin uso:** `devices.last_event_at` existe en el esquema pero
> `DeviceResolver` nunca la escribe: siempre vale NULL. Las vistas que necesitan
> ese dato lo calculan con `MAX(le.event_timestamp)`. O se mantiene en la ingesta
> o se elimina; hoy solo confunde.

---

## Panel de control

El dashboard (`public/index.php`) se construye a partir de los datos, no de
valores fijos en la plantilla. Todo se acota a una **ventana temporal**
seleccionable (24 h, 7 días, 30 días, histórico completo; por defecto 7 días)
que se propaga a todas las secciones vía `?window=`.

### Qué muestra

| Bloque                    | De dónde sale                                                                 |
| ------------------------- | ----------------------------------------------------------------------------- |
| Estado del sistema        | Antigüedad del último ingest, ingests zombie, ingests en error, tasa de error  |
| Rendimiento medido        | Última velocidad calculada, resuelta por su clave vigente                      |
| Actividad                 | Eventos e ingests de la ventana, eventos/hora, errores de parseo y de calidad  |
| Dispositivos emitiendo    | Dispositivos con eventos en la ventana frente al total registrado              |
| Métricas en vivo          | Última lectura de cada métrica del catálogo que tenga dato                     |
| Categorías activas        | Familias de evento con datos en la ventana, con reparto y último evento        |
| Tipos de evento activos   | Una fila por tipo, con sus errores de parseo dentro                            |
| Tipos sin datos           | Tipos que existen en el histórico pero ya no reportan, en bloque plegado       |
| Dispositivos más activos  | Volumen de eventos por dispositivo en la ventana                               |
| Últimos eventos           | Los 20 más recientes por orden de llegada, con resumen de métricas             |

Una categoría, un tipo o una métrica **no aparece como opción viva si no ha
producido datos en la ventana**. Los tipos dormidos se listan aparte y
atenuados, y en `events.php` los desplegables de categoría y tipo se agrupan en
`Con datos recientes` / `Sin datos desde hace más de 7 días`.

### Identidad visual

La paleta sale del logo (`public/Logo-singular.svg`) y vive en un único sitio,
`public/_viewer_head.php`, que todas las vistas incluyen. Antes cada página
repetía su propio `<head>` con el CDN de Tailwind, así que un cambio de estilo
había que replicarlo en siete ficheros.

| Escala  | Base      | Uso                                                        |
| ------- | --------- | ---------------------------------------------------------- |
| `brand` | `#E40D7E` | Magenta corporativo: enlaces, pestaña activa, foco, acentos |
| `ink`   | `#1E1E1C` | Negro corporativo y sus neutros: texto, bordes, fondos      |

Sustituyen a `sky` y `slate` de Tailwind, que no eran de marca (`slate` tira a
azul). **El magenta se reserva a lo interactivo y de marca**: los estados del
sistema usan su propia escala semántica (verde / ámbar / rojo) para que "esto es
un enlace" y "algo va mal" nunca compartan color.

Contraste del magenta sobre blanco: `brand-500` 4,51:1 · `brand-600` 5,81:1 ·
`brand-700` 7,87:1 (AA para texto normal).

La tarjeta de estado del sistema señala con un rail lateral de color en vez de
teñir el fondo entero: informa sin dominar la pantalla.

### Idioma

Toda la interfaz está en español. Se mantienen sin traducir:

- **`Bridge` y `Baliza`**, que son los términos del dominio del producto.
- **Los valores de datos** que llegan del firmware (`telemetry_snapshot`,
  `speed_calculated`, `walkerpisa_bridge`...): son identificadores estables del
  contrato de `events[]`, no texto de interfaz. Traducirlos rompería los filtros.

Las etiquetas de estado sí se traducen en el visor conservando el valor de la
base de datos: `ViewerFormatter::ingestStatusLabel()`, `severityLabel()`,
`severityOriginLabel()`, `qualityLabel()` y `anomalyLabel()`.

### Catálogo de métricas

`src/Viewer/MetricCatalog.php` es la fuente única de verdad de las magnitudes
del sistema. Existe porque el histórico tiene **dos generaciones de claves para
las mismas medidas**:

| Magnitud    | Clave vigente (`events[]`) | Clave anterior (parsers sobre `logText`) |
| ----------- | -------------------------- | ---------------------------------------- |
| Velocidad   | `speedKmh`                 | `speed_kmh`                              |
| Temperatura | `temperatureC`             | `temperature_c`                          |
| Humedad     | `humidityPct`              | `humidity_pct`                           |
| Batería     | `socPct`                   | `soc_pct`                                |
| RSSI        | `parentRssi`               | `rssi_dbm`                               |
| LiDAR       | `lidarDistanceMm`          | `lidar_mm`                               |
| Calidad aire| `airQuality`               | `aq_index`                               |

Cada métrica declara sus alias **en orden de vigencia**. `MetricCatalog::resolve()`
devuelve el valor del primer alias presente, así que un evento que traiga las dos
claves se lee por la actual y uno antiguo sigue siendo legible. El visor marca
como `legacy` los valores que vinieron de una clave de formato anterior.

**Añadir una métrica nueva al panel = añadir una entrada al catálogo.** No hay
que tocar el dashboard: aparece sola en cuanto llega el primer evento con ese
campo. Las claves que el firmware envía y el catálogo aún no conoce se muestran
igualmente en el resumen del evento (`MetricCatalog::unknownScalars()`).

### Calidad del dato

`quality_status` y `anomaly_flags` se calculan en la ingesta y ahora se muestran:
badge en la lista de eventos y desglose traducido en el detalle del evento
(`time_fallback`, `gps_no_signal`, `improbable_speed`, `low_soc`, `weak_rssi`...).

### Índices necesarios

El panel filtra por `event_timestamp` sin fijar antes `device_id` / `severity` /
`event_type`, así que necesita ese campo como primera columna de índice. Aplicar
en producción antes de usar el panel con volumen:

```
docs/migrations/2026-09-07_dashboard_indexes.sql
```

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
Viewer/ ViewerQueries, DashboardQueries, MetricCatalog, ViewerAuth, ViewerFormatter
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

### Alternativa sin Docker (macOS)

```bash
brew install php composer mariadb
brew services start mariadb

# Usuario y base de datos
mariadb -e "CREATE USER IF NOT EXISTS 'logs_user'@'127.0.0.1' IDENTIFIED BY 'logs_pass_dev';
            CREATE DATABASE IF NOT EXISTS \`logs-devices\`;
            GRANT ALL ON \`logs-devices\`.* TO 'logs_user'@'127.0.0.1';"

composer install
mariadb -h 127.0.0.1 -u logs_user -plogs_pass_dev "logs-devices" < docker/mariadb/init/001_schema.sql

# runtime.php local con DB_HOST=127.0.0.1
cp src/Config/runtime.php.dist src/Config/runtime.php

# Datos de ejemplo (opcional, cubre ambas generaciones de formato)
mariadb -h 127.0.0.1 -u logs_user -plogs_pass_dev "logs-devices" < scripts/seed_dev_data.sql

# Servir el visor
php -S 127.0.0.1:8080 -t public
```

> Los tests de integración asumen una base de datos limpia. Si has cargado
> `seed_dev_data.sql`, vacía `log_events`, `log_ingests` y `devices` antes de
> lanzar PHPUnit: el ingest zombie del fixture hace fallar
> `StoredIngestProcessorTest`.

---

## Deploy a producción

El deploy es manual (workflow dispatch): GitHub Actions → PHPUnit (con MariaDB CI) → FTP Deploy a CDMon

> Antes del primer deploy con el panel nuevo, aplicar
> `docs/migrations/2026-09-07_dashboard_indexes.sql` en phpMyAdmin. Sin esos
> índices el dashboard funciona, pero cada carga recorre `log_events` entera.

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
# Esperado: toda la suite en verde
```

Cobertura principal:

- `PayloadNormalizer` — todos los modos de procesamiento
- `StructuredEventNormalizer` — UNSYNCED, GPS error, tipos desconocidos
- `VehicleEventNormalizer` — fallback y prioridad sobre events[]
- `IngestValidator` — validación flexible legacy y nuevo formato
- `LogEventWriter`, `LogParser`, parsers, rate limiter
- `MetricCatalog` — resolución de alias entre generaciones de formato
- `ViewerFormatter` — resumen de métricas en eventos estructurados y legacy
