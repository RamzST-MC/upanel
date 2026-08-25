<?php
$sites = db()->query('SELECT domain FROM sites ORDER BY domain')->fetchAll(PDO::FETCH_COLUMN);
$domain = $_GET['domain'] ?? ($sites[0] ?? '');
$type = $_GET['type'] ?? 'access';
$logFile = '/var/log/nginx/' . basename($domain) . '.' . ($type === 'error' ? 'error' : 'access') . '.log';
[$tailOut] = $domain ? run('sudo tail -n 200 ' . escapeshellarg($logFile) . ' 2>&1') : ['Нет сайтов'];
?>
<h1>Логи сайтов</h1>
<div class="card">
  <form method="get">
    <input type="hidden" name="page" value="site_logs">
    <label>Сайт</label>
    <select name="domain" onchange="this.form.submit()">
      <?php foreach ($sites as $d): ?>
        <option value="<?= h($d) ?>" <?= $d === $domain ? 'selected' : '' ?>><?= h($d) ?></option>
      <?php endforeach; ?>
    </select>
    <label>Тип лога</label>
    <select name="type" onchange="this.form.submit()">
      <option value="access" <?= $type === 'access' ? 'selected' : '' ?>>Access log</option>
      <option value="error" <?= $type === 'error' ? 'selected' : '' ?>>Error log</option>
    </select>
  </form>
</div>
<div class="card">
  <h2>Последние 200 строк</h2>
  <pre class="term"><?= h($tailOut) ?></pre>
</div>
