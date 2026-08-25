<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    $user = preg_replace('/[^a-z0-9_.\-]/i', '', $_POST['user'] ?? '');
    $pass = $_POST['pass'] ?? '';

    if ($action === 'create' && $user && $pass) {
        run('sudo useradd -m -d ' . escapeshellarg(SITES_ROOT . '/' . $user) . ' -s /bin/bash ' . escapeshellarg($user));
        run('echo ' . escapeshellarg($user . ':' . $pass) . ' | sudo chpasswd');
        log_action("Создан хост-аккаунт {$user}");
        flash("Аккаунт {$user} создан");
    }
    if ($action === 'delete' && $user) {
        run('sudo userdel -r ' . escapeshellarg($user));
        log_action("Удалён хост-аккаунт {$user}");
        flash("Аккаунт {$user} удалён");
    }
    header('Location: /?page=accounts');
    exit;
}
[$out] = run("getent passwd | awk -F: '$3>=1000 && $3<60000 {print $1}'");
$users = array_filter(explode("\n", trim($out)));
?>
<h1>Хост-аккаунты</h1>
<div class="card">
  <h2>Создать аккаунт</h2>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="action" value="create">
    <label>Логин</label>
    <input type="text" name="user" required>
    <label>Пароль</label>
    <input type="text" name="pass" required>
    <button class="btn" type="submit">+ Создать</button>
  </form>
</div>
<div class="card">
  <h2>Список аккаунтов</h2>
  <table>
    <tr><th>Логин</th><th>Действия</th></tr>
    <?php foreach ($users as $u): ?>
      <tr>
        <td><?= h($u) ?></td>
        <td>
          <form method="post" onsubmit="return confirm('Удалить <?= h($u) ?>?')">
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="user" value="<?= h($u) ?>">
            <button class="btn danger" type="submit">Удалить</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
  </table>
</div>
