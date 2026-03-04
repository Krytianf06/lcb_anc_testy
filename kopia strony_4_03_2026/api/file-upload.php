<?php

require_once dirname(__DIR__, 2) . '/config.php';

$pdo = getDB();
$action = $_GET['action'] ?? '';

define('FILES_DIR', __DIR__ . '/../files');

define('ALLOWED_DOC_TYPES', [
    'application/pdf',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 
    'application/msword', 
]);

define('ALLOWED_DOC_EXTENSIONS', ['pdf', 'docx', 'doc']);

define('MAX_DOC_SIZE', 20 * 1024 * 1024);

define('FILE_SIGNATURES', [
    'pdf'  => ['%PDF'],           
    'docx' => ["\x50\x4B\x03\x04"], 
    'doc'  => ["\xD0\xCF\x11\xE0"], 
]);

function ensureFilesDir(): void {
    if (!is_dir(FILES_DIR)) {
        if (!mkdir(FILES_DIR, 0755, true)) {
            jsonError('Nie można utworzyć katalogu files/', 500);
        }
    }

    $htaccess = FILES_DIR . '/.htaccess';
    if (!file_exists($htaccess)) {
        file_put_contents($htaccess, implode("\n", [
            '# Blokada wykonywania skryptów',
            '<FilesMatch "\.(php|phtml|php3|php4|php5|phps|cgi|pl|py|jsp|asp|aspx|sh|bash)$">',
            '    Require all denied',
            '</FilesMatch>',
            '',
            '# Wyłącz PHP engine',
            'php_flag engine off',
            '',
            '# Wyłącz CGI',
            'Options -ExecCGI',
            '',
            '# Blokada directory listing',
            'Options -Indexes',
            '',
            '# Wymuś Content-Disposition: attachment (wymusza pobieranie, nie otwieranie)',
            '<FilesMatch "\.(pdf|docx|doc)$">',
            '    Header set Content-Disposition attachment',
            '</FilesMatch>',
            '',
            '# Blokada MIME sniffing',
            'Header set X-Content-Type-Options nosniff',
        ]));
    }

    $indexFile = FILES_DIR . '/index.html';
    if (!file_exists($indexFile)) {
        file_put_contents($indexFile, '<!DOCTYPE html><html><head><title>403</title></head><body><h1>Forbidden</h1></body></html>');
    }
}

function validateFileSignature(string $filePath, string $extension): bool {
    if (!isset(FILE_SIGNATURES[$extension])) return true; 

    $handle = fopen($filePath, 'rb');
    if (!$handle) return false;

    $header = fread($handle, 16);
    fclose($handle);

    if ($header === false || strlen($header) < 4) return false;

    foreach (FILE_SIGNATURES[$extension] as $signature) {
        if (substr($header, 0, strlen($signature)) === $signature) {
            return true;
        }
    }

    return false;
}

function sanitizeFileName(string $name): string {

    $name = str_replace("\0", '', $name);

    $name = preg_replace('/[^a-zA-Z0-9._\-]/', '_', $name);

    $name = str_replace('..', '_', $name);

    if (strlen($name) > 200) {
        $ext = pathinfo($name, PATHINFO_EXTENSION);
        $name = substr($name, 0, 190) . '.' . $ext;
    }

    return $name;
}

function checkForMaliciousContent(string $filePath, string $extension): ?string {
    $content = file_get_contents($filePath, false, null, 0, 1024 * 100); 
    if ($content === false) return 'Nie można odczytać pliku';

		$phpPatterns = [
		'<?php', '<?=',
		'<script', 'javascript:',
		'eval(', 'exec(', 'system(', 'passthru(',
		'shell_exec(', 'popen(', 'proc_open(',
	];

	if ($extension === 'pdf' || $extension === 'docx' || $extension === 'doc') {
		$phpPatterns = ['<?php', '<?=', '<script'];
	}

    $contentLower = strtolower($content);
    foreach ($phpPatterns as $pattern) {
        if (strpos($contentLower, strtolower($pattern)) !== false) {
            error_log("[FILE-UPLOAD] Malicious content detected: '$pattern' in file");
            return "Wykryto podejrzaną zawartość w pliku (pattern: $pattern)";
        }
    }

    if ($extension === 'pdf') {
        $pdfDangers = ['/JavaScript', '/JS ', '/Launch', '/EmbeddedFile', '/OpenAction'];
        foreach ($pdfDangers as $danger) {
            if (strpos($content, $danger) !== false) {
                error_log("[FILE-UPLOAD] PDF contains suspicious element: $danger");

            }
        }
    }

    return null; 
}

switch ($action) {

    case 'upload':
        $user = requireAuth();
        ensureFilesDir();

        if (empty($_FILES['file'])) {
            jsonError('Brak pliku w żądaniu', 400);
        }

        $file = $_FILES['file'];

        if ($file['error'] !== UPLOAD_ERR_OK) {
            $errors = [
                UPLOAD_ERR_INI_SIZE => 'Plik przekracza limit serwera',
                UPLOAD_ERR_FORM_SIZE => 'Plik przekracza limit formularza',
                UPLOAD_ERR_PARTIAL => 'Plik został przesłany częściowo',
                UPLOAD_ERR_NO_FILE => 'Nie przesłano pliku',
                UPLOAD_ERR_NO_TMP_DIR => 'Brak katalogu tymczasowego',
                UPLOAD_ERR_CANT_WRITE => 'Nie można zapisać pliku',
            ];
            jsonError($errors[$file['error']] ?? 'Błąd uploadu: ' . $file['error'], 400);
        }

        if ($file['size'] > MAX_DOC_SIZE) {
            jsonError('Plik za duży. Maksymalnie ' . round(MAX_DOC_SIZE / 1024 / 1024) . ' MB', 400);
        }

        $originalName = sanitizeFileName($file['name']);
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        if (!in_array($extension, ALLOWED_DOC_EXTENSIONS)) {
            jsonError('Niedozwolone rozszerzenie pliku. Dozwolone: ' . implode(', ', ALLOWED_DOC_EXTENSIONS), 400);
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $detectedMime = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if (!in_array($detectedMime, ALLOWED_DOC_TYPES)) {

            if ($extension === 'docx' && $detectedMime === 'application/zip') {

            } elseif ($extension === 'docx' && $detectedMime === 'application/octet-stream') {

            } else {
                error_log("[FILE-UPLOAD] MIME mismatch: expected doc type, got '$detectedMime' for extension '$extension'");
                jsonError("Typ pliku ($detectedMime) nie odpowiada rozszerzeniu ($extension)", 400);
            }
        }

        if (!validateFileSignature($file['tmp_name'], $extension)) {
            error_log("[FILE-UPLOAD] Invalid file signature for extension '$extension'");
            jsonError('Sygnatura pliku nie odpowiada deklarowanemu typowi', 400);
        }

        $malwareCheck = checkForMaliciousContent($file['tmp_name'], $extension);
        if ($malwareCheck !== null) {
            jsonError($malwareCheck, 400);
        }

        $uuid = bin2hex(random_bytes(16));
        $storedName = $uuid . '.' . $extension;
        $filePath = FILES_DIR . '/' . $storedName;

        if (!move_uploaded_file($file['tmp_name'], $filePath)) {
            jsonError('Nie można zapisać pliku na serwerze', 500);
        }

        chmod($filePath, 0644);

        $prefix = DB_PREFIX;
        $webPath = 'files/' . $storedName;

        try {
            $stmt = $pdo->prepare("INSERT INTO {$prefix}files (original_name, stored_name, file_path, mime_type, file_size, extension, uploaded_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $originalName,
                $storedName,
                $webPath,
                $detectedMime,
                $file['size'],
                $extension,
                $user['id']
            ]);
            $fileId = $pdo->lastInsertId();
        } catch (PDOException $e) {

            error_log("[FILE-UPLOAD] DB error (non-fatal): " . $e->getMessage());
            $fileId = 0;
        }

        error_log("[FILE-UPLOAD] Success: $originalName → $storedName by user {$user['id']}");

        logAction($user['id'], 'file_upload', "Uploaded: $originalName as $storedName");

        jsonSuccess([
            'id' => $fileId,
            'original_name' => $originalName,
            'stored_name' => $storedName,
            'file_path' => $webPath,
            'mime_type' => $detectedMime,
            'file_size' => $file['size'],
            'extension' => $extension,
        ]);
        break;

    case 'list':
        $user = requireAuth();
        $prefix = DB_PREFIX;

        try {
            $stmt = $pdo->query("SELECT * FROM {$prefix}files ORDER BY created_at DESC");
            $files = $stmt->fetchAll();
            jsonSuccess(['files' => $files]);
        } catch (PDOException $e) {
            jsonSuccess(['files' => []]);
        }
        break;

    case 'delete':
        $user = requireAuth();
        $data = getInput();
        $id = intval($data['id'] ?? 0);

        if ($id <= 0) jsonError('Nieprawidłowe ID pliku', 400);

        $prefix = DB_PREFIX;

        try {
            $stmt = $pdo->prepare("SELECT * FROM {$prefix}files WHERE id = ?");
            $stmt->execute([$id]);
            $fileRecord = $stmt->fetch();

            if (!$fileRecord) jsonError('Plik nie znaleziony', 404);

            $fullPath = FILES_DIR . '/' . $fileRecord['stored_name'];
            if (file_exists($fullPath)) {
                unlink($fullPath);
            }

            $stmt = $pdo->prepare("DELETE FROM {$prefix}files WHERE id = ?");
            $stmt->execute([$id]);

            logAction($user['id'], 'file_delete', "Deleted: {$fileRecord['original_name']}");

            jsonSuccess(['deleted' => $id]);
        } catch (PDOException $e) {
            jsonError('Błąd bazy danych', 500);
        }
        break;

    case 'test':
        ensureFilesDir();
        jsonSuccess([
            'files_dir' => FILES_DIR,
            'exists' => is_dir(FILES_DIR),
            'writable' => is_writable(FILES_DIR),
            'max_upload' => ini_get('upload_max_filesize'),
            'post_max' => ini_get('post_max_size'),
            'allowed_extensions' => ALLOWED_DOC_EXTENSIONS,
            'max_size_mb' => MAX_DOC_SIZE / 1024 / 1024,
        ]);
        break;

    default:
        jsonError('Nieznana akcja', 400);
}