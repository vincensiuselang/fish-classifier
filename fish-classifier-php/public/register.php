<?php
/**
 * Halaman Register (GET = form, POST = proses).
 * Sukses -> auto login -> redirect index.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../src/Auth.php';
Auth::boot();

$cfg = require __DIR__ . '/../config.php';
if (Auth::check()) { header('Location: index.php'); exit; }

$error = '';
$username = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string)($_POST['username'] ?? ''));
    $email    = trim((string)($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $confirm  = (string)($_POST['confirm'] ?? '');

    if ($username === '' || $email === '' || $password === '') {
        $error = 'Username, email, dan password wajib diisi.';
    } elseif (!preg_match('/^[a-zA-Z0-9_.]{3,50}$/', $username)) {
        $error = 'Username 3-50 karakter (huruf/angka/underscore/titik).';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Format email tidak valid.';
    } elseif (strlen($password) < 6) {
        $error = 'Password minimal 6 karakter.';
    } elseif ($password !== $confirm) {
        $error = 'Konfirmasi password tidak cocok.';
    } else {
        require_once __DIR__ . '/../src/Database.php';
        try {
            $pdo  = Database::pdo($cfg);
            $stmt = $pdo->prepare('SELECT id FROM users WHERE username = ? OR email = ? LIMIT 1');
            $stmt->execute([$username, $email]);
            if ($stmt->fetch()) {
                $error = 'Username atau email sudah terdaftar.';
            } else {
                $stmt = $pdo->prepare(
                    'INSERT INTO users (username, email, password_hash, role) VALUES (?, ?, ?, "user")'
                );
                $stmt->execute([$username, $email, password_hash($password, PASSWORD_BCRYPT)]);
                $id = (int)$pdo->lastInsertId();
                Auth::login(['id' => $id, 'username' => $username, 'role' => 'user']);
                header('Location: index.php');
                exit;
            }
        } catch (PDOException $e) {
            $error = 'Database error. Pastikan MySQL nyala & fish_classifier.sql sudah di-import.';
        }
    }
}

require_once __DIR__ . '/_layout.php';
layout_header('Daftar', 'register');
?>
<div class="auth-wrap">
  <h1 class="page-title">Daftar</h1>
  <p class="page-sub">Bikin akun baru buat nyimpan riwayat prediksimu.</p>

  <?php if ($error): ?>
    <div class="alert bad">! <?= e($error) ?></div>
  <?php endif; ?>

  <div class="panel">
    <div class="panel-head"><span><span class="dot"></span>AUTH // REGISTER</span><span>[new]</span></div>
    <form method="post" action="register.php">
      <label class="field">
        <span>Username</span>
        <input type="text" name="username" value="<?= e($username) ?>" required>
      </label>
      <label class="field">
        <span>Email</span>
        <input type="email" name="email" value="<?= e($email) ?>" required>
      </label>
      <div class="grid-2">
        <label class="field">
          <span>Password</span>
          <input type="password" name="password" required>
        </label>
        <label class="field">
          <span>Ulangi Password</span>
          <input type="password" name="confirm" required>
        </label>
      </div>
      <div class="btn-row">
        <button class="btn blue" type="submit">&gt; Daftar</button>
        <a class="btn ghost sm" href="index.php">Batal</a>
      </div>
    </form>
  </div>
  <p class="auth-switch">Sudah punya akun? <a href="login.php">Masuk di sini</a></p>
</div>
<?php layout_footer(); ?>
