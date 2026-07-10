<?php
/**
 * Auto-migrasi database (dipanggil docker-entrypoint saat container start).
 * Idempotent: kalau tabel 'users' sudah ada, langsung selesai.
 *
 * Exit code:
 *   0  = sukses / sudah ter-migrasi
 *   1  = gagal konek DB (entrypoint akan retry)
 */
declare(strict_types=1);

$cfg = require __DIR__ . '/../config.php';
$db  = $cfg['db'];
$schema = __DIR__ . '/../database/fish_classifier.sql';

$opt = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];

// 1) Konek ke server (tanpa dbname) buat pastiin database ada (lokal/XAMPP).
try {
    $root = new PDO(
        sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $db['host'], $db['port']),
        $db['user'], $db['pass'], $opt
    );
    // Bikin database kalau belum ada (di-skip diam2 kalau user gak punya izin, mis. Railway).
    try {
        $root->exec("CREATE DATABASE IF NOT EXISTS `{$db['name']}`
                     CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    } catch (PDOException $e) { /* Railway: DB sudah disediakan, abaikan */ }
} catch (PDOException $e) {
    fwrite(STDERR, "[migrate] gagal konek server MySQL: " . $e->getMessage() . "\n");
    exit(1);
}

// 2) Konek ke database target.
try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $db['host'], $db['port'], $db['name']),
        $db['user'], $db['pass'], $opt
    );
} catch (PDOException $e) {
    fwrite(STDERR, "[migrate] gagal konek database '{$db['name']}': " . $e->getMessage() . "\n");
    exit(1);
}

// 3) Sudah ter-migrasi? (tabel users ada)
try {
    $exists = $pdo->query("SHOW TABLES LIKE 'users'")->fetch();
    if ($exists) {
        echo "[migrate] sudah ter-migrasi, dilewati.\n";
        exit(0);
    }
} catch (PDOException $e) { /* lanjut migrasi */ }

// 4) Jalankan skema (buang CREATE DATABASE / USE karena DB sudah dipilih di DSN).
if (!is_file($schema)) {
    fwrite(STDERR, "[migrate] file skema tidak ditemukan: $schema\n");
    exit(1);
}
$raw = file_get_contents($schema);

// Buang komentar baris (-- ...) dulu, baru pecah per-statement (pisah ';').
$noComments = [];
foreach (preg_split('/\r?\n/', $raw) as $l) {
    $t = trim($l);
    if ($t === '' || str_starts_with($t, '--')) continue;
    $noComments[] = $l;
}
$statements = array_filter(array_map('trim', explode(';', implode("\n", $noComments))));

try {
    foreach ($statements as $stmt) {
        // Lewati CREATE DATABASE / USE (database sudah dipilih lewat DSN).
        if (stripos($stmt, 'CREATE DATABASE') === 0) continue;
        if (stripos($stmt, 'USE ') === 0)            continue;
        $pdo->exec($stmt);
    }
    echo "[migrate] skema + data awal berhasil diimport.\n";
    exit(0);
} catch (PDOException $e) {
    fwrite(STDERR, "[migrate] gagal import skema: " . $e->getMessage() . "\n");
    exit(1);
}
