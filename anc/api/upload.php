<?php

require_once dirname(__DIR__, 2) . '/config.php';

$pdo = getDB();
$prefix = DB_PREFIX;
$action = $_GET['action'] ?? '';

function logDebug($msg) {
    $log = date('Y-m-d H:i:s') . " $msg\n";
    file_put_contents(__DIR__ . '/blad.log', $log, FILE_APPEND | LOCK_EX);
}


function ensureImgDir(): string {
    $dir = __DIR__ . '/../img';
    if (!is_dir($dir)) {
        if (!@mkdir($dir, 0755, true)) {
            logDebug('[UPLOAD] Cannot create directory: ' . $dir);
            jsonError('Nie można utworzyć katalogu img/', 500);
        }
    }

    $htaccess = $dir . '/.htaccess';
    if (!file_exists($htaccess)) {
        @file_put_contents($htaccess, "php_flag engine off\n\n<FilesMatch \"\.(php|phtml|php3|php4|php5|pl|py|cgi|sh|bash)$\">\n    Require all denied\n</FilesMatch>\n");
    }
    return $dir;
}

switch ($action) {

    case 'test':
        $imgDir = __DIR__ . '/../img';
        $info = [
            'php_version' => PHP_VERSION,
            'upload_max_filesize' => ini_get('upload_max_filesize'),
            'post_max_size' => ini_get('post_max_size'),
            'file_uploads' => ini_get('file_uploads'),
            'img_dir' => $imgDir,
            'img_dir_exists' => is_dir($imgDir),
            'img_dir_writable' => is_dir($imgDir) ? is_writable($imgDir) : false,
            'gd_available' => extension_loaded('gd'),
            'finfo_available' => extension_loaded('fileinfo'),
            'upload_tmp_dir' => ini_get('upload_tmp_dir') ?: sys_get_temp_dir(),
            'tmp_writable' => is_writable(ini_get('upload_tmp_dir') ?: sys_get_temp_dir()),
            'max_size_constant' => UPLOAD_MAX_SIZE,
            'allowed_types_constant' => ALLOWED_MIME_TYPES,
            'files_superglobal' => $_FILES,
            'request_method' => $_SERVER['REQUEST_METHOD'],
            'content_type' => $_SERVER['CONTENT_TYPE'] ?? 'not set',
        ];

        if (!is_dir($imgDir)) {
            $created = @mkdir($imgDir, 0755, true);
            $info['img_dir_created'] = $created;
            $info['img_dir_writable'] = $created ? is_writable($imgDir) : false;
        }

        jsonSuccess($info);
        break;

    case 'upload':
        $user = requireAuth();

        logDebug('[UPLOAD] === Upload request ===');
        logDebug('[UPLOAD] Method: ' . $_SERVER['REQUEST_METHOD']);
        logDebug('[UPLOAD] Content-Type: ' . ($_SERVER['CONTENT_TYPE'] ?? 'not set'));
        logDebug('[UPLOAD] FILES keys: ' . implode(', ', array_keys($_FILES)));
        logDebug('[UPLOAD] FILES count: ' . count($_FILES));

        if (empty($_FILES)) {
            logDebug('[UPLOAD] $_FILES is completely empty!');
            logDebug('[UPLOAD] Raw input length: ' . strlen(file_get_contents('php://input')));
            jsonError('Nie przesłano żadnego pliku. $_FILES jest pusty. Sprawdź post_max_size=' . ini_get('post_max_size') . ' i upload_max_filesize=' . ini_get('upload_max_filesize'), 400);
        }

        if (!isset($_FILES['image'])) {
            logDebug('[UPLOAD] No "image" field. Available fields: ' . implode(', ', array_keys($_FILES)));
            jsonError('Brak pola "image" w przesłanych plikach. Dostępne: ' . implode(', ', array_keys($_FILES)), 400);
        }

        $file = $_FILES['image'];
        logDebug('[UPLOAD] File: ' . $file['name'] . ', size: ' . $file['size'] . ', type: ' . $file['type'] . ', error: ' . $file['error'] . ', tmp: ' . $file['tmp_name']);

        if ($file['error'] !== UPLOAD_ERR_OK) {
            $errors = [
                UPLOAD_ERR_INI_SIZE   => 'Plik zbyt duży (limit php.ini: ' . ini_get('upload_max_filesize') . ')',
                UPLOAD_ERR_FORM_SIZE  => 'Plik zbyt duży (limit formularza)',
                UPLOAD_ERR_PARTIAL    => 'Plik przesłany częściowo',
                UPLOAD_ERR_NO_FILE    => 'Nie wybrano pliku',
                UPLOAD_ERR_NO_TMP_DIR => 'Brak katalogu tymczasowego na serwerze',
                UPLOAD_ERR_CANT_WRITE => 'Błąd zapisu na dysk',
                UPLOAD_ERR_EXTENSION  => 'Rozszerzenie PHP zablokowało upload',
            ];
            $msg = $errors[$file['error']] ?? 'Nieznany błąd uploadu (kod: ' . $file['error'] . ')';
            logDebug('[UPLOAD] Error: ' . $msg);
            jsonError($msg, 400);
        }

        if ($file['size'] > UPLOAD_MAX_SIZE) {
            jsonError('Plik zbyt duży (' . round($file['size']/1024/1024, 1) . ' MB). Limit: ' . round(UPLOAD_MAX_SIZE/1024/1024, 1) . ' MB', 400);
        }
        if ($file['size'] === 0) {
            jsonError('Plik jest pusty (0 bajtów)', 400);
        }

        if (!is_uploaded_file($file['tmp_name'])) {
            logDebug('[UPLOAD] is_uploaded_file returned false for: ' . $file['tmp_name']);
            jsonError('Plik tymczasowy nie istnieje lub nie jest prawidłowym uploadem', 400);
        }

        if (extension_loaded('fileinfo')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $detectedMime = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);
        } else {
            $detectedMime = $file['type']; 
        }

        logDebug('[UPLOAD] Detected MIME: ' . $detectedMime);

        if (!in_array($detectedMime, ALLOWED_MIME_TYPES)) {
            jsonError('Niedozwolony typ pliku: ' . $detectedMime . '. Dozwolone: ' . implode(', ', ALLOWED_MIME_TYPES), 400);
        }

        $imgWidth = null;
        $imgHeight = null;
        if ($detectedMime !== 'image/svg+xml') {
            $imgInfo = @getimagesize($file['tmp_name']);
            if ($imgInfo === false) {
                jsonError('Plik nie jest prawidłowym obrazem (getimagesize failed)', 400);
            }
            $imgWidth = $imgInfo[0];
            $imgHeight = $imgInfo[1];
            logDebug('[UPLOAD] Image dimensions: ' . $imgWidth . 'x' . $imgHeight);
        } else {

            $svgContent = file_get_contents($file['tmp_name']);
            if (preg_match('/<script|onclick|onerror|onload|javascript:/i', $svgContent)) {
                jsonError('SVG zawiera potencjalnie niebezpieczny kod', 400);
            }
        }

        $uploadDir = ensureImgDir();

        $originalName = basename($file['name']);
        $originalName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $originalName);
        $originalName = preg_replace('/\0/', '', $originalName);

        $mimeToExt = [
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/gif'  => 'gif',
            'image/webp' => 'webp',
            'image/svg+xml' => 'svg',
        ];
        $ext = $mimeToExt[$detectedMime] ?? 'bin';
        $storedName = bin2hex(random_bytes(16)) . '.' . $ext;
        $targetPath = $uploadDir . '/' . $storedName;
        $relativePath = 'img/' . $storedName;

        logDebug('[UPLOAD] Target: ' . $targetPath);
        logDebug('[UPLOAD] Dir writable: ' . (is_writable($uploadDir) ? 'yes' : 'no'));

        if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
            logDebug('[UPLOAD] move_uploaded_file FAILED!');
            logDebug('[UPLOAD] tmp exists: ' . (file_exists($file['tmp_name']) ? 'yes' : 'no'));
            logDebug('[UPLOAD] target dir: ' . $uploadDir);
            logDebug('[UPLOAD] target dir exists: ' . (is_dir($uploadDir) ? 'yes' : 'no'));
            logDebug('[UPLOAD] target dir writable: ' . (is_writable($uploadDir) ? 'yes' : 'no'));
            jsonError('Nie udało się zapisać pliku. Dir writable: ' . (is_writable($uploadDir) ? 'yes' : 'no'), 500);
        }

        logDebug('[UPLOAD] File moved successfully to: ' . $targetPath);
        logDebug('[UPLOAD] File exists check: ' . (file_exists($targetPath) ? 'yes' : 'no'));
        logDebug('[UPLOAD] File size on disk: ' . filesize($targetPath));

        if ($detectedMime !== 'image/svg+xml' && extension_loaded('gd')) {
            try {
                $srcImage = null;
                switch ($detectedMime) {
                    case 'image/jpeg': $srcImage = @imagecreatefromjpeg($targetPath); break;
                    case 'image/png':  $srcImage = @imagecreatefrompng($targetPath); break;
                    case 'image/gif':  $srcImage = @imagecreatefromgif($targetPath); break;
                    case 'image/webp':
                        if (function_exists('imagecreatefromwebp')) $srcImage = @imagecreatefromwebp($targetPath);
                        break;
                }
                if ($srcImage) {
                    switch ($detectedMime) {
                        case 'image/jpeg': imagejpeg($srcImage, $targetPath, 90); break;
                        case 'image/png':
                            imagesavealpha($srcImage, true);
                            imagepng($srcImage, $targetPath, 6);
                            break;
                        case 'image/gif': imagegif($srcImage, $targetPath); break;
                        case 'image/webp':
                            if (function_exists('imagewebp')) imagewebp($srcImage, $targetPath, 90);
                            break;
                    }
                    imagedestroy($srcImage);
                    clearstatcache(true, $targetPath);
                    logDebug('[UPLOAD] GD reprocessing OK');
                }
            } catch (Exception $e) {
                logDebug('[UPLOAD] GD reprocessing failed (non-fatal): ' . $e->getMessage());
            }
        }

        $finalSize = filesize($targetPath);
        $altText = $_POST['alt_text'] ?? $file['name'];

        try {
            $stmt = $pdo->prepare("
                INSERT INTO {$prefix}images (original_name, stored_name, file_path, mime_type, file_size, width_px, height_px, alt_text, uploaded_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $originalName, $storedName, $relativePath, $detectedMime,
                $finalSize, $imgWidth, $imgHeight, $altText, $user['id']
            ]);
            $imageId = (int)$pdo->lastInsertId();

            logAction($user['id'], 'upload_image', 'image', $imageId, "Uploaded: {$originalName} → {$storedName}");

            logDebug('[UPLOAD] SUCCESS! ID: ' . $imageId . ', path: ' . $relativePath);

            jsonSuccess([
                'id'            => $imageId,
                'original_name' => $originalName,
                'stored_name'   => $storedName,
                'file_path'     => $relativePath,
                'mime_type'     => $detectedMime,
                'file_size'     => $finalSize,
                'width_px'      => $imgWidth,
                'height_px'     => $imgHeight,
                'alt_text'      => $altText,
            ]);
        } catch (PDOException $e) {
            logDebug('[UPLOAD] DB error: ' . $e->getMessage());
            @unlink($targetPath);
            jsonError('Błąd zapisu do bazy: ' . (APP_DEBUG ? $e->getMessage() : 'Skontaktuj się z administratorem'), 500);
        }
        break;

    case 'list':
        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = min(100, max(1, (int)($_GET['limit'] ?? 50)));
        $offset = ($page - 1) * $limit;
        $search = $_GET['search'] ?? '';
        $mime = $_GET['mime'] ?? '';

        $where = [];
        $params = [];

        if ($search) {
            $where[] = "(original_name LIKE ? OR alt_text LIKE ?)";
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
        }
        if ($mime) {
            $where[] = "mime_type = ?";
            $params[] = $mime;
        }

        $whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM {$prefix}images {$whereSQL}");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $stmt = $pdo->prepare("
            SELECT id, original_name, stored_name, file_path, mime_type, file_size,
                   width_px, height_px, alt_text, created_at
            FROM {$prefix}images {$whereSQL}
            ORDER BY created_at DESC
            LIMIT {$limit} OFFSET {$offset}
        ");
        $stmt->execute($params);
        $images = $stmt->fetchAll();

        jsonSuccess([
            'images' => $images,
            'total'  => $total,
            'page'   => $page,
            'limit'  => $limit,
            'pages'  => (int)ceil($total / max($limit, 1)),
        ]);
        break;

    case 'get':
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) jsonError('Brak parametru id', 400);

        $stmt = $pdo->prepare("SELECT * FROM {$prefix}images WHERE id = ?");
        $stmt->execute([$id]);
        $image = $stmt->fetch();

        if (!$image) jsonError('Obraz nie znaleziony', 404);

        $usedIn = $pdo->prepare("
            SELECT b.id, b.type, p.slug, p.title_pl
            FROM {$prefix}blocks b
            JOIN {$prefix}pages p ON b.page_id = p.id
            WHERE b.content_pl LIKE ? OR b.content_en LIKE ?
        ");
        $usedIn->execute(['%' . $image['file_path'] . '%', '%' . $image['file_path'] . '%']);
        $image['used_in'] = $usedIn->fetchAll();

        jsonSuccess(['image' => $image]);
        break;

    case 'delete':
        $user = requireAuth();
        $input = getInput();
        $id = (int)($input['id'] ?? 0);
        $force = (bool)($input['force'] ?? false);

        if (!$id) jsonError('Brak id obrazu', 400);

        $stmt = $pdo->prepare("SELECT * FROM {$prefix}images WHERE id = ?");
        $stmt->execute([$id]);
        $image = $stmt->fetch();

        if (!$image) jsonError('Obraz nie znaleziony', 404);

        if (!$force) {
            $usedStmt = $pdo->prepare("
                SELECT COUNT(*) FROM {$prefix}blocks
                WHERE content_pl LIKE ? OR content_en LIKE ?
            ");
            $usedStmt->execute(['%' . $image['file_path'] . '%', '%' . $image['file_path'] . '%']);
            $usedCount = (int)$usedStmt->fetchColumn();
            if ($usedCount > 0) {
                jsonError("Obraz jest używany w {$usedCount} blokach. Użyj force=true aby usunąć.", 409);
            }
        }

        $filePath = __DIR__ . '/../' . $image['file_path'];
        if (file_exists($filePath)) {
            @unlink($filePath);
        }

        $stmt = $pdo->prepare("DELETE FROM {$prefix}images WHERE id = ?");
        $stmt->execute([$id]);

        logAction($user['id'], 'delete_image', 'image', $id, "Deleted: {$image['original_name']}");

        jsonSuccess(['deleted' => true]);
        break;

    case 'update':
        $user = requireAuth();
        $input = getInput();
        $id = (int)($input['id'] ?? 0);

        if (!$id) jsonError('Brak id obrazu', 400);

        $stmt = $pdo->prepare("UPDATE {$prefix}images SET alt_text = ? WHERE id = ?");
        $stmt->execute([$input['alt_text'] ?? '', $id]);

        jsonSuccess(['updated' => true]);
        break;

    case 'init':

        $user = requireAuth();

        $dir = ensureImgDir();

        jsonSuccess([
            'directory' => $dir,
            'writable'  => is_writable($dir),
            'exists'    => is_dir($dir),
        ]);
        break;

    default:
        jsonError('Nieznana akcja: ' . $action, 400);
}