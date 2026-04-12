<?php
require_once __DIR__ . '/env.php';
loadEnv();

if (!defined('MAIL_DRIVER')) {
    define('MAIL_DRIVER',     \['MAIL_DRIVER']     ?? 'smtp');
    define('MAIL_HOST',       \['MAIL_HOST']       ?? 'smtp.gmail.com');
    define('MAIL_PORT',       (int)(\['MAIL_PORT'] ?? 465));
    define('MAIL_ENCRYPTION', \['MAIL_ENCRYPTION'] ?? 'ssl');
    define('MAIL_USERNAME',   \['MAIL_USERNAME']   ?? '');
    define('MAIL_PASSWORD',   \['MAIL_PASSWORD']   ?? '');
    define('MAIL_FROM',       \['MAIL_FROM']       ?? '');
    define('MAIL_FROM_NAME',  \['MAIL_FROM_NAME']  ?? 'KNH BDMS');
}
