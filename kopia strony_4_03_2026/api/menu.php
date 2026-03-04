<?php


require_once dirname(__DIR__, 2) . '/config.php';

$pdo = getDB();
$action = $_GET['action'] ?? '';

switch ($action) {

    case 'list':
        $user = optionalAuth();

        $where = $user ? '' : 'WHERE m.is_active = 1';

        $stmt = $pdo->query("
            SELECT m.*, 
                   p.slug AS page_slug
            FROM " . DB_PREFIX . "menu m
            LEFT JOIN " . DB_PREFIX . "pages p ON m.page_id = p.id
            {$where}
            ORDER BY m.sort_order ASC, m.id ASC
        ");
        $allItems = $stmt->fetchAll();

        $tree = buildMenuTree($allItems, null);

        jsonSuccess(['menu' => $tree]);
        break;

    case 'get':
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) jsonError('Brak ID', 400);

        $stmt = $pdo->prepare("
            SELECT m.*, p.slug AS page_slug
            FROM " . DB_PREFIX . "menu m
            LEFT JOIN " . DB_PREFIX . "pages p ON m.page_id = p.id
            WHERE m.id = ?
        ");
        $stmt->execute([$id]);
        $item = $stmt->fetch();

        if (!$item) jsonError('Nie znaleziono pozycji menu', 404);

        $stmt2 = $pdo->prepare("
            SELECT m.*, p.slug AS page_slug
            FROM " . DB_PREFIX . "menu m
            LEFT JOIN " . DB_PREFIX . "pages p ON m.page_id = p.id
            WHERE m.parent_id = ?
            ORDER BY m.sort_order ASC
        ");
        $stmt2->execute([$id]);
        $item['children'] = $stmt2->fetchAll();

        jsonSuccess(['item' => $item]);
        break;

    case 'create':
        $user = requireAuth();
        $data = getInput();

        $label_pl = trim($data['label_pl'] ?? '');
        if (!$label_pl) jsonError('Etykieta PL jest wymagana', 400);

        $label_en   = trim($data['label_en'] ?? '');
        $parent_id  = !empty($data['parent_id']) ? (int)$data['parent_id'] : null;
        $page_id    = !empty($data['page_id']) ? (int)$data['page_id'] : null;
        $url        = trim($data['url'] ?? '');
        $target     = in_array($data['target'] ?? '', ['_self', '_blank']) ? $data['target'] : '_self';
        $is_active  = isset($data['is_active']) ? (int)$data['is_active'] : 1;

        if ($parent_id) {
            $stmt = $pdo->prepare("SELECT id FROM " . DB_PREFIX . "menu WHERE id = ?");
            $stmt->execute([$parent_id]);
            if (!$stmt->fetch()) jsonError('Pozycja nadrzędna nie istnieje', 400);
        }

        if ($page_id) {
            $stmt = $pdo->prepare("SELECT id FROM " . DB_PREFIX . "pages WHERE id = ?");
            $stmt->execute([$page_id]);
            if (!$stmt->fetch()) jsonError('Strona nie istnieje', 400);
        }

        if (!$page_id && !$url) {
            jsonError('Wymagana strona wewnętrzna lub URL zewnętrzny', 400);
        }

        $stmt = $pdo->prepare("
            SELECT COALESCE(MAX(sort_order), 0) + 1 
            FROM " . DB_PREFIX . "menu 
            WHERE parent_id " . ($parent_id ? "= ?" : "IS NULL")
        );
        $stmt->execute($parent_id ? [$parent_id] : []);
        $sort_order = (int)$stmt->fetchColumn();

        $stmt = $pdo->prepare("
            INSERT INTO " . DB_PREFIX . "menu 
            (parent_id, page_id, label_pl, label_en, url, target, sort_order, is_active)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $parent_id, $page_id, $label_pl, $label_en,
            $url ?: null, $target, $sort_order, $is_active
        ]);

        $newId = (int)$pdo->lastInsertId();

        logAction($user['id'], 'menu_create', 'menu', $newId, "Utworzono: {$label_pl}");

        jsonSuccess(['id' => $newId, 'message' => 'Pozycja menu utworzona']);
        break;

    case 'update':
        $user = requireAuth();
        $data = getInput();

        $id = (int)($data['id'] ?? 0);
        if (!$id) jsonError('Brak ID', 400);

        $stmt = $pdo->prepare("SELECT * FROM " . DB_PREFIX . "menu WHERE id = ?");
        $stmt->execute([$id]);
        $item = $stmt->fetch();
        if (!$item) jsonError('Nie znaleziono pozycji menu', 404);

        $fields = [];
        $values = [];

        $allowed = ['label_pl', 'label_en', 'url', 'target', 'is_active', 'sort_order'];
        foreach ($allowed as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "{$field} = ?";
                $values[] = $data[$field];
            }
        }

        if (array_key_exists('parent_id', $data)) {
            $newParent = !empty($data['parent_id']) ? (int)$data['parent_id'] : null;

            if ($newParent === $id) {
                jsonError('Pozycja nie może być własnym rodzicem', 400);
            }

            if ($newParent) {
                if (isDescendant($pdo, $id, $newParent)) {
                    jsonError('Nie można przenieść do własnego poddrzewa', 400);
                }
                $stmt2 = $pdo->prepare("SELECT id FROM " . DB_PREFIX . "menu WHERE id = ?");
                $stmt2->execute([$newParent]);
                if (!$stmt2->fetch()) jsonError('Pozycja nadrzędna nie istnieje', 400);
            }

            $fields[] = "parent_id = ?";
            $values[] = $newParent;
        }

        if (array_key_exists('page_id', $data)) {
            $newPage = !empty($data['page_id']) ? (int)$data['page_id'] : null;
            if ($newPage) {
                $stmt2 = $pdo->prepare("SELECT id FROM " . DB_PREFIX . "pages WHERE id = ?");
                $stmt2->execute([$newPage]);
                if (!$stmt2->fetch()) jsonError('Strona nie istnieje', 400);
            }
            $fields[] = "page_id = ?";
            $values[] = $newPage;
        }

        if (empty($fields)) jsonError('Brak pól do aktualizacji', 400);

        $values[] = $id;
        $sql = "UPDATE " . DB_PREFIX . "menu SET " . implode(', ', $fields) . " WHERE id = ?";
        $pdo->prepare($sql)->execute($values);

        logAction($user['id'], 'menu_update', 'menu', $id, "Zaktualizowano pozycję menu");

        jsonSuccess(['message' => 'Pozycja menu zaktualizowana']);
        break;

    case 'delete':
        $user = requireAuth();
        $data = getInput();

        $id = (int)($data['id'] ?? 0);
        if (!$id) jsonError('Brak ID', 400);

        $stmt = $pdo->prepare("SELECT * FROM " . DB_PREFIX . "menu WHERE id = ?");
        $stmt->execute([$id]);
        $item = $stmt->fetch();
        if (!$item) jsonError('Nie znaleziono pozycji menu', 404);

        $mode = $data['children_mode'] ?? $data['children_action'] ?? 'delete';  

        $pdo->beginTransaction();
        try {
            if ($mode === 'move_up') {

                $stmt2 = $pdo->prepare("
                    UPDATE " . DB_PREFIX . "menu 
                    SET parent_id = ? 
                    WHERE parent_id = ?
                ");
                $stmt2->execute([$item['parent_id'], $id]);
            }

            $stmt3 = $pdo->prepare("DELETE FROM " . DB_PREFIX . "menu WHERE id = ?");
            $stmt3->execute([$id]);

            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            jsonError('Błąd usuwania: ' . $e->getMessage(), 500);
        }

        logAction($user['id'], 'menu_delete', 'menu', $id, 
            "Usunięto: {$item['label_pl']} (dzieci: {$mode})");

        jsonSuccess(['message' => 'Pozycja menu usunięta']);
        break;

    case 'reorder':
        $user = requireAuth();
        $data = getInput();

        $items = $data['items'] ?? $data['order'] ?? [];

        error_log('[MENU REORDER] Raw input keys: ' . implode(', ', array_keys($data)));
        error_log('[MENU REORDER] Items count: ' . count($items));
        if (!empty($items)) {
            error_log('[MENU REORDER] First item: ' . json_encode($items[0]));
        }

        if (!is_array($items) || empty($items)) {
            jsonError('Wymagana tablica items [{id, parent_id, sort_order}]', 400);
        }

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("
                UPDATE " . DB_PREFIX . "menu 
                SET sort_order = ?, parent_id = ?
                WHERE id = ?
            ");

            foreach ($items as $item) {
                $id         = (int)($item['id'] ?? 0);
                $sort       = (int)($item['sort_order'] ?? 0);
                $parentId   = !empty($item['parent_id']) ? (int)$item['parent_id'] : null;

                if (!$id) continue;

                $stmt->execute([$sort, $parentId, $id]);
            }

            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            jsonError('Błąd sortowania: ' . $e->getMessage(), 500);
        }

        logAction($user['id'], 'menu_reorder', 'menu', null, 
            'Zmieniono kolejność menu (' . count($items) . ' pozycji)');

        jsonSuccess(['message' => 'Kolejność menu zaktualizowana']);
        break;

    default:
        jsonError('Nieznana akcja. Dostępne: list, get, create, update, delete, reorder', 400);
}

function buildMenuTree(array $items, $parentId): array {
    $tree = [];
    foreach ($items as $item) {
        $itemParent = $item['parent_id'] ? (int)$item['parent_id'] : null;
        if ($itemParent === $parentId) {
            $item['children'] = buildMenuTree($items, (int)$item['id']);
            $tree[] = $item;
        }
    }
    return $tree;
}

function isDescendant(PDO $pdo, int $nodeId, int $targetId): bool {
    $descendants = [];
    $queue = [$nodeId];

    while (!empty($queue)) {
        $current = array_shift($queue);
        $stmt = $pdo->prepare("SELECT id FROM " . DB_PREFIX . "menu WHERE parent_id = ?");
        $stmt->execute([$current]);
        $children = $stmt->fetchAll(PDO::FETCH_COLUMN);

        foreach ($children as $childId) {
            if ((int)$childId === $targetId) return true;
            $descendants[] = (int)$childId;
            $queue[] = (int)$childId;
        }

        if (count($descendants) > 100) break;
    }

    return false;
}