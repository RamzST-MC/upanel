<?php
require __DIR__ . '/../src/bootstrap.php';
session_start();
require __DIR__ . '/../src/menu.php';
init_db();

$page = $_GET['page'] ?? 'dashboard';

// Страницы, доступные без логина
$public = ['login'];

if ($page === 'logout') {
    log_action('logout');
    session_destroy();
    header('Location: /?page=login');
    exit;
}

if (!in_array($page, $public, true)) {
    require_login();
}

$allowed = [
    'login', 'dashboard', 'accounts', 'services', 'update', 'sites', 'php', 'site_logs',
    'database', 'mail', 'mail_accounts', 'dnsbl', 'dns', 'files', 'webshell',
    'cron', 'monitoring', 'sysinfo', 'processes', 'ssl', 'backups', 'firewall',
    'account',
];
if (!in_array($page, $allowed, true)) {
    $page = 'dashboard';
}

$pageFile = __DIR__ . '/../src/pages/' . $page . '.php';
ob_start();
if (file_exists($pageFile)) {
    require $pageFile;
} else {
    echo '<div class="card">Раздел в разработке.</div>';
}
$content = ob_get_clean();

if ($page === 'login') {
    echo $content;
} else {
    require __DIR__ . '/../src/templates/layout.php';
}
