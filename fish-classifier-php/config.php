<?php
/**
 * Konfigurasi terpusat.
 *
 * DB otomatis menyesuaikan lingkungan:
 *   - LOKAL (XAMPP)  -> pakai default root tanpa password.
 *   - RAILWAY / cloud -> baca dari environment variable:
 *       * DATABASE_URL atau MYSQL_URL  (format: mysql://user:pass@host:port/dbname)
 *       * atau MYSQLHOST/MYSQLPORT/MYSQLUSER/MYSQLPASSWORD/MYSQLDATABASE
 *       * atau DB_HOST/DB_PORT/DB_USER/DB_PASSWORD/DB_NAME
 * Tidak perlu edit file ini saat deploy — cukup set variable di dashboard.
 */

/** Ambil env var pertama yang ada isinya. */
$env = static function (array $keys, $default = null) {
    foreach ($keys as $k) {
        $v = getenv($k);
        if ($v !== false && $v !== '') return $v;
    }
    return $default;
};

// Default lokal (XAMPP)
$db = [
    'host' => '127.0.0.1',
    'port' => 3306,
    'name' => 'fish_classifier',
    'user' => 'root',
    'pass' => '',
];

// 1) Kalau ada URL koneksi (Railway ngasih MYSQL_URL / DATABASE_URL) -> parse.
$url = $env(['DATABASE_URL', 'MYSQL_URL', 'JAWSDB_URL', 'CLEARDB_DATABASE_URL']);
if ($url) {
    $p = parse_url($url);
    if ($p !== false) {
        $db['host'] = $p['host'] ?? $db['host'];
        $db['port'] = $p['port'] ?? 3306;
        $db['user'] = isset($p['user']) ? rawurldecode($p['user']) : $db['user'];
        $db['pass'] = isset($p['pass']) ? rawurldecode($p['pass']) : $db['pass'];
        $db['name'] = isset($p['path']) ? ltrim($p['path'], '/') : $db['name'];
    }
}

// 2) Variable per-field menimpa (Railway MYSQL* atau DB_* generik).
$db['host'] = $env(['MYSQLHOST', 'DB_HOST'], $db['host']);
$db['port'] = (int)$env(['MYSQLPORT', 'DB_PORT'], $db['port']);
$db['name'] = $env(['MYSQLDATABASE', 'DB_NAME'], $db['name']);
$db['user'] = $env(['MYSQLUSER', 'DB_USER'], $db['user']);
$db['pass'] = $env(['MYSQLPASSWORD', 'DB_PASSWORD'], $db['pass']);

return [
    'class_names' => ['Bawal Putih', 'Nila', 'Pari', 'Tongkol', 'Tuna'],
    'num_classes' => 5,
    // Resolusi inference. 128 = cepat (~5s), 160 = seimbang, 224 = paling akurat.
    'img_size'    => (int)$env(['FISH_IMG_SIZE'], 128),
    'top_k'       => 3,
    'model_name'  => 'cnn_scratch',
    'weights_json'=> __DIR__ . '/weights/cnn_scratch.json',
    'weights_bin' => __DIR__ . '/weights/cnn_scratch.bin',
    'max_upload_mb' => 10,

    // ===== INFERENCE ENGINE =====
    // 'python' = tembak ke FastAPI EfficientNet (CEPAT ~0.2s + akurat). Default.
    // 'php'    = hitung CNN scratch di PHP murni (lambat ~30s; buat offline tanpa Python).
    'infer_engine'    => $env(['FISH_INFER'], 'python'),
    'python_api'      => $env(['FISH_PY_API'], 'http://127.0.0.1:8000'),
    'py_model'        => $env(['FISH_PY_MODEL'], 'transfer'),
    'py_fallback_php' => true,   // Python API mati -> otomatis balik ke PHP biar gak error total

    'db' => $db,
];
