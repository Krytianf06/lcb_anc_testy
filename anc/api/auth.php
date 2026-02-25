<?php
// LOGGING DO PLIKU
function logDebug($msg) {
    $log = date('Y-m-d H:i:s') . " $msg\n";
    //file_put_contents(__DIR__ . '/blad.log', $log, FILE_APPEND | LOCK_EX);
}

logDebug('=== SCRIPT START ===');

// 1. TEST CONFIG
logDebug('Loading config...');
try {
    require_once dirname(__DIR__, 2) . '/config.php';
    logDebug('CONFIG OK');
} catch (Throwable $e) {
    logDebug('CONFIG FATAL: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Config failed: ' . $e->getMessage()]);
    exit;
}

// 2. TEST FUNKCJE
logDebug('Testing functions...');
$missing = [];
if (!function_exists('getDB')) $missing[] = 'getDB';
if (!function_exists('jsonError')) $missing[] = 'jsonError';
if (!function_exists('jsonSuccess')) $missing[] = 'jsonSuccess';

if (!empty($missing)) {
    logDebug('MISSING FUNCTIONS: ' . implode(', ', $missing));
    http_response_code(500);
    echo json_encode(['error' => 'Missing: ' . implode(', ', $missing)]);
    exit;
}
logDebug('FUNCTIONS OK');

// 3. TEST STAŁE
$constants = ['DB_PREFIX', 'BCRYPT_COST', 'SESSION_LIFETIME', 'APP_DEBUG'];
$missingConstants = [];
foreach ($constants as $const) {
    if (!defined($const)) $missingConstants[] = $const;
}
if (!empty($missingConstants)) {
    logDebug('MISSING CONSTANTS: ' . implode(', ', $missingConstants));
    http_response_code(500);
    echo json_encode(['error' => 'Missing constants: ' . implode(', ', $missingConstants)]);
    exit;
}
logDebug('CONSTANTS OK');

logDebug('ALL TESTS PASSED - running normal code');

$action = $_GET['action'] ?? '';
logDebug("ACTION: $action");

switch ($action) {
    case 'debug':
        if (!APP_DEBUG) {
            jsonError('Debug wyłączony', 403);
        }

        try {
            $pdo = getDB();
            $dbOk = true;
            $dbError = null;
        } catch (Exception $e) {
            $dbOk = false;
            $dbError = $e->getMessage();
        }

        $userInfo = null;
        $hashInfo = null;
        $testVerify = null;

        if ($dbOk) {
            try {
                $stmt = $pdo->query("SELECT id, username, LEFT(password, 7) as hash_prefix, LENGTH(password) as hash_len, is_active, role FROM " . DB_PREFIX . "users LIMIT 10");
                $userInfo = $stmt->fetchAll();

                $stmt = $pdo->prepare("SELECT password FROM " . DB_PREFIX . "users WHERE username = 'admin'");
                $stmt->execute();
                $adminRow = $stmt->fetch();
                if ($adminRow) {
                    $hash = $adminRow['password'];
                    $hashInfo = [
                        'length' => strlen($hash),
                        'prefix' => substr($hash, 0, 7),
                        'is_bcrypt' => (bool)preg_match('/^\$2[yab]\$/', $hash),
                    ];
                    $testVerify = password_verify('admin', $hash);
                }
            } catch (Exception $e) {
                $userInfo = 'Error: ' . $e->getMessage();
            }

            try {
                $stmt = $pdo->query("SELECT COUNT(*) as cnt FROM " . DB_PREFIX . "sessions");
                $sessCount = $stmt->fetch()['cnt'];
            } catch (Exception $e) {
                $sessCount = 'Error: ' . $e->getMessage();
            }
        }

        jsonSuccess([
            'db_connected' => $dbOk,
            'db_error' => $dbError,
            'php_version' => PHP_VERSION,
            'password_hash_test' => password_hash('test', PASSWORD_BCRYPT, ['cost' => 10]),
            'users' => $userInfo,
            'admin_hash_info' => $hashInfo,
            'admin_password_verify_test' => $testVerify,
            'sessions_count' => $sessCount ?? 0,
            'input_method_test' => $_SERVER['REQUEST_METHOD'],
            'content_type' => $_SERVER['CONTENT_TYPE'] ?? 'not set',
            'auth_header' => isset($_SERVER['HTTP_AUTHORIZATION']) ? 'present' : 'missing',
        ]);
        break;

    case 'login':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonError('Metoda POST wymagana', 405);
        }

        $debugInfo = [];

        try {

            $rawInput = file_get_contents('php://input');
            $debugInfo['raw_input_length'] = strlen($rawInput);

            $input = null;

            if (!empty($rawInput)) {
                $input = json_decode($rawInput, true);
                $debugInfo['json_decode_ok'] = is_array($input);
            }

            if (!is_array($input) || empty($input)) {
                if (!empty($_POST)) {
                    $input = $_POST;
                    $debugInfo['source'] = '$_POST';
                }
            } else {
                $debugInfo['source'] = 'json_body';
            }

            if (!is_array($input)) {
                $input = [];
            }

            $username = trim($input['username'] ?? '');
            $password = $input['password'] ?? '';
            $debugInfo['username'] = $username;
            $debugInfo['password_length'] = strlen($password);

            if (empty($username) || empty($password)) {
                $err = 'Podaj login i hasło';
                if (APP_DEBUG) $err .= ' | debug: ' . json_encode($debugInfo);
                jsonError($err, 400);
            }

            $pdo = getDB();
            //$debugInfo['db_connected'] = true;

            $stmt = $pdo->prepare("
                SELECT id, username, password, email, role, is_active 
                FROM " . DB_PREFIX . "users 
                WHERE username = :username
                LIMIT 1
            ");
            $stmt->execute([':username' => $username]);
            $user = $stmt->fetch();

            if (!$user) {
                $err = 'Nieprawidłowy login lub hasło';
                if (APP_DEBUG) $err .= ' | user not found';

                password_verify($password, '$2y$10$abcdefghijklmnopqrstuuABCDEFGHIJKLMNOPQRSTUVWXYZ012');
                jsonError($err, 401);
            }

            $debugInfo['user_found'] = true;
            $debugInfo['user_id'] = $user['id'];
            $debugInfo['is_active'] = $user['is_active'];

            if (!$user['is_active']) {
                jsonError('Konto jest zablokowane', 403);
            }

            $hash = $user['password'];
            $debugInfo['hash_length'] = strlen($hash);
            $debugInfo['hash_prefix'] = substr($hash, 0, 7);
            $isBcrypt = (bool)preg_match('/^\$2[yab]\$/', $hash);
            $debugInfo['is_bcrypt'] = $isBcrypt;

            $passwordOk = false;

            if ($isBcrypt) {
                $passwordOk = password_verify($password, $hash);
                $debugInfo['bcrypt_verify'] = $passwordOk;
            }

            if (!$passwordOk && !$isBcrypt) {
                if ($password === $hash) {
                    $passwordOk = true;
                    $debugInfo['match'] = 'plain';
                } elseif (md5($password) === $hash) {
                    $passwordOk = true;
                    $debugInfo['match'] = 'md5';
                }
            }

            if ($passwordOk && !$isBcrypt) {
                $newHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => BCRYPT_COST]);
                $stmt = $pdo->prepare("UPDATE " . DB_PREFIX . "users SET password = :pw WHERE id = :id");
                $stmt->execute([':pw' => $newHash, ':id' => $user['id']]);
                $debugInfo['rehashed'] = true;
            }

            if (!$passwordOk) {
                $err = 'Nieprawidłowy login lub hasło';
                if (APP_DEBUG) $err .= ' | debug: ' . json_encode($debugInfo);
                try {
                    logAction(0, 'login_failed', 'user', (int)$user['id'], "Nieudane logowanie: {$username}");
                } catch (Exception $e) {}
                jsonError($err, 401);
            }

			$token = bin2hex(random_bytes(32));
			$tokenHash = hash('sha256', $token);
			$expiresAt = date('Y-m-d H:i:s', time() + SESSION_LIFETIME);

			try {
				$stmt = $pdo->prepare("DELETE FROM " . DB_PREFIX . "sessions WHERE user_id = :uid AND expires_at < NOW()");
				$stmt->execute([':uid' => $user['id']]);
			} catch (Exception $e) {
				$debugInfo['cleanup_error'] = $e->getMessage();
			}

			// ✅ POPRAWIONY INSERT z created_at
			$stmt = $pdo->prepare("
				INSERT INTO " . DB_PREFIX . "sessions (user_id, token, ip_address, user_agent, expires_at, created_at)
				VALUES (:user_id, :token, :ip, :ua, :expires, NOW())
			");
			$stmt->execute([
				':user_id'  => $user['id'],
				':token'    => $tokenHash,
				':ip'       => $_SERVER['REMOTE_ADDR'] ?? '',
				':ua'       => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
				':expires'  => $expiresAt
			]);
logDebug('FUNCTIONS OK');

            $stmt = $pdo->prepare("UPDATE " . DB_PREFIX . "users SET last_login = NOW() WHERE id = :id");
            $stmt->execute([':id' => $user['id']]);

            try {
                logAction((int)$user['id'], 'login', 'user', (int)$user['id'], "Zalogowano: {$username}");
            } catch (Exception $e) {}

            $response = [
                'token'    => $token,
                'expires'  => $expiresAt,
                'user'     => [
                    'id'       => (int)$user['id'],
                    'username' => $user['username'],
                    'email'    => $user['email'] ?? '',
                    'role'     => $user['role']
                ]
            ];

            if (APP_DEBUG) {
                $response['_debug'] = $debugInfo;
            }

            jsonSuccess($response, 'Zalogowano pomyślnie');

        } catch (Exception $e) {
            $err = 'Błąd serwera podczas logowania';
            if (APP_DEBUG) {
                $err .= ': ' . $e->getMessage();
                $debugInfo['exception'] = $e->getMessage();
                $debugInfo['trace'] = $e->getTraceAsString();
            }
            jsonError($err, 500);
        }
        break;

    case 'logout':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonError('Metoda POST wymagana', 405);
        }

        try {
            $pdo = getDB();
            $tokenRaw = getBearerToken();

            if (!$tokenRaw) {
                jsonSuccess(null, 'Wylogowano (brak tokena)');
            }

            $tokenHash = hash('sha256', $tokenRaw);

            $stmt = $pdo->prepare("
                SELECT s.user_id, u.username
                FROM " . DB_PREFIX . "sessions s
                JOIN " . DB_PREFIX . "users u ON u.id = s.user_id
                WHERE s.token = :token
                LIMIT 1
            ");
            $stmt->execute([':token' => $tokenHash]);
            $session = $stmt->fetch();

            $stmt = $pdo->prepare("DELETE FROM " . DB_PREFIX . "sessions WHERE token = :token");
            $stmt->execute([':token' => $tokenHash]);

            if ($session) {
                try {
                    logAction((int)$session['user_id'], 'logout', 'user', (int)$session['user_id'], 
                        "Wylogowano: {$session['username']}");
                } catch (Exception $e) {}
            }

            jsonSuccess(null, 'Wylogowano pomyślnie');

        } catch (Exception $e) {

            jsonSuccess(null, 'Wylogowano (z ostrzeżeniem)');
        }
        break;

    case 'check':
        $user = optionalAuth();

        if (!$user) {
            jsonError('Sesja wygasła lub nieprawidłowy token', 401);
        }

        jsonSuccess([
            'user' => [
                'id'       => (int)$user['id'],
                'username' => $user['username'],
                'email'    => $user['email'] ?? '',
                'role'     => $user['role']
            ]
        ], 'Sesja aktywna');
        break;

    case 'password':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonError('Metoda POST wymagana', 405);
        }

        $user = requireAuth();
        $pdo = getDB();

        $rawInput = file_get_contents('php://input');
        $input = json_decode($rawInput, true);
        if (!is_array($input)) $input = $_POST;

        $currentPassword = $input['current_password'] ?? '';
        $newPassword     = $input['new_password'] ?? '';
        $confirmPassword = $input['confirm_password'] ?? '';

        if (empty($currentPassword) || empty($newPassword)) {
            jsonError('Podaj obecne i nowe hasło', 400);
        }

        if (strlen($newPassword) < 6) {
            jsonError('Nowe hasło musi mieć minimum 6 znaków', 400);
        }

        if ($newPassword !== $confirmPassword) {
            jsonError('Nowe hasła nie są identyczne', 400);
        }

        $stmt = $pdo->prepare("SELECT password FROM " . DB_PREFIX . "users WHERE id = :id");
        $stmt->execute([':id' => $user['id']]);
        $row = $stmt->fetch();

        if (!password_verify($currentPassword, $row['password'])) {
            jsonError('Obecne hasło jest nieprawidłowe', 400);
        }

        $newHash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => BCRYPT_COST]);
        $stmt = $pdo->prepare("
            UPDATE " . DB_PREFIX . "users 
            SET password = :password, updated_at = NOW()
            WHERE id = :id
        ");
        $stmt->execute([':password' => $newHash, ':id' => $user['id']]);

        $currentToken = getBearerToken();
        $currentTokenHash = $currentToken ? hash('sha256', $currentToken) : '';

        $stmt = $pdo->prepare("
            DELETE FROM " . DB_PREFIX . "sessions 
            WHERE user_id = :user_id AND token != :current_token
        ");
        $stmt->execute([
            ':user_id'       => $user['id'],
            ':current_token' => $currentTokenHash
        ]);

        try {
            logAction((int)$user['id'], 'password_change', 'user', (int)$user['id'], 'Zmieniono hasło');
        } catch (Exception $e) {}

        jsonSuccess(null, 'Hasło zmienione pomyślnie');
        break;

    default:
        jsonError('Nieznana akcja. Dostępne: login, logout, check, password, debug', 400);
        break;
}