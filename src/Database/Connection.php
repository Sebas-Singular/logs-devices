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
            return new PDO(
                $dsn,
                $username,
                $password,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );
        } catch (PDOException $exception) {
            throw new PDOException(
                'Database connection failed: ' . $exception->getMessage(),
                (int) $exception->getCode(),
                $exception
            );
        }
    }
}