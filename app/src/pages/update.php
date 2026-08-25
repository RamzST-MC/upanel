<?php
/**
 * Проверка и установка обновлений панели через git.
 * Панель должна быть установлена через install.sh (git clone) —
 * тогда REPO_ROOT содержит .git и есть upstream-ветка для сравнения.
 */
$isGitRepo = is_dir(REPO_ROOT . '/.git');
$message = '';
$diffLog = '';
$behind = null;
$remoteVersion = null;

function git_run(string $args): array {
    return run('git -C ' . escapeshellarg(REPO_ROOT) . ' ' . $args . ' 2>&1');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isGitRepo) {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'check') {
        git_run('fetch --quiet');
        [$log] = git_run("log --oneline HEAD..@{u}");
        $diffLog = trim($log);
        $behind = $diffLog === '' ? 0 : count(explode("\n", $diffLog));
        [$branchNow] = git_run('rev-parse --abbrev-ref HEAD');
        [$remoteVer, , $remoteVerCode] = git_run("show origin/" . trim($branchNow) . ":VERSION");
        $remoteVersion = $remoteVerCode === 0 ? trim($remoteVer) : null;
        log_action('Проверка обновлений панели');
    }

    if ($action === 'update') {
        git_run('fetch --quiet');
        [$out, , $code] = git_run('pull --ff-only');
        run('sudo chown -R www-data:www-data ' . escapeshellarg(REPO_ROOT));
        if ($code === 0) {
            log_action('Панель обновлена через git pull');
            flash('Панель обновлена: ' . trim($out));
        } else {
            flash('Ошибка обновления: ' . trim($out), 'error');
        }
        header('Location: /?page=update');
        exit;
    }
}

[$currentCommit] = $isGitRepo ? git_run("log -1 --format='%h  %ci  %s'") : ['—'];
[$branch] = $isGitRepo ? git_run('rev-parse --abbrev-ref HEAD') : ['—'];
[$remoteUrl, , $remoteCode] = $isGitRepo ? git_run('remote get-url origin') : ['—', '', 1];
if ($remoteCode !== 0) $remoteUrl = 'не настроен';
?>
<h1>Проверка обновлений</h1>

<?php if (!$isGitRepo): ?>
  <div class="card">
    <p style="color:#a11">Панель установлена не через git-клон, поэтому автообновление недоступно.
    Переустановите её через <code>install.sh</code>, который клонирует репозиторий, чтобы включить эту функцию.</p>
  </div>
<?php else: ?>

  <div class="card">
    <h2>Текущая версия</h2>
    <table>
      <tr><th>Версия панели</th><td><strong><?= h(panel_version()) ?></strong></td></tr>
      <tr><th>Ветка</th><td><?= h(trim($branch)) ?></td></tr>
      <tr><th>Коммит</th><td><?= h(trim($currentCommit)) ?></td></tr>
      <tr><th>Репозиторий</th><td><?= h(trim($remoteUrl)) ?></td></tr>
    </table>
  </div>

  <div class="card">
    <h2>Проверить обновления</h2>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="action" value="check">
      <button class="btn" type="submit">🔄 Проверить обновление</button>
    </form>

    <?php if ($behind !== null): ?>
      <?php if ($behind === 0): ?>
        <p style="margin-top:14px;color:#166534">✅ Установлена последняя версия (<?= h(panel_version()) ?>).</p>
      <?php else: ?>
        <p style="margin-top:14px;color:#a15c00">
          ⬇️ Доступно новых коммитов: <?= (int)$behind ?>
          <?php if ($remoteVersion && $remoteVersion !== panel_version()): ?>
            — новая версия: <strong><?= h($remoteVersion) ?></strong> (сейчас <?= h(panel_version()) ?>)
          <?php endif; ?>
        </p>
        <pre class="term" style="height:auto;color:#ccc"><?= h($diffLog) ?></pre>
        <form method="post" onsubmit="return confirm('Обновить панель сейчас? (git pull)')">
          <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="action" value="update">
          <button class="btn" type="submit" style="margin-top:10px">⬆️ Обновить сейчас</button>
        </form>
      <?php endif; ?>
    <?php endif; ?>
  </div>

<?php endif; ?>
