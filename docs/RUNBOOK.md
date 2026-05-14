# logs-devices — Runbook operativo

Aplicación interna de Singular Things para recibir, almacenar, parsear y visualizar logs de bridges/balizas IoT del proyecto WalkerPisa.

## 1. Producción

URL principal:

````text
https://logs.singularthings.io/

Restricciones reales del hosting:

Proveedor: CDmon Senior
Acceso: FTP + phpMyAdmin
Sin SSH operativo confirmado
Sin Docker en producción
Sin cron
Sin workers persistentes
Sin acceso server-level
PHP 8.3
MariaDB

El proyecto vive dentro de la raíz del subdominio. No se asume acceso a una carpeta private externa.

2. Configuración

La configuración real de producción se lee desde:

src/Config/runtime.php

Ese fichero:

No se versiona
No se despliega por GitHub Actions
Debe subirse/editarse manualmente por FTP cuando haga falta

Template versionado:

src/Config/runtime.php.dist

Variables principales:

APP_ENV=production
APP_URL=https://logs.singularthings.io

DB_HOST=...
DB_PORT=...
DB_DATABASE=...
DB_USERNAME=...
DB_PASSWORD=...

LOG_INGEST_SECRET=...
LOG_INGEST_USER_AGENT=WalkerPisa-Bridge-Logs

VIEWER_AUTH_ENABLED=true
VIEWER_AUTH_USERNAME=...
VIEWER_AUTH_PASSWORD=...

ADMIN_REPROCESS_TOKEN=...

REPROCESS_ENABLED=false
ARCHIVE_RAW_ENDPOINT_ENABLED=false
SERVICES_LOGS_IMPORT_ENABLED=false

INGEST_RATE_LIMIT_ENABLED=true
INGEST_RATE_LIMIT_MAX=1000
INGEST_RATE_LIMIT_WINDOW_SECONDS=600

LEGACY_FORWARD_ENABLED=true

No usar nombres antiguos como:

INGEST_SECRET
ADMIN_TOKEN
VIEWER_BASIC_USER
VIEWER_BASIC_PASSWORD
3. Endpoints

Viewer protegido por HTTP Basic Auth:

GET /
GET /index.php
GET /devices.php
GET /device.php?id=<id>
GET /events.php
GET /event.php?id=<id>
GET /ingests.php
GET /ingest.php?id=<id>

API pública reducida:

GET /api/health.php

API full protegida por token admin:

GET /api/health.php?mode=full
Header: X-Admin-Token: <ADMIN_REPROCESS_TOKEN>

Ingesta nueva:

POST /api/ingest.php

Ingesta legacy usada por bridges desplegados:

POST /services/logs/index.php

Contrato legacy externo que no debe romperse sin coordinar firmware:

User-Agent: WalkerPisa-Bridge-Logs
X-Log-Auth: <LOG_INGEST_SECRET>
Content-Type: application/json
4. Seguridad HTTP

La raíz tiene .htaccess con:

Options -Indexes
Bloqueo de src/
Bloqueo de vendor/
Bloqueo de storage/
Bloqueo de services/logs/storage/
Bloqueo de scripts/
Bloqueo de docs/
Bloqueo de tests/
Bloqueo de private/
Bloqueo de dotfiles
Whitelist defensiva de rutas públicas
Bloqueo de acceso directo a /public

Rutas que deben estar bloqueadas:

/src/Config/runtime.php
/vendor/autoload.php
/storage/raw/
/services/logs/storage/
/composer.json
/.env
/.git/config
/private/.env
/scripts/reprocess_ingests.php
/docs/RUNBOOK.md
/tests/bootstrap.php
/home.html
/random.php
/api/random.php
/public/index.php
/public/api/health.php
5. Deploy

Deploy actual:

GitHub Actions → FTP

El intento de FTPS explícito falló contra el host actual:

AUTH TLS
500 AUTH not understood

Puerto observado:

21 abierto
990 cerrado
22 abierto pendiente de confirmar

Hasta que CDmon confirme SFTP/FTPS usable, el deploy sigue por FTP plano. Si se consigue SFTP/FTPS, cambiar el workflow y rotar credenciales.

El deploy excluye:

src/Config/runtime.php
storage/**
services/logs/storage/**
private/**
docs/**
scripts/**
tests/**
logs-notifications/**
composer.json
composer.lock
index.php raíz
6. CI

Hay workflow de tests:

.github/workflows/test.yml

Corre en:

push
pull_request

El deploy manual también ejecuta PHPUnit antes de subir por FTP. Si PHPUnit falla, no hay deploy.

7. Smoke tests Windows PowerShell

Definir variables:

$BASE = "https://logs.singularthings.io"
$VIEWER_USER = "USUARIO"
$VIEWER_PASSWORD = "PASSWORD"
$AUTH = "$($VIEWER_USER):$($VIEWER_PASSWORD)"

Viewer:

curl.exe -sS -o NUL -w "%{http_code}`n" -u "$AUTH" "$BASE/"
curl.exe -sS -o NUL -w "%{http_code}`n" -u "$AUTH" "$BASE/devices.php"
curl.exe -sS -o NUL -w "%{http_code}`n" -u "$AUTH" "$BASE/events.php"
curl.exe -sS -o NUL -w "%{http_code}`n" -u "$AUTH" "$BASE/ingests.php"

Esperado:

200
200
200
200

Health público:

curl.exe -sS "$BASE/api/health.php"

Esperado:

{
  "ok": true,
  "app": "logs-devices",
  "mode": "public",
  "env": "production",
  "database": "ok",
  "storage": "ok"
}

Health full:

$ADMIN_REPROCESS_TOKEN = "TOKEN_REAL"

curl.exe -sS `
  -H "X-Admin-Token: $ADMIN_REPROCESS_TOKEN" `
  "$BASE/api/health.php?mode=full"
8. Smoke test de ingestión legacy
$BASE = "https://logs.singularthings.io"
$LOG_INGEST_SECRET = "SECRETO_REAL"

$payloadPath = "$env:TEMP\legacy-smoke.json"

$body = @{
  message = "bridge_error"
  bridgeId = "99"
  bridgeName = "Runbook Smoke"
  errorText = "Runbook smoke test"
} | ConvertTo-Json -Compress

$utf8NoBom = New-Object System.Text.UTF8Encoding $false
[System.IO.File]::WriteAllText($payloadPath, $body, $utf8NoBom)

curl.exe -i -X POST `
  -H "User-Agent: WalkerPisa-Bridge-Logs" `
  -H "X-Log-Auth: $LOG_INGEST_SECRET" `
  -H "Content-Type: application/json" `
  --data-binary "@$payloadPath" `
  "$BASE/services/logs/index.php"

Esperado:

HTTP 200
"ok": true
"stored": true
9. Smoke test de ingestión nueva
$BASE = "https://logs.singularthings.io"
$LOG_INGEST_SECRET = "SECRETO_REAL"
$payloadPath = "$env:TEMP\api-ingest-smoke.json"
$timestamp = (Get-Date).ToUniversalTime().ToString("yyyy-MM-dd HH:mm:ss")

$logText = "[$timestamp] [TELEMETRY] INFO: [ff:ff:ff:00:00:99] TELEMETRY -> id=99 name='Runbook Smoke' timestamp=$timestamp | T=20.0C H=50.0% P=1013.0hPa AQ=100.0 (READY acc=3 stab=1 runin=1)"

$body = @{
  message = "bridge_logs"
  bridgeId = "99"
  bridgeName = "Runbook Smoke"
  logText = $logText
} | ConvertTo-Json -Compress

$utf8NoBom = New-Object System.Text.UTF8Encoding $false
[System.IO.File]::WriteAllText($payloadPath, $body, $utf8NoBom)

curl.exe -i -X POST `
  -H "User-Agent: WalkerPisa-Bridge-Logs" `
  -H "X-Log-Auth: $LOG_INGEST_SECRET" `
  -H "Content-Type: application/json" `
  --data-binary "@$payloadPath" `
  "$BASE/api/ingest.php"

Esperado:

HTTP 200
"ok": true
10. Admin endpoints

Reprocess:

POST /api/admin/reprocess.php

Archive raw:

POST /api/admin/archive_raw.php

Import services logs:

POST /api/admin/import_services_logs.php

Todos deben requerir:

X-Admin-Token: <ADMIN_REPROCESS_TOKEN>

Y deben estar desactivados por defecto salvo necesidad operativa.

11. Backups

Antes de cualquier cambio SQL en producción:

phpMyAdmin → Export → SQL completo → guardar fuera del hosting

Recomendación operativa mínima:

Backup manual mensual de la BD
Backup antes de migraciones SQL
Guardar copia en Drive/S3 privado de empresa
12. Troubleshooting rápido

Si /api/health.php devuelve 503:

Revisar DB en mode=full
Revisar storage raw/rejected
No tocar bridges hasta confirmar causa

Si llegan ingests pero no eventos:

Abrir /ingests.php
Abrir detalle del ingest
Revisar status, line_count, parsed_ok_count, parsed_error_count
Buscar eventos con parse_ok=0

Si el legacy responde 403:

Comprobar User-Agent
Comprobar X-Log-Auth
Comprobar LOG_INGEST_SECRET en runtime.php

Si el deploy falla:

Revisar job PHPUnit before deploy
Si PHPUnit falla, corregir código antes de desplegar
Si FTP falla, revisar credenciales FTP/secrets GitHub/CDmon

---

# Verificación local

```powershell
php -l .\src\Ingest\LogEventWriter.php
php -l .\tests\Ingest\LogEventWriterTest.php

vendor\bin\phpunit .\tests\Ingest\LogEventWriterTest.php
vendor\bin\phpunit

Comprueba que el writer ya no tiene el insert por evento:
````

Select-String -Path ".\src\Ingest\LogEventWriter.php" -Pattern "array_chunk","INSERT_CHUNK_SIZE","ON DUPLICATE KEY UPDATE"
