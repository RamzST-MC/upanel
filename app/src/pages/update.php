<?php
/**
 * Проверка и установка обновлений панели через git + вкладка "Что нового"
 * с содержимым CHANGELOG.md.
 */
$isGitRepo = is_dir(REPO_ROOT . '/.git');
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

// Простой рендер CHANGELOG.md: "## " -> заголовок версии, "- " -> пункт списка
$changelogFile = REPO_ROOT . '/CHANGELOG.md';
$changelogHtml = '';
if (is_file($changelogFile)) {
    foreach (explode("\n", file_get_contents($changelogFile)) as $line) {
        $line = rtrim($line);
        if ($line === '') continue;
        if (str_starts_with($line, '## ')) {
            $changelogHtml .= '<h3>' . h(substr($line, 3)) . '</h3>';
        } elseif (str_starts_with($line, '- ')) {
            $changelogHtml .= '<li>' . h(substr($line, 2)) . '</li>';
        } else {
            $changelogHtml .= '<p>' . h($line) . '</p>';
        }
    }
    // оборачиваем идущие подряд <li> в <ul> простым способом
    $changelogHtml = preg_replace('#(<li>.*?</li>)(?!\s*<li>)#s', '<ul>$1</ul>', $changelogHtml);
    $changelogHtml = str_replace('</ul><ul>', '', $changelogHtml);
} else {
    $changelogHtml = '<p style="color:var(--muted)">CHANGELOG.md не найден.</p>';
}
?>
<h1>Обновление</h1>

<div class="card">
  <h2>Версия панели: <strong><?= h(panel_version()) ?></strong></h2>
  <?php if ($isGitRepo): ?>
    <details style="margin-top:10px">
      <summary style="cursor:pointer;color:var(--muted);font-size:13px">Технические детали</summary>
      <table style="margin-top:10px">
        <tr><th>Ветка</th><td><?= h(trim($branch)) ?></td></tr>
        <tr><th>Коммит</th><td><?= h(trim($currentCommit)) ?></td></tr>
        <tr><th>Репозиторий</th><td><?= h(trim($remoteUrl)) ?></td></tr>
      </table>
    </details>
  <?php endif; ?>
</div>

<div class="tabs">
  <div class="tab-buttons">
    <button type="button" class="tab-btn active" data-tab="tab-update">Проверка обновлений</button>
    <button type="button" class="tab-btn" data-tab="tab-changelog">Что нового</button>
  </div>

  <div id="tab-update" class="tab-panel active">
    <?php if (!$isGitRepo): ?>
      <div class="card">
        <p style="color:#a11">Панель установлена не через git-клон, поэтому автообновление недоступно.
        Переустановите её через <code>install.sh</code>, который клонирует репозиторий.</p>
      </div>
    <?php else: ?>
      <div class="card">
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
  </div>

  <div id="tab-changelog" class="tab-panel">
    <div class="card changelog">
      <?= $changelogHtml ?>
    </div>
  </div>
</div>

<script>
document.querySelectorAll('.tab-btn').forEach(function (btn) {
  btn.addEventListener('click', function () {
    document.querySelectorAll('.tab-btn').forEach(function (b) { b.classList.remove('active'); });
    document.querySelectorAll('.tab-panel').forEach(function (p) { p.classList.remove('active'); });
    btn.classList.add('active');
    document.getElementById(btn.dataset.tab).classList.add('active');
  });
});
</script>
