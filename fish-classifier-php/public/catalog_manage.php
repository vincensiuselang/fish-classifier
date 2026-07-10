<?php
/**
 * Kelola Katalog Ikan - CRUD penuh (ADMIN only).
 *   CREATE / UPDATE / DELETE. READ publik ada di catalog.php
 */
declare(strict_types=1);
require_once __DIR__ . '/../src/Auth.php';
Auth::boot();
Auth::requireAdmin();

$cfg = require __DIR__ . '/../config.php';
require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/_layout.php';

$flash = ['type' => '', 'msg' => ''];

/* ---------- proses aksi ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    if (!Auth::csrfCheck($_POST['csrf'] ?? null)) {
        header('Location: catalog_manage.php?err=' . urlencode('Sesi kadaluarsa, coba lagi.')); exit;
    }

    $name    = trim((string)($_POST['name'] ?? ''));
    $sci     = trim((string)($_POST['scientific_name'] ?? ''));
    $habitat = trim((string)($_POST['habitat'] ?? ''));
    $desc    = trim((string)($_POST['description'] ?? ''));
    $weightR = trim((string)($_POST['avg_weight_kg'] ?? ''));
    $weight  = ($weightR === '') ? null : (float)$weightR;
    $id      = (int)($_POST['id'] ?? 0);

    try {
        $pdo = Database::pdo($cfg);

        if ($action === 'delete') {
            $pdo->prepare('DELETE FROM fish_catalog WHERE id = ?')->execute([$id]);
            header('Location: catalog_manage.php?ok=' . urlencode('Jenis ikan #' . $id . ' dihapus.')); exit;
        }

        if ($action === 'create' || $action === 'update') {
            if ($name === '') {
                header('Location: catalog_manage.php?err=' . urlencode('Nama ikan wajib diisi.')); exit;
            }
            if ($action === 'create') {
                $stmt = $pdo->prepare(
                    'INSERT INTO fish_catalog (name, scientific_name, habitat, description, avg_weight_kg, created_by)
                     VALUES (?, ?, ?, ?, ?, ?)'
                );
                $stmt->execute([$name, $sci ?: null, $habitat ?: null, $desc ?: null, $weight, Auth::id()]);
                header('Location: catalog_manage.php?ok=' . urlencode('Jenis ikan "' . $name . '" ditambahkan.')); exit;
            } else {
                $stmt = $pdo->prepare(
                    'UPDATE fish_catalog SET name = ?, scientific_name = ?, habitat = ?, description = ?, avg_weight_kg = ?
                     WHERE id = ?'
                );
                $stmt->execute([$name, $sci ?: null, $habitat ?: null, $desc ?: null, $weight, $id]);
                header('Location: catalog_manage.php?ok=' . urlencode('Jenis ikan #' . $id . ' diperbarui.')); exit;
            }
        }
    } catch (PDOException $e) {
        $msg = ($e->getCode() === '23000')
            ? 'Nama ikan sudah ada (harus unik).'
            : 'Database error.';
        header('Location: catalog_manage.php?err=' . urlencode($msg)); exit;
    }
    header('Location: catalog_manage.php'); exit;
}

if (isset($_GET['ok']))  $flash = ['type' => 'ok',  'msg' => (string)$_GET['ok']];
if (isset($_GET['err'])) $flash = ['type' => 'bad', 'msg' => (string)$_GET['err']];

/* ---------- read ---------- */
$dbError = '';
$rows = [];
$editRow = null;
$editId = (int)($_GET['edit'] ?? 0);
try {
    $pdo  = Database::pdo($cfg);
    $rows = $pdo->query(
        'SELECT id, name, scientific_name, habitat, description, avg_weight_kg, updated_at
         FROM fish_catalog ORDER BY name ASC'
    )->fetchAll();
    foreach ($rows as $r) { if ((int)$r['id'] === $editId) $editRow = $r; }
} catch (PDOException $e) {
    $dbError = 'Database error. Pastikan MySQL nyala & fish_classifier.sql sudah di-import.';
}

$isEdit = $editRow !== null;
$csrf = Auth::csrfToken();
layout_header('Kelola Katalog', 'manage');
?>
<h1 class="page-title">Kelola Katalog</h1>
<p class="page-sub">Admin panel // tambah, edit, hapus jenis ikan. Tampilan publiknya di <a href="catalog.php">Katalog</a>.</p>

<?php if ($flash['msg']): ?>
  <div class="alert <?= $flash['type']==='ok'?'ok':'bad' ?>"><?= $flash['type']==='ok'?'ok: ':'! ' ?><?= e($flash['msg']) ?></div>
<?php endif; ?>
<?php if ($dbError): ?>
  <div class="alert bad">! <?= e($dbError) ?></div>
<?php endif; ?>

<div class="panel" id="form">
  <div class="panel-head">
    <span><span class="dot"></span><?= $isEdit ? 'UPDATE // RECORD #'.(int)$editRow['id'] : 'CREATE // JENIS IKAN BARU' ?></span>
    <span><?= $isEdit ? '[edit]' : '[new]' ?></span>
  </div>
  <form method="post" action="catalog_manage.php">
    <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
    <input type="hidden" name="action" value="<?= $isEdit ? 'update' : 'create' ?>">
    <?php if ($isEdit): ?><input type="hidden" name="id" value="<?= (int)$editRow['id'] ?>"><?php endif; ?>
    <div class="grid-2">
      <label class="field">
        <span>Nama Ikan *</span>
        <input type="text" name="name" maxlength="50" required value="<?= e($editRow['name'] ?? '') ?>">
      </label>
      <label class="field">
        <span>Nama Ilmiah</span>
        <input type="text" name="scientific_name" maxlength="100" value="<?= e($editRow['scientific_name'] ?? '') ?>">
      </label>
      <label class="field">
        <span>Habitat</span>
        <input type="text" name="habitat" maxlength="100" value="<?= e($editRow['habitat'] ?? '') ?>">
      </label>
      <label class="field">
        <span>Berat Rata-rata (kg)</span>
        <input type="number" step="0.01" min="0" name="avg_weight_kg" value="<?= e($editRow['avg_weight_kg'] ?? '') ?>">
      </label>
    </div>
    <label class="field">
      <span>Deskripsi</span>
      <textarea name="description" maxlength="1000"><?= e($editRow['description'] ?? '') ?></textarea>
    </label>
    <div class="btn-row">
      <button class="btn <?= $isEdit?'blue':'' ?>" type="submit">&gt; <?= $isEdit ? 'Simpan Perubahan' : 'Tambah Ikan' ?></button>
      <?php if ($isEdit): ?><a class="btn ghost sm" href="catalog_manage.php">Batal Edit</a><?php endif; ?>
    </div>
  </form>
</div>

<div class="panel">
  <div class="panel-head"><span><span class="dot"></span>DATA // <?= count($rows) ?> JENIS</span><span>fish_catalog</span></div>
  <?php if (!$rows && !$dbError): ?>
    <div class="empty">Katalog kosong. Tambah lewat form di atas.</div>
  <?php elseif ($rows): ?>
    <div class="table-scroll">
      <table class="grid">
        <thead>
          <tr><th>#</th><th>Nama</th><th>Ilmiah</th><th>Habitat</th><th>Berat</th><th>Update</th><th>Aksi</th></tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td class="num"><?= (int)$r['id'] ?></td>
            <td><b><?= e($r['name']) ?></b></td>
            <td><i><?= $r['scientific_name'] ? e($r['scientific_name']) : '<span class="mono-note">—</span>' ?></i></td>
            <td><?= $r['habitat'] ? e($r['habitat']) : '<span class="mono-note">—</span>' ?></td>
            <td><?= $r['avg_weight_kg'] !== null ? e(rtrim(rtrim(number_format((float)$r['avg_weight_kg'],2),'0'),'.')).' kg' : '<span class="mono-note">—</span>' ?></td>
            <td class="mono-note"><?= e($r['updated_at']) ?></td>
            <td>
              <div class="btn-row">
                <a class="btn ghost sm" href="catalog_manage.php?edit=<?= (int)$r['id'] ?>#form">Edit</a>
                <form method="post" action="catalog_manage.php" onsubmit="return confirm('Hapus <?= e($r['name']) ?>?');" style="display:inline">
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
