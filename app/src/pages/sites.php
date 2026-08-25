<?php
function nginx_vhost_template(string $domain, string $docroot, string $php): string {
    return <<<CONF
server {
    listen 80;
    server_name {$domain} www.{$domain};
    root {$docroot};
    index index.php index.html;

    access_log /var/log/nginx/{$domain}.access.log;
    error_log  /var/log/nginx/{$domain}.error.log;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~ \.php\$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php{$php}-fpm.sock;
    }

    location ~ /\.ht {
        deny all;
    }
}
CONF;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $domain = preg_replace('/[^a-z0-9.\-]/i', '', $_POST['domain'] ?? '');
        $php = preg_replace('/[^0-9.]/', '', $_POST['php_version'] ?? '8.3');
        $docroot = SITES_ROOT . '/' . $domain;

        if ($domain === '') {
            flash('Укажите домен', 'error');
        } else {
            run('sudo /usr/local/bin/upanel-helper mkdir_site ' . escapeshellarg($docroot));
            file_put_contents('/tmp/vhost_' . $domain . '.conf', nginx_vhost_template($domain, $docroot, $php));
            [, $err, $code] = run('sudo /usr/local/bin/upanel-helper add_vhost ' . escapeshellarg($domain) . ' ' . escapeshellarg('/tmp/vhost_' . $domain . '.conf'));
            if ($code === 0) {
                $stmt = db()->prepare('INSERT OR IGNORE INTO sites (domain, docroot, php_version) VALUES (?, ?, ?)');
                $stmt->execute([$domain, $docroot, $php]);
                log_action("Создан сайт {$domain}");
                flash("Сайт {$domain} создан");
            } else {
                flash('Ошибка создания vhost: ' . $err, 'error');
            }
        }
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = db()->prepare('SELECT * FROM sites WHERE id = ?');
        $stmt->execute([$id]);
        $site = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($site) {
            run('sudo /usr/local/bin/upanel-helper remove_vhost ' . escapeshellarg($site['domain']));
            db()->prepare('DELETE FROM sites WHERE id = ?')->execute([$id]);
            log_action("Удалён сайт {$site['domain']}");
            flash("Сайт {$site['domain']} удалён");
        }
    }
    header('Location: /?page=sites');
    exit;
}

$sites = db()->query('SELECT * FROM sites ORDER BY id DESC')->fetchAll(PDO::FETCH_ASSOC);
[$phpVersionsOut] = run("ls /etc/php 2>/dev/null");
$phpVersions = array_filter(explode("\n", trim($phpVersionsOut)));
if (!$phpVersions) $phpVersions = ['8.3'];
?>
<h1>Сайты</h1>

<div class="card">
  <h2>Добавить сайт</h2>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="action" value="create">
    <label>Домен</label>
    <input type="text" name="domain" placeholder="example.com" required>
    <label>Версия PHP</label>
    <select name="php_version">
      <?php foreach ($phpVersions as $v): ?>
        <option value="<?= h($v) ?>"><?= h($v) ?></option>
      <?php endforeach; ?>
    </select>
    <button class="btn" type="submit">+ Добавить</button>
  </form>
</div>

<div class="card">
  <h2>Все сайты</h2>
  <table>
    <tr><th>Домен</th><th>Директория</th><th>PHP</th><th>Создан</th><th>Действия</th></tr>
    <?php foreach ($sites as $s): ?>
      <tr>
        <td><a href="http://<?= h($s['domain']) ?>" target="_blank"><?= h($s['domain']) ?></a></td>
        <td><?= h($s['docroot']) ?></td>
        <td><?= h($s['php_version']) ?></td>
        <td><?= h($s['created_at']) ?></td>
        <td>
          <a class="btn secondary" href="/?page=files&path=<?= urlencode($s['docroot']) ?>">Файлы</a>
          <a class="btn secondary" href="/?page=ssl&domain=<?= urlencode($s['domain']) ?>">SSL</a>
          <form method="post" style="display:inline" onsubmit="return confirm('Удалить сайт <?= h($s['domain']) ?>?')">
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
            <button class="btn danger" type="submit">Удалить</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$sites): ?><tr><td colspan="5">Сайтов пока нет</td></tr><?php endif; ?>
  </table>
</div>
