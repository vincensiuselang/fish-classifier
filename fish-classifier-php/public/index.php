<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/Auth.php';
Auth::boot();
$cfg = require __DIR__ . '/../config.php';
require_once __DIR__ . '/_layout.php';
layout_header('Prediksi', 'predict');
$badgeClass = ['b1','b2','b3','b4','b5'];
?>
<h1 class="page-title">Klasifikasi Ikan</h1>
<p class="page-sub">CNN inference 100% PHP // upload gambar ikan, model nebak jenisnya + confidence top-3.</p>

<div class="badges" style="margin-bottom:22px">
  <?php foreach ($cfg['class_names'] as $i => $c): ?>
    <span class="badge <?= $badgeClass[$i % 5] ?>"><?= e($c) ?></span>
  <?php endforeach; ?>
</div>

<?php if (!Auth::check()): ?>
  <div class="alert info">
    Kamu jalan sebagai <b>guest</b>. Prediksi tetap bisa, tapi <a href="login.php">masuk</a> dulu
    kalau mau hasilnya kesimpan di <b>Riwayat</b> dan bisa di-edit/hapus.
  </div>
<?php endif; ?>

<div class="panel">
  <div class="panel-head"><span><span class="dot"></span>INPUT // UPLOAD IMAGE</span><span>max <?= (int)$cfg['max_upload_mb'] ?>MB</span></div>

  <div id="drop" class="drop">
    <input type="file" id="file" accept="image/*" hidden>
    <div id="dropInner">
      <div class="drop-icon">[ + ]</div>
      <p><strong>Klik</strong> atau <strong>seret</strong> gambar ikan ke sini</p>
      <div class="hint">JPG / PNG / WEBP &middot; maks <?= (int)$cfg['max_upload_mb'] ?> MB</div>
    </div>
    <img id="preview" alt="preview" hidden>
  </div>

  <div class="btn-row" style="margin-top:16px">
    <button id="btn" class="btn" disabled>&gt; Prediksi Sekarang</button>
    <button id="reset" class="btn ghost sm" type="button" hidden>Reset</button>
  </div>

  <div id="status" class="status" hidden></div>
</div>

<div id="result" class="panel result" hidden>
  <div class="panel-head"><span><span class="dot"></span>OUTPUT // PREDICTION</span><span id="savedTag"></span></div>
  <div class="top">
    <div class="top-label">Prediksi</div>
    <div class="top-class" id="topClass">&mdash;</div>
    <div class="top-conf" id="topConf"></div>
  </div>
  <div class="bars" id="bars"></div>
  <div class="meta" id="meta"></div>
</div>

<script src="assets/app.js"></script>
<?php layout_footer(); ?>
