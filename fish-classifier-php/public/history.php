<?php
/**
 * Riwayat Prediksi - CRUD (wajib login).
 *   READ   : daftar prediksi milik user
 *   UPDATE : edit label (koreksi kelas) + catatan
 *   DELETE : hapus prediksi milik sendiri
 *   CREATE : lewat halaman Prediksi (index.php)
 */
declare(strict_types=1);
require_once __DIR__ . '/../src/Auth.php';
Auth::boot();
Auth::requireLogin();

$cfg = require __DIR__ . '/../config.php';
require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/_layout.php';

$uid   = Auth::id();
$flash = ['type' => '', 'msg' => ''];

/* ---------- proses aksi (POST) : Update / Delete ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    if (!Auth::csrfCheck($_POST['csrf'] ?? null)) {
        header('Location: history.php?err=' . urlencode('Sesi kadaluarsa, coba lagi.')); exit;
    }
    try {
        $pdo = Database::pdo($cfg);
        $id  = (int)($_POST['id'] ?? 0);

        if ($action === 'update') {
            $label = trim((string)($_POST['label'] ?? ''));
            $note  = trim((string)($_POST['note'] ?? ''));
            $stmt = $pdo->prepare(
                'UPDATE predictions SET label = ?, note = ? WHERE id = ? AND user_id = ?'
            );
            $stmt->execute([
                $label !== '' ? mb_substr($label, 0, 50) : null,
                $note  !== '' ? mb_substr($note, 0, 255) : null,
                $id, $uid,
            ]);
            header('Location: history.php?ok=' . urlencode('Data #' . $id . ' diperbarui.')); exit;
        }

        if ($action === 'delete') {
            $stmt = $pdo->prepare('DELETE FROM predictions WHERE id = ? AND user_id = ?');
            $stmt->execute([$id, $uid]);
            header('Location: history.php?ok=' . urlencode('Data #' . $id . ' dihapus.')); exit;
        }
    } catch (PDOException $e) {
        header('Location: history.php?err=' . urlencode('Database error.')); exit;
    }
    header('Location: history.php'); exit;
}

if (isset($_GET['ok']))  $flash = ['type' => 'ok',  'msg' => (string)$_GET['ok']];
if (isset($_GET['err'])) $flash = ['type' => 'bad', 'msg' => (string)$_GET['err']];

/* ---------- ambil data (READ) ---------- */
$dbError = '';
$items = [];
$editRow = null;
$editId = (int)($_GET['edit'] ?? 0);
try {
    $pdo  = Database::pdo($cfg);
    $stmt = $pdo->prepare(
        'SELECT id, image_name, predicted_class, confidence, top_k, label, note,
                model_name, input_size, elapsed_ms, created_at
         FROM predictions WHERE user_id = ?
         ORDER BY created_at DESC, id DESC LIMIT 100'
    );
    $stmt->execute([$uid]);
    $items = $stmt->fetchAll();
    foreach ($items as $r) { if ((int)$r['id'] === $editId) $editRow = $r; }
} catch (PDOException $e) {
    $dbError = 'Database error. Pastikan MySQL nyala & fish_classifier.sql sudah di-import.';
}

function conf_pill(float $c): string {
    if ($c >= 0.85) return 'ok';
    if ($c >= 0.60) return 'hi';
    return 'lo';
}

$csrf = Auth::csrfToken();
layout_header('Riwayat', 'history');
?>
<h1 class="page-title">Riwayat Prediksi</h1>
<p class="page-sub">Data prediksi milik <b>@<?= e(Auth::user()['username']) ?></b> // edit label &amp; catatan, atau hapus.</p>

<?php if ($flash['msg']): ?>
  <div class="alert <?= $flash['type']==='ok'?'ok':'bad' ?>"><?= $flash['type']==='ok'?'ok: ':'! ' ?><?= e($flash['msg']) ?></div>
<?php endif; ?>

<?php if ($dbError): ?>
  <div class="alert bad">! <?= e($dbError) ?></div>
<?php endif; ?>

<?php if ($editRow): ?>
  <div class="panel">
    <div class="panel-head"><span><span class="dot"></span>UPDATE // RECORD #<?= (int)$editRow['id'] ?></span><span>[edit]</span></div>
    <p class="mono-note">Gambar: <b><?= e($editRow['image_name']) ?></b> &middot; Prediksi model: <b><?= e($editRow['predicted_class']) ?></b> (<?= number_format((float)$editRow['confidence']*100,1) ?>%)</p>
    <form method="post" action="history.php">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
      <input type="hidden" name="action" value="update">
      <input type="hidden" name="id" value="<?= (int)$editRow['id'] ?>">
      <div class="grid-2">
        <label class="field">
          <span>Label / Koreksi Kelas</span>
          <select name="label">
            <option value="">— (pakai prediksi model) —</option>
            <?php foreach ($cfg['class_names'] as $c): ?>
              <option value="<?= e($c) ?>" <?= ($editRow['label']===$c)?'selected':'' ?>><?= e($c) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label class="field">
          <span>Catatan</span>
          <input type="text" name="note" maxlength="255" value="<?= e($editRow['note']) ?>" placeholder="mis. gambar buram / hasil meragukan">
        </label>
      </div>
      <div class="btn-row">
        <button class="btn blue" type="submit">&gt; Simpan Perubahan</button>
        <a class="btn ghost sm" href="history.php">Batal</a>
      </div>
    </form>
  </div>
<?php endif; ?>

<div class="panel">
  <div class="panel-head"><span><span class="dot"></span>RECORDS // <?= count($items) ?> ROW(S)</span><span><a href="index.php" style="color:#8dff9e">+ prediksi baru</a></span></div>

  <?php if (!$items && !$dbError): ?>
    <div class="empty">Belum ada riwayat. <a href="index.php">Coba prediksi gambar</a> dulu.</div>
  <?php elseif ($items): ?>
    <div class="table-scroll">
      <table class="grid">
        <thead>
          <tr>
            <th>#</th><th>Gambar</th><th>Prediksi</th><th>Conf</th>
            <th>Label</th><th>Catatan</th><th>Waktu</th><th>Aksi</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($items as $r):
            $c = (float)$r['confidence']; ?>
          <tr>
            <td class="num"><?= (int)$r['id'] ?></td>
            <td><?= e($r['image_name']) ?></td>
            <td><b><?= e($r['predicted_class']) ?></b></td>
            <td><span class="pill <?= conf_pill($c) ?>"><?= number_format($c*100,1) ?>%</span></td>
            <td><?= $r['label'] ? e($r['label']) : '<span class="mono-note">—</span>' ?></td>
            <td><?= $r['note'] ? e($r['note']) : '<span class="mono-note">—</span>' ?></td>
            <td class="mono-note"><?= e($r['created_at']) ?></td>
            <td>
              <div class="btn-row">
                <a class="btn ghost sm" href="history.php?edit=<?= (int)$r['id'] ?>#edit">Edit</a>
                <form method="post" action="history.php" onsubmit="return confirm('Hapus data #<?= (int)$r['id'] ?>?');" style="display:inline">
                  <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                  <button class="btn danger sm" type="submit">Hapus</button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>
<?php layout_footer(); ?>
