<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $pid = (int)($_POST['pid'] ?? 0);
    if ($pid) { run('sudo kill -9 ' . $pid); flash("Процесс {$pid} завершён"); }
    header('Location: /?page=processes');
    exit;
}
[$psOut] = run("ps -eo pid,user,pcpu,pmem,comm --sort=-pcpu | head -50");
$lines = explode("\n", trim($psOut));
$header = array_shift($lines);
?>
<h1>Менеджер процессов</h1>
<div class="card">
  <table>
    <tr><th>PID</th><th>Пользователь</th><th>CPU%</th><th>MEM%</th><th>Команда</th><th></th></tr>
    <?php foreach ($lines as $line):
        $parts = preg_split('/\s+/', trim($line), 5);
        if (count($parts) < 5) continue;
        [$pid, $user, $cpu, $mem, $cmd] = $parts;
    ?>
      <tr>
        <td><?= h($pid) ?></td><td><?= h($user) ?></td><td><?= h($cpu) ?></td><td><?= h($mem) ?></td><td><?= h($cmd) ?></td>
        <td>
          <form method="post" onsubmit="return confirm('Завершить процесс <?= h($pid) ?>?')">
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="pid" value="<?= h($pid) ?>">
            <button class="btn danger" type="submit">Kill</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
  </table>
</div>
