<?php
define('BIND_ZONES_DIR', '/etc/bind/zones');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    $domain = preg_replace('/[^a-z0-9.\-]/i', '', $_POST['domain'] ?? '');

    if ($action === 'create' && $domain) {
        $serial = date('Ymd') . '01';
        $zone = <<<ZONE
\$TTL 3600
@   IN  SOA ns1.{$domain}. admin.{$domain}. (
        {$serial} ; Serial
        3600       ; Refresh
        1800       ; Retry
        604800     ; Expire
        3600 )     ; Minimum TTL

    IN  NS  ns1.{$domain}.
@   IN  A   127.0.0.1
www IN  A   127.0.0.1
ZONE;
        run('sudo mkdir -p ' . escapeshellarg(BIND_ZONES_DIR));
        file_put_contents('/tmp/zone_' . $domain, $zone);
        run('sudo cp /tmp/zone_' . escapeshellarg($domain) . ' ' . escapeshellarg(BIND_ZONES_DIR . '/db.' . $domain));
        run('echo ' . escapeshellarg('zone "' . $domain . '" { type master; file "' . BIND_ZONES_DIR . '/db.' . $domain . '"; };') . ' | sudo tee -a /etc/bind/named.conf.local');
        run('sudo systemctl restart bind9');
        flash("Зона {$domain} создана");
    }
    header('Location: /?page=dns');
    exit;
}
[$zonesOut] = run('ls ' . escapeshellarg(BIND_ZONES_DIR) . ' 2>/dev/null');
$zones = array_filter(explode("\n", trim($zonesOut)));
?>
<h1>DNS зоны (bind9)</h1>
<div class="card">
  <p style="color:var(--muted)">Требует установленного bind9 (флаг <code>--with-dns</code> в install.sh).</p>
  <h2>Создать зону</h2>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="action" value="create">
    <label>Домен</label>
    <input type="text" name="domain" placeholder="example.com" required>
    <button class="btn" type="submit">+ Создать зону</button>
  </form>
</div>
<div class="card">
  <h2>Существующие зоны</h2>
  <table><tr><th>Файл</th></tr>
    <?php foreach ($zones as $z): ?><tr><td><?= h($z) ?></td></tr><?php endforeach; ?>
    <?php if (!$zones): ?><tr><td>Зон нет</td></tr><?php endif; ?>
  </table>
</div>
