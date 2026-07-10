<?php
/**
 * High-level classifier: gabungin WeightLoader + Preprocess + FishCNN jadi satu API.
 */
require_once __DIR__ . '/WeightLoader.php';
require_once __DIR__ . '/Preprocess.php';
require_once __DIR__ . '/FishCNN.php';

class Classifier
{
    private FishCNN $net;
    private Preprocess $pre;
    private array $cfg;

    public function __construct(array $cfg)
    {
        $this->cfg = $cfg;
        $w = new WeightLoader($cfg['weights_json'], $cfg['weights_bin']);
        $this->net = new FishCNN($w);
        $this->pre = new Preprocess($cfg['img_size']);
    }

    public function classes(): array { return $this->net->idxToClass; }

    /**
     * Prediksi dari byte gambar mentah. Return array siap di-JSON-kan.
     */
    public function predictBytes(string $bytes): array
    {
        $t0 = microtime(true);
        $x = $this->pre->fromString($bytes);
        $logits = $this->net->forward($x, $this->cfg['img_size']);
        $probs = FishCNN::softmax($logits);

        $idxToClass = $this->net->idxToClass;
        $pairs = [];
        foreach ($probs as $i => $p) {
            $pairs[] = ['class' => $idxToClass[$i], 'confidence' => $p];
        }
        usort($pairs, fn($a, $b) => $b['confidence'] <=> $a['confidence']);
        $topk = array_slice($pairs, 0, $this->cfg['top_k']);

        return [
            'prediction' => $topk[0]['class'],
            'confidence' => $topk[0]['confidence'],
            'top_k'      => $topk,
            'model'      => $this->cfg['model_name'],
            'input_size' => $this->cfg['img_size'],
            'elapsed_ms' => (int)round((microtime(true) - $t0) * 1000),
        ];
    }
}
