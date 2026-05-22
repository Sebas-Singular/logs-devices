<?php

declare(strict_types=1);

namespace App\Storage;

use App\Support\Env;

final class Paths
{
    public static function for(string $relative): string
    {
        $basePath = Env::get('STORAGE_BASE_PATH');

        if ($basePath === null || trim($basePath) === '') {
            $basePath = dirname(__DIR__, 2) . '/storage';
        }

        return rtrim($basePath, '/\\') . '/' . ltrim($relative, '/\\');
    }
}
