<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'create_job') {
        $name = preg_replace('/[^a-zA-Z0-9_\-]/', '', $_POST['name'] ?? '');
        $path = $_POST['path'] ?? '';
        $schedule = in_array($_POST['schedule'] ?? '', ['daily', 'weekly'], true) ? $_POST['schedule'] : 'daily';
        if ($name && is_dir($path)) {
            $stmt = db()->prepare('INSERT INTO backup_jobs (name, path, schedule) VALUES (?, ?, ?)');
            $stmt->execute([$name, $path, $schedule]);
            flash("Задание бэкапа {$name} создано");
        } else {
            flash('Проверьте имя и путь', 'error');
        }
    }

    if ($action === 'run_now') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = db()->prepare('SELECT * FROM backup_jobs WHERE id = ?');
        $stmt->execute([$id]);
        $job = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($job) {
            if (!is_dir(BACKUP_DIR)) run('sudo mkdir -p ' . escapeshellarg(BACKUP_DIR));
            $file = BACKUP_DIR . '/' . $job['name'] . '_' . date('Ymd_His') . '.tar.gz';
            run('sudo tar -czf ' . escapeshellarg($file) . ' -C ' . escapeshellarg(dirname($job['path'])) . ' ' . escapeshellarg(basename($job['path'])));
            log_action("Бэкап {$job['name']} выполнен");
            flash("Бэкап создан: " . basename($file));
        }
    }

    if ($action === 'delete_job') {
        db()->prepare('DELETE FROM backup_jobs WHERE id = ?')->execute([(int)($_POST['id'] ?? 0)]);
        flash('Задание удалено');
    }

    if ($action === 'delete_file') {
        $f = basename($_POST['file'] ?? '');
        if ($f) { run('sudo rm -f ' . escapeshellarg(BACKUP_DIR . '/' . $f)); flash('Файл удалён'); }
    }

    header('Location: /?page=backups');
    exit;
}

$jobs = db()->query('SELECT * FROM backup_jobs ORDER BY id DESC')->fetchAll(PDO::FETCH_ASSOC);
$files = is_dir(BACKUP_DIR) ? array_diff(scandir(BACKUP_DIR), ['.', '..']) : [];
?>
<h1>Резервное копирование</h1>

<div class="card">
  <h2>Новое задание</h2>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="action" value="create_job">
    <label>Название</label>
    <input type="text" name="name" required>
    <label>Путь (например /var/www/example.com)</label>
    <input type="text" name="path" required>
    <label>Периодичность</label>
    <select name="schedule"><option value="daily">Ежедневно</option><option value="weekly">Еженедельно</option></select>
    <button class="btn" type="submit">+ Добавить</button>
  </form>
</div>

<div class="card">
  <h2>Задания</h2>
  <table>
    <tr><th>Имя</th><th>Путь</th><th>Период</th><th>Действия</th></tr>
    <?php foreach ($jobs as $j): ?>
      <tr>
        <td><?= h($j['name']) ?></td>
        <td><?= h($j['path']) ?></td>
        <td><?= h($j['schedule']) ?></td>
        <td>
          <form method="post" style="display:inline">
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="run_now">
            <input type="hidden" name="id" value="<?= (int)$j['id'] ?>">
            <button class="btn secondary" type="submit">Создать бэкап сейчас</button>
          </form>
          <form method="post" style="display:inline">
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="delete_job">
            <input type="hidden" name="id" value="<?= (int)$j['id'] ?>">
            <button class="btn danger" type="submit">Удалить</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
  </table>
</div>

<div class="card">
  <h2>Список бэкапов</h2>
  <table>
    <tr><th>Файл</th><th>Размер</th><th>Действия</th></tr>
    <?php foreach ($files as $f): $full = BACKUP_DIR . '/' . $f; ?>
      <tr>
        <td><?= h($f) ?></td>
        <td><?= round(filesize($full) / 1024 / 1024, 2) ?> МБ</td>
        <td>
          <form method="post" style="display:inline" onsubmit="return confirm('Удалить файл?')">
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="delete_file">
            <input type="hidden" name="file" value="<?= h($f) ?>">
            <button class="btn danger" type="submit">Удалить</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$files): ?><tr><td colspan="3">Бэкапов пока нет</td></tr><?php endif; ?>
  </table>
</div>
