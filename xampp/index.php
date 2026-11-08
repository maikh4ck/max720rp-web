<?php
declare(strict_types=1);
session_set_cookie_params(['httponly'=>true,'secure'=>!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off','samesite'=>'Lax']);
session_start();
$cfg = require __DIR__ . '/config.php';
try {
    $db = new PDO($cfg['dsn'], $cfg['db_user'], $cfg['db_password'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false]);
} catch (Throwable $e) {
    http_response_code(503);
    exit('No se pudo conectar a MariaDB. Inicia MySQL en XAMPP, importa xampp/schema.sql y revisa xampp/config.php.');
}
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function q(string $sql, array $args=[]): PDOStatement { global $db; $s=$db->prepare($sql); $s->execute($args); return $s; }
function go(string $page, array $params=[]): never { header('Location: index.php?' . http_build_query(['p'=>$page]+$params)); exit; }
function note(string $kind,string $msg): void { $_SESSION['flash'][]=[$kind,$msg]; }
function csrf(): string { return '<input type="hidden" name="csrf" value="'.e($_SESSION['csrf']).'">'; }
function form_ok(): void { if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) { http_response_code(400); exit('Formulario caducado. Recarga la página e inténtalo otra vez.'); } }
function me(): ?array { global $db; if (empty($_SESSION['uid'])) return null; return q('SELECT id,username,email,role FROM users WHERE id=? AND is_deleted=0',[(int)$_SESSION['uid']])->fetch() ?: null; }
function admin_only(): array { $u=me(); if (!$u || $u['role']!=='admin') { http_response_code($u?403:401); exit('Acceso solo para administradores.'); } return $u; }
function price(int $c): string { return number_format($c/100,2,',','.').' €'; }
function asset(string $path): string { return 'static/'.rawurlencode(basename($path)); }
function image_path(string $v): string { return str_starts_with($v,'uploads/') ? 'uploads/'.rawurlencode(basename($v)) : ($v ?: asset('max720rp-logo.png')); }
function pw_ok(string $stored,string $password): bool {
    if (str_starts_with($stored,'scrypt:')) { // Hashwerkzeug: scrypt:N:r:p$salt$hex
        $parts=explode('$',$stored); if(count($parts)!==3) return false;
        if(!preg_match('/^scrypt:([0-9]+):([0-9]+):([0-9]+)$/',$parts[0],$m)) return false;
        $n=(int)$m[1]; $r=(int)$m[2]; $p=(int)$m[3];
        if($n<2 || ($n & ($n-1))!==0 || $n>1048576 || $r<1 || $r>32 || $p<1 || $p>16 || (128*$n*$r)>268435456 || strlen($parts[2])!==128) return false;
        $derived=derive_scrypt($password,$parts[1],$n,$r,$p);
        return $derived!==null && hash_equals($parts[2],$derived);
    }
    if (str_starts_with($stored,'pbkdf2:')) { // Hash Werkzeug/Flask: pbkdf2:sha256:iterations$salt$hex
        $parts=explode('$',$stored); if(count($parts)!==3) return false;
        preg_match('/^pbkdf2:([^:]+):([0-9]+)$/',$parts[0],$m); if(!$m) return false;
        return hash_equals($parts[2],hash_pbkdf2($m[1],$password,$parts[1],(int)$m[2],strlen($parts[2]),false));
    }
    return password_verify($password,$stored);
}
function derive_scrypt(string $password,string $salt,int $n,int $r,int $p): ?string {
    global $cfg;
    if(!function_exists('proc_open')) return null;
    $php='"'.str_replace('"','',$cfg['php_cli']).'"';
    $script='"'.str_replace('"','',__DIR__.'/verify_scrypt.php').'"';
    $command=$php.' -d extension=php_ffi.dll -d ffi.enable=true '.$script;
    $pipes=[]; $proc=@proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,__DIR__,null,['bypass_shell'=>true]);
    if(!is_resource($proc)) return null;
    $payload=json_encode(['password'=>$password,'salt'=>$salt,'n'=>$n,'r'=>$r,'p'=>$p,'openssl'=>$cfg['openssl_dll']],JSON_UNESCAPED_SLASHES);
    fwrite($pipes[0],$payload); fclose($pipes[0]);
    $output=trim(stream_get_contents($pipes[1])); fclose($pipes[1]);
    stream_get_contents($pipes[2]); fclose($pipes[2]);
    $exit=proc_close($proc);
    return $exit===0 && preg_match('/^[a-f0-9]{128}$/',$output) ? $output : null;
}
function password_store(string $password): string { return password_hash($password,PASSWORD_DEFAULT); }
function smtp_ready(): bool {
    $c=$GLOBALS['cfg'];
    return !empty($c['smtp_host']) && !empty($c['smtp_port']) && filter_var($c['smtp_user']??'',FILTER_VALIDATE_EMAIL)
        && filter_var($c['contact_email']??'',FILTER_VALIDATE_EMAIL) && strlen(trim((string)($c['smtp_password']??'')))>0;
}
function smtp_response($stream,array $expected): string {
    $response=''; $code=0;
    do {
        $line=fgets($stream,2048);
        if($line===false) throw new RuntimeException('Conexión SMTP cerrada durante la respuesta.');
        $response.=$line;
        if(preg_match('/^(\d{3})([ -])/',$line,$m)) { $code=(int)$m[1]; if($m[2]===' ') break; }
    } while(!feof($stream));
    if(!in_array($code,$expected,true)) throw new RuntimeException('Respuesta SMTP inesperada: '.$code);
    return $response;
}
function smtp_command($stream,string $command,array $expected): string {
    if(fwrite($stream,$command."\r\n")===false) throw new RuntimeException('No se pudo enviar el comando SMTP.');
    return smtp_response($stream,$expected);
}
function send_mail(string $to,string $subject,string $body,?string $reply=null): bool {
    $c=$GLOBALS['cfg'];
    if(!smtp_ready() || !filter_var($to,FILTER_VALIDATE_EMAIL)) return false;
    $host=(string)$c['smtp_host']; $port=(int)$c['smtp_port']; $stream=null;
    try {
        $context=stream_context_create(['ssl'=>['verify_peer'=>true,'verify_peer_name'=>true,'peer_name'=>$host]]);
        $stream=stream_socket_client('tcp://'.$host.':'.$port,$errno,$errstr,15,STREAM_CLIENT_CONNECT,$context);
        if(!$stream) throw new RuntimeException('No se pudo conectar con el servidor SMTP ('.$errno.').');
        stream_set_timeout($stream,15);
        smtp_response($stream,[220]);
        $ehlo=preg_replace('/[^a-zA-Z0-9.-]/','',$_SERVER['SERVER_NAME']??'localhost') ?: 'localhost';
        smtp_command($stream,'EHLO '.$ehlo,[250]);
        smtp_command($stream,'STARTTLS',[220]);
        if(stream_socket_enable_crypto($stream,true,STREAM_CRYPTO_METHOD_TLS_CLIENT)!==true) throw new RuntimeException('No se pudo activar TLS para SMTP.');
        smtp_command($stream,'EHLO '.$ehlo,[250]);
        smtp_command($stream,'AUTH LOGIN',[334]);
        smtp_command($stream,base64_encode((string)$c['smtp_user']),[334]);
        smtp_command($stream,base64_encode(preg_replace('/\s+/','',(string)$c['smtp_password'])),[235]);
        smtp_command($stream,'MAIL FROM:<'.$c['smtp_user'].'>',[250]);
        smtp_command($stream,'RCPT TO:<'.$to.'>',[250,251]);
        smtp_command($stream,'DATA',[354]);
        $fromName='=?UTF-8?B?'.base64_encode((string)($c['mail_from_name']??'MAX720RP')).'?=';
        $safeSubject='=?UTF-8?B?'.base64_encode(str_replace(["\r","\n"],'',$subject)).'?=';
        $headers=[
            'Date: '.date(DATE_RFC2822),
            'From: '.$fromName.' <'.$c['smtp_user'].'>',
            'To: '.$to,
            'Subject: '.$safeSubject,
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
        ];
        if($reply && filter_var($reply,FILTER_VALIDATE_EMAIL)) $headers[]='Reply-To: '.$reply;
        $body=preg_replace('/\r\n|\r|\n/',"\r\n",$body);
        $body=preg_replace('/^\./m','..',$body);
        $message=implode("\r\n",$headers)."\r\n\r\n".$body."\r\n.\r\n";
        if(fwrite($stream,$message)===false) throw new RuntimeException('No se pudo transmitir el correo.');
        smtp_response($stream,[250]);
        @fwrite($stream,"QUIT\r\n");
        return true;
    } catch(Throwable $e) {
        error_log('[MAX720RP SMTP] '.$e->getMessage());
        return false;
    } finally {
        if(is_resource($stream)) fclose($stream);
    }
}
function layout(string $title,string $content,?array $user): never {
    $flashes=$_SESSION['flash']??[]; unset($_SESSION['flash']);
    $active=$_GET['p']??'inicio'; $nav=[['inicio','Inicio'],['musica','Música'],['merch','Merch'],['contacto','Contacto']];
    ?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="theme-color" content="#090909"><title><?=e($title)?> — MAX720RP</title><link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@500;600;700;800;900&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet"><link rel="stylesheet" href="static/style.css"><link rel="stylesheet" href="static/brand-overrides.css"></head><body>
    <header class="site-header"><a class="brand" href="index.php" aria-label="MAX720RP, inicio"><img class="brand-logo" src="<?=e(asset('max720rp-logo.png'))?>" alt=""><span class="brand-copy"><span class="brand-name">MAX<span class="brand-number">720RP</span></span></span></a><button class="menu-toggle" aria-label="Abrir menú" aria-expanded="false">☰</button><nav class="nav-links" aria-label="Navegación principal">
    <?php foreach($nav as [$key,$label]): ?><a class="<?=$active===$key?'active':''?>" href="index.php?p=<?=$key?>"><?=$label?></a><?php endforeach; ?>
    <?php if($user && $user['role']==='admin'): ?><a class="admin-link <?=$active==='panel'?'active':''?>" href="index.php?p=panel">Panel</a><a class="<?=$active==='productos'?'active':''?>" href="index.php?p=productos">Productos</a><?php endif; ?>
    <?php if($user): ?><span class="nav-user"><?=e($user['username'])?></span><form method="post" action="index.php?p=salir"><?=csrf()?><button class="nav-button">Salir</button></form><?php else: ?><a class="nav-login" href="index.php?p=login">Iniciar sesión</a><?php endif; ?></nav></header>
    <?php if($flashes): ?><div class="flash-stack"><?php foreach($flashes as [$kind,$msg]): ?><div class="flash <?=e($kind)?>" role="status"><?=e($msg)?></div><?php endforeach; ?></div><?php endif; ?><main><?=$content?></main><footer class="site-footer"><div class="footer-simple"><small>© 2026 MAX720RP. Todos los derechos reservados.</small></div></footer><script src="static/site.js"></script></body></html><?php exit;
}
$user=me(); $page=$_GET['p']??'inicio'; $method=$_SERVER['REQUEST_METHOD'];

// Autenticación y formularios POST protegidos contra CSRF.
if ($method==='POST') form_ok();
if ($page==='salir' && $method==='POST') { $_SESSION=[]; session_regenerate_id(true); note('success','Has cerrado sesión.'); go('inicio'); }
if ($page==='login' && $method==='POST') {
    $account=trim($_POST['account']??''); $found=q('SELECT * FROM users WHERE is_deleted=0 AND (LOWER(username)=LOWER(?) OR LOWER(email)=LOWER(?))',[$account,$account])->fetch();
    if($found && pw_ok($found['password_hash'],$_POST['password']??'')) { session_regenerate_id(true); $_SESSION['uid']=(int)$found['id']; $_SESSION['csrf']=bin2hex(random_bytes(32)); go($found['role']==='admin'?'panel':'inicio'); }
    note('error','Usuario o contraseña incorrectos.'); go('login');
}
if ($page==='registro' && $method==='POST') {
    $name=trim($_POST['username']??''); $email=strtolower(trim($_POST['email']??'')); $pw=$_POST['password']??'';
    if(mb_strlen($name)<3 || mb_strlen($name)>40 || mb_strlen($pw)<8 || !filter_var($email,FILTER_VALIDATE_EMAIL)) note('error','Usa un usuario de 3 a 40 caracteres, email válido y contraseña de 8 o más caracteres.');
    else try { q('INSERT INTO users(username,email,password_hash) VALUES(?,?,?)',[$name,$email,password_store($pw)]); note('success','Cuenta creada. Ya puedes iniciar sesión.'); go('login'); } catch(Throwable $e) { note('error','Ese nombre o email ya está registrado.'); }
    go('registro');
}
if ($page==='contacto' && $method==='POST') {
    $name=trim($_POST['name']??''); $email=trim($_POST['email']??''); $subject=trim($_POST['subject']??''); $message=trim($_POST['message']??'');
    if(!$name || !filter_var($email,FILTER_VALIDATE_EMAIL) || !in_array($subject,['Concierto / booking','Colaboración','Prensa','Otro'],true) || !$message || mb_strlen($message)>5000) note('error','Revisa los campos del formulario (máximo 5000 caracteres en el mensaje).');
    else { q('INSERT INTO inquiries(name,email,subject,message) VALUES(?,?,?,?)',[$name,$email,$subject,$message]); $ok=send_mail($cfg['contact_email'],'MAX720RP · '.$subject,"Nombre: $name\nEmail: $email\nAsunto: $subject\n\n$message",$email); note($ok?'success':'error',$ok?'Tu mensaje se envió correctamente.':'El mensaje quedó guardado, pero no pudo enviarse. Configura SMTP en xampp/config.local.php.'); }
    go('contacto');
}
if ($page==='registro' && $user) go('inicio');
if ($page==='forgot' && $method==='POST') {
    if(!smtp_ready()) { note('error','El correo de recuperación aún no está configurado. Completa SMTP en config.local.php.'); go('forgot'); }
    $email=strtolower(trim($_POST['email']??'')); $target=q('SELECT id,email FROM users WHERE is_deleted=0 AND LOWER(email)=LOWER(?)',[$email])->fetch();
    if($target) { $token=bin2hex(random_bytes(32)); q('UPDATE password_resets SET used_at=? WHERE user_id=? AND used_at IS NULL',[time(),$target['id']]); q('INSERT INTO password_resets(user_id,token_hash,expires_at) VALUES(?,?,?)',[$target['id'],hash('sha256',$token),time()+1800]); $secure=!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off'; $base=rtrim($cfg['base_url'] ?: (($secure?'https':'http').'://'.$_SERVER['HTTP_HOST'].dirname($_SERVER['SCRIPT_NAME'])),'/'); $sent=send_mail($target['email'],'Restablece tu contraseña de MAX720RP',"Hemos recibido una solicitud para cambiar la contraseña de tu cuenta MAX720RP.\n\nCrea una contraseña nueva desde este enlace (válido durante 30 minutos):\n$base/index.php?p=reset&token=$token\n\nSi no solicitaste este cambio, ignora este mensaje."); if(!$sent) q('UPDATE password_resets SET used_at=? WHERE token_hash=?',[time(),hash('sha256',$token)]); }
    note('success','Si el correo está registrado, recibirás instrucciones para cambiar la contraseña.'); go('forgot');
}
if ($page==='reset' && $method==='GET') {
    $valid=q('SELECT id FROM password_resets WHERE token_hash=? AND used_at IS NULL AND expires_at>?',[hash('sha256',$_GET['token']??''),time()])->fetchColumn();
    if (!$valid) { note('error','El enlace ha caducado o ya se utilizó. Solicita uno nuevo.'); go('forgot'); }
}
if ($page==='reset' && $method==='POST') {
    $hash=hash('sha256',$_GET['token']??''); $row=q('SELECT * FROM password_resets WHERE token_hash=? AND used_at IS NULL AND expires_at>?',[$hash,time()])->fetch(); $pw=$_POST['password']??'';
    if(!$row) note('error','El enlace ha caducado o ya se utilizó.'); elseif(mb_strlen($pw)<8) note('error','La contraseña debe tener al menos 8 caracteres.'); else { q('UPDATE users SET password_hash=? WHERE id=?',[password_store($pw),$row['user_id']]); q('UPDATE password_resets SET used_at=? WHERE user_id=? AND used_at IS NULL',[time(),$row['user_id']]); note('success','Contraseña actualizada. Ya puedes iniciar sesión.'); go('login'); } go('reset',['token'=>$_GET['token']??'']);
}
if ($page==='carrito' && $method==='POST') {
    $id=(int)($_POST['product_id']??0); $product=q('SELECT id,stock FROM products WHERE id=? AND active=1',[$id])->fetch();
    if(!$product || $product['stock']<1) note('error','El producto está agotado.'); else { $_SESSION['cart'][$id]=min((int)$product['stock'],(int)($_SESSION['cart'][$id]??0)+1); note('success','Producto añadido al carrito.'); } go('merch');
}
if ($page==='vaciar' && $method==='POST') { unset($_SESSION['cart']); note('success','Carrito vaciado.'); go('merch'); }
if ($page==='pedido' && $method==='POST') {
    if(!$user) { note('error','Inicia sesión para confirmar el pedido.'); go('login'); }
    $cart=$_SESSION['cart']??[]; $db->beginTransaction();
    try { $lines=[]; $total=0; foreach($cart as $id=>$qty) { $p=q('SELECT * FROM products WHERE id=? AND active=1 FOR UPDATE',[(int)$id])->fetch(); if(!$p || $qty<1 || $qty>$p['stock']) throw new RuntimeException(); $lines[]=[$p,(int)$qty]; $total+=(int)$p['price_cents']*(int)$qty; }
        if(!$lines) throw new RuntimeException(); q('INSERT INTO orders(user_id,total_cents) VALUES(?,?)',[$user['id'],$total]); $oid=(int)$db->lastInsertId(); foreach($lines as [$p,$qty]) { q('UPDATE products SET stock=stock-? WHERE id=? AND stock>=?',[$qty,$p['id'],$qty]); q('INSERT INTO order_items(order_id,product_id,product_name,quantity,unit_price_cents) VALUES(?,?,?,?,?)',[$oid,$p['id'],$p['name'],$qty,$p['price_cents']]); } $db->commit(); unset($_SESSION['cart']); note('success',"Pedido #$oid registrado. Te contactaremos para coordinar pago y envío.");
    } catch(Throwable $e) { if($db->inTransaction()) $db->rollBack(); note('error','El stock cambió o el carrito está vacío. Actualiza e inténtalo de nuevo.'); } go('merch');
}

// Acciones del administrador.
if (in_array($page,['panel','productos'],true)) $user=admin_only();
if ($page==='panel' && $method==='POST') {
    $action=$_POST['action']??''; $id=(int)($_POST['user_id']??0); $target=q('SELECT * FROM users WHERE id=? AND is_deleted=0',[$id])->fetch();
    if($action==='role' && $target && in_array($_POST['role']??'', ['user','admin'],true) && ($id!==$user['id'] || $_POST['role']==='admin')) { q('UPDATE users SET role=? WHERE id=?',[$_POST['role'],$id]); note('success','Rol actualizado.'); }
    elseif($action==='reset_password' && $target && mb_strlen($_POST['new_password']??'')>=8) { q('UPDATE users SET password_hash=? WHERE id=?',[password_store($_POST['new_password']),$id]); q('UPDATE password_resets SET used_at=? WHERE user_id=? AND used_at IS NULL',[time(),$id]); note('success','Contraseña restablecida para '. $target['username'].'.'); }
    elseif($action==='delete_user' && $target && $id!==$user['id']) { $orders=(int)q('SELECT COUNT(*) FROM orders WHERE user_id=?',[$id])->fetchColumn(); if($orders) { q("UPDATE users SET username=?,email=?,password_hash=?,role='user',is_deleted=1 WHERE id=?",["cuenta-eliminada-$id","eliminado-$id@invalid.local",password_store(bin2hex(random_bytes(24))),$id]); note('success','Cuenta anonimizada; se conservó su historial de pedidos.'); } else { q('DELETE FROM users WHERE id=?',[$id]); note('success','Cuenta eliminada.'); } }
    elseif($action==='delete_order') { q('DELETE FROM orders WHERE id=?',[(int)($_POST['order_id']??0)]); note('success','Pedido eliminado. El stock actual no se modificó.'); }
    elseif($action==='delete_inquiry') { q('DELETE FROM inquiries WHERE id=?',[(int)($_POST['inquiry_id']??0)]); note('success','Mensaje eliminado.'); }
    else note('error','No se pudo completar esa acción.'); go('panel');
}
if ($page==='productos' && $method==='POST') {
    $id=(int)($_POST['product_id']??0); $action=$_POST['action']??'save'; $back='productos'.(!empty($_POST['return_q'])?'&q='.urlencode($_POST['return_q']):'');
    try {
        if($action==='delete' && $id) { $p=q('SELECT image_url FROM products WHERE id=?',[$id])->fetch(); q('UPDATE order_items SET product_id=NULL WHERE product_id=?',[$id]); q('DELETE FROM products WHERE id=?',[$id]); delete_image($p['image_url']??''); note('success','Producto eliminado; los pedidos conservan su descripción histórica.'); go('productos',!empty($_POST['return_q'])?['q'=>$_POST['return_q']]:[]); }
        $name=trim($_POST['name']??''); $desc=trim($_POST['description']??''); $cents=(int)round((float)($_POST['price']??-1)*100); $stock=(int)($_POST['stock']??-1); $active=(int)(($_POST['active']??'0')==='1');
        if(!$name || mb_strlen($name)>120 || mb_strlen($desc)>1000 || $cents<0 || $cents>10000000 || $stock<0 || $stock>1000000) throw new RuntimeException('datos');
        $old=$id?q('SELECT image_url FROM products WHERE id=?',[$id])->fetch():null; if($id && !$old) throw new RuntimeException('producto'); $img=$old['image_url']??'';
        if(isset($_FILES['image']) && $_FILES['image']['error']!==UPLOAD_ERR_NO_FILE) $img=save_image($_FILES['image']);
        if($id) q('UPDATE products SET name=?,description=?,price_cents=?,image_url=?,stock=?,active=? WHERE id=?',[$name,$desc,$cents,$img,$stock,$active,$id]); else q('INSERT INTO products(name,description,price_cents,image_url,stock,active) VALUES(?,?,?,?,?,?)',[$name,$desc,$cents,$img,$stock,$active]);
        if($old && $img!==$old['image_url']) delete_image($old['image_url']); note('success',$id?'Producto actualizado.':'Producto creado.');
    } catch(Throwable $e) { note('error','Revisa los datos y la imagen. Se admiten PNG, JPG o WebP hasta 8 MB.'); }
    go('productos',!empty($_POST['return_q'])?['q'=>$_POST['return_q']]:[]);
}
function save_image(array $f): string { if($f['error']!==UPLOAD_ERR_OK || $f['size']>8*1024*1024) throw new RuntimeException('image'); $mime=(new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']); $ext=['image/png'=>'png','image/jpeg'=>'jpg','image/webp'=>'webp'][$mime]??null; if(!$ext || !getimagesize($f['tmp_name'])) throw new RuntimeException('image'); $dir=__DIR__.'/uploads'; if(!is_dir($dir)) mkdir($dir,0775,true); $file=bin2hex(random_bytes(16)).'.'.$ext; if(!move_uploaded_file($f['tmp_name'],$dir.'/'.$file)) throw new RuntimeException('image'); return 'uploads/'.$file; }
function delete_image(string $img): void { if(str_starts_with($img,'uploads/')) { $f=__DIR__.'/uploads/'.basename($img); if(is_file($f)) @unlink($f); } }

// Renderizado de páginas.
ob_start();
switch($page) {
case 'inicio':
case 'musica':
    $songs=q('SELECT * FROM songs WHERE published=1 ORDER BY view_rank,id')->fetchAll(); if($page==='inicio') $songs=array_slice($songs,0,3);
    if($page==='inicio') { ?><section class="hero"><div class="hero-bg"></div><div class="hero-content"><p class="eyebrow"><span class="live-dot"></span> ARTISTA · RAP · URBANO</p><h1>SONIDO<br><em>SIN LÍMITES.</em></h1><p class="hero-copy">Soy MAX720RP. Música nacida de lo que vivo, hecha para quien nunca deja de avanzar. Mi objetivo: convertir cada historia en un sonido que conecte y que acerque a Cristo.</p><div class="hero-actions"><a class="button button-red" href="index.php?p=musica">Escucha mi música <span>↗</span></a><a class="button button-outline" href="index.php?p=contacto">Booking / contacto</a></div><div class="hero-meta"><span>INDEPENDIENTE · AUTÉNTICO · 720°</span><a href="https://www.youtube.com/@max720rp" target="_blank" rel="noopener">YOUTUBE OFICIAL ↗</a></div></div><div class="hero-number">720<span>°</span></div><div class="hero-side">MAX720RP — DESDE AQUÍ, HACIA TODO</div></section><section class="section section-music"><div class="section-heading"><div><p class="eyebrow">CATÁLOGO</p><h2>TOP <em>LANZAMIENTOS</em></h2></div><a class="text-link" href="https://www.youtube.com/@max720rp" target="_blank" rel="noopener">Ver toda la música ↗</a></div><?php render_songs($songs); ?></section><section class="manifesto"><div class="manifesto-mark">720°</div><div><p class="eyebrow">MI VISIÓN</p><h2>UNA VOZ PROPIA.<br><em>UN CAMINO PROPIO.</em></h2><p>No hago música para encajar. Cada tema es parte de un recorrido: trabajo, testimonios, sueños y la energía de seguir haciendo el llamado. Cristo es el punto de encuentro.</p></div></section><section class="section merch-preview"><div class="section-heading"><div><p class="eyebrow">LLEVA LA IDENTIDAD</p><h2>MERCH <em>OFICIAL</em></h2></div><a class="text-link" href="index.php?p=merch">Ver tienda →</a></div><?php render_products(q('SELECT * FROM products WHERE active=1 AND stock>0 ORDER BY id DESC LIMIT 3')->fetchAll(),false); ?></section><section class="booking-band"><div><p class="eyebrow">¿ORGANIZAS UN EVENTO?</p><h2>HABLEMOS DE<br><em>PRÓXIMO SHOW.</em></h2></div><a class="button button-red" href="index.php?p=contacto">Contactar para booking ↗</a></section><?php }
    else { ?><section class="page-hero"><p class="eyebrow">CATÁLOGO OFICIAL · MÁS ESCUCHADAS</p><h1>CADA TEMA,<br><em>UNA HISTORIA.</em></h1><p>Los vídeos están ordenados según el ranking que compartiste.</p></section><section class="section"><?php render_songs($songs); ?><div class="center-action"><a class="button button-outline" href="https://www.youtube.com/@max720rp" target="_blank" rel="noopener">Ir al canal de YouTube ↗</a></div></section><?php } break;
case 'merch':
    $products=q('SELECT * FROM products WHERE active=1 AND stock>0 ORDER BY id DESC')->fetchAll(); $cart=$_SESSION['cart']??[]; ?><section class="page-hero"><p class="eyebrow">TIENDA OFICIAL</p><h1>VÍSTELO.<br><em>REPRESÉNTALO.</em></h1><p>Merch MAX720RP. Cada pedido queda registrado en tu cuenta.</p></section><section class="section shop-layout"><?php render_products($products,true); ?><aside class="cart-panel"><p class="eyebrow">TU PEDIDO</p><h2>Carrito</h2><?php $total=0; foreach($cart as $pid=>$qty) { $p=q('SELECT name,price_cents FROM products WHERE id=?',[(int)$pid])->fetch(); if(!$p)continue; $total+=$p['price_cents']*$qty; ?><div class="cart-line"><span><?=e($p['name'])?> × <?=$qty?></span><strong><?=price($p['price_cents']*$qty)?></strong></div><?php } ?><div class="cart-total"><span>Total</span><strong><?=price($total)?></strong></div><?php if($cart): ?><form method="post" action="index.php?p=vaciar"><?=csrf()?><button class="text-link">Vaciar carrito</button></form><p class="muted">El pago y el envío se coordinan tras recibir el pedido.</p><form method="post" action="index.php?p=pedido"><?=csrf()?><button class="button button-red button-wide"><?=$user?'Confirmar pedido':'Inicia sesión para pedir'?> →</button></form><?php else: ?><p class="muted">Tu carrito está vacío. Añade una prenda para empezar.</p><?php endif; ?></aside></section><?php break;
case 'contacto': ?><section class="page-hero"><p class="eyebrow">BOOKING · COLABORACIONES · PRENSA</p><h1>HAGAMOS<br><em>QUE SUENE.</em></h1><p>Cuéntame qué tienes en mente. Para conciertos, incluye fecha y ciudad.</p></section><section class="section contact-layout"><div class="contact-note"><span class="contact-symbol">↗</span><h2>UNA IDEA<br>EMPIEZA AQUÍ.</h2><p>Los mensajes se guardan en el panel. También puedes escribirme directamente.</p><a href="mailto:max720rp@gmail.com">max720rp@gmail.com ↗</a><p><a href="https://www.youtube.com/@max720rp" target="_blank" rel="noopener">YouTube oficial ↗</a></p></div><form class="form-card" method="post" action="index.php?p=contacto"><?=csrf()?><label>Nombre<input name="name" required maxlength="120" placeholder="Tu nombre"></label><label>Email<input type="email" name="email" required maxlength="254" placeholder="nombre@email.com"></label><label>Asunto<select name="subject" required><option value="">Selecciona un tema</option><?php foreach(['Concierto / booking','Colaboración','Prensa','Otro'] as $s): ?><option><?=e($s)?></option><?php endforeach; ?></select></label><label>Mensaje<textarea name="message" required maxlength="5000" rows="6" placeholder="Fecha, ciudad, detalles del evento..."></textarea></label><button class="button button-red">Enviar mensaje ↗</button></form></section><?php break;
case 'login': ?><section class="auth-wrap"><div class="auth-art"><img class="auth-logo" src="<?=asset('max720rp-logo.png')?>" alt="Logo MAX720RP"><p>VUELVE A<br><em>LA FRECUENCIA.</em></p></div><form class="form-card auth-card" method="post" action="index.php?p=login"><?=csrf()?><p class="eyebrow">ZONA DE USUARIO</p><h1>Iniciar sesión</h1><p class="muted">Accede para gestionar tus pedidos.</p><label>Usuario o email<input name="account" required autocomplete="username"></label><label>Contraseña<input type="password" name="password" required autocomplete="current-password"></label><button class="button button-red button-wide">Entrar →</button><p class="form-foot"><a href="index.php?p=forgot">¿Olvidaste tu contraseña?</a></p><p class="form-foot">¿Aún no tienes cuenta? <a href="index.php?p=registro">Regístrate</a></p></form></section><?php break;
case 'registro': ?><section class="auth-wrap"><div class="auth-art"><img class="auth-logo" src="<?=asset('max720rp-logo.png')?>" alt="MAX720RP"><p>ENTRA EN<br><em>EL MOVIMIENTO.</em></p></div><form class="form-card auth-card" method="post" action="index.php?p=registro"><?=csrf()?><p class="eyebrow">ÚNETE A LA COMUNIDAD</p><h1>Crear cuenta</h1><label>Nombre de usuario<input name="username" required minlength="3" maxlength="40" autocomplete="username"></label><label>Email<input type="email" name="email" required maxlength="254"></label><label>Contraseña<input type="password" name="password" required minlength="8" autocomplete="new-password"></label><button class="button button-red button-wide">Crear cuenta →</button><p class="form-foot">¿Ya tienes cuenta? <a href="index.php?p=login">Inicia sesión</a></p></form></section><?php break;
case 'forgot': ?><section class="auth-wrap"><div class="auth-art"><img class="auth-logo" src="<?=asset('max720rp-logo.png')?>" alt="MAX720RP"><p>RECUPERA<br><em>EL ACCESO.</em></p></div><form class="form-card auth-card" method="post" action="index.php?p=forgot"><?=csrf()?><p class="eyebrow">RECUPERACIÓN SEGURA</p><h1>¿Olvidaste la contraseña?</h1><p class="muted">Escribe tu email y te enviaremos un enlace válido durante 30 minutos.</p><label>Email de la cuenta<input type="email" name="email" required></label><button class="button button-red button-wide">Enviar enlace →</button><p class="form-foot"><a href="index.php?p=login">Volver a iniciar sesión</a></p></form></section><?php break;
case 'reset': ?><section class="auth-wrap"><div class="auth-art"><img class="auth-logo" src="<?=asset('max720rp-logo.png')?>" alt="MAX720RP"><p>CREA UNA<br><em>NUEVA CLAVE.</em></p></div><form class="form-card auth-card" method="post" action="index.php?p=reset&amp;token=<?=e($_GET['token']??'')?>"><?=csrf()?><p class="eyebrow">ENLACE DE UN SOLO USO</p><h1>Nueva contraseña</h1><label>Nueva contraseña<input type="password" name="password" required minlength="8" autocomplete="new-password"></label><button class="button button-red button-wide">Guardar contraseña →</button></form></section><?php break;
case 'panel':
    $users=q('SELECT id,username,email,role,created_at FROM users WHERE is_deleted=0 ORDER BY id')->fetchAll(); $orders=q('SELECT o.*,u.username FROM orders o JOIN users u ON u.id=o.user_id ORDER BY o.id DESC LIMIT 20')->fetchAll(); $inquiries=q('SELECT * FROM inquiries ORDER BY id DESC LIMIT 20')->fetchAll(); ?><section class="page-hero admin-hero"><p class="eyebrow">ACCESO DE ADMINISTRADOR</p><h1>PANEL DE<br><em>CONTROL.</em></h1><p>Gestiona permisos, cuentas, pedidos y mensajes.</p><a class="button button-red" href="index.php?p=productos">Gestionar tienda →</a></section><section class="section admin-section"><div class="section-heading"><div><p class="eyebrow">CUENTAS</p><h2>USUARIOS <em>Y ROLES</em></h2></div><a class="text-link" href="index.php?p=productos">Gestionar productos →</a></div><div class="table-wrap"><table class="users-table"><thead><tr><th>Usuario</th><th>Email</th><th>Alta</th><th>Rol</th><th>Permisos</th><th>Restablecer contraseña</th><th>Eliminar</th></tr></thead><tbody><?php foreach($users as $u): ?><tr><td><?=e($u['username'])?> <?=$u['id']==$user['id']?'(tú)':''?></td><td><?=e($u['email'])?></td><td><?=e(substr($u['created_at'],0,10))?></td><td><span class="role-badge <?=e($u['role'])?>"><?=e($u['role'])?></span></td><td><form method="post" action="index.php?p=panel"><?=csrf()?><input type="hidden" name="action" value="role"><input type="hidden" name="user_id" value="<?=$u['id']?>"><select name="role"><option value="user" <?=$u['role']==='user'?'selected':''?>>Usuario</option><option value="admin" <?=$u['role']==='admin'?'selected':''?>>Admin</option></select><button class="small-button">Guardar</button></form></td><td><form method="post" action="index.php?p=panel" class="user-reset-form"><?=csrf()?><input type="hidden" name="action" value="reset_password"><input type="hidden" name="user_id" value="<?=$u['id']?>"><input type="password" name="new_password" minlength="8" required placeholder="Nueva contraseña"><button class="small-button">Restablecer</button></form></td><td><form method="post" action="index.php?p=panel" onsubmit="return confirm('¿Eliminar esta cuenta?')"><?=csrf()?><input type="hidden" name="action" value="delete_user"><input type="hidden" name="user_id" value="<?=$u['id']?>"><button class="delete-button" <?=$u['id']==$user['id']?'disabled':''?>>Eliminar</button></form></td></tr><?php endforeach; ?></tbody></table></div><div class="admin-columns"><section><div class="section-heading"><h2>PEDIDOS <em>RECIENTES</em></h2></div><?php foreach($orders as $o): ?><article class="admin-entry"><div><strong>Pedido #<?=$o['id']?></strong><p><?=e($o['username'])?> · <?=e($o['created_at'])?></p><form method="post" action="index.php?p=panel" onsubmit="return confirm('¿Eliminar pedido?')"><?=csrf()?><input type="hidden" name="action" value="delete_order"><input type="hidden" name="order_id" value="<?=$o['id']?>"><button class="delete-button">Eliminar pedido</button></form></div><span><?=price($o['total_cents'])?></span></article><?php endforeach; ?></section><section><div class="section-heading"><h2>MENSAJES <em>RECIENTES</em></h2></div><?php foreach($inquiries as $i): ?><details class="admin-entry inquiry"><summary><strong><?=e($i['subject'])?> · <?=e($i['name'])?></strong><small><?=e($i['created_at'])?></small></summary><a href="mailto:<?=e($i['email'])?>"><?=e($i['email'])?></a><p><?=nl2br(e($i['message']))?></p><form method="post" action="index.php?p=panel" onsubmit="return confirm('¿Eliminar este mensaje?')"><?=csrf()?><input type="hidden" name="action" value="delete_inquiry"><input type="hidden" name="inquiry_id" value="<?=$i['id']?>"><button class="delete-button">Eliminar mensaje</button></form></details><?php endforeach; ?></section></div></section><?php break;
case 'productos':
    $query=trim($_GET['q']??''); $rows=$query?q('SELECT * FROM products WHERE name LIKE ? OR description LIKE ? ORDER BY id DESC',['%'.$query.'%','%'.$query.'%'])->fetchAll():q('SELECT * FROM products ORDER BY id DESC')->fetchAll(); ?><section class="page-hero admin-hero"><p class="eyebrow">GESTIÓN DE TIENDA</p><h1>PRODUCTOS<br><em>Y MERCH.</em></h1><p>Crea productos, actualiza existencias y decide cuáles aparecen en la tienda.</p><a class="button button-outline" href="index.php?p=panel">← Volver al panel</a></section><section class="section product-admin"><div class="section-heading"><h2>AÑADIR A <em>LA TIENDA</em></h2></div><?php product_form(null); ?><div class="section-heading existing-products-heading"><h2>ARTÍCULOS <em>ACTUALES</em></h2></div><form class="product-search" method="get"><input type="hidden" name="p" value="productos"><label for="sq">Buscar producto</label><div><input id="sq" name="q" type="search" value="<?=e($query)?>" placeholder="Nombre o descripción"><button class="button button-red">Buscar ⌕</button><?php if($query): ?><a class="button button-outline" href="index.php?p=productos">Limpiar</a><?php endif; ?></div></form><div class="managed-products"><?php foreach($rows as $p): ?><article class="managed-product"><div class="managed-product-art"><img src="<?=e(image_path($p['image_url']))?>" alt="<?=e($p['name'])?>"><span class="visibility <?=$p['active']?'published':'hidden'?>"><?=$p['active']?'Publicado':'Oculto'?></span></div><?php product_form($p,$query); ?><form class="delete-product-form" method="post" action="index.php?p=productos" onsubmit="return confirm('¿Eliminar este producto?')"><?=csrf()?><input type="hidden" name="action" value="delete"><input type="hidden" name="product_id" value="<?=$p['id']?>"><input type="hidden" name="return_q" value="<?=e($query)?>"><button class="delete-button">Eliminar producto</button></form></article><?php endforeach; ?><?php if(!$rows): ?><p class="empty-state">No se encontraron productos.</p><?php endif; ?></div></section><?php break;
default: http_response_code(404); ?><section class="page-hero"><h1>PÁGINA NO<br><em>ENCONTRADA.</em></h1><a href="index.php">Volver al inicio</a></section><?php
}
$content=ob_get_clean(); $titles=['inicio'=>'Inicio','musica'=>'Música','merch'=>'Tienda oficial','contacto'=>'Contacto y booking','login'=>'Iniciar sesión','registro'=>'Crear cuenta','forgot'=>'Recuperar contraseña','reset'=>'Nueva contraseña','panel'=>'Panel de control','productos'=>'Gestionar tienda']; layout($titles[$page]??'MAX720RP',$content,$user);

function render_songs(array $songs): void { ?><div class="song-grid <?=$GLOBALS['page']==='musica'?'song-grid-all':''?>"><?php foreach($songs as $i=>$s): $href=$s['youtube_url'] ?: 'https://www.youtube.com/@max720rp'; ?><article class="song-card"><a class="song-video-link" href="<?=e($href)?>" target="_blank" rel="noopener"><div class="cover-art"><img class="cover-thumbnail" src="<?=e($s['cover_url']?:asset('max720rp-logo.png'))?>" alt="Miniatura de <?=e($s['title'])?>"><span class="cover-index">TOP <?=((int)$s['view_rank']<1000?(int)$s['view_rank']:$i+1)?></span><span class="cover-title"><?=e($s['title'])?></span><span class="thumbnail-play">▶</span></div></a><div class="song-info"><div><h3><?=e($s['title'])?></h3><p><?=e($s['description'])?></p></div><a class="play-button" href="<?=e($href)?>" target="_blank" rel="noopener" aria-label="Escuchar">▶</a></div></article><?php endforeach; if(!$songs): ?><p class="empty-state">Todavía no hay lanzamientos publicados.</p><?php endif; ?></div><?php }
function render_products(array $products,bool $stock): void { ?><div class="product-grid"><?php foreach($products as $p): ?><article class="product-card"><div class="product-image product-placeholder"><img class="shop-product-image" src="<?=e(image_path($p['image_url']))?>" alt="<?=e($p['name'])?>"><?php if($stock): ?><small>QUEDAN <?=$p['stock']?></small><?php endif; ?></div><div class="product-info"><div><h3><?=e($p['name'])?></h3><p><?=e($p['description'])?></p></div><strong><?=price($p['price_cents'])?></strong></div><form method="post" action="index.php?p=carrito"><?=csrf()?><input type="hidden" name="product_id" value="<?=$p['id']?>"><button class="button button-outline button-wide" <?=$p['stock']<1?'disabled':''?>>Añadir al carrito ＋</button></form></article><?php endforeach; if(!$products): ?><p class="empty-state">No hay productos disponibles ahora mismo.</p><?php endif; ?></div><?php }
function product_form(?array $p,string $query=''): void { ?><form class="product-edit-form" method="post" action="index.php?p=productos" enctype="multipart/form-data"><?=csrf()?><input type="hidden" name="product_id" value="<?=e($p['id']??'')?>"><input type="hidden" name="return_q" value="<?=e($query)?>"><div class="product-fields"><label>Nombre<input name="name" required maxlength="120" value="<?=e($p['name']??'')?>" placeholder="Camiseta MAX720RP"></label><label>Precio (€)<input name="price" type="number" step="0.01" min="0" required value="<?=isset($p['price_cents'])?number_format($p['price_cents']/100,2,'.',''):''?>"></label><label>Existencias<input name="stock" type="number" min="0" required value="<?=e($p['stock']??0)?>"></label><label>Visibilidad<select name="active"><option value="1" <?=!isset($p['active'])||$p['active']?'selected':''?>>Publicado · visible</option><option value="0" <?=isset($p['active'])&&!$p['active']?'selected':''?>>Oculto · borrador</option></select></label><label class="wide-field">Descripción<textarea name="description" maxlength="1000" rows="2"><?=e($p['description']??'')?></textarea></label><label class="wide-field"><?=$p?'Cambiar imagen':'Imagen del producto'?><input name="image" type="file" accept="image/png,image/jpeg,image/webp"><small><?=$p?'Deja vacío para conservar la imagen actual.':'PNG, JPG o WebP · máximo 8 MB'?></small></label></div><button class="button <?=$p?'button-outline':'button-red'?>"><?=$p?'Guardar cambios':'Crear producto'?></button></form><?php }

