<?php
/**
 * Halaman Login (GET = form, POST = proses).
 * Sukses -> session di-set, redirect ke ?next atau index.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../src/Auth.php';
Auth::boot();

$cfg  = require __DIR__ . '/../config.php';
$next = isset($_GET['next']) ? (string)$_GET['next'] : 'index.php';
if (str_starts_with($next, 'http') || !str_ends_with($next, '.php')) $next = 'index.php';

if (Auth::check()) { header('Location: index.php'); exit; }

$error = '';
$identity = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $identity = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    if ($identity === '' || $password === '') {
        $error = 'Username/email dan password wajib diisi.';
    } else {
        require_once __DIR__ . '/../src/Database.php';
        try {
            $pdo  = Database::pdo($cfg);
            $stmt = $pdo->prepare(
                'SELECT id, username, email, password_hash, role FROM users
                 WHERE username = ? OR email = ? LIMIT 1'
            );
            $stmt->execute([$identity, $identity]);
            $user = $stmt->fetch();

            if (!$user || !password_verify($password, $user['password_hash'])) {
                $error = 'Username atau password salah.';
            } else {
                Auth::login($user);
                header('Location: ' . $next);
                exit;
            }
        } catch (PDOException $e) {
            $error = 'Database error. Pastikan MySQL nyala & fish_classifier.sql sudah di-import.';
        }
    }
}

require_once __DIR__ . '/_layout.php';
layout_header('Masuk', 'login');
?>
<div class="auth-wrap">
  <h1 class="page-title">Masuk</h1>
  <p class="page-sub">Login buat akses riwayat prediksi & fitur kelola.</p>

  <?php if ($error): ?>
    <div class="alert bad">! <?= e($error) ?></div>
  <?php endif; ?>

  <div class="panel">
    <div class="panel-head"><span><span class="dot"></span>AUTH // LOGIN</span><span>[secure]</span></div>
    <form method="post" action="login.php?next=<?= e(urlencode($next)) ?>">
      <label class="field">
        <span>Username / Email</span>
        <input type="text" name="username" value="<?= e($identity) ?>" autofocus required>
      </label>
      <label class="field">
        <span>Password</span>
        <input type="password" name="password" required>
      </label>
      <div class="btn-row">
        <button class="btn" type="submit">&gt; Masuk</button>
        <a class="btn ghost sm" href="index.php">Batal</a>
      </div>
    </form>
  </div>

  <div class="alert info">
    <b>Akun demo:</b> admin / admin123 (admin) &middot; demo / demo123 (user)
  </div>
  <p class="auth-switch">Belum punya akun? <a href="register.php">Daftar di sini</a></p>
</div>
<?php layout_footer(); ?>
