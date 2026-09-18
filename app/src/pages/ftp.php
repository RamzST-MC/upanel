<?php
// FTP-аккаунты = системные пользователи без shell-логина, обслуживаемые vsftpd
// (chroot в свою домашнюю папку). Квота и "только чтение" хранятся в БД панели
// и применяются: квота — через setquota (если поддерживается ФС), "только
// чтение" — через персональный конфиг vsftpd (user_config_dir).
$logDir = DATA_DIR . '/install_logs';
$logFile = $logDir . '/vsftpd.log';
$DONE_MARKER = '__UPANEL_DONE__';
$userConfDir = '/etc/vsftpd/user_conf';

function ftp_valid_user(string $u): string {
    return preg_replace('/[^a-z0-9_.\-]/i', '', $u);
}

// ------------------------------------------------------------------
// AJAX: статус фоновой установки vsftpd
// ------------------------------------------------------------------
if (($_GET['ajax'] ?? '') === 'status') {
    if (($_GET['csrf'] ?? '') !== ($_SESSION['csrf'] ?? '')) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'bad csrf']);
        exit;
    }
    header('Content-Type: application/json');
    $content = is_file($logFile) ? file_get_contents($logFile) : '';
    $done = false; $code = null;
    if (preg_match('/' . preg_quote($DONE_MARKER, '/') . '(-?\d+)/', $content, $m)) {
        $done = true;
        $code = (int)$m[1];
        $content = trim(preg_replace('/' . preg_quote($DONE_MARKER, '/') . '-?\d+\s*/', '', $content));
    }
    echo json_encode(['done' => $done, 'code' => $code, 'log' => $content]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    // --- Установка vsftpd в фоне (см. mail.php — тот же приём) ---
    if ($action === 'install_start') {
        if (!is_dir($logDir)) mkdir($logDir, 0750, true);
        $pasvMin = 30000;
        $pasvMax = 30100;
        $rootScript = "export DEBIAN_FRONTEND=noninteractive; "
            . "echo '=== " . date('Y-m-d H:i:s') . " ==='; "
            . "echo '\$ apt-get install -y vsftpd'; apt-get install -y vsftpd; code=\$?; "
            . "mkdir -p " . escapeshellarg($userConfDir) . "; "
            . "grep -q '^/usr/sbin/nologin$' /etc/shells || echo /usr/sbin/nologin >> /etc/shells; "
            . "cp -n /etc/vsftpd.conf /etc/vsftpd.conf.upanel-orig 2>/dev/null; "
            . "sed -i '/^user_config_dir=/d;/^chroot_local_user=/d;/^allow_writeable_chroot=/d;/^write_enable=/d;/^pasv_enable=/d;/^pasv_min_port=/d;/^pasv_max_port=/d' /etc/vsftpd.conf; "
            . "printf '%s\\n' 'chroot_local_user=YES' 'allow_writeable_chroot=YES' 'write_enable=YES' "
            . "'pasv_enable=YES' 'pasv_min_port={$pasvMin}' 'pasv_max_port={$pasvMax}' "
            . "'user_config_dir=" . $userConfDir . "' >> /etc/vsftpd.conf; "
            . "echo '\$ ufw allow 21/tcp, {$pasvMin}:{$pasvMax}/tcp'; "
            . "(ufw allow 21/tcp; ufw allow {$pasvMin}:{$pasvMax}/tcp; ufw reload) 2>&1 || true; "
            . "echo '\$ systemctl enable --now vsftpd'; systemctl enable --now vsftpd; "
            . "echo '{$DONE_MARKER}'\$code";
        $privCmd = 'sudo /usr/local/bin/upanel-helper shell ' . escapeshellarg($rootScript);
        file_put_contents($logFile, '');
        run('setsid nohup bash -c ' . escapeshellarg($privCmd) . ' > ' . escapeshellarg($logFile) . ' 2>&1 < /dev/null &');
        log_action('apt-get install vsftpd (запущено в фоне)');
        header('Content-Type: application/json');
        echo json_encode(['ok' => true]);
        exit;
    }

    if (in_array($action, ['start', 'stop', 'restart'], true)) {
        run('sudo systemctl ' . escapeshellarg($action) . ' vsftpd');
        flash("vsftpd: {$action}");
        header('Location: /?page=ftp');
        exit;
    }

    // --- Патч сети для уже установленного vsftpd: passive mode + порты в UFW.
    // Нужно, если сервер ставился до появления этой настройки — иначе
    // подключение снаружи (а иногда и из локальной сети, если UFW включён)
    // не проходит: порт 21 и диапазон passive-портов закрыты файрволом.
    if ($action === 'fix_network') {
        $pasvMin = 30000;
        $pasvMax = 30100;
        $script = "cp -n /etc/vsftpd.conf /etc/vsftpd.conf.upanel-orig 2>/dev/null; "
            . "sed -i '/^pasv_enable=/d;/^pasv_min_port=/d;/^pasv_max_port=/d' /etc/vsftpd.conf; "
            . "printf '%s\\n' 'pasv_enable=YES' 'pasv_min_port={$pasvMin}' 'pasv_max_port={$pasvMax}' >> /etc/vsftpd.conf; "
            . "(ufw allow 21/tcp; ufw allow {$pasvMin}:{$pasvMax}/tcp; ufw reload) 2>&1; "
            . "systemctl restart vsftpd; ufw status 2>&1";
        [$out] = run('sudo /usr/local/bin/upanel-helper shell ' . escapeshellarg($script) . ' 2>&1');
        log_action('Исправлены сетевые настройки vsftpd (passive mode + UFW)');
        flash("Настройки применены. Открыты порты 21 и {$pasvMin}-{$pasvMax}/tcp, включён passive-режим, служба перезапущена.");
        header('Location: /?page=ftp');
        exit;
    }

    // --- Создание FTP-аккаунта ---
    if ($action === 'create') {
        $user = ftp_valid_user($_POST['user'] ?? '');
        $pass = $_POST['pass'] ?? '';
        $home = trim($_POST['home'] ?? '') ?: ('/home/' . $user);
        $quota = max(0, (int)($_POST['quota'] ?? 0));
        $readonly = !empty($_POST['readonly']) ? 1 : 0;

        [$exists] = run('id -u ' . escapeshellarg($user) . ' 2>/dev/null');
        if ($user === '' || $pass === '') {
            flash('Укажите логин и пароль', 'error');
        } elseif (trim($exists) !== '') {
            flash("Пользователь {$user} уже существует", 'error');
        } else {
            run('sudo mkdir -p ' . escapeshellarg($home));
            run('sudo useradd -M -d ' . escapeshellarg($home) . ' -s /usr/sbin/nologin ' . escapeshellarg($user));
            run('echo ' . escapeshellarg($user . ':' . $pass) . ' | sudo chpasswd');
            run('sudo chown -R ' . escapeshellarg($user) . ':' . escapeshellarg($user) . ' ' . escapeshellarg($home));
            run('sudo chmod 750 ' . escapeshellarg($home));

            if ($readonly) {
                run('sudo /usr/local/bin/upanel-helper shell ' . escapeshellarg(
                    "mkdir -p {$userConfDir}; printf 'write_enable=NO\n' > {$userConfDir}/{$user}"
                ));
            }
            if ($quota > 0) {
                run('sudo setquota -u ' . escapeshellarg($user) . ' 0 ' . ($quota * 1024) . ' 0 0 /home 2>/dev/null');
            }

            $stmt = db()->prepare('INSERT OR REPLACE INTO ftp_accounts (id, username, home_dir, quota_mb, readonly)
                VALUES ((SELECT id FROM ftp_accounts WHERE username = ?), ?, ?, ?, ?)');
            $stmt->execute([$user, $user, $home, $quota, $readonly]);

            log_action("Создан FTP-аккаунт {$user}");
            flash("FTP-аккаунт {$user} создан");
        }
    }

    // --- Изменение квоты / флага "только чтение" ---
    if ($action === 'update_quota') {
        $user = ftp_valid_user($_POST['user'] ?? '');
        $quota = max(0, (int)($_POST['quota'] ?? 0));
        $readonly = !empty($_POST['readonly']) ? 1 : 0;
        if ($user !== '') {
            $stmt = db()->prepare('UPDATE ftp_accounts SET quota_mb = ?, readonly = ? WHERE username = ?');
            $stmt->execute([$quota, $readonly, $user]);
            if ($quota > 0) {
                run('sudo setquota -u ' . escapeshellarg($user) . ' 0 ' . ($quota * 1024) . ' 0 0 /home 2>/dev/null');
            }
            if ($readonly) {
                run('sudo /usr/local/bin/upanel-helper shell ' . escapeshellarg(
                    "mkdir -p {$userConfDir}; printf 'write_enable=NO\n' > {$userConfDir}/{$user}"
                ));
            } else {
                run('sudo /usr/local/bin/upanel-helper shell ' . escapeshellarg("rm -f {$userConfDir}/{$user}"));
            }
            log_action("Изменена квота FTP-аккаунта {$user}");
            flash("Квота {$user} обновлена");
        }
    }

    // --- Смена пароля ---
    if ($action === 'change_password') {
        $user = ftp_valid_user($_POST['user'] ?? '');
        $pass = $_POST['pass'] ?? '';
        if ($user !== '' && $pass !== '') {
            run('echo ' . escapeshellarg($user . ':' . $pass) . ' | sudo chpasswd');
            log_action("Изменён пароль FTP-аккаунта {$user}");
            flash("Пароль {$user} обновлён");
        }
    }

    // --- Удаление ---
    if ($action === 'delete') {
        $user = ftp_valid_user($_POST['user'] ?? '');
        $removeHome = !empty($_POST['remove_home']);
        if ($user !== '') {
            run('sudo userdel ' . ($removeHome ? '-r ' : '') . escapeshellarg($user));
            run('sudo /usr/local/bin/upanel-helper shell ' . escapeshellarg("rm -f {$userConfDir}/{$user}"));
            $stmt = db()->prepare('DELETE FROM ftp_accounts WHERE username = ?');
            $stmt->execute([$user]);
            log_action("Удалён FTP-аккаунт {$user}");
            flash("FTP-аккаунт {$user} удалён");
        }
    }

    header('Location: /?page=ftp');
    exit;
}

[$activeOut] = run('systemctl is-active vsftpd 2>/dev/null');
$vsftpdActive = trim($activeOut) === 'active';
[$loadState] = run('systemctl show -p LoadState --value vsftpd 2>/dev/null');
$vsftpdInstalled = trim($loadState) === 'loaded';
[$vsftpdStatusOut] = $vsftpdInstalled ? run('systemctl status vsftpd --no-pager -l 2>&1') : ['', '', 0];

$accounts = db()->query('SELECT * FROM ftp_accounts ORDER BY username')->fetchAll();
foreach ($accounts as &$a) {
    [$existsOut] = run('id -u ' . escapeshellarg($a['username']) . ' 2>/dev/null');
    $a['exists'] = trim($existsOut) !== '';
    [$duOut] = run('sudo du -sm ' . escapeshellarg($a['home_dir']) . ' 2>/dev/null | cut -f1');
    $a['used_mb'] = (int)trim($duOut);
}
unset($a);

// IP и порт для подключения FTP-клиентом
[$pubIpOut] = run('curl -s --max-time 2 https://api.ipify.org 2>/dev/null');
$serverIp = trim($pubIpOut);
if ($serverIp === '' || !filter_var($serverIp, FILTER_VALIDATE_IP)) {
    [$localIpOut] = run("hostname -I 2>/dev/null | awk '{print \$1}'");
    $serverIp = trim($localIpOut) ?: '—';
}
$ftpPort = 21;
if ($vsftpdInstalled) {
    [$portOut] = run("grep -E '^listen_port=' /etc/vsftpd.conf 2>/dev/null | cut -d= -f2");
    $portOut = trim($portOut);
    if ($portOut !== '' && ctype_digit($portOut)) $ftpPort = (int)$portOut;
}
?>
<h1>FTP-аккаунты</h1>

<div class="card">
  <h2>FTP-сервер (vsftpd)</h2>
  <p><span class="status-dot <?= $vsftpdActive ? 'status-on' : 'status-off' ?>"></span>
    <?= $vsftpdActive ? 'Работает' : ($vsftpdInstalled ? 'Остановлен' : 'Не установлен') ?>
  </p>
  <?php if (!$vsftpdInstalled): ?>
    <button type="button" class="btn primary js-ftp-install-btn">Установить</button>
  <?php else: ?>
    <form method="post" style="display:inline">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <button class="btn secondary" name="action" value="start">Старт</button>
      <button class="btn secondary" name="action" value="restart">Рестарт</button>
      <button class="btn danger" name="action" value="stop">Стоп</button>
    </form>
    <button type="button" class="btn secondary js-toggle" data-target="vsftpd-status" style="margin-left:8px">ℹ️ Статус</button>
    <form method="post" style="display:inline">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="action" value="fix_network">
      <button class="btn secondary" type="submit" title="Открыть порт 21 и passive-диапазон в UFW, включить passive mode">🔧 Исправить сеть</button>
    </form>
    <pre class="term" id="row-vsftpd-status" style="display:none;height:220px;margin-top:12px"><?= h(trim($vsftpdStatusOut)) ?></pre>
  <?php endif; ?>
</div>

<div class="card ftp-install-progress" id="ftp-install-progress" style="display:none">
  <h2 id="ftp-install-title">Идёт установка</h2>
  <div class="progress-bar-outer">
    <div class="progress-bar-inner progress-stripes" id="ftp-install-bar"></div>
  </div>
  <button type="button" class="btn secondary" id="ftp-install-toggle" style="margin-top:12px">↑ Скрыть</button>
  <pre class="term" id="ftp-install-term" style="height:220px;margin-top:10px"></pre>
</div>

<div class="card">
  <h2>Добавить пользователя ФТП</h2>
  <form method="post" id="ftp-create-form">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="action" value="create">

    <label>Логин</label>
    <input type="text" name="user" id="ftp-new-user" required autocomplete="off">

    <label>Пароль</label>
    <div style="display:flex;gap:8px;align-items:center">
      <input type="text" name="pass" id="ftp-new-pass" required autocomplete="off" style="flex:1">
      <button type="button" class="btn secondary" id="ftp-gen-pass" title="Генератор паролей">🔑</button>
    </div>
    <div class="pw-strength-outer"><div class="pw-strength-inner" id="ftp-new-strength"></div></div>

    <label>Домашняя папка</label>
    <input type="text" name="home" id="ftp-new-home" placeholder="/home/имя_пользователя">

    <label>Квота</label>
    <div style="display:flex;gap:8px;align-items:center">
      <input type="number" name="quota" min="0" value="250" style="flex:1"> <span>Мб (0 — без лимита)</span>
    </div>

    <label style="display:flex;align-items:center;gap:6px;margin-top:10px">
      <input type="checkbox" name="readonly" value="1" style="width:auto"> Только чтение
    </label>

    <button class="btn" type="submit" style="margin-top:14px">+ Добавить ФТП аккаунт</button>
  </form>
</div>

<div class="card">
  <h2>ФТП аккаунты</h2>
  <p style="color:var(--muted)">
    🔌 Подключение: <strong><?= h($serverIp) ?></strong>, порт <strong><?= (int)$ftpPort ?></strong>
    — <code>ftp://<?= h($serverIp) ?>:<?= (int)$ftpPort ?></code>
    <?php if (!$vsftpdInstalled): ?><br><span style="color:#a15c00">FTP-сервер ещё не установлен — подключение будет недоступно.</span><?php endif; ?>
  </p>
  <table>
    <tr><th>Логин</th><th>Домашняя папка</th><th>Использовано / Квота</th><th>Только чтение</th><th>Действия</th></tr>
    <?php foreach ($accounts as $a): $u = h($a['username']); ?>
      <tr>
        <td><?= $u ?><?php if (!$a['exists']): ?> <span title="Системный пользователь не найден">⚠️</span><?php endif; ?></td>
        <td><?= h($a['home_dir']) ?></td>
        <td><?= (int)$a['used_mb'] ?> / <?= $a['quota_mb'] > 0 ? (int)$a['quota_mb'] . ' Мб' : 'без лимита' ?></td>
        <td><input type="checkbox" disabled <?= $a['readonly'] ? 'checked' : '' ?>></td>
        <td>
          <button type="button" class="btn secondary js-toggle" data-target="quota-<?= $u ?>">✏️ Изменить квоту</button>
          <button type="button" class="btn secondary js-toggle" data-target="pass-<?= $u ?>">🔑 Изменить пароль</button>
          <button type="button" class="btn danger js-toggle" data-target="del-<?= $u ?>">🗑️ Удалить</button>
        </td>
      </tr>
      <tr class="ftp-panel-row" id="row-quota-<?= $u ?>" style="display:none">
        <td colspan="5">
          <div class="ftp-inline-panel">
            <strong>ФТП квота</strong>
            <form method="post" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:8px">
              <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="action" value="update_quota">
              <input type="hidden" name="user" value="<?= $u ?>">
              <input type="number" name="quota" min="0" value="<?= (int)$a['quota_mb'] ?>" style="width:120px"> Мб
              <label style="display:flex;align-items:center;gap:6px"><input type="checkbox" name="readonly" value="1" style="width:auto" <?= $a['readonly'] ? 'checked' : '' ?>> Только чтение</label>
              <button class="btn" type="submit">✔ Сохранить</button>
              <button type="button" class="btn secondary js-toggle" data-target="quota-<?= $u ?>">⊘ Отменить</button>
            </form>
          </div>
        </td>
      </tr>
      <tr class="ftp-panel-row" id="row-pass-<?= $u ?>" style="display:none">
        <td colspan="5">
          <div class="ftp-inline-panel">
            <strong>Пароль</strong>
            <form method="post" class="ftp-pass-form" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:8px">
              <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="action" value="change_password">
              <input type="hidden" name="user" value="<?= $u ?>">
              <input type="text" name="pass" class="ftp-pass-input" required autocomplete="off" style="flex:1;min-width:220px">
              <button type="button" class="btn secondary js-gen-pass">🔑</button>
              <button class="btn" type="submit">✔ Сохранить</button>
              <button type="button" class="btn secondary js-toggle" data-target="pass-<?= $u ?>">⊘ Отменить</button>
            </form>
            <div class="pw-strength-outer"><div class="pw-strength-inner js-strength-bar"></div></div>
          </div>
        </td>
      </tr>
      <tr class="ftp-panel-row" id="row-del-<?= $u ?>" style="display:none">
        <td colspan="5">
          <div class="ftp-inline-panel">
            <strong>Удалить пользователя</strong>
            <form method="post" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:8px">
              <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="user" value="<?= $u ?>">
              <label style="display:flex;align-items:center;gap:6px"><input type="checkbox" name="remove_home" value="1" style="width:auto"> Удалить домашнюю папку пользователя</label>
              <button class="btn danger" type="submit">🗑️ Удалить</button>
              <button type="button" class="btn secondary js-toggle" data-target="del-<?= $u ?>">⊘ Отменить</button>
            </form>
          </div>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$accounts): ?><tr><td colspan="5">FTP-аккаунтов пока нет</td></tr><?php endif; ?>
  </table>
</div>

<script>
(function () {
  function randomPassword(len) {
    var chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#%';
    var out = '';
    for (var i = 0; i < len; i++) out += chars[Math.floor(Math.random() * chars.length)];
    return out;
  }
  function strength(pass) {
    var score = 0;
    if (pass.length >= 8) score++;
    if (pass.length >= 12) score++;
    if (/[a-z]/.test(pass) && /[A-Z]/.test(pass)) score++;
    if (/\d/.test(pass)) score++;
    if (/[^A-Za-z0-9]/.test(pass)) score++;
    return Math.min(score, 5);
  }
  function paintStrength(bar, pass) {
    var s = strength(pass);
    var colors = ['#e5484d', '#e5484d', '#f59e0b', '#f59e0b', '#22c55e', '#16a34a'];
    bar.style.width = (s / 5 * 100) + '%';
    bar.style.background = colors[s];
  }

  // Генератор + индикатор для формы создания аккаунта
  var newPass = document.getElementById('ftp-new-pass');
  var newStrength = document.getElementById('ftp-new-strength');
  document.getElementById('ftp-gen-pass').addEventListener('click', function () {
    newPass.value = randomPassword(14);
    paintStrength(newStrength, newPass.value);
  });
  newPass.addEventListener('input', function () { paintStrength(newStrength, newPass.value); });

  // Автоподстановка логина/домашней папки
  var userInput = document.getElementById('ftp-new-user');
  var homeInput = document.getElementById('ftp-new-home');
  userInput.addEventListener('input', function () {
    if (!homeInput.dataset.touched) {
      var v = userInput.value.replace(/[^a-z0-9_.\-]/gi, '');
      homeInput.value = v ? '/home/' + v : '';
    }
  });
  homeInput.addEventListener('input', function () { homeInput.dataset.touched = '1'; });

  // Генератор + индикатор для форм смены пароля в списке
  document.querySelectorAll('.js-gen-pass').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var form = btn.closest('form');
      var input = form.querySelector('.ftp-pass-input');
      var bar = form.parentElement.querySelector('.js-strength-bar');
      input.value = randomPassword(14);
      if (bar) paintStrength(bar, input.value);
    });
  });
  document.querySelectorAll('.ftp-pass-input').forEach(function (input) {
    input.addEventListener('input', function () {
      var bar = input.closest('.ftp-inline-panel').querySelector('.js-strength-bar');
      if (bar) paintStrength(bar, input.value);
    });
  });

  // Разворачивание/сворачивание строк-панелей (Изменить квоту / пароль / Удалить)
  document.querySelectorAll('.js-toggle').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var row = document.getElementById('row-' + btn.dataset.target);
      if (!row) return;
      var shown = row.tagName === 'TR' ? 'table-row' : 'block';
      row.style.display = row.style.display === 'none' ? shown : 'none';
    });
  });

  // --- Установка vsftpd (фон + живой лог, как на странице "Почта") ---
  var installBtn = document.querySelector('.js-ftp-install-btn');
  if (installBtn) {
    var progressCard = document.getElementById('ftp-install-progress');
    var progressBar = document.getElementById('ftp-install-bar');
    var progressTitle = document.getElementById('ftp-install-title');
    var term = document.getElementById('ftp-install-term');
    var toggleBtn = document.getElementById('ftp-install-toggle');
    var csrf = <?= json_encode(csrf_token()) ?>;
    var poller = null;

    toggleBtn.addEventListener('click', function () {
      var hidden = term.style.display === 'none';
      term.style.display = hidden ? 'block' : 'none';
      toggleBtn.innerHTML = hidden ? '↑ Скрыть' : '↓ Показать';
    });

    function pollStatus() {
      fetch('/?page=ftp&ajax=status&csrf=' + encodeURIComponent(csrf))
        .then(function (r) { return r.json(); })
        .then(function (data) {
          term.textContent = data.log || '';
          term.scrollTop = term.scrollHeight;
          if (data.done) {
            clearInterval(poller);
            progressBar.classList.remove('progress-stripes');
            if (data.code === 0) {
              progressBar.style.background = '#22c55e';
              progressTitle.textContent = '✅ Установка завершена';
            } else {
              progressBar.style.background = '#e5484d';
              progressTitle.textContent = '⚠️ Установка завершилась с ошибкой (код ' + data.code + ')';
            }
            setTimeout(function () { window.location.reload(); }, 1500);
          }
        })
        .catch(function () {});
    }

    installBtn.addEventListener('click', function () {
      if (!confirm('Установить vsftpd через apt-get?')) return;
      installBtn.disabled = true;
      installBtn.innerHTML = '<span class="spinner"></span> Запуск…';
      progressCard.style.display = 'block';
      progressTitle.textContent = 'Идёт установка: vsftpd';
      progressBar.classList.add('progress-stripes');
      progressBar.style.background = '';
      term.style.display = 'block';
      toggleBtn.innerHTML = '↑ Скрыть';
      term.textContent = '';
      progressCard.scrollIntoView({ behavior: 'smooth', block: 'start' });

      var body = new URLSearchParams();
      body.set('csrf', csrf);
      body.set('action', 'install_start');
      fetch('/?page=ftp', { method: 'POST', body: body })
        .then(function (r) { return r.json(); })
        .then(function () {
          poller = setInterval(pollStatus, 1000);
          pollStatus();
        })
        .catch(function () { progressTitle.textContent = '⚠️ Не удалось запустить установку'; });
    });
  }
})();
</script>
