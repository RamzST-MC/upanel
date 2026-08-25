<?php
/**
 * Bootstrap: конфигурация, подключение к SQLite (данные панели),
 * сессии, вспомогательные функции для выполнения shell-команд.
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
date_default_timezone_set('UTC');

define('APP_ROOT', dirname(__DIR__));
define('DATA_DIR', APP_ROOT . '/data');
define('DB_FILE', DATA_DIR . '/panel.sqlite');
define('SITES_ROOT', '/var/www');
define('BACKUP_DIR', '/var/backups/panel');
define('NGINX_SITES_AVAILABLE', '/etc/nginx/sites-available');
define('NGINX_SITES_ENABLED', '/etc/nginx/sites-enabled');

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO('sqlite:' . DB_FILE);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }
    return $pdo;
}

/**
 * Выполнить системную команду с sudo (панель работает от www-data,
 * которому в install.sh выданы точечные права через sudoers).
 * Возвращает [stdout, stderr, exit_code].
 */
function run(string $cmd): array {
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open($cmd, $descriptors, $pipes);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);
    return [$out, $err, $code];
}

function is_logged_in(): bool {
    return !empty($_SESSION['user']);
}

function require_login(): void {
    if (!is_logged_in()) {
        header('Location: /?page=login');
        exit;
    }
}

function csrf_token(): string {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function csrf_check(): void {
    if (($_POST['csrf'] ?? '') !== ($_SESSION['csrf'] ?? '')) {
        http_response_code(403);
        die('Неверный CSRF токен');
    }
}

function flash(string $msg, string $type = 'success'): void {
    $_SESSION['flash'][] = ['msg' => $msg, 'type' => $type];
}

function get_flashes(): array {
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function init_db(): void {
    if (!is_dir(DATA_DIR)) mkdir(DATA_DIR, 0750, true);
    $pdo = db();
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT UNIQUE NOT NULL,
        password_hash TEXT NOT NULL,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS sites (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        domain TEXT UNIQUE NOT NULL,
        aliases TEXT DEFAULT '',
        docroot TEXT NOT NULL,
        php_version TEXT DEFAULT '8.3',
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS backup_jobs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        path TEXT NOT NULL,
        schedule TEXT DEFAULT 'daily',
        active INTEGER DEFAULT 1,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS activity_log (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT,
        action TEXT,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    )");
}

function log_action(string $action): void {
    try {
        $stmt = db()->prepare("INSERT INTO activity_log (username, action) VALUES (?, ?)");
        $stmt->execute([$_SESSION['user'] ?? 'system', $action]);
    } catch (Throwable $e) { /* ignore */ }
}
