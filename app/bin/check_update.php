<?php
// Вызывается из crontab (см. "Обновление" -> "Автопроверка обновлений").
// Обновляет общий кеш data/update_check.json, который читает дашборд,
// не дожидаясь, пока кто-то откроет панель в браузере.
// Использование: php check_update.php
require __DIR__ . '/../src/bootstrap.php';
init_db();

$prevFile = panel_update_cache_file();
$prevBehind = 0;
if (is_file($prevFile)) {
    $prev = json_decode((string)file_get_contents($prevFile), true);
    $prevBehind = is_array($prev) ? (int)($prev['behind'] ?? 0) : 0;
}

$status = panel_update_refresh();
$nowBehind = (int)($status['behind'] ?? 0);

// Пишем в лог действий только при переходе "обновлений нет" -> "есть обновление",
// чтобы не засорять лог одинаковой записью каждый час.
if ($nowBehind > 0 && $prevBehind === 0) {
    log_action('Автопроверка: доступно обновление панели (' . $nowBehind . ' коммитов'
        . (!empty($status['remote_version']) ? ', версия ' . $status['remote_version'] : '') . ')');
}

echo json_encode($status, JSON_UNESCAPED_UNICODE) . "\n";
