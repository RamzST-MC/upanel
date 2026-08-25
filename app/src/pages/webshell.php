<?php
// ВНИМАНИЕ: выполняет команды от root через sudoers-whitelist (см. install.sh).
// Держите панель за firewall/VPN и используйте сложный пароль — это по сути root-доступ.
$output = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $cmd = $_POST['cmd'] ?? '';
    if (trim($cmd) !== '') {
        [$out, $err] = run('sudo /usr/local/bin/upanel-helper shell ' . escapeshellarg($cmd) . ' 2>&1');
        $output = $out . $err;
        log_action('Web Shell: ' . $cmd);
    }
}
?>
<h1>Web Shell</h1>
<div class="card">
  <p style="color:#a11">⚠️ Выполняет команды от root. Используйте осторожно, ограничьте доступ к панели firewall'ом.</p>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="text" name="cmd" placeholder="например: systemctl status nginx" autofocus>
    <button class="btn" type="submit">Выполнить</button>
  </form>
  <?php if ($output !== ''): ?>
    <pre class="term"><?= h($output) ?></pre>
  <?php endif; ?>
</div>
