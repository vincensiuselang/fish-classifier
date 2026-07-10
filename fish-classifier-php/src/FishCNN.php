<?php
/**
 * Forward pass FishCNN di PHP murni — versi DINAMIS.
 *
 * Arsitektur TIDAK lagi hardcoded: dibaca otomatis dari manifest weights (.json).
 * Asumsi pattern model (berlaku buat v1 4-blok maupun v2 5-blok double-conv):
 *   features   : rangkaian Conv3x3(pad=1) + BatchNorm + ReLU, dengan MaxPool2d(2) di antara blok
 *   global pool: Global Average Pooling
 *   classifier : rangkaian Linear dengan ReLU di antaranya
 *
 * Deteksi otomatis:
 *   - Conv  = param 'features.N.weight' dengan shape 4 dimensi [outC, inC, 3, 3]
 *   - BN    = 'features.(N+1).*' (selalu tepat setelah conv)
 *   - Pool  = ada jika jarak index conv berikutnya > (bn_index + 2); conv terakhir selalu diikuti pool
 *   - Linear= param 'classifier.N.weight' dengan shape 2 dimensi [out, in]
 *
 * BatchNorm di-fold ke conv saat load. Conv pakai tap-based accumulation + input padding.
 * Feature map = array float datar, index = ((c*H)+y)*W+x.
 */
require_once __DIR__ . '/WeightLoader.php';

class FishCNN
{
    private WeightLoader $w;
    private array $convs = [];   // tiap entry: ['w','b','inC','outC','pool']
    private array $linears = []; // tiap entry: ['w','b','in','out','relu']
    public array $idxToClass;

    public function __construct(WeightLoader $w)
    {
        $this->w = $w;
        $this->idxToClass = $w->classMap();

        [$convDefs, $linearDefs] = $this->detectArchitecture($w);
        foreach ($convDefs as $d) $this->foldBlock($d);
        foreach ($linearDefs as $d) {
            $this->linears[] = [
                'w' => $w->get($d['key'] . '.weight'),
                'b' => $w->get($d['key'] . '.bias'),
                'in' => $d['in'], 'out' => $d['out'], 'relu' => $d['relu'],
            ];
        }
    }

    /**
     * Scan manifest: temukan semua conv (features.*) + linear (classifier.*)
     * dan tentukan posisi MaxPool dari gap index di nn.Sequential.
     */
    private function detectArchitecture(WeightLoader $w): array
    {
        $convIdx = [];   // index Sequential -> shape
        $fcIdx = [];
        foreach ($w->paramNames() as $name) {
            if (preg_match('/^features\.(\d+)\.weight$/', $name, $m)) {
                $shape = $w->shape($name);
                if (count($shape) === 4) $convIdx[(int)$m[1]] = $shape;
            } elseif (preg_match('/^classifier\.(\d+)\.weight$/', $name, $m)) {
                $shape = $w->shape($name);
                if (count($shape) === 2) $fcIdx[(int)$m[1]] = $shape;
            }
        }
        ksort($convIdx); ksort($fcIdx);
        if (!$convIdx || !$fcIdx) {
            throw new RuntimeException('Manifest tidak berisi layer features/classifier yang dikenali.');
        }

        $convDefs = [];
        $indices = array_keys($convIdx);
        foreach ($indices as $i => $n) {
            $shape = $convIdx[$n];
            $bnIdx = $n + 1; // BatchNorm selalu tepat setelah conv
            // Pool ada kalau conv berikutnya bukan langsung setelah ReLU (gap > 2),
            // atau ini conv terakhir (model selalu tutup features dengan pool).
            $isLast = ($i === count($indices) - 1);
            $pool = $isLast || ($indices[$i + 1] - $bnIdx > 2);
            $convDefs[] = [
                'conv' => "features.$n",
                'bn'   => "features.$bnIdx",
                'inC'  => $shape[1],
                'outC' => $shape[0],
                'pool' => $pool,
            ];
        }

        $linearDefs = [];
        $fcKeys = array_keys($fcIdx);
        foreach ($fcKeys as $i => $n) {
            $shape = $fcIdx[$n];
            $linearDefs[] = [
                'key'  => "classifier.$n",
                'in'   => $shape[1],
                'out'  => $shape[0],
                'relu' => ($i < count($fcKeys) - 1), // ReLU di semua kecuali output layer
            ];
        }
        return [$convDefs, $linearDefs];
    }

    private function foldBlock(array $b): void
    {
        $eps = 1e-5;
        $cw = $this->w->get($b['conv'] . '.weight');
        $cb = $this->w->get($b['conv'] . '.bias');
        $g  = $this->w->get($b['bn'] . '.weight');
        $be = $this->w->get($b['bn'] . '.bias');
        $rm = $this->w->get($b['bn'] . '.running_mean');
        $rv = $this->w->get($b['bn'] . '.running_var');
        $outC = $b['outC']; $inC = $b['inC']; $ksz = $inC * 9;
        $foldedW = $cw; $foldedB = [];
        for ($o = 0; $o < $outC; $o++) {
            $scale = $g[$o] / sqrt($rv[$o] + $eps);
            $base = $o * $ksz;
            for ($k = 0; $k < $ksz; $k++) $foldedW[$base + $k] = $cw[$base + $k] * $scale;
            $foldedB[$o] = ($cb[$o] - $rm[$o]) * $scale + $be[$o];
        }
        $this->convs[] = [
            'w' => $foldedW, 'b' => $foldedB,
            'inC' => $inC, 'outC' => $outC, 'pool' => $b['pool'],
        ];
    }

    /**
     * Conv 3x3 pad=1 stride=1 (BN folded). Tap-based: pad input sekali, lalu untuk tiap
     * (outChannel, inChannel, tap) lakukan plane-add dengan inner loop minimal.
     */
    private function conv(array $x, int $inC, int $H, int $Wd, array $cw, array $cb, int $outC): array
    {
        $HW = $H * $Wd;
        $Hp = $H + 2; $Wp = $Wd + 2; $HWp = $Hp * $Wp;
        // ---- pad semua input channel sekali (zero pad 1) ----
        $pad = array_fill(0, $inC * $HWp, 0.0);
        for ($c = 0; $c < $inC; $c++) {
            $src = $c * $HW; $dst = $c * $HWp + $Wp + 1; // mulai di (1,1)
            for ($y = 0; $y < $H; $y++) {
                $s = $src + $y * $Wd; $d = $dst + $y * $Wp;
                for ($xx = 0; $xx < $Wd; $xx++) $pad[$d + $xx] = $x[$s + $xx];
            }
        }
        $ksz = $inC * 9;
        $out = array_fill(0, $outC * $HW, 0.0);
        for ($o = 0; $o < $outC; $o++) {
            // init acc dengan bias
            $acc = array_fill(0, $HW, $cb[$o]);
            $wb = $o * $ksz;
            for ($c = 0; $c < $inC; $c++) {
                $pc = $c * $HWp;
                $wc = $wb + $c * 9;
                for ($ky = 0; $ky < 3; $ky++) {
                    for ($kx = 0; $kx < 3; $kx++) {
                        $wv = $cw[$wc + $ky * 3 + $kx];
                        if ($wv == 0.0) continue;
                        // input index utk output (oy,ox) = padded(oy+ky, ox+kx)
                        for ($oy = 0; $oy < $H; $oy++) {
                            $ib = $pc + ($oy + $ky) * $Wp + $kx;
                            $ob = $oy * $Wd;
                            for ($ox = 0; $ox < $Wd; $ox++) {
                                $acc[$ob + $ox] += $wv * $pad[$ib + $ox];
                            }
                        }
                    }
                }
            }
            // tulis acc (sekaligus ReLU di sini biar hemat satu pass)
            $ob = $o * $HW;
            for ($i = 0; $i < $HW; $i++) { $v = $acc[$i]; $out[$ob + $i] = $v > 0 ? $v : 0.0; }
        }
        return $out;
    }

    private function maxpool(array $x, int $C, int $H, int $Wd): array
    {
        $H2 = intdiv($H, 2); $W2 = intdiv($Wd, 2);
        $out = array_fill(0, $C * $H2 * $W2, 0.0);
        $HW = $H * $Wd; $HW2 = $H2 * $W2;
        for ($c = 0; $c < $C; $c++) {
            $ib = $c * $HW; $ob = $c * $HW2;
            for ($y = 0; $y < $H2; $y++) {
                $r0 = $ib + 2 * $y * $Wd; $r1 = $r0 + $Wd; $orow = $ob + $y * $W2;
                for ($x2 = 0; $x2 < $W2; $x2++) {
                    $ix = 2 * $x2;
                    $a = $x[$r0 + $ix]; $b = $x[$r0 + $ix + 1];
                    $cc = $x[$r1 + $ix]; $d = $x[$r1 + $ix + 1];
                    $m = $a > $b ? $a : $b; if ($cc > $m) $m = $cc; if ($d > $m) $m = $d;
                    $out[$orow + $x2] = $m;
                }
            }
        }
        return $out;
    }

    private function gap(array $x, int $C, int $H, int $Wd): array
    {
        $HW = $H * $Wd; $out = array_fill(0, $C, 0.0);
        for ($c = 0; $c < $C; $c++) {
            $b = $c * $HW; $s = 0.0;
            for ($i = 0; $i < $HW; $i++) $s += $x[$b + $i];
            $out[$c] = $s / $HW;
        }
        return $out;
    }

    private function linear(array $x, array $W, array $b, int $inN, int $outN): array
    {
        $out = array_fill(0, $outN, 0.0);
        for ($o = 0; $o < $outN; $o++) {
            $wb = $o * $inN; $s = $b[$o];
            for ($i = 0; $i < $inN; $i++) $s += $W[$wb + $i] * $x[$i];
            $out[$o] = $s;
        }
        return $out;
    }

    /** Forward dari tensor CHW. ReLU sudah dilakukan di dalam conv. Return logits. */
    public function forward(array $x, int $size = 224): array
    {
        $H = $size; $Wd = $size;
        $lastC = 0;
        foreach ($this->convs as $cv) {
            $x = $this->conv($x, $cv['inC'], $H, $Wd, $cv['w'], $cv['b'], $cv['outC']);
            $lastC = $cv['outC'];
            if ($cv['pool']) {
                $x = $this->maxpool($x, $lastC, $H, $Wd);
                $H = intdiv($H, 2); $Wd = intdiv($Wd, 2);
            }
        }
        $x = $this->gap($x, $lastC, $H, $Wd);
        foreach ($this->linears as $fc) {
            $x = $this->linear($x, $fc['w'], $fc['b'], $fc['in'], $fc['out']);
            if ($fc['relu']) {
                for ($i = 0; $i < $fc['out']; $i++) if ($x[$i] < 0) $x[$i] = 0.0;
            }
        }
        return $x;
    }

    public static function softmax(array $z): array
    {
        $m = max($z); $e = []; $s = 0.0;
        foreach ($z as $v) { $ev = exp($v - $m); $e[] = $ev; $s += $ev; }
        foreach ($e as $i => $v) $e[$i] = $v / $s;
        return $e;
    }
}
