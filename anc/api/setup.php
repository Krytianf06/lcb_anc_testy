<?php

require_once dirname(__DIR__, 2) . '/config.php';

$password = 'admin';
$hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]);

echo "<h2>Generator hash bcrypt</h2>";
echo "<p><b>Hasło:</b> {$password}</p>";
echo "<p><b>Hash:</b> {$hash}</p>";
echo "<p><b>Weryfikacja:</b> " . (password_verify($password, $hash) ? '✅ OK' : '❌ BŁĄD') . "</p>";

try {
    $pdo = getDB();
    $prefix = DB_PREFIX;

    $stmt = $pdo->prepare("UPDATE {$prefix}users SET password = ? WHERE username = 'admin'");
    $stmt->execute([$hash]);

    if ($stmt->rowCount() > 0) {
        echo "<p style='color:green; font-size:20px;'><b>✅ Hasło admina zaktualizowane w bazie!</b></p>";
    } else {
        echo "<p style='color:orange;'><b>⚠️ Nie znaleziono użytkownika 'admin' w bazie. Tworzę...</b></p>";

        $stmt = $pdo->prepare("INSERT INTO {$prefix}users (username, password, email, role, is_active) VALUES ('admin', ?, 'admin@localhost', 'admin', 1)");
        $stmt->execute([$hash]);
        echo "<p style='color:green;'><b>✅ Użytkownik admin utworzony!</b></p>";
    }

    $stmt = $pdo->prepare("SELECT id, username, password FROM {$prefix}users WHERE username = 'admin'");
    $stmt->execute();
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    echo "<h3>Stan w bazie:</h3>";
    echo "<p><b>ID:</b> {$user['id']}</p>";
    echo "<p><b>Username:</b> {$user['username']}</p>";
    echo "<p><b>Hash w bazie:</b> {$user['password']}</p>";
    echo "<p><b>password_verify('admin', hash):</b> " . (password_verify('admin', $user['password']) ? '✅ OK' : '❌ BŁĄD') . "</p>";

} catch (PDOException $e) {
    echo "<p style='color:red;'><b>❌ Błąd bazy: " . $e->getMessage() . "</b></p>";
}

echo "<hr>";
echo "<p style='color:red;'><b>⚠️ USUŃ TEN PLIK PO UŻYCIU! (api/setup.php)</b></p>";