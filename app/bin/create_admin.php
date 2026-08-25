<?php
// Использование: php create_admin.php <username> <password>
require __DIR__ . '/../src/bootstrap.php';

if ($argc < 3) {
    fwrite(STDERR, "Usage: php create_admin.php <username> <password>\n");
    exit(1);
}
[$script, $username, $password] = $argv;

init_db();
$hash = password_hash($password, PASSWORD_DEFAULT);
$pdo = db();
$stmt = $pdo->prepare(
    'INSERT OR REPLACE INTO users (id, username, password_hash)
     VALUES ((SELECT id FROM users WHERE username = ?), ?, ?)'
);
$stmt->execute([$username, $username, $hash]);
echo "OK\n";
