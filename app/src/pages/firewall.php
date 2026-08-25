<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    if ($action === 'allow') {
        $port = preg_replace('/[^0-9\/a-z]/', '', $_POST['port'] ?? '');
        if ($port) { run('sudo ufw allow ' . escapeshellarg($port)); flash("Порт {$port} разрешён"); }
    }
    if ($action === 'deny') {
        $port = preg_replace('/[^0-9\/a-z]/', '', $_POST['port'] ?? '');
        if ($port) { run('sudo ufw deny ' . escapeshellarg($port)); flash("Порт {$port} запрещён"); }
    }
    if ($action === 'delete') {
        $num = (int)($_POST['num'] ?? 0);
        if ($num) { run("echo y | sudo ufw delete {$num}"); flash('Правило удалено'); }
    }
    if ($action === 'toggle') {
        [$out] = run('sudo ufw status | head -1');
        if (stripos($out, 'active') !== false) run('sudo ufw disable');
        else run('echo y | sudo ufw enable');
    }
    header('Location: /?page=firewall');
    exit;
}
[$statusOut] = run('sudo ufw status numbered 2>/dev/null');
$lines = explode("\n", trim($statusOut));
?>
<h1>Firewall (UFW)</h1>

<div class="card">
  <h2>Статус</h2>
  <pre class="term" style="height:auto;color:#ccc"><?= h($statusOut) ?></pre>
  <form method="post"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="toggle">
    <button class="btn secondary" type="submit">Вкл/Выкл firewall</button></form>
</div>

<div class="card">
  <h2>Добавить правило</h2>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <label>Порт / сервис (напр. 22, 80/tcp, 443)</label>
    <input type="text" name="port" required>
    <button class="btn" name="action" value="allow" type="submit">Разрешить</button>
    <button class="btn danger" name="action" value="deny" type="submit">Запретить</button>
  </form>
</div>

<div class="card">
  <h2>Удалить правило по номеру</h2>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="action" value="delete">
    <label>Номер правила (см. список выше)</label>
    <input type="number" name="num" required>
    <button class="btn danger" type="submit">Удалить</button>
  </form>
</div>
