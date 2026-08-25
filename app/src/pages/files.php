<?php
$root = SITES_ROOT;
$path = $_GET['path'] ?? $root;
$path = realpath($path) ?: $root;
if (strpos($path, $root) !== 0) $path = $root; // не даём выйти из /var/www

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    $cur = realpath($_POST['cur'] ?? $root) ?: $root;
    if (strpos($cur, $root) !== 0) $cur = $root;

    if ($action === 'mkdir') {
        $name = basename($_POST['name'] ?? '');
        if ($name) { @mkdir($cur . '/' . $name, 0755); flash('Папка создана'); }
    }
    if ($action === 'delete') {
        $target = realpath($_POST['target'] ?? '');
        if ($target && strpos($target, $root) === 0) {
            if (is_dir($target)) run('rm -rf ' . escapeshellarg($target));
            else @unlink($target);
            flash('Удалено');
        }
    }
    if ($action === 'upload' && !empty($_FILES['file']['tmp_name'])) {
        $dest = $cur . '/' . basename($_FILES['file']['name']);
        move_uploaded_file($_FILES['file']['tmp_name'], $dest);
        flash('Файл загружен');
    }
    header('Location: /?page=files&path=' . urlencode($cur));
    exit;
}

$items = @scandir($path) ?: [];
$parent = dirname($path);
?>
<h1>Файловый менеджер</h1>
<div class="card">
  <p>Путь: <code><?= h($path) ?></code>
    <?php if (strpos($parent, $root) === 0 || $parent === $root): ?>
      — <a href="/?page=files&path=<?= urlencode($parent) ?>">⬅ вверх</a>
    <?php endif; ?>
  </p>

  <table>
    <tr><th>Имя</th><th>Тип</th><th>Размер</th><th>Действия</th></tr>
    <?php foreach ($items as $item):
        if ($item === '.' || $item === '..') continue;
        $full = $path . '/' . $item;
        $isDir = is_dir($full);
    ?>
      <tr>
        <td><?= $isDir ? '📁' : '📄' ?>
          <?php if ($isDir): ?>
            <a href="/?page=files&path=<?= urlencode($full) ?>"><?= h($item) ?></a>
          <?php else: ?>
            <?= h($item) ?>
          <?php endif; ?>
        </td>
        <td><?= $isDir ? 'Папка' : 'Файл' ?></td>
        <td><?= $isDir ? '—' : h((string)filesize($full)) . ' B' ?></td>
        <td>
          <form method="post" style="display:inline" onsubmit="return confirm('Удалить <?= h($item) ?>?')">
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="cur" value="<?= h($path) ?>">
            <input type="hidden" name="target" value="<?= h($full) ?>">
            <button class="btn danger" type="submit">Удалить</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
  </table>
</div>

<div class="card">
  <h2>Новая папка</h2>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="action" value="mkdir">
    <input type="hidden" name="cur" value="<?= h($path) ?>">
    <input type="text" name="name" placeholder="имя папки" required>
    <button class="btn secondary" type="submit">Создать</button>
  </form>
</div>

<div class="card">
  <h2>Загрузить файл</h2>
  <form method="post" enctype="multipart/form-data">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="action" value="upload">
    <input type="hidden" name="cur" value="<?= h($path) ?>">
    <input type="file" name="file" required>
    <button class="btn secondary" type="submit">Загрузить</button>
  </form>
</div>
