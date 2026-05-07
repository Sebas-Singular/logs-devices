<?php
 
declare(strict_types=1);
 
use App\Database\Connection;
use App\Support\Env;
 
require_once __DIR__ . '/../vendor/autoload.php';
 
Env::load(__DIR__ . '/../../../private/.env');
 
$appEnv = Env::get('APP_ENV', 'unknown');
$phpVersion = PHP_VERSION;
 
$databaseStatus = 'error';
$databaseMessage = '';
 
try {
    $pdo = Connection::make();
    $stmt = $pdo->query('SELECT 1 AS ok');
    $result = $stmt->fetch();
 
    if ((int) ($result['ok'] ?? 0) === 1) {
        $databaseStatus = 'ok';
        $databaseMessage = 'Connection successful';
    }
} catch (Throwable $exception) {
    $databaseMessage = $exception->getMessage();
}
 
http_response_code($databaseStatus === 'ok' ? 200 : 500);
 
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>logs-devices v2</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body {
            font-family: system-ui, Arial, sans-serif;
            max-width: 900px;
            margin: 40px auto;
            padding: 0 20px;
            background: #f6f7f9;
            color: #111827;
        }
 
        .card {
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 24px;
            box-shadow: 0 8px 24px rgba(15, 23, 42, 0.06);
        }
 
        .status-ok { color: #047857; font-weight: 700; }
        .status-error { color: #b91c1c; font-weight: 700; }
 
        code {
            background: #f3f4f6;
            padding: 2px 6px;
            border-radius: 6px;
        }
    </style>
</head>
<body>
    <main class="card">
        <h1>logs-devices v2</h1>
        <p>Entorno local de diagnóstico.</p>
 
        <h2>Estado</h2>
 
        <p>
            <strong>APP_ENV:</strong>
            <code><?= htmlspecialchars($appEnv, ENT_QUOTES, 'UTF-8') ?></code>
        </p>
 
        <p>
            <strong>PHP:</strong>
            <code><?= htmlspecialchars($phpVersion, ENT_QUOTES, 'UTF-8') ?></code>
        </p>
 
        <p>
            <strong>Database:</strong>
            <span class="<?= $databaseStatus === 'ok' ? 'status-ok' : 'status-error' ?>">
                <?= htmlspecialchars($databaseStatus, ENT_QUOTES, 'UTF-8') ?>
            </span>
        </p>
 
        <p>
            <strong>Database message:</strong>
            <code><?= htmlspecialchars($databaseMessage, ENT_QUOTES, 'UTF-8') ?></code>
        </p>
 
        <h2>Endpoints</h2>
 
        <p>Healthcheck: <a href="/api/health.php">/api/health.php</a></p>
    </main>
</body>
</html>