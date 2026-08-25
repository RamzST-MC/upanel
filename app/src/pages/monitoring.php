<?php
$services = ['nginx', 'mysql', 'ssh', 'ufw', 'postfix', 'dovecot', 'fail2ban', 'cron'];
[$phpFpmOut] = run("ls /etc/php 2>/dev/null");
foreach (array_filter(explode("\n", trim($phpFpmOut))) as $v) {
    array_unshift($services, 'php' . $v . '-fpm');
}
[$diskOut] = run("df -h | grep -E '^/dev'");
?>
<h1>Мониторинг сервисов</h1>

<div class="card">
  <h2>Службы</h2>
  <table>
    <tr><th>Служба</th><th>Статус</th></tr>
    <?php foreach ($services as $svc):
        [$out] = run('systemctl is-active ' . escapeshellarg($svc) . ' 2>/dev/null');
        $active = trim($out) === 'active';
    ?>
      <tr>
        <td><?= h($svc) ?></td>
        <td><span class="status-dot <?= $active ? 'status-on' : 'status-off' ?>"></span><?= $active ? 'Работает' : 'Остановлен' ?></td>
      </tr>
    <?php endforeach; ?>
  </table>
</div>

<div class="card">
  <h2>Дисковое пространство</h2>
  <pre class="term" style="height:auto;color:#ccc"><?= h($diskOut) ?></pre>
</div>
