<?php
require 'config/env.php';
loadEnv();
echo 'DB_NAME='        . ($_ENV['DB_NAME']        ?? 'MISSING') . "\n";
echo 'MAIL_USERNAME='  . ($_ENV['MAIL_USERNAME']  ?? 'MISSING') . "\n";
echo 'MAIL_FROM_NAME=' . ($_ENV['MAIL_FROM_NAME'] ?? 'MISSING') . "\n";
echo 'MAIL_PASSWORD='  . (empty($_ENV['MAIL_PASSWORD']) ? 'EMPTY' : 'SET') . "\n";
echo "OK\n";
