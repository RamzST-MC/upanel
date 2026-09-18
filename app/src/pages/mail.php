<?php
$services = ['postfix' => 'Исходящая почта (Postfix)', 'dovecot' => 'Входящая почта (Dovecot)', 'spamassassin' => 'Антиспам', 'clamav-daemon' => 'Антивирус'];
// Пакеты apt для установки службы (может отличаться от имени юнита systemd)
$packages = ['postfix' => 'postfix', 'dovecot' => 'dovecot-core dovecot-imapd', 'spamassassin' => 'spamassassin', 'clamav-daemon' => 'clamav clamav-daemon'];
$logDir = DATA_DIR . '/install_logs';
$DONE_MARKER = '__UPANEL_DONE__';

function mail_install_log_path(string $dir, string $svc): string {
    return $dir . '/' . preg_replace('/[^a-z0-9._-]/i', '_', $svc) . '.log';
}

// ------------------------------------------------------------------
// AJAX: опрос статуса установки (лог в реальном времени)
// ------------------------------------------------------------------
if (($_GET['ajax'] ?? '') === 'status') {
    if (($_GET['csrf'] ?? '') !== ($_SESSION['csrf'] ?? '')) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'bad csrf']);
        exit;
    }
    $svc = $_GET['service'] ?? '';
    header('Content-Type: application/json');
    if (!isset($packages[$svc])) { echo json_encode(['error' => 'unknown service']); exit; }

    $logFile = mail_install_log_path($logDir, $svc);
    $content = is_file($logFile) ? file_get_contents($logFile) : '';
    $done = false;
    $code = null;
    if (preg_match('/' . preg_quote($DONE_MARKER, '/') . '(-?\d+)/', $content, $m)) {
        $done = true;
        $code = (int)$m[1];
        $content = trim(preg_replace('/' . preg_quote($DONE_MARKER, '/') . '-?\d+\s*/', '', $content));
    }
    echo json_encode(['done' => $done, 'code' => $code, 'log' => $content]);
    exit;
}

// ------------------------------------------------------------------
// POST: запуск установки в фоне (AJAX) или старт/стоп/рестарт службы
// ------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = $_POST['do'] ?? '';
    $svc = $_POST['service'] ?? '';

    if ($act === 'install_start' && isset($packages[$svc])) {
        if (!is_dir($logDir)) mkdir($logDir, 0750, true);
        $logFile = mail_install_log_path($logDir, $svc);

        // sudoers для www-data разрешает только точечные команды (systemctl
        // start/stop/restart, apt-get без переменных окружения и т.д.) —
        // "sudo systemctl enable" и "sudo DEBIAN_FRONTEND=... apt-get" не
        // проходят. Поэтому выполняем весь сценарий одним куском через
        // уже разрешённый root-хелпер upanel-helper (как в Web Shell):
        // внутри него env-переменные и systemctl enable работают без ограничений.
        $pkg = $packages[$svc];
        $rootScript = "export DEBIAN_FRONTEND=noninteractive; "
                    . "echo '=== " . date('Y-m-d H:i:s') . " ==='; "
                    . "echo '\$ apt-get install -y {$pkg}'; apt-get install -y {$pkg}; code=\$?; "
                    . "echo '\$ systemctl enable --now {$svc}'; systemctl enable --now " . escapeshellarg($svc) . "; "
                    . "echo '{$DONE_MARKER}'\$code";
        $privCmd = 'sudo /usr/local/bin/upanel-helper shell ' . escapeshellarg($rootScript);

        file_put_contents($logFile, '');
        run('setsid nohup bash -c ' . escapeshellarg($privCmd) . ' > ' . escapeshellarg($logFile) . ' 2>&1 < /dev/null &');

        log_action("apt-get install {$svc} (запущено в фоне)");
        header('Content-Type: application/json');
        echo json_encode(['ok' => true]);
        exit;
    }

    if (isset($services[$svc]) && in_array($act, ['start', 'stop', 'restart'], true)) {
        run('sudo systemctl ' . escapeshellarg($act) . ' ' . escapeshellarg($svc));
        flash("{$svc}: {$act}");
        header('Location: /?page=mail');
        exit;
    }

    header('Location: /?page=mail');
    exit;
}
?>
<h1>Почтовый сервер</h1>
<div class="card">
  <p style="color:var(--muted)">Postfix и Dovecot ставятся опционально флагом <code>--with-mail</code> в install.sh. Управление аккаунтами — в разделе «Почтовые аккаунты».</p>
  <table>
    <tr><th>Служба</th><th>Описание</th><th>Статус</th><th>Действия</th></tr>
    <?php foreach ($services as $svc => $desc):
        [$out] = run('systemctl is-active ' . escapeshellarg($svc) . ' 2>/dev/null');
        $active = trim($out) === 'active';
        [$loadState] = run('systemctl show -p LoadState --value ' . escapeshellarg($svc) . ' 2>/dev/null');
        $installed = trim($loadState) === 'loaded';
    ?>
      <tr id="row-<?= h($svc) ?>">
        <td><?= h($svc) ?></td><td><?= h($desc) ?></td>
        <td class="status-cell"><span class="status-dot <?= $active ? 'status-on' : 'status-off' ?>"></span><?= $active ? 'Работает' : 'Остановлен/не установлен' ?></td>
        <td>
          <?php if (!$installed): ?>
            <button type="button" class="btn primary js-install-btn" data-service="<?= h($svc) ?>">Установить</button>
          <?php else: ?>
            <form method="post" style="display:inline">
              <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="service" value="<?= h($svc) ?>">
              <button class="btn secondary" name="do" value="start">Старт</button>
              <button class="btn secondary" name="do" value="restart">Рестарт</button>
              <button class="btn danger" name="do" value="stop">Стоп</button>
            </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
  </table>
</div>

<!-- Блок хода установки (скрыт, пока установка не запущена) -->
<div class="card install-progress-card" id="install-progress" style="display:none">
  <h2 id="install-progress-title">Идёт установка</h2>
  <div class="progress-bar-outer">
    <div class="progress-bar-inner progress-stripes" id="install-progress-bar"></div>
  </div>
  <button type="button" class="btn secondary" id="install-toggle-btn" style="margin-top:12px">↑ Скрыть</button>
  <pre class="term" id="install-term" style="height:260px;margin-top:10px"></pre>
</div>

<?php
// Логи последних попыток установки — показываем, если для службы есть файл лога
$anyLog = false;
foreach ($services as $svc => $desc) {
    if (is_file(mail_install_log_path($logDir, $svc))) $anyLog = true;
}
if ($anyLog):
?>
<div class="card">
  <h2>Логи установки</h2>
  <?php foreach ($services as $svc => $desc):
      $logFile = mail_install_log_path($logDir, $svc);
      if (!is_file($logFile)) continue;
      $content = file_get_contents($logFile);
      $content = preg_replace('/' . preg_quote($DONE_MARKER, '/') . '-?\d+\s*/', '', $content);
      $ok = strpos($content, '[exit code: 0]') !== false || (bool)preg_match('/\$ sudo systemctl enable/', $content);
  ?>
    <details style="margin-bottom:10px" id="log-details-<?= h($svc) ?>">
      <summary style="cursor:pointer;font-size:13.5px">
        <span class="status-dot <?= $ok ? 'status-on' : 'status-off' ?>"></span>
        <strong><?= h($svc) ?></strong> — последняя попытка установки
      </summary>
      <pre class="term" style="height:220px;margin-top:8px" id="log-pre-<?= h($svc) ?>"><?= h($content) ?></pre>
    </details>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<script>
(function () {
  var progressCard = document.getElementById('install-progress');
  var progressBar  = document.getElementById('install-progress-bar');
  var progressTitle = document.getElementById('install-progress-title');
  var term = document.getElementById('install-term');
  var toggleBtn = document.getElementById('install-toggle-btn');
  var csrf = <?= json_encode(csrf_token()) ?>;
  var poller = null;

  toggleBtn.addEventListener('click', function () {
    var hidden = term.style.display === 'none';
    term.style.display = hidden ? 'block' : 'none';
    toggleBtn.innerHTML = hidden ? '↑ Скрыть' : '↓ Показать';
  });

  function pollStatus(svc) {
    fetch('/?page=mail&ajax=status&service=' + encodeURIComponent(svc) + '&csrf=' + encodeURIComponent(csrf))
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
      .catch(function () { /* сеть моргнула — попробуем на следующем тике */ });
  }

  document.querySelectorAll('.js-install-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var svc = btn.dataset.service;
      if (!confirm('Установить пакет для «' + svc + '» через apt-get?')) return;

      btn.disabled = true;
      btn.innerHTML = '<span class="spinner"></span> Запуск…';

      progressCard.style.display = 'block';
      progressTitle.textContent = 'Идёт установка: ' + svc;
      progressBar.classList.add('progress-stripes');
      progressBar.style.background = '';
      term.style.display = 'block';
      toggleBtn.innerHTML = '↑ Скрыть';
      term.textContent = '';
      progressCard.scrollIntoView({ behavior: 'smooth', block: 'start' });

      var body = new URLSearchParams();
      body.set('csrf', csrf);
      body.set('service', svc);
      body.set('do', 'install_start');

      fetch('/?page=mail', { method: 'POST', body: body })
        .then(function (r) { return r.json(); })
        .then(function () {
          poller = setInterval(function () { pollStatus(svc); }, 1000);
          pollStatus(svc);
        })
        .catch(function () {
          progressTitle.textContent = '⚠️ Не удалось запустить установку';
        });
    });
  });
})();
</script>
