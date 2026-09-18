<?php
/**
 * Bootstrap: конфигурация, подключение к SQLite (данные панели),
 * сессии, вспомогательные функции для выполнения shell-команд.
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
date_default_timezone_set('UTC');

define('APP_ROOT', dirname(__DIR__));
define('REPO_ROOT', dirname(APP_ROOT)); // корень git-репозитория (родитель app/)
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
    $pdo->exec("CREATE TABLE IF NOT EXISTS ftp_accounts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT UNIQUE NOT NULL,
        home_dir TEXT NOT NULL,
        quota_mb INTEGER DEFAULT 0,
        readonly INTEGER DEFAULT 0,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    )");
}

function log_action(string $action): void {
    try {
        $stmt = db()->prepare("INSERT INTO activity_log (username, action) VALUES (?, ?)");
        $stmt->execute([$_SESSION['user'] ?? 'system', $action]);
    } catch (Throwable $e) { /* ignore */ }
}

/**
 * Версия панели — читается из файла VERSION в корне репозитория.
 * Чтобы выпустить новую версию: поменяйте содержимое VERSION, закоммитьте и запушьте.
 */
function panel_version(): string {
    $file = REPO_ROOT . '/VERSION';
    if (is_file($file)) {
        $v = trim(file_get_contents($file));
        if ($v !== '') return $v;
    }
    return 'dev';
}

/**
 * Кешированная проверка обновлений панели (git fetch + сравнение с origin).
 * Результат кешируется в файле на $ttl секунд, чтобы не дёргать git
 * при каждом открытии дашборда. Страница «Обновление» может обновить
 * кеш немедленно через panel_update_refresh().
 */
function panel_update_cache_file(): string {
    return DATA_DIR . '/update_check.json';
}

function panel_update_refresh(): array {
    $isGitRepo = is_dir(REPO_ROOT . '/.git');
    $result = ['checked_at' => time(), 'is_git' => $isGitRepo, 'behind' => null, 'remote_version' => null];

    if ($isGitRepo) {
        run('git -C ' . escapeshellarg(REPO_ROOT) . ' fetch --quiet 2>&1');
        [$log] = run('git -C ' . escapeshellarg(REPO_ROOT) . ' log --oneline HEAD..@{u} 2>&1');
        $log = trim($log);
        $result['behind'] = $log === '' ? 0 : count(explode("\n", $log));

        [$branchNow] = run('git -C ' . escapeshellarg(REPO_ROOT) . ' rev-parse --abbrev-ref HEAD 2>&1');
        [$remoteVer, , $remoteVerCode] = run('git -C ' . escapeshellarg(REPO_ROOT) . ' show origin/' . escapeshellarg(trim($branchNow)) . ':VERSION 2>&1');
        $result['remote_version'] = $remoteVerCode === 0 ? trim($remoteVer) : null;
    }

    @file_put_contents(panel_update_cache_file(), json_encode($result));
    return $result;
}

function panel_update_status(int $ttl = 60): array {
    $file = panel_update_cache_file();
    if (is_file($file)) {
        $data = json_decode((string)file_get_contents($file), true);
        if (is_array($data) && isset($data['checked_at']) && (time() - $data['checked_at']) < $ttl) {
            return $data;
        }
    }
    return panel_update_refresh();
}

/**
 * Автопроверка обновлений через системный cron (root-crontab, задание
 * выполняется от имени www-data). Включение/выключение — тумблер на
 * странице "Обновление".
 */
define('PANEL_AUTO_UPDATE_MARKER', '# upanel-auto-update-check');

function panel_auto_update_cron_line(): string {
    [$phpPath] = run('command -v php 2>/dev/null');
    $phpPath = trim($phpPath) ?: '/usr/bin/php';
    $script = APP_ROOT . '/bin/check_update.php';
    return '* * * * * sudo -u www-data ' . escapeshellarg($phpPath) . ' ' . escapeshellarg($script)
        . ' >/dev/null 2>&1 ' . PANEL_AUTO_UPDATE_MARKER;
}

function panel_auto_update_enabled(): bool {
    [$cur] = run('sudo crontab -l 2>/dev/null');
    return str_contains((string)$cur, PANEL_AUTO_UPDATE_MARKER);
}

function panel_auto_update_set(bool $enabled): void {
    [$cur] = run('sudo crontab -l 2>/dev/null');
    $lines = $cur ? explode("\n", rtrim($cur, "\n")) : [];
    $lines = array_values(array_filter($lines, fn($l) => !str_contains($l, PANEL_AUTO_UPDATE_MARKER)));
    if ($enabled) {
        $lines[] = panel_auto_update_cron_line();
    }
    $tmp = tempnam(sys_get_temp_dir(), 'cron');
    file_put_contents($tmp, implode("\n", array_filter($lines)) . "\n");
    run('sudo crontab ' . escapeshellarg($tmp));
    unlink($tmp);
}
