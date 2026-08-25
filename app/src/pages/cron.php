<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    [$cur] = run('sudo crontab -l 2>/dev/null');
    $lines = $cur ? explode("\n", rtrim($cur, "\n")) : [];

    if ($action === 'add') {
        $schedule = trim($_POST['schedule'] ?? '');
        $cmd = trim($_POST['command'] ?? '');
        if ($schedule && $cmd) {
            $lines[] = $schedule . ' ' . $cmd;
            flash('Задание добавлено');
        }
    }
    if ($action === 'delete') {
        $idx = (int)($_POST['idx'] ?? -1);
        if (isset($lines[$idx])) { unset($lines[$idx]); flash('Задание удалено'); }
    }

    $tmp = tempnam(sys_get_temp_dir(), 'cron');
    file_put_contents($tmp, implode("\n", array_values(array_filter($lines))) . "\n");
    run('sudo crontab ' . escapeshellarg($tmp));
    unlink($tmp);

    header('Location: /?page=cron');
    exit;
}

[$cronOut] = run('sudo crontab -l 2>/dev/null');
$lines = $cronOut ? explode("\n", trim($cronOut)) : [];
?>
<h1>CRON</h1>

<div class="card">
  <h2>Добавить задание</h2>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="action" value="add">
    <label>Расписание (cron-выражение, напр. "0 3 * * *")</label>
    <input type="text" name="schedule" placeholder="0 3 * * *" required>
    <label>Команда</label>
    <input type="text" name="command" placeholder="/usr/bin/php /var/www/example.com/cron.php" required>
    <button class="btn" type="submit">+ Добавить</button>
  </form>
</div>

<div class="card">
  <h2>Текущие задания</h2>
  <table>
    <tr><th>#</th><th>Строка</th><th></th></tr>
    <?php foreach ($lines as $i => $l): if (trim($l) === '') continue; ?>
      <tr>
        <td><?= $i ?></td><td><code><?= h($l) ?></code></td>
        <td>
          <form method="post"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="action" value="delete"><input type="hidden" name="idx" value="<?= $i ?>">
          <button class="btn danger" type="submit">Удалить</button></form>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$lines): ?><tr><td colspan="3">Заданий нет</td></tr><?php endif; ?>
  </table>
</div>
