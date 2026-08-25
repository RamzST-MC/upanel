<?php
/** @var string $page */
/** @var string $content */
$menu = menu_structure();
[$la1, $la5, $la15] = sys_getloadavg();
$uptime = @trim(shell_exec('uptime -p')) ?: '—';
$disk = @disk_free_space('/');
$diskTotal = @disk_total_space('/');
$diskUsedPct = $diskTotal ? round((1 - $disk / $diskTotal) * 100) : 0;
?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>UPanel — панель управления сервером</title>
<link rel="stylesheet" href="/assets/style.css">
</head>
<body>
<header class="topbar">
    <div class="brand">🐧 UPanel</div>
    <div class="topinfo">
        <span title="Load average">LA: <?= h(sprintf('%.2f, %.2f, %.2f', $la1, $la5, $la15)) ?></span>
        <span title="Uptime"><?= h($uptime) ?></span>
        <span title="Диск">Диск: <?= $diskUsedPct ?>%</span>
    </div>
    <div class="topuser">
        <?php if (is_logged_in()): ?>
            <span><?= h($_SESSION['user']) ?></span>
            <a href="/?page=logout">Выход</a>
        <?php endif; ?>
    </div>
</header>
<div class="layout">
    <?php if (is_logged_in()): ?>
    <nav class="sidebar">
        <?php foreach ($menu as $section => $items): ?>
            <div class="section-title"><?= h($section) ?></div>
            <?php foreach ($items as [$label, $slug, $icon]): ?>
                <a class="menu-item <?= $page === $slug ? 'active' : '' ?>" href="/?page=<?= h($slug) ?>">
                    <span class="icon"><?= $icon ?></span> <?= h($label) ?>
                </a>
            <?php endforeach; ?>
        <?php endforeach; ?>
        <div class="sidebar-footer">UPanel v<?= h(panel_version()) ?></div>
    </nav>
    <?php endif; ?>
    <main class="content">
        <?php foreach (get_flashes() as $f): ?>
            <div class="flash flash-<?= h($f['type']) ?>"><?= h($f['msg']) ?></div>
        <?php endforeach; ?>
        <?= $content ?>
    </main>
</div>
</body>
</html>
