<?php
/**
 * config/env.php — .env file loader
 *
 * Reads the .env file from the project root and populates $_ENV.
 * Call this once at the top of config/db.php and config/email.php.
 */

function loadEnv(): void
{
    static $loaded = false;
    if ($loaded) return;

    $envFile = __DIR__ . '/../.env';
    if (!file_exists($envFile)) return;

    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        // Skip comments
        if (str_starts_with(trim($line), '#')) continue;

        // Parse KEY=VALUE
        if (!str_contains($line, '=')) continue;
        [$key, $value] = explode('=', $line, 2);
        $key   = trim($key);
        $value = trim($value);

        // Remove surrounding quotes if present
        if (preg_match('/^"(.*)"$/', $value, $m) || preg_match("/^'(.*)'$/", $value, $m)) {
            $value = $m[1];
        }

        if ($key !== '') {
            $_ENV[$key] = $value;
            putenv("{$key}={$value}");
        }
    }

    $loaded = true;
}
