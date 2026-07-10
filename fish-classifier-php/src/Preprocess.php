<?php
/**
 * Preprocessing gambar pakai GD, meniru torchvision val transform:
 *   Resize((224,224)) bilinear -> ToTensor (0..1, CHW) -> Normalize(ImageNet mean/std)
 * Output: array float datar layout CHW, index = ((c*H)+y)*W+x
 */
class Preprocess
{
    public int $size;
    private array $mean = [0.485, 0.456, 0.406];
    private array $std  = [0.229, 0.224, 0.225];

    public function __construct(int $size = 224) { $this->size = $size; }

    public function fromString(string $bytes): array
    {
        $im = @imagecreatefromstring($bytes);
        if ($im === false) throw new RuntimeException("Gagal membaca gambar (format tidak didukung?).");
        return $this->run($im);
    }

    public function fromFile(string $path): array
    {
        $bytes = file_get_contents($path);
        if ($bytes === false) throw new RuntimeException("Gagal membuka file: $path");
        return $this->fromString($bytes);
    }

    private function run($im): array
    {
        $S = $this->size;
        $dst = imagecreatetruecolor($S, $S);
        imagecopyresampled($dst, $im, 0, 0, 0, 0, $S, $S, imagesx($im), imagesy($im));
        imagedestroy($im);

        $n = $S * $S;
        $out = array_fill(0, 3 * $n, 0.0);
        $mr = $this->mean[0]; $mg = $this->mean[1]; $mb = $this->mean[2];
        $sr = $this->std[0];  $sg = $this->std[1];  $sb = $this->std[2];
        $offG = $n; $offB = 2 * $n;
        for ($y = 0; $y < $S; $y++) {
            $row = $y * $S;
            for ($x = 0; $x < $S; $x++) {
                $rgb = imagecolorat($dst, $x, $y);
                $r = (($rgb >> 16) & 0xFF) / 255.0;
                $g = (($rgb >> 8) & 0xFF) / 255.0;
                $b = ($rgb & 0xFF) / 255.0;
                $i = $row + $x;
                $out[$i]        = ($r - $mr) / $sr;
                $out[$offG + $i] = ($g - $mg) / $sg;
                $out[$offB + $i] = ($b - $mb) / $sb;
            }
        }
        imagedestroy($dst);
        return $out;
    }
}
