<?php

require_once dirname(__DIR__, 2) . '/config.php';

$pdo = getDB();
$prefix = DB_PREFIX;
$action = $_GET['action'] ?? '';

switch ($action) {

    case 'list':
        $pageId = (int)($_GET['page_id'] ?? 0);
        if ($pageId <= 0) {
            jsonError('Brak page_id', 400);
        }

        $user = optionalAuth();
        $activeOnly = $user ? '' : "AND b.is_active = 1";

        $stmt = $pdo->prepare("
            SELECT b.id, b.page_id, b.type, b.content_pl, b.content_en,
                   b.settings, b.width, b.sort_order, b.is_active,
                   b.created_at, b.updated_at
            FROM {$prefix}blocks b
            WHERE b.page_id = ? {$activeOnly}
            ORDER BY b.sort_order ASC, b.id ASC
        ");
        $stmt->execute([$pageId]);
        $blocks = $stmt->fetchAll();

        foreach ($blocks as &$block) {
            $block['settings'] = $block['settings'] ? json_decode($block['settings'], true) : null;
            $block['is_active'] = (bool)$block['is_active'];
        }

        jsonSuccess(['blocks' => $blocks]);
        break;

    case 'get':
        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) {
            jsonError('Brak id', 400);
        }

        $stmt = $pdo->prepare("
            SELECT id, page_id, type, content_pl, content_en,
                   settings, width, sort_order, is_active,
                   created_at, updated_at
            FROM {$prefix}blocks
            WHERE id = ?
        ");
        $stmt->execute([$id]);
        $block = $stmt->fetch();

        if (!$block) {
            jsonError('Blok nie znaleziony', 404);
        }

        $block['settings'] = $block['settings'] ? json_decode($block['settings'], true) : null;
        $block['is_active'] = (bool)$block['is_active'];

        jsonSuccess(['block' => $block]);
        break;

    case 'create':
        $user = requireAuth();
        $input = getInput();

        $pageId = (int)($input['page_id'] ?? 0);
        $type = $input['type'] ?? '';
        $contentPl = $input['content_pl'] ?? '';
        $contentEn = $input['content_en'] ?? '';
        $settings = $input['settings'] ?? null;
        $width = $input['width'] ?? 'full';

        if ($pageId <= 0) {
            jsonError('Brak page_id', 400);
        }

        $allowedTypes = ['heading', 'text', 'image', 'button', 'link', 'html', 'php', 'map', 'download', 'spacer'];
        if (!in_array($type, $allowedTypes)) {
            jsonError('Nieprawidłowy typ bloku. Dozwolone: ' . implode(', ', $allowedTypes), 400);
        }

        $allowedWidths = ['full', 'half', 'third', 'two-thirds'];
        if (!in_array($width, $allowedWidths)) {
            $width = 'full';
        }

		if (empty($contentPl) && $type !== 'php' && $type !== 'map' && $type !== 'download' && $type !== 'spacer') {
		  jsonError('Treść (content_pl) jest wymagana', 400);
		}

        $stmt = $pdo->prepare("SELECT id FROM {$prefix}pages WHERE id = ?");
        $stmt->execute([$pageId]);
        if (!$stmt->fetch()) {
            jsonError('Strona nie istnieje', 404);
        }

        if ($type === 'php' && $user['role'] !== 'admin') {
            jsonError('Bloki PHP może dodawać tylko administrator', 403);
        }

        $stmt = $pdo->prepare("SELECT COALESCE(MAX(sort_order), 0) + 1 FROM {$prefix}blocks WHERE page_id = ?");
        $stmt->execute([$pageId]);
        $sortOrder = (int)$stmt->fetchColumn();

        $settingsJson = $settings ? json_encode($settings, JSON_UNESCAPED_UNICODE) : null;

        $stmt = $pdo->prepare("
            INSERT INTO {$prefix}blocks (page_id, type, content_pl, content_en, settings, width, sort_order, is_active)
            VALUES (?, ?, ?, ?, ?, ?, ?, 1)
        ");
        $stmt->execute([$pageId, $type, $contentPl, $contentEn ?: null, $settingsJson, $width, $sortOrder]);

        $blockId = (int)$pdo->lastInsertId();

        logAction($user['id'], 'block_create', 'blocks', $blockId, "Block #{$blockId} type={$type} on page #{$pageId}");

        $stmt = $pdo->prepare("SELECT * FROM {$prefix}blocks WHERE id = ?");
        $stmt->execute([$blockId]);
        $block = $stmt->fetch();
        $block['settings'] = $block['settings'] ? json_decode($block['settings'], true) : null;
        $block['is_active'] = (bool)$block['is_active'];

        jsonSuccess(['block' => $block, 'message' => 'Blok utworzony'], 201);
        break;

    case 'update':
        $user = requireAuth();
        $input = getInput();

        $id = (int)($input['id'] ?? 0);
        if ($id <= 0) {
            jsonError('Brak id', 400);
        }

        $stmt = $pdo->prepare("SELECT * FROM {$prefix}blocks WHERE id = ?");
        $stmt->execute([$id]);
        $existing = $stmt->fetch();

        if (!$existing) {
            jsonError('Blok nie znaleziony', 404);
        }

        if ($existing['type'] === 'php' && $user['role'] !== 'admin') {
            jsonError('Bloki PHP może edytować tylko administrator', 403);
        }

        $fields = [];
        $values = [];

        if (isset($input['content_pl'])) {
            $fields[] = 'content_pl = ?';
            $values[] = $input['content_pl'];
        }
        if (isset($input['content_en'])) {
            $fields[] = 'content_en = ?';
            $values[] = $input['content_en'];
        }
        if (isset($input['settings'])) {
            $fields[] = 'settings = ?';
            $values[] = json_encode($input['settings'], JSON_UNESCAPED_UNICODE);
        }
        if (isset($input['width'])) {
            $allowedWidths = ['full', 'half', 'third', 'two-thirds'];
            if (in_array($input['width'], $allowedWidths)) {
                $fields[] = 'width = ?';
                $values[] = $input['width'];
            }
        }
        if (isset($input['is_active'])) {
            $fields[] = 'is_active = ?';
            $values[] = (int)(bool)$input['is_active'];
        }
        if (isset($input['sort_order'])) {
            $fields[] = 'sort_order = ?';
            $values[] = (int)$input['sort_order'];
        }

        if (isset($input['type'])) {
            $allowedTypes = ['heading', 'text', 'image', 'button', 'link', 'html', 'php', 'map', 'download', 'spacer'];
            if (in_array($input['type'], $allowedTypes)) {
                if ($input['type'] === 'php' && $user['role'] !== 'admin') {
                    jsonError('Zmiana na typ PHP — tylko administrator', 403);
                }
                $fields[] = 'type = ?';
                $values[] = $input['type'];
            }
        }

        if (empty($fields)) {
            jsonError('Brak pól do aktualizacji', 400);
        }

        $fields[] = 'updated_at = NOW()';
        $values[] = $id;

        $sql = "UPDATE {$prefix}blocks SET " . implode(', ', $fields) . " WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($values);

        logAction($user['id'], 'block_update', 'blocks', $id, "Block #{$id}");

        $stmt = $pdo->prepare("SELECT * FROM {$prefix}blocks WHERE id = ?");
        $stmt->execute([$id]);
        $block = $stmt->fetch();
        $block['settings'] = $block['settings'] ? json_decode($block['settings'], true) : null;
        $block['is_active'] = (bool)$block['is_active'];

        jsonSuccess(['block' => $block, 'message' => 'Blok zaktualizowany']);
        break;

    case 'delete':
        $user = requireAuth();
        $input = getInput();

        $id = (int)($input['id'] ?? 0);
        if ($id <= 0) {
            jsonError('Brak id', 400);
        }

        $stmt = $pdo->prepare("SELECT * FROM {$prefix}blocks WHERE id = ?");
        $stmt->execute([$id]);
        $block = $stmt->fetch();

        if (!$block) {
            jsonError('Blok nie znaleziony', 404);
        }

        if ($block['type'] === 'php' && $user['role'] !== 'admin') {
            jsonError('Bloki PHP może usuwać tylko administrator', 403);
        }

        $stmt = $pdo->prepare("DELETE FROM {$prefix}blocks WHERE id = ?");
        $stmt->execute([$id]);

        logAction($user['id'], 'block_delete', 'blocks', $id, "Block #{$id} type={$block['type']} from page #{$block['page_id']}");

        jsonSuccess(['message' => 'Blok usunięty']);
        break;

    case 'reorder':
        $user = requireAuth();
        $input = getInput();

        $pageId = (int)($input['page_id'] ?? 0);
        $order = $input['order'] ?? []; 

        if ($pageId <= 0) {
            jsonError('Brak page_id', 400);
        }
        if (!is_array($order) || empty($order)) {
            jsonError('Brak tablicy order', 400);
        }

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("UPDATE {$prefix}blocks SET sort_order = ?, updated_at = NOW() WHERE id = ? AND page_id = ?");

            foreach ($order as $index => $blockId) {
                $stmt->execute([$index, (int)$blockId, $pageId]);
            }

            $pdo->commit();

            logAction($user['id'], 'blocks_reorder', 'blocks', $pageId, "Page #{$pageId}, " . count($order) . " blocks");

            jsonSuccess(['message' => 'Kolejność zmieniona']);
        } catch (Exception $e) {
            $pdo->rollBack();
            jsonError('Błąd zmiany kolejności: ' . $e->getMessage(), 500);
        }
        break;

    case 'duplicate':
        $user = requireAuth();
        $input = getInput();

        $id = (int)($input['id'] ?? 0);
        if ($id <= 0) {
            jsonError('Brak id', 400);
        }

        $stmt = $pdo->prepare("SELECT * FROM {$prefix}blocks WHERE id = ?");
        $stmt->execute([$id]);
        $original = $stmt->fetch();

        if (!$original) {
            jsonError('Blok nie znaleziony', 404);
        }

        if ($original['type'] === 'php' && $user['role'] !== 'admin') {
            jsonError('Bloki PHP może duplikować tylko administrator', 403);
        }

        $stmt = $pdo->prepare("
            UPDATE {$prefix}blocks
            SET sort_order = sort_order + 1, updated_at = NOW()
            WHERE page_id = ? AND sort_order > ?
        ");
        $stmt->execute([$original['page_id'], $original['sort_order']]);

        $stmt = $pdo->prepare("
            INSERT INTO {$prefix}blocks (page_id, type, content_pl, content_en, settings, width, sort_order, is_active)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $original['page_id'],
            $original['type'],
            $original['content_pl'],
            $original['content_en'],
            $original['settings'],
            $original['width'],
            $original['sort_order'] + 1,
            $original['is_active']
        ]);

        $newId = (int)$pdo->lastInsertId();

        logAction($user['id'], 'block_duplicate', 'blocks', $newId, "Block #{$id} → #{$newId}");

        $stmt = $pdo->prepare("SELECT * FROM {$prefix}blocks WHERE id = ?");
        $stmt->execute([$newId]);
        $block = $stmt->fetch();
        $block['settings'] = $block['settings'] ? json_decode($block['settings'], true) : null;
        $block['is_active'] = (bool)$block['is_active'];

        jsonSuccess(['block' => $block, 'message' => 'Blok zduplikowany'], 201);
        break;

    case 'bulk_width':
        $user = requireAuth();
        $input = getInput();

        $items = $input['items'] ?? []; 

        if (!is_array($items) || empty($items)) {
            jsonError('Brak tablicy items', 400);
        }

        $allowedWidths = ['full', 'half', 'third', 'two-thirds'];

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("UPDATE {$prefix}blocks SET width = ?, updated_at = NOW() WHERE id = ?");

            $count = 0;
            foreach ($items as $item) {
                $blockId = (int)($item['id'] ?? 0);
                $width = $item['width'] ?? 'full';

                if ($blockId > 0 && in_array($width, $allowedWidths)) {
                    $stmt->execute([$width, $blockId]);
                    $count++;
                }
            }

            $pdo->commit();

            logAction($user['id'], 'blocks_bulk_width', 'blocks', 0, "{$count} blocks updated");

            jsonSuccess(['message' => "Zaktualizowano szerokość {$count} bloków"]);
        } catch (Exception $e) {
            $pdo->rollBack();
            jsonError('Błąd: ' . $e->getMessage(), 500);
        }
        break;

    default:
        jsonError('Nieznana akcja. Dostępne: list, get, create, update, delete, reorder, duplicate, bulk_width', 400);
}