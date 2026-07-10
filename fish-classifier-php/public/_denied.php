<?php require_once __DIR__ . '/_layout.php'; layout_header('403 Ditolak'); ?>
<h1 class="page-title">403 // Akses Ditolak</h1>
<p class="page-sub">Halaman ini khusus admin.</p>
<div class="panel">
  <div class="panel-head"><span><span class="dot"></span>ERROR</span><span>403</span></div>
  <p>Kamu login sebagai user biasa. Fitur <b>Kelola Katalog</b> cuma buat akun ber-role <b>admin</b>.</p>
  <div class="btn-row">
    <a class="btn ink" href="index.php">Kembali</a>
    <a class="btn ghost" href="catalog.php">Lihat Katalog</a>
  </div>
</div>
<?php layout_footer(); ?>
