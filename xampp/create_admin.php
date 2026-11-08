<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$cfg=require __DIR__.'/config.php';
$db=new PDO($cfg['dsn'],$cfg['db_user'],$cfg['db_password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$username=trim(readline('Usuario administrador: '));
$email=strtolower(trim(readline('Email: ')));
$password=readline('Nueva contraseña (8+ caracteres): ');
if(mb_strlen($username)<3 || !filter_var($email,FILTER_VALIDATE_EMAIL) || mb_strlen($password)<8) { fwrite(STDERR,"Datos no válidos.\n"); exit(1); }
$st=$db->prepare('SELECT id FROM users WHERE LOWER(username)=LOWER(?) OR LOWER(email)=LOWER(?) LIMIT 1'); $st->execute([$username,$email]); $id=$st->fetchColumn();
if($id) { $st=$db->prepare("UPDATE users SET role='admin',email=?,password_hash=?,is_deleted=0 WHERE id=?"); $st->execute([$email,password_hash($password,PASSWORD_DEFAULT),$id]); }
else { $st=$db->prepare("INSERT INTO users(username,email,password_hash,role) VALUES(?,?,?,'admin')"); $st->execute([$username,$email,password_hash($password,PASSWORD_DEFAULT)]); }
echo "Cuenta de administrador preparada.\n";

