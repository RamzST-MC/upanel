<?php
[$installedOut] = run("ls /etc/php 2>/dev/null");
$installed = array_filter(explode("\n", trim($installedOut)));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $ver = preg_replace('/[^0-9.]/', '', $_POST['version'] ?? '');
    $action = $_POST['action'] ?? '';
    if ($ver && $action === 'install') {
        run('sudo add-apt-repository -y ppa:ondrej/php 2>&1');
        run('sudo apt-get update -y 2>&1');
        run('sudo apt-get install -y php' . escapeshellarg($ver) . '-fpm php' . escapeshellarg($ver) . '-mysql php' . escapeshellarg($ver) . '-curl php' . escapeshellarg($ver) . '-gd php' . escapeshellarg($ver) . '-mbstring php' . escapeshellarg($ver) . '-xml 2>&1');
        run('sudo systemctl enable --now php' . escapeshellarg($ver) . '-fpm');
        flash("PHP {$ver} установлен");
    }
    header('Location: /?page=php');
    exit;
}
?>
<h1>Версии PHP</h1>
<div class="card">
  <h2>Установленные версии</h2>
  <table>
    <tr><th>Версия</th><th>Статус FPM</th></tr>
    <?php foreach ($installed as $v):
        [$out] = run('systemctl is-active php' . escapeshellarg($v) . '-fpm 2>/dev/null');
        $active = trim($out) === 'active';
    ?>
      <tr><td><?= h($v) ?></td><td><span class="status-dot <?= $active ? 'status-on' : 'status-off' ?>"></span><?= $active ? 'Работает' : 'Остановлен' ?></td></tr>
    <?php endforeach; ?>
  </table>
</div>
<div class="card">
  <h2>Установить версию PHP</h2>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="action" value="install">
    <label>Версия (напр. 8.1, 8.2, 8.3)</label>
    <input type="text" name="version" placeholder="8.3" required>
    <button class="btn" type="submit">Установить (может занять пару минут)</button>
  </form>
</div>
