# logs-devices — Runbook operativo

Runbook de operación para la aplicación `logs-devices`.

La aplicación recibe logs HTTP de bridges/dispositivos, guarda el payload crudo en NDJSON, registra el POST en `log_ingests`, parsea líneas en `log_events`, resuelve dispositivos en `devices` y ofrece un viewer web protegido.

---

## 1. Entorno de producción

Hosting confirmado:

- Proveedor: CDMon Senior
- Acceso: FTP + phpMyAdmin
- Sin SSH
- Sin Docker
- Sin Python/Node persistente
- Backend: PHP 8.3
- Base de datos: MariaDB
- Despliegue: GitHub Actions → FTP

Estructura esperada en servidor:

```text
/usr/home/singularthings.io/
├── private/
│   ├── .env
│   └── storage/
│       ├── raw/
│       ├── rejected/
│       ├── archive/
│       └── rate-limit/
└── web/
    └── logs-devices/
        ├── public/
        │   ├── index.php
        │   ├── devices.php
        │   ├── device.php
        │   ├── events.php
        │   ├── event.php
        │   ├── ingests.php
        │   ├── ingest.php
        │   └── api/
        │       ├── ingest.php
        │       ├── health.php
        │       └── admin/
        │           ├── reprocess.php
        │           └── archive_raw.php
        ├── src/
        ├── vendor/
        └── composer.json
2. Endpoints principales
Viewer

Protegido con HTTP Basic Auth.

GET /
GET /devices.php
GET /device.php?id=<device_id>
GET /events.php
GET /event.php?id=<event_id>
GET /ingests.php
GET /ingest.php?id=<ingest_id>
Ingesta

Contrato externo de bridges.

POST /
POST /api/ingest.php

Ambos deben seguir funcionando. POST / existe por compatibilidad con bridges que envían directamente a la raíz del dominio.

Healthcheck
GET /api/health.php
Administración

Desactivados por defecto salvo activación explícita en .env.

POST /api/admin/reprocess.php
POST /api/admin/archive_raw.php
3. Variables de entorno

Los secretos deben vivir fuera del repo, en:

/private/.env

No guardar secretos en Git.

Variables relevantes:

APP_ENV=production

DB_HOST=...
DB_PORT=...
DB_DATABASE=...
DB_USERNAME=...
DB_PASSWORD=...

INGEST_SECRET=...
ADMIN_TOKEN=...

VIEWER_BASIC_USER=...
VIEWER_BASIC_PASSWORD=...

REPROCESS_ENDPOINT_ENABLED=false
ARCHIVE_RAW_ENDPOINT_ENABLED=false

INGEST_RATE_LIMIT_ENABLED=true
INGEST_RATE_LIMIT_MAX=1000
INGEST_RATE_LIMIT_WINDOW_SECONDS=600

Los nombres exactos deben coincidir con los usados por el código actual del proyecto. No copiar valores reales a documentación, issues, prompts ni chats.

4. Checklist después de cada despliegue

Tras un despliegue a CDMon, comprobar:

4.1. Healthcheck
curl.exe -i "https://logs.singularthings.io/api/health.php"

Esperado:

HTTP/1.1 200
"ok": true
"database": "ok"

Revisar también:

checks.database.ok
checks.storage.raw.ok
checks.storage.rejected.ok
warnings

archive y rate_limit pueden aparecer como warning, pero conviene corregirlos.

4.2. Viewer protegido

Sin credenciales:

curl.exe -i "https://logs.singularthings.io/events.php"

Esperado:

401 Unauthorized

Con credenciales:

curl.exe -i -u "USUARIO:PASSWORD" "https://logs.singularthings.io/events.php?limit=5"

Esperado:

200 OK
4.3. Ingesta compatible

Con payload de prueba controlado:

curl.exe -i -X POST "https://logs.singularthings.io/api/ingest.php" `
  -H "Content-Type: application/json" `
  -H "User-Agent: WalkerPisa-Bridge-Logs" `
  -H "X-Log-Auth: <INGEST_SECRET>" `
  --data-binary "@test-production-payload.json"

Esperado en payload nuevo:

"ok": true
"duplicate": false
"message": "Payload received and processed."

Esperado si el payload ya existía:

"ok": true
"duplicate": true
"message": "Payload already received."
4.4. Ingesta legacy en raíz
curl.exe -i -X POST "https://logs.singularthings.io/" `
  -H "Content-Type: application/json" `
  -H "User-Agent: WalkerPisa-Bridge-Logs" `
  -H "X-Log-Auth: <INGEST_SECRET>" `
  --data-binary "@test-production-payload.json"

Debe responder igual que /api/ingest.php.

5. Interpretación del healthcheck
Estado sano
{
  "ok": true,
  "database": "ok"
}

Además:

checks.storage.raw.ok = true
checks.storage.rejected.ok = true
BD fallando

Síntomas:

HTTP 503
"database": "error"
checks.database.ok = false

Acciones:

Revisar credenciales en /private/.env.
Comprobar en phpMyAdmin si la base existe.
Comprobar que las tablas existen:
devices
log_ingests
log_events
No tocar bridges hasta confirmar que el endpoint no puede escribir.
Cuando vuelva la BD, probar /api/health.php.
Storage raw fallando

Síntomas:

HTTP 503
checks.storage.raw.ok = false

Acciones:

Revisar que existe:

/private/storage/raw
Revisar permisos de escritura.
Revisar que PHP puede crear archivos.
No activar archivado mientras raw falle.
Reprobar /api/health.php.
Storage rejected fallando

Síntomas:

HTTP 503
checks.storage.rejected.ok = false

Acciones:

Revisar que existe:

/private/storage/rejected
Revisar permisos de escritura.
Corregir antes de considerar producción sana.
Rate-limit storage fallando

Síntomas:

warnings contiene storage_rate_limit_not_ready

Acciones:

Revisar:

/private/storage/rate-limit
Revisar permisos.
La ingesta no debería pararse por esto porque el rate limiter trabaja en modo fail-open.
6. Reprocess de ingests

El reprocess permite procesar ingests ya guardados.

Endpoint:

POST /api/admin/reprocess.php

Debe estar desactivado por defecto:

REPROCESS_ENDPOINT_ENABLED=false
Activar temporalmente

En /private/.env:

REPROCESS_ENDPOINT_ENABLED=true

Después de usarlo, volver a:

REPROCESS_ENDPOINT_ENABLED=false
Ejecutar batch
curl.exe -i -X POST "https://logs.singularthings.io/api/admin/reprocess.php?limit=10" `
  -H "X-Admin-Token: <ADMIN_TOKEN>"
Reprocesar un ingest concreto
curl.exe -i -X POST "https://logs.singularthings.io/api/admin/reprocess.php?id=406&force=1" `
  -H "X-Admin-Token: <ADMIN_TOKEN>"
Seguridad

No dejar el endpoint activado permanentemente salvo decisión explícita.

7. Archivado de raw NDJSON

El archivado comprime ficheros antiguos de:

/private/storage/raw/

hacia:

/private/storage/archive/raw/

Ejemplo:

raw/2026/05/07/bridge_120.ndjson
archive/raw/2026/05/07/bridge_120.ndjson.gz

Endpoint:

POST /api/admin/archive_raw.php

Debe estar desactivado por defecto:

ARCHIVE_RAW_ENDPOINT_ENABLED=false
Activar temporalmente

En /private/.env:

ARCHIVE_RAW_ENDPOINT_ENABLED=true

Después de usarlo:

ARCHIVE_RAW_ENDPOINT_ENABLED=false
Dry-run remoto
curl.exe -i -X POST "https://logs.singularthings.io/api/admin/archive_raw.php?limit=20&min_age_days=7" `
  -H "X-Admin-Token: <ADMIN_TOKEN>"

No modifica ficheros.

Ejecución real
curl.exe -i -X POST "https://logs.singularthings.io/api/admin/archive_raw.php?execute=1&delete_source=1&limit=20&min_age_days=7" `
  -H "X-Admin-Token: <ADMIN_TOKEN>"

Recomendación:

Hacer primero dry-run.
Revisar resultados.
Ejecutar con límite pequeño.
Repetir si todo está correcto.
Desactivar endpoint al terminar.
8. Rate limiting de ingesta

El rate limiting protege:

POST /
POST /api/ingest.php

Configuración recomendada:

INGEST_RATE_LIMIT_ENABLED=true
INGEST_RATE_LIMIT_MAX=1000
INGEST_RATE_LIMIT_WINDOW_SECONDS=600

Respuesta si se supera el límite:

HTTP 429 Too Many Requests
error = rate_limited

En caso de emergencia, se puede desactivar temporalmente:

INGEST_RATE_LIMIT_ENABLED=false

Después de diagnosticar, volver a activarlo.

9. Investigación de errores de parseo
Ver eventos con error
/events.php?parse_ok=0
Ver detalle de un evento
/event.php?id=<event_id>

Revisar:

parse_error
message_text
measurements
context
ingest
bridge
Ver ingest que produjo el evento

Desde event.php, usar el enlace al ingest.

O directamente:

/ingest.php?id=<ingest_id>
Ver todos los eventos del ingest
/events.php?ingest_id=<ingest_id>
10. Investigación por dispositivo
Listado
/devices.php
Detalle
/device.php?id=<device_id>
Eventos de un dispositivo
/events.php?device_id=<device_id>
Eventos de un bridge
/events.php?bridge_id=<bridge_device_id>
11. Qué hacer si llegan logs pero no aparecen eventos
Comprobar /api/health.php.
Ver /ingests.php.
Buscar ingests recientes.
Abrir el detalle del ingest.
Comprobar:
status
line_count
parsed_ok_count
parsed_error_count
raw_path
Si status está en received, usar reprocess.
Si status está en error, revisar mensaje asociado o reprocesar con cuidado.
Si hay parse errors, revisar /events.php?parse_ok=0.
```

# logs-devices — Runbook operativo

Runbook de operación para la aplicación `logs-devices`.

La aplicación recibe logs HTTP de bridges/dispositivos, guarda el payload crudo en NDJSON, registra el POST en `log_ingests`, parsea líneas en `log_events`, resuelve dispositivos en `devices` y ofrece un viewer web protegido.

---

## 1. Entorno de producción

Hosting confirmado:

- Proveedor: CDMon Senior
- Acceso: FTP + phpMyAdmin
- Sin SSH
- Sin Docker
- Sin Python/Node persistente
- Backend: PHP 8.3
- Base de datos: MariaDB
- Despliegue: GitHub Actions → FTP

Estructura esperada en servidor:

```text
/usr/home/singularthings.io/
└── web/
    └── logs-devices/
        ├── public/
        │   ├── index.php
        │   ├── devices.php
        │   ├── device.php
        │   ├── events.php
        │   ├── event.php
        │   ├── ingests.php
        │   ├── ingest.php
        │   └── api/
        │       ├── ingest.php
        │       ├── health.php
        │       └── admin/
        │           ├── reprocess.php
        │           └── archive_raw.php
        ├── storage/
        │        ├── raw/
        │        ├── rejected/
        │        ├── archive/
        │        └── rate-limit/
        │
        ├── services/
        │        └── logs/
        │              ├──index.php
        │              └──storage/
        ├── src/
        ├── vendor/
        └── composer.json
        
2. Endpoints principales
Viewer

Protegido con HTTP Basic Auth.

GET /
GET /devices.php
GET /device.php?id=<device_id>
GET /events.php
GET /event.php?id=<event_id>
GET /ingests.php
GET /ingest.php?id=<ingest_id>
Ingesta

Contrato externo de bridges.

POST /
POST /api/ingest.php

Ambos deben seguir funcionando. POST / existe por compatibilidad con bridges que envían directamente a la raíz del dominio.

Healthcheck
GET /api/health.php
Administración

Desactivados por defecto salvo activación explícita en .env.

POST /api/admin/reprocess.php
POST /api/admin/archive_raw.php
3. Variables de entorno

Los secretos deben vivir fuera del repo, en:

/private/.env

No guardar secretos en Git.

Variables relevantes:

APP_ENV=production

DB_HOST=...
DB_PORT=...
DB_DATABASE=...
DB_USERNAME=...
DB_PASSWORD=...

INGEST_SECRET=...
ADMIN_TOKEN=...

VIEWER_BASIC_USER=...
VIEWER_BASIC_PASSWORD=...

REPROCESS_ENDPOINT_ENABLED=false
ARCHIVE_RAW_ENDPOINT_ENABLED=false

INGEST_RATE_LIMIT_ENABLED=true
INGEST_RATE_LIMIT_MAX=1000
INGEST_RATE_LIMIT_WINDOW_SECONDS=600

Los nombres exactos deben coincidir con los usados por el código actual del proyecto. No copiar valores reales a documentación, issues, prompts ni chats.

4. Checklist después de cada despliegue

Tras un despliegue a CDMon, comprobar:

4.1. Healthcheck
curl.exe -i "https://logs.singularthings.io/api/health.php"

Esperado:

HTTP/1.1 200
"ok": true
"database": "ok"

Revisar también:

checks.database.ok
checks.storage.raw.ok
checks.storage.rejected.ok
warnings

archive y rate_limit pueden aparecer como warning, pero conviene corregirlos.

4.2. Viewer protegido

Sin credenciales:

curl.exe -i "https://logs.singularthings.io/events.php"

Esperado:

401 Unauthorized

Con credenciales:

curl.exe -i -u "USUARIO:PASSWORD" "https://logs.singularthings.io/events.php?limit=5"

Esperado:

200 OK
4.3. Ingesta compatible

Con payload de prueba controlado:

curl.exe -i -X POST "https://logs.singularthings.io/api/ingest.php" `
  -H "Content-Type: application/json" `
  -H "User-Agent: WalkerPisa-Bridge-Logs" `
  -H "X-Log-Auth: <INGEST_SECRET>" `
  --data-binary "@test-production-payload.json"

Esperado en payload nuevo:

"ok": true
"duplicate": false
"message": "Payload received and processed."

Esperado si el payload ya existía:

"ok": true
"duplicate": true
"message": "Payload already received."
4.4. Ingesta legacy en raíz
curl.exe -i -X POST "https://logs.singularthings.io/" `
  -H "Content-Type: application/json" `
  -H "User-Agent: WalkerPisa-Bridge-Logs" `
  -H "X-Log-Auth: <INGEST_SECRET>" `
  --data-binary "@test-production-payload.json"

Debe responder igual que /api/ingest.php.

5. Interpretación del healthcheck
Estado sano
{
  "ok": true,
  "database": "ok"
}

Además:

checks.storage.raw.ok = true
checks.storage.rejected.ok = true
BD fallando

Síntomas:

HTTP 503
"database": "error"
checks.database.ok = false

Acciones:

Revisar credenciales en /private/.env.
Comprobar en phpMyAdmin si la base existe.
Comprobar que las tablas existen:
devices
log_ingests
log_events
No tocar bridges hasta confirmar que el endpoint no puede escribir.
Cuando vuelva la BD, probar /api/health.php.
Storage raw fallando

Síntomas:

HTTP 503
checks.storage.raw.ok = false

Acciones:

Revisar que existe:

/private/storage/raw
Revisar permisos de escritura.
Revisar que PHP puede crear archivos.
No activar archivado mientras raw falle.
Reprobar /api/health.php.
Storage rejected fallando

Síntomas:

HTTP 503
checks.storage.rejected.ok = false

Acciones:

Revisar que existe:

/private/storage/rejected
Revisar permisos de escritura.
Corregir antes de considerar producción sana.
Rate-limit storage fallando

Síntomas:

warnings contiene storage_rate_limit_not_ready

Acciones:

Revisar:

/private/storage/rate-limit
Revisar permisos.
La ingesta no debería pararse por esto porque el rate limiter trabaja en modo fail-open.
6. Reprocess de ingests

El reprocess permite procesar ingests ya guardados.

Endpoint:

POST /api/admin/reprocess.php

Debe estar desactivado por defecto:

REPROCESS_ENDPOINT_ENABLED=false
Activar temporalmente

En /private/.env:

REPROCESS_ENDPOINT_ENABLED=true

Después de usarlo, volver a:

REPROCESS_ENDPOINT_ENABLED=false
Ejecutar batch
curl.exe -i -X POST "https://logs.singularthings.io/api/admin/reprocess.php?limit=10" `
  -H "X-Admin-Token: <ADMIN_TOKEN>"
Reprocesar un ingest concreto
curl.exe -i -X POST "https://logs.singularthings.io/api/admin/reprocess.php?id=406&force=1" `
  -H "X-Admin-Token: <ADMIN_TOKEN>"
Seguridad

No dejar el endpoint activado permanentemente salvo decisión explícita.

7. Archivado de raw NDJSON

El archivado comprime ficheros antiguos de:

/private/storage/raw/

hacia:

/private/storage/archive/raw/

Ejemplo:

raw/2026/05/07/bridge_120.ndjson
archive/raw/2026/05/07/bridge_120.ndjson.gz

Endpoint:

POST /api/admin/archive_raw.php

Debe estar desactivado por defecto:

ARCHIVE_RAW_ENDPOINT_ENABLED=false
Activar temporalmente

En /private/.env:

ARCHIVE_RAW_ENDPOINT_ENABLED=true

Después de usarlo:

ARCHIVE_RAW_ENDPOINT_ENABLED=false
Dry-run remoto
curl.exe -i -X POST "https://logs.singularthings.io/api/admin/archive_raw.php?limit=20&min_age_days=7" `
  -H "X-Admin-Token: <ADMIN_TOKEN>"

No modifica ficheros.

Ejecución real
curl.exe -i -X POST "https://logs.singularthings.io/api/admin/archive_raw.php?execute=1&delete_source=1&limit=20&min_age_days=7" `
  -H "X-Admin-Token: <ADMIN_TOKEN>"

Recomendación:

Hacer primero dry-run.
Revisar resultados.
Ejecutar con límite pequeño.
Repetir si todo está correcto.
Desactivar endpoint al terminar.
8. Rate limiting de ingesta

El rate limiting protege:

POST /
POST /api/ingest.php

## Configuración de runtime

La configuración vive en `src/Config/runtime.php` (NO versionado, NO desplegado por FTP).

Para crear/actualizar:
1. Conéctate por FTP a la raíz del subdominio.
2. Sube manualmente `src/Config/runtime.php` con los valores reales.
3. El archivo lo ignora `.gitignore` y lo excluye el workflow de deploy.

Variables obligatorias (validadas en Bootstrap::init):
- APP_ENV, LOG_INGEST_SECRET, LOG_INGEST_USER_AGENT
- DB_HOST, DB_DATABASE, DB_USERNAME, DB_PASSWORD

Variables opcionales:
- VIEWER_AUTH_ENABLED (default true), VIEWER_AUTH_USERNAME, VIEWER_AUTH_PASSWORD
- REPROCESS_ENABLED (default false), ADMIN_REPROCESS_TOKEN
- ARCHIVE_RAW_ENDPOINT_ENABLED (default false)
- SERVICES_LOGS_IMPORT_ENABLED (default false), SERVICES_LOGS_STORAGE_PATH
- INGEST_RATE_LIMIT_ENABLED (default true), INGEST_RATE_LIMIT_MAX (1000), INGEST_RATE_LIMIT_WINDOW_SECONDS (600)
- STORAGE_BASE_PATH (default: <project_root>/storage)

NB: NUNCA editar directamente desde phpMyAdmin ni un editor externo sin
backup — runtime.php es la única barrera entre el dashboard y el dominio.

9. Investigación de errores de parseo
Ver eventos con error
/events.php?parse_ok=0
Ver detalle de un evento
/event.php?id=<event_id>

Revisar:

parse_error
message_text
measurements
context
ingest
bridge
Ver ingest que produjo el evento

Desde event.php, usar el enlace al ingest.

O directamente:

/ingest.php?id=<ingest_id>
Ver todos los eventos del ingest
/events.php?ingest_id=<ingest_id>
10. Investigación por dispositivo
Listado
/devices.php
Detalle
/device.php?id=<device_id>
Eventos de un dispositivo
/events.php?device_id=<device_id>
Eventos de un bridge
/events.php?bridge_id=<bridge_device_id>
11. Qué hacer si llegan logs pero no aparecen eventos
Comprobar /api/health.php.
Ver /ingests.php.
Buscar ingests recientes.
Abrir el detalle del ingest.
Comprobar:
status
line_count
parsed_ok_count
parsed_error_count
raw_path
Si status está en received, usar reprocess.
Si status está en error, revisar mensaje asociado o reprocesar con cuidado.
Si hay parse errors, revisar /events.php?parse_ok=0.
```
