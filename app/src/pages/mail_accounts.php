<?php
// Простая модель: почтовый аккаунт = системный пользователь без shell-доступа,
// письма которого принимает Postfix/Dovecot локально (user@<hostname_domain>).
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    $user = preg_replace('/[^a-z0-9_.\-]/i', '', $_POST['user'] ?? '');
    $pass = $_POST['pass'] ?? '';

    if ($action === 'create' && $user && $pass) {
        run('sudo useradd -m -s /usr/sbin/nologin ' . escapeshellarg($user));
        run('echo ' . escapeshellarg($user . ':' . $pass) . ' | sudo chpasswd');
        log_action("Создан почтовый аккаунт {$user}");
        flash("Почтовый аккаунт {$user} создан");
    }
    if ($action === 'delete' && $user) {
        run('sudo userdel -r ' . escapeshellarg($user));
        log_action("Удалён почтовый аккаунт {$user}");
        flash("Аккаунт {$user} удалён");
    }
    header('Location: /?page=mail_accounts');
    exit;
}

[$passwdOut] = run("getent passwd | awk -F: '$7==\"/usr/sbin/nologin\" && $3>=1000 {print $1}'");
$accounts = array_filter(explode("\n", trim($passwdOut)));
?>
<h1>Почтовые аккаунты</h1>
<div class="card">
  <h2>Создать аккаунт</h2>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="action" value="create">
    <label>Имя пользователя (до @)</label>
    <input type="text" name="user" required>
    <label>Пароль</label>
    <input type="text" name="pass" required>
    <button class="btn" type="submit">+ Создать</button>
  </form>
</div>
<div class="card">
  <h2>Список аккаунтов</h2>
  <table>
    <tr><th>Пользователь</th><th>Действия</th></tr>
    <?php foreach ($accounts as $a): ?>
      <tr>
        <td><?= h($a) ?></td>
        <td>
          <form method="post" onsubmit="return confirm('Удалить аккаунт <?= h($a) ?>?')">
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="user" value="<?= h($a) ?>">
            <button class="btn danger" type="submit">Удалить</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$accounts): ?><tr><td colspan="2">Аккаунтов нет</td></tr><?php endif; ?>
  </table>
</div>
