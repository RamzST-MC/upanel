<?php
$services = ['postfix' => 'Исходящая почта (Postfix)', 'dovecot' => 'Входящая почта (Dovecot)', 'spamassassin' => 'Антиспам', 'clamav-daemon' => 'Антивирус'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = $_POST['do'] ?? '';
    $svc = $_POST['service'] ?? '';
    if (isset($services[$svc]) && in_array($act, ['start', 'stop', 'restart'], true)) {
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
    ?>
      <tr>
        <td><?= h($svc) ?></td><td><?= h($desc) ?></td>
        <td><span class="status-dot <?= $active ? 'status-on' : 'status-off' ?>"></span><?= $active ? 'Работает' : 'Остановлен/не установлен' ?></td>
        <td>
          <form method="post" style="display:inline">
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="service" value="<?= h($svc) ?>">
            <button class="btn secondary" name="do" value="start">Старт</button>
            <button class="btn secondary" name="do" value="restart">Рестарт</button>
            <button class="btn danger" name="do" value="stop">Стоп</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
  </table>
</div>
