<?php
/** Endpoint: daftar kelas + info model. Mirror / dan /classes versi FastAPI. */
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json; charset=utf-8');
$cfg = require __DIR__ . '/../config.php';
echo json_encode([
    'service'       => 'Indonesian Fish Classifier (PHP)',
    'status'        => 'ok',
    'engine'        => 'pure-PHP CNN inference',
    'default_model' => $cfg['model_name'],
    'input_size'    => $cfg['img_size'],
    'classes'       => $cfg['class_names'],
    'num_classes'   => $cfg['num_classes'],
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
