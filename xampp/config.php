<?php
declare(strict_types=1);

// Configuración local XAMPP. Los secretos van en config.local.php (ignorado por Git).
$cfg = [
    'dsn' => 'mysql:host=127.0.0.1;port=3306;dbname=max720rp;charset=utf8mb4',
    'db_user' => 'root',
    'db_password' => '',
    'contact_email' => 'max720rp@gmail.com',
    'base_url' => '', // Vacío = autodetectar; en producción usa HTTPS y una URL fija.
    'php_cli' => 'C:/xampp/php/php.exe',
    'openssl_dll' => 'C:/xampp/apache/bin/libcrypto-3-x64.dll',
    'smtp_host' => 'smtp.gmail.com',
    'smtp_port' => 587,
    'smtp_user' => 'max720rp@gmail.com',
    'smtp_password' => '',
    'mail_from_name' => 'MAX720RP',
];
$localFile = __DIR__ . '/config.local.php';
if (is_file($localFile)) {
    $local = require $localFile;
    if (is_array($local)) $cfg = array_replace($cfg, $local);
}
return $cfg;

