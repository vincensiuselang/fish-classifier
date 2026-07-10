<?php
/**
 * Inference lewat FastAPI Python (EfficientNet) — jauh lebih cepat & akurat
 * daripada CNN scratch PHP murni. predict.php nembak ke sini via cURL,
 * server-to-server (bukan dari browser), jadi gak ada isu CORS.
 *
 * Return array-nya SAMA persis bentuknya kayak Classifier::predictBytes(),
 * jadi predict.php & frontend gak perlu diubah.
 */
class RemoteClassifier
{
    private array $cfg;

    public function __construct(array $cfg)
    {
        $this->cfg = $cfg;
    }

    /**
     * @param string $bytes    isi file gambar mentah
     * @param string $filename nama file asli (buat multipart)
     * @param string $mime     mime type asli (WAJIB image/*, FastAPI ngecek ini)
     */
    public function predictBytes(string $bytes, string $filename = 'upload.jpg', string $mime = 'image/jpeg'): array
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('Ekstensi php_curl belum aktif di PHP kamu.');
        }

        $t0  = microtime(true);
        $base = rtrim($this->cfg['python_api'], '/');
        $url  = $base . '/predict?model=' . rawurlencode($this->cfg['py_model']);

        // FastAPI baca UploadFile -> harus multipart/form-data field "file".
        $tmp = tempnam(sys_get_temp_dir(), 'fish');
        file_put_contents($tmp, $bytes);
        $cfile = new CURLFile($tmp, $mime, $filename);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => ['file' => $cfile],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 30,
        ]);
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cerr = curl_error($ch);
        curl_close($ch);
        @unlink($tmp);

        if ($resp === false) {
            throw new RuntimeException('Gagal konek ke Python API (' . $cerr . ').');
        }
        $data = json_decode($resp, true);
        if (!is_array($data)) {
            throw new RuntimeException('Respons Python API tidak valid.');
        }
        if ($code >= 400) {
            throw new RuntimeException($data['detail'] ?? ('Python API error HTTP ' . $code));
        }

        // Map ke bentuk yang dipakai frontend/DB PHP.
        return [
            'prediction' => $data['prediction'] ?? ($data['raw_prediction'] ?? '-'),
            'confidence' => (float)($data['confidence'] ?? 0),
            'top_k'      => $data['top_k'] ?? [],
            'model'      => ($data['model'] ?? 'transfer') . ' (EfficientNet)',
            'input_size' => 224,
            'elapsed_ms' => (int)round((microtime(true) - $t0) * 1000),
            'engine'     => 'python',
        ];
    }
}
