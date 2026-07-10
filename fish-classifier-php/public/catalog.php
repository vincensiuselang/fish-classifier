<?php
/**
 * Katalog Ikan - tampilan publik (READ, boleh guest).
 * CRUD-nya ada di catalog_manage.php (admin only).
 */
declare(strict_types=1);
require_once __DIR__ . '/../src/Auth.php';
Auth::boot();
$cfg = require __DIR__ . '/../config.php';
require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/_layout.php';

$dbError = '';
$rows = [];
try {
    $pdo  = Database::pdo($cfg);
    $rows = $pdo->query(
        'SELECT id, name, scientific_name, habitat, description, avg_weight_kg
         FROM fish_catalog ORDER BY name ASC'
    )->fetchAll();
} catch (PDOException $e) {
    $dbError = 'Database error. Pastikan MySQL nyala & fish_classifier.sql sudah di-import.';
}

layout_header('Katalog', 'catalog');
?>
<h1 class="page-title">Katalog Ikan</h1>
<p class="page-sub">Data master <?= count($rows) ?> jenis ikan yang dikenali model.<?php if (Auth::isAdmin()): ?> // <a href="catalog_manage.php">Kelola data →</a><?php endif; ?></p>

<?php if ($dbError): ?>
  <div class="alert bad">! <?= e($dbError) ?></div>
<?php endif; ?>

<?php if (!$rows && !$dbError): ?>
  <div class="empty">Katalog kosong.</div>
<?php endif; ?>

<?php foreach ($rows as $i => $r):
    $bc = ['b1','b2','b3','b4','b5'][$i % 5]; ?>
  <div class="panel">
    <div class="panel-head">
      <span><span class="dot"></span><?= e($r['name']) ?></span>
      <span><?= $r['avg_weight_kg'] !== null ? '~'.rtrim(rtrim(number_format((float)$r['avg_weight_kg'],2),'0'),'.').' kg' : '' ?></span>
    </div>
    <div class="badges" style="margin-bottom:10px">
      <?php if ($r['scientific_name']): ?><span class="badge <?= $bc ?>"><i><?= e($r['scientific_name']) ?></i></span><?php endif; ?>
      <?php if ($r['habitat']): ?><span class="badge">habitat: <?= e($r['habitat']) ?></span><?php endif; ?>
    </div>
    <p><?= e($r['description']) ?: '<span class="mono-note">Belum ada deskripsi.</span>' ?></p>
  </div>
<?php endforeach; ?>
<?php layout_footer(); ?>
