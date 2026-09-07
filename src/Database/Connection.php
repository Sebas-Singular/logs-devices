<?php

declare(strict_types=1);

namespace App\Database;

use App\Support\Env;
use PDO;
use PDOException;

final class Connection
{
    public static function make(): PDO
    {
        $host = Env::get('DB_HOST', 'db');
        $port = Env::get('DB_PORT', '3306');
        $database = Env::get('DB_DATABASE', 'logs-devices');
        $username = Env::get('DB_USERNAME', 'logs_user');
        $password = Env::get('DB_PASSWORD', 'logs_pass_dev');

        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $host,
            $port,
            $database
        );

        try {
            $pdo = new PDO(
                $dsn,
                $username,
                $password,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );

            // Bootstrap fija PHP en UTC y todos los DATETIME se escriben desde
            // PHP. Si la sesión de MariaDB usa la hora local del servidor
            // (habitual en hosting compartido), NOW() no cuadra con los valores
            // almacenados y toda comparación temporal -zombies, antigüedad del
            // último ingest- sale desplazada.
            //
            // Se ejecuta como sentencia en lugar de con MYSQL_ATTR_INIT_COMMAND
            // porque esa constante está deprecada desde PHP 8.5 y su sustituta
            // (Pdo\Mysql::ATTR_INIT_COMMAND) no existe en 8.3, que es la versión
            // de producción y de CI.
            $pdo->exec("SET time_zone = '+00:00'");

            return $pdo;
        } catch (PDOException $exception) {
            throw new PDOException(
                'Database connection failed: ' . $exception->getMessage(),
                (int) $exception->getCode(),
                $exception
            );
        }
    }
}