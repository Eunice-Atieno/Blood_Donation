<?php
require_once __DIR__ . '/env.php';
loadEnv();

if (!defined('MAIL_DRIVER')) {
    define('MAIL_DRIVER',     $_ENV['MAIL_DRIVER']     ?? 'smtp');
    define('MAIL_HOST',       $_ENV['MAIL_HOST']       ?? 'smtp.gmail.com');
    define('MAIL_PORT',       (int)($_ENV['MAIL_PORT'] ?? 465));
    define('MAIL_ENCRYPTION', $_ENV['MAIL_ENCRYPTION'] ?? 'ssl');
    define('MAIL_USERNAME',   $_ENV['MAIL_USERNAME']   ?? '');
    define('MAIL_PASSWORD',   $_ENV['MAIL_PASSWORD']   ?? '');
    define('MAIL_FROM',       $_ENV['MAIL_FROM']       ?? '');
    define('MAIL_FROM_NAME',  $_ENV['MAIL_FROM_NAME']  ?? 'KNH BDMS');
}