<?php
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $u = trim($_POST['username'] ?? '');
    $p = $_POST['password'] ?? '';
    $stmt = db()->prepare('SELECT * FROM users WHERE username = ?');
    $stmt->execute([$u]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row && password_verify($p, $row['password_hash'])) {
        $_SESSION['user'] = $u;
        log_action('login');
        header('Location: /?page=dashboard');
        exit;
    }
    $error = 'Неверный логин или пароль';
}
?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Вход — UPanel</title>
<link rel="stylesheet" href="/assets/style.css">
</head>
<body>
<div class="login-wrap">
  <div class="login-box">
    <h1>🐧 UPanel — вход</h1>
    <?php if ($error): ?><div class="flash flash-error"><?= h($error) ?></div><?php endif; ?>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <label>Логин</label>
      <input type="text" name="username" required autofocus>
      <label>Пароль</label>
      <input type="password" name="password" required>
      <button class="btn" style="width:100%" type="submit">Войти</button>
    </form>
  </div>
</div>
</body>
</html>
