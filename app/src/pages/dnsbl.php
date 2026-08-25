<?php
$dnsblServers = [
    'zen.spamhaus.org', 'pbl.spamhaus.org', 'cbl.abuseat.org',
    'b.barracudacentral.org', 'all.s5h.net', 'bl.spamcop.net',
];
$ip = $_GET['ip'] ?? trim((string)@run('curl -s https://api.ipify.org')[0]);
$results = [];
if (filter_var($ip, FILTER_VALIDATE_IP)) {
    $rev = implode('.', array_reverse(explode('.', $ip)));
    foreach ($dnsblServers as $srv) {
        $listed = checkdnsrr($rev . '.' . $srv, 'A');
        $results[$srv] = $listed;
    }
}
?>
<h1>Проверка IP в черных списках (DNSBL)</h1>
<div class="card">
  <form method="get">
    <input type="hidden" name="page" value="dnsbl">
    <label>IP адрес для проверки</label>
    <input type="text" name="ip" value="<?= h($ip) ?>" required>
    <button class="btn" type="submit">Проверить</button>
  </form>
</div>
<?php if ($results): ?>
<div class="card">
  <h2>Результаты</h2>
  <table>
    <tr><th>DNSBL сервер</th><th>Статус</th></tr>
    <?php foreach ($results as $srv => $listed): ?>
      <tr>
        <td><?= h($srv) ?></td>
        <td><span class="status-dot <?= $listed ? 'status-off' : 'status-on' ?>"></span><?= $listed ? 'В черном списке' : 'Чисто' ?></td>
      </tr>
    <?php endforeach; ?>
  </table>
</div>
<?php endif; ?>
