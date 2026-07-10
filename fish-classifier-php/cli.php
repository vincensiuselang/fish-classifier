<?php
/** CLI inference (pengganti `python -m src.predict`). Usage: php cli.php path/gambar.jpg */
if ($argc < 2) { fwrite(STDERR, "Usage: php cli.php <path-gambar> [size]\n"); exit(1); }
$cfg = require __DIR__ . '/config.php';
if (isset($argv[2])) $cfg['img_size'] = (int)$argv[2];
require_once __DIR__ . '/src/Classifier.php';
$clf = new Classifier($cfg);
$bytes = file_get_contents($argv[1]);
if ($bytes === false) { fwrite(STDERR, "Gagal baca file: {$argv[1]}\n"); exit(1); }
$r = $clf->predictBytes($bytes);
printf("\nPrediksi: %s (%.2f%%)  [%d ms, %dpx]\n", $r['prediction'], $r['confidence']*100, $r['elapsed_ms'], $r['input_size']);
echo "Top-" . count($r['top_k']) . ":\n";
foreach ($r['top_k'] as $t) printf("  %-12s %.2f%%\n", $t['class'], $t['confidence']*100);
