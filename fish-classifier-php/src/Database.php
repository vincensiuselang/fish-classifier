<?php
/**
 * Koneksi PDO singleton ke MySQL (XAMPP).
 * Import database/fish_classifier.sql via phpMyAdmin sebelum dipakai.
 */
class Database
{
    private static ?PDO $pdo = null;

    public static function pdo(array $cfg): PDO
    {
        if (self::$pdo === null) {
            $db = $cfg['db'];
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                $db['host'], $db['port'], $db['name']
            );
            self::$pdo = new PDO($dsn, $db['user'], $db['pass'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        }
        return self::$pdo;
    }

    /** Simpan hasil prediksi ke tabel predictions. */
    public static function logPrediction(array $cfg, array $result, string $imageName, ?int $userId): void
    {
        $pdo = self::pdo($cfg);
        $stmt = $pdo->prepare(
            'INSERT INTO predictions
                (user_id, image_name, predicted_class, confidence, top_k, model_name, input_size, elapsed_ms)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $userId,
            mb_substr($imageName, 0, 255),
            $result['prediction'],
            round((float)$result['confidence'], 5),
            json_encode($result['top_k'], JSON_UNESCAPED_UNICODE),
            $result['model'],
            $result['input_size'],
            $result['elapsed_ms'],
        ]);
    }
}
