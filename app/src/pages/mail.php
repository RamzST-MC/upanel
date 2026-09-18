<?php
$services = ['postfix' => 'Исходящая почта (Postfix)', 'dovecot' => 'Входящая почта (Dovecot)', 'spamassassin' => 'Антиспам', 'clamav-daemon' => 'Антивирус'];
// Пакеты apt для установки службы (может отличаться от имени юнита systemd)
$packages = ['postfix' => 'postfix', 'dovecot' => 'dovecot-core dovecot-imapd', 'spamassassin' => 'spamassassin', 'clamav-daemon' => 'clamav clamav-daemon'];
$logDir = DATA_DIR . '/install_logs';

function mail_install_log_path(string $dir, string $svc): string {
    return $dir . '/' . preg_replace('/[^a-z0-9._-]/i', '_', $svc) . '.log';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = $_POST['do'] ?? '';
    $svc = $_POST['service'] ?? '';
    if ($act === 'install' && isset($packages[$svc])) {
        $cmd = 'sudo DEBIAN_FRONTEND=noninteractive apt-get install -y ' . $packages[$svc];
        [$out1, $err1, $code1] = run($cmd);
        [$out2, $err2, $code2] = run('sudo systemctl enable --now ' . escapeshellarg($svc));

        if (!is_dir($logDir)) mkdir($logDir, 0750, true);
        $log = "=== " . date('Y-m-d H:i:s') . " ===\n";
        $log .= "\$ {$cmd}\n" . $out1 . $err1 . "\n[exit code: {$code1}]\n\n";
        $log .= "\$ sudo systemctl enable --now {$svc}\n" . $out2 . $err2 . "\n[exit code: {$code2}]\n";
        file_put_contents(mail_install_log_path($logDir, $svc), $log);

        log_action("apt-get install {$svc} (exit {$code1})");
        if ($code1 === 0) {
            flash("{$svc}: установка завершена успешно");
        } else {
            flash("{$svc}: установка завершилась с ошибкой (код {$code1}), см. лог ниже", 'error');
        }
    } elseif (isset($services[$svc]) && in_array($act, ['start', 'stop', 'restart'], true)) {
        run('sudo systemctl ' . escapeshellarg($act) . ' ' . escapeshellarg($svc));
        flash("{$svc}: {$act}");
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
      <tr>
        <td><?= h($svc) ?></td><td><?= h($desc) ?></td>
        <td><span class="status-dot <?= $active ? 'status-on' : 'status-off' ?>"></span><?= $active ? 'Работает' : 'Остановлен/не установлен' ?></td>
        <td>
          <form method="post" style="display:inline" class="install-form">
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="service" value="<?= h($svc) ?>">
            <?php if (!$installed): ?>
              <button class="btn primary js-install-btn" name="do" value="install" data-label="Устанавливается…" onclick="return confirm('Установить пакет для «<?= h($svc) ?>» через apt-get?');">Установить</button>
            <?php else: ?>
              <button class="btn secondary" name="do" value="start">Старт</button>
              <button class="btn secondary" name="do" value="restart">Рестарт</button>
              <button class="btn danger" name="do" value="stop">Стоп</button>
            <?php endif; ?>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
  </table>
</div>

<?php
// Логи последних попыток установки — показываем, если для службы есть файл лога
$anyLog = false;
foreach ($services as $svc => $desc) {
    $logFile = mail_install_log_path($logDir, $svc);
    if (is_file($logFile)) $anyLog = true;
}
if ($anyLog):
?>
<div class="card">
  <h2>Логи установки</h2>
  <?php foreach ($services as $svc => $desc):
      $logFile = mail_install_log_path($logDir, $svc);
      if (!is_file($logFile)) continue;
      $content = file_get_contents($logFile);
      $ok = strpos($content, '[exit code: 0]') !== false && substr_count($content, '[exit code: 0]') === 2;
  ?>
    <details style="margin-bottom:10px">
      <summary style="cursor:pointer;font-size:13.5px">
        <span class="status-dot <?= $ok ? 'status-on' : 'status-off' ?>"></span>
        <strong><?= h($svc) ?></strong> — <?= $ok ? 'успешно' : 'см. подробности' ?>
      </summary>
      <pre class="term" style="height:220px;margin-top:8px"><?= h($content) ?></pre>
    </details>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<script>
// При отправке формы установки показываем спиннер на кнопке.
// confirm() в onclick может отменить submit — тогда этот код просто не выполнится.
document.querySelectorAll('.install-form').forEach(function (form) {
  form.addEventListener('submit', function () {
    var btn = form.querySelector('.js-install-btn');
    if (!btn) return;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span> ' + (btn.dataset.label || 'Устанавливается…');
  });
});
</script>
