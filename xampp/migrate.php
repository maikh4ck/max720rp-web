<?php
declare(strict_types=1);
// Importación única y transaccional de ../max720rp.db a la BD MariaDB configurada.
// Ejecutar desde la consola XAMPP: php migrate.php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$cfg = require __DIR__ . '/config.php';
$sourcePath = $argv[1] ?? (dirname(__DIR__) . '/max720rp.db');
try {
    if (!is_file($sourcePath)) throw new RuntimeException('No se encuentra el SQLite de origen: ' . $sourcePath);
    $src = new PDO('sqlite:' . $sourcePath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $dst = new PDO($cfg['dsn'], $cfg['db_user'], $cfg['db_password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
    foreach (preg_split('/;\s*(?:\r?\n|$)/', file_get_contents(__DIR__ . '/schema.sql')) as $sql) {
        if (trim($sql) !== '') $dst->exec($sql);
    }
    $tables = ['users','songs','products','orders','order_items','inquiries','password_resets','app_settings'];
    $dst->beginTransaction();
    $dst->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach ($tables as $table) {
        $rows = $src->query("SELECT * FROM \"$table\"")->fetchAll(PDO::FETCH_ASSOC);
        $dst->exec("DELETE FROM `$table`");
        if (!$rows) continue;
        $cols = array_keys($rows[0]);
        $colSql = implode(',', array_map(fn($c) => '`' . str_replace('`','',$c) . '`', $cols));
        $marks = implode(',', array_fill(0, count($cols), '?'));
        $stmt = $dst->prepare("INSERT INTO `$table` ($colSql) VALUES ($marks)");
        foreach ($rows as $row) $stmt->execute(array_values($row));
        // MariaDB ajusta AUTO_INCREMENT automáticamente al insertar IDs explícitos.
        // Evitamos ALTER TABLE dentro de la transacción porque provoca un commit implícito.
    }
    $dst->exec('SET FOREIGN_KEY_CHECKS=1');
    $dst->commit();
    echo "Migración completada. SQLite original permanece intacta.\n";
} catch (Throwable $e) {
    if (isset($dst) && $dst->inTransaction()) $dst->rollBack();
    fwrite(STDERR, "No se pudo migrar: " . $e->getMessage() . "\n");
    exit(1);
}

