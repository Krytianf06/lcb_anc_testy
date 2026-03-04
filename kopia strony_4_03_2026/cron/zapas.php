<?php

define('BACKUP_DIR', __DIR__ . '/../backup/');
define('BACKUP_KEEP_DAYS', 30);
define('BACKUP_COMPRESS', true);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

require_once dirname(__DIR__, 2) . '/config.php';

$key = $_GET['key'] ?? '';
if (!hash_equals(BACKUP_SECRET, $key)) {
	header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'brak dostępu'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!is_dir(BACKUP_DIR)) {
    if (!mkdir(BACKUP_DIR, 0700, true)) {
        jsonOut(false, 'Nie można utworzyć katalogu backup');
    }
}

$htaccess = BACKUP_DIR . '.htaccess';
if (!file_exists($htaccess)) {
    file_put_contents($htaccess, "Require all denied\n");
}

$timestamp = date('Y-m-d_H-i-s');
$filename = 'backup_' . DB_NAME . '_' . $timestamp . '.sql';
$filepath = BACKUP_DIR . $filename;

try {
    $pdo = getDB();
    $sql = generateSqlDump($pdo);
    file_put_contents($filepath, $sql, LOCK_EX);
} catch (Exception $e) {
    jsonOut(false, 'Błąd backupu: ' . $e->getMessage());
}

if (!file_exists($filepath) || filesize($filepath) < 100) {
    jsonOut(false, 'Backup nie został utworzony');
}

$finalPath = $filepath;
$finalName = $filename;

if (BACKUP_COMPRESS && function_exists('gzencode')) {
    $gzPath = $filepath . '.gz';
    $content = file_get_contents($filepath);
    file_put_contents($gzPath, gzencode($content, 9), LOCK_EX);
    unlink($filepath);
    $finalPath = $gzPath;
    $finalName = $filename . '.gz';
}

$deleted = cleanOldBackups(BACKUP_DIR, BACKUP_KEEP_DAYS);

jsonOut(true, 'Backup utworzony', [
    'file' => $finalName,
    'size' => formatBytes(filesize($finalPath)),
    'old_deleted' => $deleted,
    'timestamp' => $timestamp,
]);

function jsonOut(bool $success, string $message, array $data = []): void {
    echo json_encode(array_merge([
        'success' => $success,
        'message' => $message,
    ], $data), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

function generateSqlDump(PDO $pdo): string {
    $sql = "-- Backup bazy danych\n";
    $sql .= "-- Data: " . date('Y-m-d H:i:s') . "\n\n";
    $sql .= "SET NAMES utf8mb4;\n";
    $sql .= "SET FOREIGN_KEY_CHECKS = 0;\n\n";

    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);

    foreach ($tables as $table) {
        $create = $pdo->query("SHOW CREATE TABLE `{$table}`")->fetch(PDO::FETCH_ASSOC);
        $sql .= "-- ──────────────────────────\n";
        $sql .= "-- Tabela: {$table}\n";
        $sql .= "-- ──────────────────────────\n";
        $sql .= "DROP TABLE IF EXISTS `{$table}`;\n";
        $sql .= $create['Create Table'] . ";\n\n";

        $rows = $pdo->query("SELECT * FROM `{$table}`")->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) > 0) {
            $columns = array_keys($rows[0]);
            $colList = '`' . implode('`, `', $columns) . '`';

            $chunks = array_chunk($rows, 100);
            foreach ($chunks as $chunk) {
                $sql .= "INSERT INTO `{$table}` ({$colList}) VALUES\n";
                $values = [];
                foreach ($chunk as $row) {
                    $vals = [];
                    foreach ($row as $val) {
                        if ($val === null) {
                            $vals[] = 'NULL';
                        } else {
                            $vals[] = $pdo->quote($val);
                        }
                    }
                    $values[] = '(' . implode(', ', $vals) . ')';
                }
                $sql .= implode(",\n", $values) . ";\n";
            }
            $sql .= "\n";
        }
    }

    $sql .= "SET FOREIGN_KEY_CHECKS = 1;\n";
    return $sql;
}

function cleanOldBackups(string $dir, int $keepDays): int {
    $deleted = 0;
    $cutoff = time() - ($keepDays * 86400);
    $files = glob($dir . 'backup_*');
    foreach ($files as $file) {
        if (is_file($file) && filemtime($file) < $cutoff) {
            unlink($file);
            $deleted++;
        }
    }
    return $deleted;
}

function formatBytes(int $bytes): string {
    if ($bytes >= 1048576) return round($bytes / 1048576, 2) . ' MB';
    if ($bytes >= 1024) return round($bytes / 1024, 2) . ' KB';
    return $bytes . ' B';
}