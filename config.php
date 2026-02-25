<?php

define('DB_HOST', 'db.web.amu.edu.pl');
define('DB_NAME', 'lcb_anc');
define('DB_USER', 'lcb_anc');
define('DB_PASS', 'T7$kL9@mQ2pZx$1');
define('DB_CHARSET', 'utf8mb4');
define('DB_PREFIX', 'ANC_');

define('APP_DEBUG', false);
define('APP_URL', 'https://lcb-anc.web.amu.edu.pl');
define('UPLOAD_DIR', __DIR__ . '/../img/');
define('UPLOAD_URL', '/img/');
define('UPLOAD_MAX_SIZE', 10 * 1024 * 1024); 
define('ALLOWED_MIME_TYPES', ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml']);
define('ALLOWED_EXTENSIONS', ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg']);
define('SESSION_LIFETIME', 86400); 
define('BCRYPT_COST', 12);


header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');


if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}


function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            jsonError('Błąd połączenia z bazą danych' . (APP_DEBUG ? ': ' . $e->getMessage() : ''), 500);
        }
    }
    return $pdo;
}


function jsonSuccess(mixed $data = null, string $message = 'OK', int $code = 200): void {
    http_response_code($code);
    echo json_encode([
        'success' => true,
        'message' => $message,
        'data'    => $data,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}


function jsonError(string $message = 'Błąd', int $code = 400): void {
    http_response_code($code);
    echo json_encode([
        'success' => false,
        'message' => $message,
        'data'    => null,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}


function getInput(): array {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}


function getMethod(): string {
    return strtoupper($_SERVER['REQUEST_METHOD']);
}

function requireAuth(): array {
    $token = getBearerToken();
    if (!$token) {
        jsonError('Brak tokena autoryzacji', 401);
    }

    $db = getDB();
    $tokenHash = hash('sha256', $token);
    $stmt = $db->prepare('
        SELECT s.user_id, s.expires_at, u.username, u.role, u.is_active
        FROM ' . DB_PREFIX . 'sessions s
        JOIN ' . DB_PREFIX . 'users u ON u.id = s.user_id
        WHERE s.token = :token
    ');
    $stmt->execute([':token' => $tokenHash]);
    $session = $stmt->fetch();

    if (!$session) {
        jsonError('Nieprawidłowy token', 401);
    }
    if (strtotime($session['expires_at']) < time()) {
        
        $db->prepare('DELETE FROM ' . DB_PREFIX . 'sessions WHERE token = :token')
           ->execute([':token' => $tokenHash]);
        jsonError('Sesja wygasła', 401);
    }
    if (!$session['is_active']) {
        jsonError('Konto nieaktywne', 403);
    }

    return [
        'id'       => (int)$session['user_id'],
        'username' => $session['username'],
        'role'     => $session['role'],
    ];
}

function optionalAuth(): ?array {
    $token = getBearerToken();
    if (!$token) return null;

    try {
        $db = getDB();
        $tokenHash = hash('sha256', $token);
        $stmt = $db->prepare('
            SELECT s.user_id, s.expires_at, u.username, u.role, u.is_active
            FROM ' . DB_PREFIX . 'sessions s
            JOIN ' . DB_PREFIX . 'users u ON u.id = s.user_id
            WHERE s.token = :token AND s.expires_at > NOW() AND u.is_active = 1
        ');
        $stmt->execute([':token' => $tokenHash]);
        $session = $stmt->fetch();
        if ($session) {
            return [
                'id'       => (int)$session['user_id'],
                'username' => $session['username'],
                'role'     => $session['role'],
            ];
        }
    } catch (Exception $e) {}

    return null;
}


function requireAdmin(): array {
    $user = requireAuth();
    if ($user['role'] !== 'admin') {
        jsonError('Brak uprawnień administratora', 403);
    }
    return $user;
}

function getBearerToken(): ?string {
    $headers = '';
    if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $headers = $_SERVER['HTTP_AUTHORIZATION'];
    } elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        $headers = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    } elseif (function_exists('apache_request_headers')) {
        $reqHeaders = apache_request_headers();
        $headers = $reqHeaders['Authorization'] ?? '';
    }

    if (preg_match('/Bearer\s+(.+)$/i', $headers, $matches)) {
        return trim($matches[1]);
    }
    return null;
}

function logAction(int $userId, string $action, ?string $entity = null, ?int $entityId = null, ?string $details = null): void {
    try {
        $db = getDB();
        $stmt = $db->prepare('
            INSERT INTO ' . DB_PREFIX . 'logs (user_id, action, entity, entity_id, details, ip_address, user_agent)
            VALUES (:user_id, :action, :entity, :entity_id, :details, :ip, :ua)
        ');
        $stmt->execute([
            ':user_id'   => $userId,
            ':action'    => $action,
            ':entity'    => $entity,
            ':entity_id' => $entityId,
            ':details'   => $details,
            ':ip'        => $_SERVER['REMOTE_ADDR'] ?? null,
            ':ua'        => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
        ]);
    } catch (Exception $e) {
        
    }
}
