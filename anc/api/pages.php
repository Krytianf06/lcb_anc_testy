<?php
/**
 * API: Strony (Pages)
 * 
 * GET    ?action=list              — lista wszystkich stron
 * GET    ?action=get&slug=home     — pojedyncza strona z blokami
 * POST   ?action=create            — utwórz stronę (wymaga auth)
 * POST   ?action=update            — aktualizuj stronę (wymaga auth)
 * POST   ?action=delete            — usuń stronę (wymaga auth/admin)
 * POST   ?action=reorder           — zmień kolejność stron (wymaga auth)
 */

require_once dirname(__DIR__, 2) . '/config.php';

$pdo = getDB();
$prefix = DB_PREFIX;
$action = $_GET['action'] ?? '';

switch ($action) {

    // ─── Lista stron ───────────────────────────────────────
    case 'list':
        $showInactive = false;
        $user = optionalAuth();
        if ($user && in_array($user['role'], ['admin', 'editor'])) {
            $showInactive = true;
        }

        $sql = "SELECT id, slug, title_pl, title_en, is_active, sort_order, created_at, updated_at 
                FROM {$prefix}pages";
        if (!$showInactive) {
            $sql .= " WHERE is_active = 1";
        }
        $sql .= " ORDER BY sort_order ASC, id ASC";

        $stmt = $pdo->query($sql);
        $pages = $stmt->fetchAll();

        // Dodaj liczbę bloków do każdej strony
        $countStmt = $pdo->prepare("SELECT page_id, COUNT(*) as block_count FROM {$prefix}blocks GROUP BY page_id");
        $countStmt->execute();
        $counts = [];
        while ($row = $countStmt->fetch()) {
            $counts[$row['page_id']] = (int)$row['block_count'];
        }

        foreach ($pages as &$page) {
            $page['block_count'] = $counts[$page['id']] ?? 0;
        }

        jsonSuccess(['pages' => $pages]);
        break;

    // ─── Pobierz stronę z blokami ──────────────────────────
    case 'get':
        $slug = $_GET['slug'] ?? '';
        $id = $_GET['id'] ?? null;

        if (empty($slug) && empty($id)) {
            jsonError('Podaj slug lub id strony', 400);
        }

        if (!empty($slug)) {
            $stmt = $pdo->prepare("SELECT * FROM {$prefix}pages WHERE slug = ?");
            $stmt->execute([$slug]);
        } else {
            $stmt = $pdo->prepare("SELECT * FROM {$prefix}pages WHERE id = ?");
            $stmt->execute([(int)$id]);
        }

        $page = $stmt->fetch();
        if (!$page) {
            jsonError('Strona nie znaleziona', 404);
        }

        // Sprawdź czy nieaktywna strona — tylko dla zalogowanych
        if (!$page['is_active']) {
            $user = optionalAuth();
            if (!$user || !in_array($user['role'], ['admin', 'editor'])) {
                jsonError('Strona nie znaleziona', 404);
            }
        }

        // Pobierz bloki strony
        $blockStmt = $pdo->prepare(
            "SELECT id, type, content_pl, content_en, settings, width, sort_order, is_active 
             FROM {$prefix}blocks 
             WHERE page_id = ? 
             ORDER BY sort_order ASC, id ASC"
        );
        $blockStmt->execute([$page['id']]);
        $blocks = $blockStmt->fetchAll();

        // Dekoduj JSON settings
        foreach ($blocks as &$block) {
            $block['settings'] = $block['settings'] ? json_decode($block['settings'], true) : null;
        }

        $page['blocks'] = $blocks;

        jsonSuccess(['page' => $page]);
        break;

    // ─── Utwórz stronę ────────────────────────────────────
    case 'create':
        $user = requireAuth();
        $input = getInput();

        $slug = trim($input['slug'] ?? '');
        $titlePl = trim($input['title_pl'] ?? '');
        $titleEn = trim($input['title_en'] ?? '');
        $isActive = isset($input['is_active']) ? (int)$input['is_active'] : 1;

        if (empty($slug) || empty($titlePl)) {
            jsonError('Slug i tytuł PL są wymagane', 400);
        }

        // Walidacja sluga — tylko litery, cyfry, myślniki
        if (!preg_match('/^[a-z0-9\-]+$/', $slug)) {
            jsonError('Slug może zawierać tylko małe litery, cyfry i myślniki', 400);
        }

        // Sprawdź unikalność sluga
        $checkStmt = $pdo->prepare("SELECT id FROM {$prefix}pages WHERE slug = ?");
        $checkStmt->execute([$slug]);
        if ($checkStmt->fetch()) {
            jsonError('Strona o takim slugu już istnieje', 409);
        }

        // Pobierz max sort_order
        $maxStmt = $pdo->query("SELECT COALESCE(MAX(sort_order), 0) + 1 as next_order FROM {$prefix}pages");
        $nextOrder = $maxStmt->fetch()['next_order'];

        $insertStmt = $pdo->prepare(
            "INSERT INTO {$prefix}pages (slug, title_pl, title_en, is_active, sort_order, created_by) 
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        $insertStmt->execute([$slug, $titlePl, $titleEn, $isActive, $nextOrder, $user['id']]);
        $pageId = (int)$pdo->lastInsertId();

        logAction($user['id'], 'page_create', 'pages', $pageId, "Utworzono stronę: {$slug}");

        // Pobierz utworzoną stronę
        $stmt = $pdo->prepare("SELECT * FROM {$prefix}pages WHERE id = ?");
        $stmt->execute([$pageId]);
        $page = $stmt->fetch();

        jsonSuccess(['page' => $page, 'message' => 'Strona utworzona'], 201);
        break;

    // ─── Aktualizuj stronę ─────────────────────────────────
    case 'update':
        $user = requireAuth();
        $input = getInput();

        $id = (int)($input['id'] ?? 0);
        if ($id <= 0) {
            jsonError('ID strony jest wymagane', 400);
        }

        // Sprawdź czy strona istnieje
        $checkStmt = $pdo->prepare("SELECT * FROM {$prefix}pages WHERE id = ?");
        $checkStmt->execute([$id]);
        $existing = $checkStmt->fetch();
        if (!$existing) {
            jsonError('Strona nie znaleziona', 404);
        }

        // Przygotuj dane do aktualizacji
        $fields = [];
        $values = [];

        if (isset($input['slug'])) {
            $slug = trim($input['slug']);
            if (!preg_match('/^[a-z0-9\-]+$/', $slug)) {
                jsonError('Slug może zawierać tylko małe litery, cyfry i myślniki', 400);
            }
            // Sprawdź unikalność (poza aktualną stroną)
            $dupStmt = $pdo->prepare("SELECT id FROM {$prefix}pages WHERE slug = ? AND id != ?");
            $dupStmt->execute([$slug, $id]);
            if ($dupStmt->fetch()) {
                jsonError('Strona o takim slugu już istnieje', 409);
            }
            $fields[] = "slug = ?";
            $values[] = $slug;
        }

        if (isset($input['title_pl'])) {
            $fields[] = "title_pl = ?";
            $values[] = trim($input['title_pl']);
        }

        if (isset($input['title_en'])) {
            $fields[] = "title_en = ?";
            $values[] = trim($input['title_en']);
        }

        if (isset($input['is_active'])) {
            $fields[] = "is_active = ?";
            $values[] = (int)$input['is_active'];
        }

        if (isset($input['sort_order'])) {
            $fields[] = "sort_order = ?";
            $values[] = (int)$input['sort_order'];
        }

        if (empty($fields)) {
            jsonError('Brak danych do aktualizacji', 400);
        }

        $values[] = $id;
        $sql = "UPDATE {$prefix}pages SET " . implode(', ', $fields) . " WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($values);

        logAction($user['id'], 'page_update', 'pages', $id, "Zaktualizowano stronę ID: {$id}");

        // Pobierz zaktualizowaną stronę
        $stmt = $pdo->prepare("SELECT * FROM {$prefix}pages WHERE id = ?");
        $stmt->execute([$id]);
        $page = $stmt->fetch();

        jsonSuccess(['page' => $page, 'message' => 'Strona zaktualizowana']);
        break;

    // ─── Usuń stronę ───────────────────────────────────────
    case 'delete':
        $user = requireAdmin(); // Tylko admin może usuwać strony
        $input = getInput();

        $id = (int)($input['id'] ?? 0);
        if ($id <= 0) {
            jsonError('ID strony jest wymagane', 400);
        }

        // Sprawdź czy strona istnieje
        $checkStmt = $pdo->prepare("SELECT slug FROM {$prefix}pages WHERE id = ?");
        $checkStmt->execute([$id]);
        $existing = $checkStmt->fetch();
        if (!$existing) {
            jsonError('Strona nie znaleziona', 404);
        }

        // Zabezpieczenie — nie usuwaj strony głównej
        if ($existing['slug'] === 'home') {
            jsonError('Nie można usunąć strony głównej', 403);
        }

        // Bloki zostaną usunięte automatycznie (ON DELETE CASCADE)
        // Menu - page_id zostanie ustawione na NULL (ON DELETE SET NULL)

        $delStmt = $pdo->prepare("DELETE FROM {$prefix}pages WHERE id = ?");
        $delStmt->execute([$id]);

        logAction($user['id'], 'page_delete', 'pages', $id, "Usunięto stronę: {$existing['slug']}");

        jsonSuccess(['message' => 'Strona usunięta']);
        break;

    // ─── Zmień kolejność stron ─────────────────────────────
    case 'reorder':
        $user = requireAuth();
        $input = getInput();

        $order = $input['order'] ?? [];
        if (empty($order) || !is_array($order)) {
            jsonError('Tablica order jest wymagana', 400);
        }

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("UPDATE {$prefix}pages SET sort_order = ? WHERE id = ?");
            foreach ($order as $index => $pageId) {
                $stmt->execute([$index, (int)$pageId]);
            }
            $pdo->commit();

            logAction($user['id'], 'pages_reorder', 'pages', null, "Zmieniono kolejność stron");

            jsonSuccess(['message' => 'Kolejność zaktualizowana']);
        } catch (Exception $e) {
            $pdo->rollBack();
            jsonError('Błąd podczas zmiany kolejności', 500);
        }
        break;

    // ─── Nieznana akcja ────────────────────────────────────
    default:
        jsonError('Nieznana akcja. Dostępne: list, get, create, update, delete, reorder', 400);
}
