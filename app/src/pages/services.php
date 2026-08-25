<?php
$services = [
    'nginx' => 'Веб-сервер Nginx',
    'mysql' => 'MySQL/MariaDB',
    'ssh' => 'SSH сервер',
    'ufw' => 'Firewall (UFW)',
    'postfix' => 'Почта (Postfix)',
    'dovecot' => 'Почта (Dovecot)',
    'fail2ban' => 'Fail2ban',
    'cron' => 'Планировщик CRON',
];
[$phpFpmOut] = run("ls /etc/php 2>/dev/null");
foreach (array_filter(explode("\n", trim($phpFpmOut))) as $v) {
    $services = ['php' . $v . '-fpm' => "PHP-FPM {$v}"] + $services;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $svc = $_POST['service'] ?? '';
    $act = $_POST['do'] ?? '';
    if (isset($services[$svc]) && in_array($act, ['start', 'stop', 'restart'], true)) {
        run('sudo systemctl ' . escapeshellarg($act) . ' ' . escapeshellarg($svc));
        log_action("systemctl {$act} {$svc}");
        flash("Служба {$svc}: {$act} выполнено");
    }
    header('Location: /?page=services');
    exit;
}
?>
<h1>Управление сервером</h1>
<div class="card">
  <table>
    <tr><th>Служба</th><th>Описание</th><th>Статус</th><th>Управление</th></tr>
    <?php foreach ($services as $svc => $desc):
        [$out] = run('systemctl is-active ' . escapeshellarg($svc) . ' 2>/dev/null');
        $active = trim($out) === 'active';
    ?>
      <tr>
        <td><?= h($svc) ?></td>
        <td><?= h($desc) ?></td>
        <td><span class="status-dot <?= $active ? 'status-on' : 'status-off' ?>"></span><?= $active ? 'Работает' : 'Остановлен' ?></td>
        <td>
          <form method="post" style="display:inline">
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="service" value="<?= h($svc) ?>">
            <button class="btn secondary" name="do" value="start">Старт</button>
            <button class="btn secondary" name="do" value="restart">Рестарт</button>
            <button class="btn danger" name="do" value="stop">Стоп</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
  </table>
</div>
