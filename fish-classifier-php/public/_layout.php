<?php
/**
 * Partial layout dipakai semua halaman.
 * layout_header($title, $active) -> buka <html> + topbar/nav
 * layout_footer()               -> status bar + tutup </html>
 * Butuh Auth.php sudah di-require oleh halaman pemanggil.
 */
declare(strict_types=1);

function e(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function layout_header(string $title, string $active = ''): void
{
    $u = Auth::user();
    ?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($title) ?> // FISH-CLF</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/style.css">
</head>
<body>
<div class="topbar">
  <div class="topbar-inner">
    <a class="brand" href="index.php">
      <span class="mark">FISH</span>-CLF<span class="blink">_</span>
    </a>
    <nav class="nav">
      <a href="index.php"   class="<?= $active==='predict'?'active':'' ?>">Prediksi</a>
      <a href="catalog.php" class="<?= $active==='catalog'?'active':'' ?>">Katalog</a>
      <?php if (Auth::check()): ?>
        <a href="history.php" class="<?= $active==='history'?'active':'' ?>">Riwayat</a>
        <?php if (Auth::isAdmin()): ?>
          <a href="catalog_manage.php" class="<?= $active==='manage'?'active':'' ?>">Kelola</a>
        <?php endif; ?>
        <span class="who">@<b><?= e($u['username']) ?></b><?php if (Auth::isAdmin()): ?><span class="tag-admin">ADMIN</span><?php endif; ?></span>
        <a href="logout.php">Keluar</a>
      <?php else: ?>
        <a href="login.php"    class="<?= $active==='login'?'active':'' ?>">Masuk</a>
        <a href="register.php" class="<?= $active==='register'?'active':'' ?>">Daftar</a>
      <?php endif; ?>
    </nav>
  </div>
</div>
<div class="wrap">
<?php
}

function layout_footer(): void
{
    $on = Auth::check();
    ?>
</div>
<div class="statusbar">
  <div class="statusbar-inner">
    <span>SYS: <b>ONLINE</b></span>
    <span>ENGINE: <b>PURE-PHP CNN</b></span>
    <span>AUTH: <b><?= $on ? 'SESSION_ACTIVE' : 'GUEST' ?></b></span>
    <span class="push">FISH-CLF v2 // UAS PEMROGRAMAN WEB</span>
  </div>
</div>
</body>
</html>
<?php
}
