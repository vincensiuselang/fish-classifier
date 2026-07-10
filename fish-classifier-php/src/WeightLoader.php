<?php
/**
 * Load bobot model dari file biner (.bin) + manifest (.json).
 * Format: semua param disimpan float32 little-endian, offset & shape ada di manifest.
 * Tidak ada dependensi eksternal. Murni PHP.
 */
class WeightLoader
{
    private array $manifest;
    private string $binPath;
    private array $cache = [];

    public function __construct(string $jsonPath, string $binPath)
    {
        if (!is_file($jsonPath) || !is_file($binPath)) {
            throw new RuntimeException("File bobot tidak ditemukan. Pastikan weights/cnn_scratch.json & .bin ada.");
        }
        $this->manifest = json_decode(file_get_contents($jsonPath), true);
        $this->binPath  = $binPath;
    }

    public function classMap(): array { return $this->manifest['idx_to_class']; }
    public function valAcc(): float { return (float)($this->manifest['val_acc'] ?? 0); }

    public function shape(string $name): array { return $this->manifest['params'][$name]['shape']; }

    /** Semua nama param sesuai urutan di manifest (urutan state_dict PyTorch). */
    public function paramNames(): array { return array_keys($this->manifest['params']); }

    public function has(string $name): bool { return isset($this->manifest['params'][$name]); }

    /** Ambil param sebagai array float datar (flat). */
    public function get(string $name): array
    {
        if (isset($this->cache[$name])) return $this->cache[$name];
        if (!isset($this->manifest['params'][$name])) {
            throw new RuntimeException("Param '$name' tidak ada di manifest.");
        }
        $p = $this->manifest['params'][$name];
        $fh = fopen($this->binPath, 'rb');
        fseek($fh, $p['offset']);
        $raw = fread($fh, $p['count'] * 4);
        fclose($fh);
        // 'g' = float 32-bit little-endian
        $vals = array_values(unpack('g*', $raw));
        $this->cache[$name] = $vals;
        return $vals;
    }
}
