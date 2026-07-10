<?php
/**
 * Endpoint API: POST gambar -> JSON prediksi.
 * Mirror /predict dari versi FastAPI lama.
 *   - field file: gambar (jpg/png/webp/gif)
 *   - return: {prediction, confidence, top_k[], model, input_size, elapsed_ms}
 */
declare(strict_types=1);
session_start(); // buat ambil user_id kalau login (riwayat prediksi per user)
@set_time_limit(120);
ini_set('memory_limit', '512M');

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

function fail(int $code, string $msg): void {
    http_response_code($code);
    echo json_encode(['error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fail(405, 'Pakai metode POST dengan field "file".');
}

$cfg = require __DIR__ . '/../config.php';

if (!isset($_FILES['file'])) {
    fail(400, 'Tidak ada file. Upload gambar di field "file".');
}
$f = $_FILES['file'];
if ($f['error'] !== UPLOAD_ERR_OK) {
    fail(400, 'Upload gagal (kode error: ' . $f['error'] . '). Mungkin file kebesaran.');
}
if ($f['size'] > $cfg['max_upload_mb'] * 1024 * 1024) {
    fail(400, 'File kebesaran (maks ' . $cfg['max_upload_mb'] . ' MB).');
}

$bytes = file_get_contents($f['tmp_name']);
if ($bytes === false || $bytes === '') {
    fail(400, 'File kosong / gagal dibaca.');
}
// validasi benar-benar gambar
$info = @getimagesizefromstring($bytes);
if ($info === false) {
    fail(400, 'File harus berupa gambar (jpg/png/webp/gif).');
}

$engine = $cfg['infer_engine'] ?? 'python';
$mime   = $info['mime'] ?? 'image/jpeg';
$fname  = (string)($f['name'] ?? 'upload.jpg');

try {
    if ($engine === 'python') {
        // CEPAT: tembak ke FastAPI EfficientNet.
        require_once __DIR__ . '/../src/RemoteClassifier.php';
        $clf = new RemoteClassifier($cfg);
        $result = $clf->predictBytes($bytes, $fname, $mime);
    } else {
        require_once __DIR__ . '/../src/Classifier.php';
        $clf = new Classifier($cfg);
        $result = $clf->predictBytes($bytes);
        $result['engine'] = 'php';
    }
} catch (Throwable $e) {
    // Kalau Python API mati & fallback diizinkan -> pakai PHP (lambat tapi gak mati total).
    if ($engine === 'python' && !empty($cfg['py_fallback_php'])) {
        try {
            require_once __DIR__ . '/../src/Classifier.php';
            $clf = new Classifier($cfg);
            $result = $clf->predictBytes($bytes);
            $result['engine'] = 'php-fallback';
            $result['note']   = 'Python API mati, pakai PHP (lambat). Nyalain: uvicorn app.backend.main:app --port 8000';
        } catch (Throwable $e2) {
            fail(500, 'Inference gagal: ' . $e2->getMessage());
        }
    } else {
        fail(503, 'Python API tidak jalan (' . $e->getMessage() . '). Nyalain: uvicorn app.backend.main:app --port 8000');
    }
}

// ===== Log riwayat ke database (best-effort: DB mati != prediksi gagal) =====
$result['saved_to_db'] = false;
try {
    require_once __DIR__ . '/../src/Database.php';
    $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
    Database::logPrediction($cfg, $result, (string)($f['name'] ?? 'unknown'), $userId);
    $result['saved_to_db'] = true;
} catch (Throwable $e) {
    // Sengaja di-ignore: user tetap dapat hasil prediksi walau MySQL belum nyala.
}

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
