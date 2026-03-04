<?php
/**
 * Dodawanie użytkownika do ANC_users
 * USUŃ TEN PLIK PO UŻYCIU!
 */
 
// ── Konfiguracja bazy ──
require_once dirname(__DIR__, 2) . '/config.php';

$message = '';


    $username = 'ogrodnik';
    $password = 'roslinyRosnaVVdoniczkach';
    $email    = '';
    $role     = 'admin';

    if (empty($username) || empty($password)) {
        $message = '❌ Podaj login i hasło.';
    } elseif (strlen($password) < 8) {
        $message = '❌ Hasło musi mieć min. 8 znaków.';
    } else {
        try {
            $pdo = getDB();

            // Sprawdź czy user już istnieje
            $stmt = $pdo->prepare("SELECT id FROM ANC_users WHERE username = :u LIMIT 1");
            $stmt->execute([':u' => $username]);
            if ($stmt->fetch()) {
                $message = '❌ Użytkownik "' . htmlspecialchars($username) . '" już istnieje.';
            } else {
                // Hashuj hasło bcrypt
                $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

                $stmt = $pdo->prepare("
                    INSERT INTO ANC_users (username, password, email, role, is_active, created_at)
                    VALUES (:username, :password, :email, :role, 1, NOW())
                ");
                $stmt->execute([
                    ':username' => $username,
                    ':password' => $hash,
                    ':email'    => $email ?: null,
                    ':role'     => in_array($role, ['admin','editor','viewer']) ? $role : 'editor',
                ]);

                $message = '✅ Użytkownik "' . htmlspecialchars($username) . '" dodany (ID: ' . $pdo->lastInsertId() . ')';
            }
        } catch (PDOException $e) {
            $message = '❌ Błąd bazy: ' . htmlspecialchars($e->getMessage());
        }
    }
echo $message;
?>
