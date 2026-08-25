<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $old = $_POST['old'] ?? '';
    $new = $_POST['new'] ?? '';
    $stmt = db()->prepare('SELECT * FROM users WHERE username = ?');
    $stmt->execute([$_SESSION['user']]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row && password_verify($old, $row['password_hash']) && strlen($new) >= 8) {
        db()->prepare('UPDATE users SET password_hash = ? WHERE username = ?')
            ->execute([password_hash($new, PASSWORD_DEFAULT), $_SESSION['user']]);
        log_action('Смена пароля панели');
        flash('Пароль изменён');
    } else {
        flash('Проверьте текущий пароль (новый — минимум 8 символов)', 'error');
    }
    header('Location: /?page=account');
    exit;
}
?>
<h1>Смена пароля</h1>
<div class="card">
  <form method="post">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <label>Текущий пароль</label>
    <input type="password" name="old" required>
    <label>Новый пароль (мин. 8 символов)</label>
    <input type="password" name="new" required minlength="8">
    <button class="btn" type="submit">Сохранить</button>
  </form>
</div>
