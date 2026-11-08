<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$cfg=require __DIR__.'/config.php';
$source=$argv[1]??(dirname(__DIR__).'/max720rp.db');
if (!is_file($source)) { fwrite(STDERR,"No se encuentra la base SQLite: $source\n" ); exit(1); }
try {
    $serverDsn=preg_replace('/;dbname=[^;]+/','',$cfg['dsn']);
    $db=new PDO($serverDsn,$cfg['db_user'],$cfg['db_password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $db->exec('CREATE DATABASE IF NOT EXISTS max720rp CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $_SERVER['MAX720RP_SQLITE_SOURCE']=$source;
    $argv=[__DIR__.'/migrate.php',$source];
    require __DIR__.'/migrate.php';
} catch(Throwable $e) {
    fwrite(STDERR,"No se pudo preparar XAMPP: ".$e->getMessage()."\n"); exit(1);
}

