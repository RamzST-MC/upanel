<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    $domain = preg_replace('/[^a-z0-9.\-]/i', '', $_POST['domain'] ?? '');
    $email = filter_var($_POST['email'] ?? '', FILTER_SANITIZE_EMAIL);

    if ($action === 'issue' && $domain) {
        [$out, $err, $code] = run('sudo certbot --nginx -d ' . escapeshellarg($domain) . ' -d ' . escapeshellarg('www.' . $domain) .
            ' --non-interactive --agree-tos -m ' . escapeshellarg($email ?: 'admin@' . $domain) . ' 2>&1');
        if ($code === 0) { log_action("SSL выпущен для {$domain}"); flash("Сертификат для {$domain} выпущен"); }
        else flash('Ошибка certbot: ' . $out, 'error');
    }
    if ($action === 'renew') {
        run('sudo certbot renew --non-interactive 2>&1');
        flash('Обновление сертификатов запущено');
    }
    header('Location: /?page=ssl');
    exit;
}

[$certsOut] = run('sudo certbot certificates 2>/dev/null');
$prefillDomain = $_GET['domain'] ?? '';
?>
<h1>Сертификаты SSL</h1>

<div class="card">
  <h2>Выпустить Let's Encrypt сертификат</h2>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="action" value="issue">
    <label>Домен</label>
    <input type="text" name="domain" value="<?= h($prefillDomain) ?>" required>
    <label>Email для уведомлений Let's Encrypt</label>
    <input type="text" name="email" placeholder="admin@example.com">
    <button class="btn" type="submit">Выпустить сертификат</button>
  </form>
</div>

<div class="card">
  <h2>Текущие сертификаты</h2>
  <pre class="term" style="height:auto;color:#ccc"><?= h($certsOut ?: 'Сертификатов нет') ?></pre>
  <form method="post"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="renew">
    <button class="btn secondary" type="submit">Обновить все сертификаты</button></form>
</div>
