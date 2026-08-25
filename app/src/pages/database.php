<?php
// Требует /root/.my.cnf с root-доступом к MySQL (создаётся install.sh)
function mysql_query_raw(string $sql): array {
    $tmp = tempnam(sys_get_temp_dir(), 'sql');
    file_put_contents($tmp, $sql);
    [$out, $err, $code] = run('sudo mysql --defaults-file=/root/.my.cnf < ' . escapeshellarg($tmp) . ' 2>&1');
    unlink($tmp);
    return [$out, $code];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'create_db') {
        $db = preg_replace('/[^a-zA-Z0-9_]/', '', $_POST['dbname'] ?? '');
        $user = preg_replace('/[^a-zA-Z0-9_]/', '', $_POST['dbuser'] ?? '');
        $pass = $_POST['dbpass'] ?? '';
        if ($db && $user && $pass) {
            $sql = "CREATE DATABASE IF NOT EXISTS `{$db}` CHARACTER SET utf8mb4;
                    CREATE USER IF NOT EXISTS '{$user}'@'localhost' IDENTIFIED BY '" . addslashes($pass) . "';
                    GRANT ALL PRIVILEGES ON `{$db}`.* TO '{$user}'@'localhost';
                    FLUSH PRIVILEGES;";
            [$out, $code] = mysql_query_raw($sql);
            if ($code === 0) {
                log_action("Создана БД {$db}");
                flash("База {$db} и пользователь {$user} созданы");
            } else {
                flash('Ошибка: ' . $out, 'error');
            }
        } else {
            flash('Заполните все поля', 'error');
        }
    }

    if ($action === 'drop_db') {
        $db = preg_replace('/[^a-zA-Z0-9_]/', '', $_POST['dbname'] ?? '');
        if ($db) {
            mysql_query_raw("DROP DATABASE IF EXISTS `{$db}`;");
            log_action("Удалена БД {$db}");
            flash("База {$db} удалена");
        }
    }
    header('Location: /?page=database');
    exit;
}

[$dbListOut] = run("sudo mysql --defaults-file=/root/.my.cnf -N -e \"SHOW DATABASES\" 2>/dev/null");
$dbs = array_filter(explode("\n", trim($dbListOut)));
$systemDbs = ['information_schema', 'mysql', 'performance_schema', 'sys'];
?>
<h1>База данных</h1>

<div class="card">
  <h2>Создать базу и пользователя</h2>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="action" value="create_db">
    <label>Имя базы</label>
    <input type="text" name="dbname" required>
    <label>Пользователь</label>
    <input type="text" name="dbuser" required>
    <label>Пароль</label>
    <input type="text" name="dbpass" required>
    <button class="btn" type="submit">+ Создать</button>
  </form>
</div>

<div class="card">
  <h2>Список баз</h2>
  <table>
    <tr><th>Имя</th><th>Действия</th></tr>
    <?php foreach ($dbs as $d): if (in_array($d, $systemDbs, true)) continue; ?>
      <tr>
        <td><?= h($d) ?></td>
        <td>
          <form method="post" style="display:inline" onsubmit="return confirm('Удалить базу <?= h($d) ?>?')">
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="drop_db">
            <input type="hidden" name="dbname" value="<?= h($d) ?>">
            <button class="btn danger" type="submit">Удалить</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
  </table>
</div>
