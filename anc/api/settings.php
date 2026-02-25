<?php

require_once dirname(__DIR__, 2) . '/config.php';

$pdo = getDB();
$prefix = DB_PREFIX;
$action = $_GET['action'] ?? '';

$protectedKeys = [
    'site_name', 'logo_url', 'banner_url', 'footer_text',
    'meta_description', 'meta_keywords', 'contact_email',
    'contact_phone', 'contact_address', 'analytics_code', 'custom_css'
];

switch ($action) {

    case 'list':
        $user = optionalAuth();

        $stmt = $pdo->query("
            SELECT id, setting_key, value_pl, value_en, updated_at
            FROM {$prefix}settings
            ORDER BY setting_key ASC
        ");
        $settings = $stmt->fetchAll();

        $format = $_GET['format'] ?? 'list';

        if ($format === 'map') {
            $map = [];
            foreach ($settings as $s) {
                $map[$s['setting_key']] = [
                    'id'        => (int)$s['id'],
                    'value_pl'  => $s['value_pl'],
                    'value_en'  => $s['value_en'],
                    'updated_at'=> $s['updated_at']
                ];
            }
            jsonSuccess($map);
        }

        jsonSuccess($settings);
        break;

    case 'get':
        $key = $_GET['key'] ?? '';
        if (!$key) {
            jsonError('Podaj parametr key', 400);
        }

        $stmt = $pdo->prepare("
            SELECT id, setting_key, value_pl, value_en, updated_at
            FROM {$prefix}settings
            WHERE setting_key = ?
        ");
        $stmt->execute([$key]);
        $setting = $stmt->fetch();

        if (!$setting) {
            jsonError('Ustawienie nie znalezione', 404);
        }

        jsonSuccess($setting);
        break;

    case 'update':
        $user = requireAuth();
        $input = getInput();

        $key = $input['key'] ?? '';
        if (!$key) {
            jsonError('Podaj klucz ustawienia (key)', 400);
        }

        $stmt = $pdo->prepare("SELECT id FROM {$prefix}settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        $existing = $stmt->fetch();

        if (!$existing) {
            jsonError('Ustawienie nie znalezione', 404);
        }

        $updates = [];
        $params = [];

        if (array_key_exists('value_pl', $input)) {
            $updates[] = "value_pl = ?";
            $params[] = $input['value_pl'];
        }
        if (array_key_exists('value_en', $input)) {
            $updates[] = "value_en = ?";
            $params[] = $input['value_en'];
        }

        if (empty($updates)) {
            jsonError('Podaj value_pl lub value_en', 400);
        }

        $params[] = $key;
        $sql = "UPDATE {$prefix}settings SET " . implode(', ', $updates) . " WHERE setting_key = ?";
        $pdo->prepare($sql)->execute($params);

        logAction($user['id'], 'setting_update', 'settings', $existing['id'],
            "Zaktualizowano ustawienie: {$key}");

        $stmt = $pdo->prepare("SELECT * FROM {$prefix}settings WHERE setting_key = ?");
        $stmt->execute([$key]);

        jsonSuccess($stmt->fetch(), 'Ustawienie zaktualizowane');
        break;

    case 'bulk_update':
        $user = requireAuth();
        $input = getInput();

        $settings = $input['settings'] ?? [];
        if (empty($settings) || !is_array($settings)) {
            jsonError('Podaj tablicę settings [{key, value_pl, value_en}, ...]', 400);
        }

        $pdo->beginTransaction();
        try {
            $updated = 0;

            foreach ($settings as $item) {
                $key = $item['key'] ?? '';
                if (!$key) continue;

                $stmt = $pdo->prepare("SELECT id FROM {$prefix}settings WHERE setting_key = ?");
                $stmt->execute([$key]);
                $existing = $stmt->fetch();

                if (!$existing) {

                    $stmt = $pdo->prepare("
                        INSERT INTO {$prefix}settings (setting_key, value_pl, value_en)
                        VALUES (?, ?, ?)
                    ");
                    $stmt->execute([
                        $key,
                        $item['value_pl'] ?? null,
                        $item['value_en'] ?? null
                    ]);
                } else {

                    $updates = [];
                    $params = [];

                    if (array_key_exists('value_pl', $item)) {
                        $updates[] = "value_pl = ?";
                        $params[] = $item['value_pl'];
                    }
                    if (array_key_exists('value_en', $item)) {
                        $updates[] = "value_en = ?";
                        $params[] = $item['value_en'];
                    }

                    if (!empty($updates)) {
                        $params[] = $key;
                        $sql = "UPDATE {$prefix}settings SET " . implode(', ', $updates) . " WHERE setting_key = ?";
                        $pdo->prepare($sql)->execute($params);
                    }
                }

                $updated++;
            }

            $pdo->commit();

            logAction($user['id'], 'settings_bulk_update', 'settings', null,
                "Zaktualizowano {$updated} ustawień");

            jsonSuccess(['updated' => $updated], "Zaktualizowano {$updated} ustawień");

        } catch (Exception $e) {
            $pdo->rollBack();
            jsonError('Błąd aktualizacji: ' . $e->getMessage(), 500);
        }
        break;

    case 'create':
        $user = requireAdmin();
        $input = getInput();

        $key = $input['key'] ?? '';
        if (!$key) {
            jsonError('Podaj klucz ustawienia (key)', 400);
        }

        if (!preg_match('/^[a-z0-9_]{2,50}$/', $key)) {
            jsonError('Klucz może zawierać tylko małe litery, cyfry i _ (2-50 znaków)', 400);
        }

        $stmt = $pdo->prepare("SELECT id FROM {$prefix}settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        if ($stmt->fetch()) {
            jsonError('Ustawienie o tym kluczu już istnieje', 409);
        }

        $stmt = $pdo->prepare("
            INSERT INTO {$prefix}settings (setting_key, value_pl, value_en)
            VALUES (?, ?, ?)
        ");
        $stmt->execute([
            $key,
            $input['value_pl'] ?? null,
            $input['value_en'] ?? null
        ]);

        $id = $pdo->lastInsertId();

        logAction($user['id'], 'setting_create', 'settings', $id,
            "Utworzono ustawienie: {$key}");

        $stmt = $pdo->prepare("SELECT * FROM {$prefix}settings WHERE id = ?");
        $stmt->execute([$id]);

        jsonSuccess($stmt->fetch(), 'Ustawienie utworzone');
        break;

    case 'delete':
        $user = requireAdmin();
        $input = getInput();

        $key = $input['key'] ?? '';
        if (!$key) {
            jsonError('Podaj klucz ustawienia (key)', 400);
        }

        if (in_array($key, $protectedKeys)) {
            jsonError('Nie można usunąć systemowego ustawienia', 403);
        }

        $stmt = $pdo->prepare("SELECT id FROM {$prefix}settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        $existing = $stmt->fetch();

        if (!$existing) {
            jsonError('Ustawienie nie znalezione', 404);
        }

        $pdo->prepare("DELETE FROM {$prefix}settings WHERE setting_key = ?")->execute([$key]);

        logAction($user['id'], 'setting_delete', 'settings', $existing['id'],
            "Usunięto ustawienie: {$key}");

        jsonSuccess(null, 'Ustawienie usunięte');
        break;

    default:
        jsonError('Nieznana akcja. Dostępne: list, get, update, bulk_update, create, delete', 400);
}