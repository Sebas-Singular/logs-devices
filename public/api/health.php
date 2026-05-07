<?php
 
declare(strict_types=1);
 
use App\Database\Connection;
use App\Support\Env;
 
require_once __DIR__ . '/../../vendor/autoload.php';
 
Env::load(__DIR__ . '/../../../../private/.env');
 
header('Content-Type: application/json; charset=utf-8');
 
$response = [
    'ok' => false,
    'app' => 'logs-devices',
    'env' => Env::get('APP_ENV', 'unknown'),
    'php' => PHP_VERSION,
    'database' => 'error',
];
 
try {
    $pdo = Connection::make();
    $stmt = $pdo->query('SELECT DATABASE() AS database_name, NOW() AS database_time');
    $row = $stmt->fetch();
 
    $response['ok'] = true;
    $response['database'] = 'ok';
    $response['database_name'] = $row['database_name'] ?? null;
    $response['database_time'] = $row['database_time'] ?? null;
 
    http_response_code(200);
} catch (Throwable $exception) {
    $response['error'] = $exception->getMessage();
 
    http_response_code(500);
}
 
echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);