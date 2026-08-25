<?php
[$out] = run('systemctl list-units --type=service --state=running 2>/dev/null | wc -l');
$runningServices = trim($out) ?: '0';

$sitesCount = db()->query('SELECT COUNT(*) c FROM sites')->fetch()['c'] ?? 0;

[$memOut] = run("free -m | awk '/Mem:/ {print $3\"/\"$2}'");
$mem = trim($memOut) ?: '—';

[$osOut] = run('lsb_release -ds 2>/dev/null || cat /etc/os-release | grep PRETTY_NAME | cut -d= -f2');
$os = trim($osOut, " \t\n\r\0\x0B\"");
?>
<h1>Дашборд</h1>
<div class="grid">
  <div class="stat"><div class="val"><?= h($sitesCount) ?></div><div class="lbl">Сайтов</div></div>
  <div class="stat"><div class="val"><?= h($runningServices) ?></div><div class="lbl">Активных служб</div></div>
  <div class="stat"><div class="val"><?= h($mem) ?> МБ</div><div class="lbl">Память (исп./всего)</div></div>
  <div class="stat"><div class="val"><?= h($os ?: 'Ubuntu') ?></div><div class="lbl">ОС</div></div>
</div>

<div class="card">
  <h2>Быстрые ссылки</h2>
  <p>
    <a class="btn secondary" href="/?page=sites">+ Добавить сайт</a>
    <a class="btn secondary" href="/?page=database">+ Создать БД</a>
    <a class="btn secondary" href="/?page=ssl">🔒 Выпустить SSL</a>
    <a class="btn secondary" href="/?page=backups">💾 Настроить бэкап</a>
  </p>
</div>

<div class="card">
  <h2>Последние действия</h2>
  <table>
    <tr><th>Время</th><th>Пользователь</th><th>Действие</th></tr>
    <?php foreach (db()->query('SELECT * FROM activity_log ORDER BY id DESC LIMIT 10') as $row): ?>
      <tr>
        <td><?= h($row['created_at']) ?></td>
        <td><?= h($row['username']) ?></td>
        <td><?= h($row['action']) ?></td>
      </tr>
    <?php endforeach; ?>
  </table>
</div>
