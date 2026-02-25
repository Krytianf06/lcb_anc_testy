<?php

define('PHP_INCLUDES_DIR', realpath(__DIR__ . '/../php'));

$requestedFile = $_GET['file'] ?? '';

if (empty($requestedFile)) {
    http_response_code(400);
    echo '<div class="text-red-500 text-sm p-2">Błąd: nie podano nazwy pliku</div>';
    exit;
}

if (!preg_match('/^[a-zA-Z0-9_\-\.]+$/', $requestedFile)) {
    http_response_code(400);
    echo '<div class="text-red-500 text-sm p-2">Błąd: niedozwolone znaki w nazwie pliku</div>';
    exit;
}

if (!str_ends_with(strtolower($requestedFile), '.php')) {
    http_response_code(400);
    echo '<div class="text-red-500 text-sm p-2">Błąd: dozwolone tylko pliki .php</div>';
    exit;
}

if (strpos($requestedFile, '..') !== false) {
    http_response_code(403);
    echo '<div class="text-red-500 text-sm p-2">Błąd: wykryto próbę path traversal</div>';
    error_log("[PHP-INCLUDE] Path traversal attempt: $requestedFile from {$_SERVER['REMOTE_ADDR']}");
    exit;
}

if (strpos($requestedFile, '/') !== false || strpos($requestedFile, '\\') !== false) {
    http_response_code(403);
    echo '<div class="text-red-500 text-sm p-2">Błąd: niedozwolone separatory katalogów</div>';
    exit;
}

if (strpos($requestedFile, "\0") !== false) {
    http_response_code(403);
    echo '<div class="text-red-500 text-sm p-2">Błąd: wykryto null byte</div>';
    error_log("[PHP-INCLUDE] Null byte attack attempt from {$_SERVER['REMOTE_ADDR']}");
    exit;
}

if (!PHP_INCLUDES_DIR || !is_dir(PHP_INCLUDES_DIR)) {
    http_response_code(500);
    echo '<div class="text-red-500 text-sm p-2">Błąd: katalog /php/ nie istnieje na serwerze</div>';
    exit;
}

$fullPath = PHP_INCLUDES_DIR . DIRECTORY_SEPARATOR . $requestedFile;
$realPath = realpath($fullPath);

if ($realPath === false || !file_exists($realPath)) {
    http_response_code(404);
    echo '<div class="text-gray-400 text-sm p-2">Plik nie znaleziony: ' . htmlspecialchars($requestedFile) . '</div>';
    exit;
}

if (strpos($realPath, PHP_INCLUDES_DIR) !== 0) {
    http_response_code(403);
    echo '<div class="text-red-500 text-sm p-2">Błąd: plik poza dozwolonym katalogiem</div>';
    error_log("[PHP-INCLUDE] Symlink/traversal escape attempt: $requestedFile → $realPath from {$_SERVER['REMOTE_ADDR']}");
    exit;
}

if (!is_file($realPath)) {
    http_response_code(400);
    echo '<div class="text-red-500 text-sm p-2">Błąd: to nie jest plik</div>';
    exit;
}

if (!is_readable($realPath)) {
    http_response_code(500);
    echo '<div class="text-red-500 text-sm p-2">Błąd: brak uprawnień do odczytu pliku</div>';
    exit;
}

try {
    ob_start();
    include $realPath;
    $output = ob_get_clean();
    echo $output;
} catch (Throwable $e) {
    ob_end_clean();
    http_response_code(500);
    echo '<div class="text-red-500 text-sm p-2">Błąd wykonania: ' . htmlspecialchars($e->getMessage()) . '</div>';
    error_log("[PHP-INCLUDE] Execution error in $requestedFile: " . $e->getMessage());
}