<?php
declare(strict_types=1);
require dirname(__DIR__) . '/config.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function respond(array $data, int $status = 200): never {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function activeCycle(PDO $pdo, int $userId): array {
    $stmt = $pdo->prepare('SELECT id, cycle_number FROM reading_cycles WHERE user_id = ? AND completed_at IS NULL ORDER BY cycle_number DESC LIMIT 1');
    $stmt->execute([$userId]);
    $cycle = $stmt->fetch();
    if ($cycle) return $cycle;
    $stmt = $pdo->prepare('SELECT COALESCE(MAX(cycle_number), 0) + 1 FROM reading_cycles WHERE user_id = ?');
    $stmt->execute([$userId]);
    $number = (int)$stmt->fetchColumn();
    $stmt = $pdo->prepare('INSERT INTO reading_cycles (user_id, cycle_number, started_at) VALUES (?, ?, NOW())');
    $stmt->execute([$userId, $number]);
    return ['id' => (int)$pdo->lastInsertId(), 'cycle_number' => $number];
}
function readingState(PDO $pdo, int $userId): array {
    $cycle = activeCycle($pdo, $userId);
    $stmt = $pdo->prepare('SELECT b.name, rc.chapter_number FROM reading_chapters rc JOIN bible_books b ON b.id = rc.book_id WHERE rc.cycle_id = ?');
    $stmt->execute([(int)$cycle['id']]);
    $marked = array_map(static fn(array $r): string => $r['name'] . ' ' . $r['chapter_number'], $stmt->fetchAll());
    $stmt = $pdo->prepare('SELECT b.name, uvm.chapter_number, uvm.verse_number FROM user_verse_marks uvm JOIN bible_books b ON b.id = uvm.book_id WHERE uvm.user_id = ?');
    $stmt->execute([$userId]);
    $verseMarks = [];
    foreach ($stmt->fetchAll() as $r) $verseMarks[$r['name'] . ' ' . $r['chapter_number'] . ':' . $r['verse_number']] = true;
    $total = (int)$pdo->query('SELECT COUNT(*) FROM (SELECT DISTINCT book_id, chapter_number FROM bible_verses) AS chapters')->fetchColumn();
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM reading_cycles WHERE user_id = ? AND completed_at IS NOT NULL');
    $stmt->execute([$userId]);
    $completed = (int)$stmt->fetchColumn();
    $stmt = $pdo->prepare('SELECT cycle_number, started_at, completed_at FROM reading_cycles WHERE user_id = ? ORDER BY cycle_number DESC');
    $stmt->execute([$userId]);
    return [
        'markedChapters' => $marked,
        'markedVerses' => $verseMarks,
        'totalChapters' => $total,
        'completedReadings' => $completed,
        'currentCycle' => (int)$cycle['cycle_number'],
        'history' => $stmt->fetchAll(),
        'csrfToken' => (string)($_SESSION['csrf_token'] ?? '')
    ];
}

if (empty($_SESSION['user_id'])) respond(['error' => 'Inicia sessão para guardar o progresso na base de dados.'], 401);
$userId = (int)$_SESSION['user_id'];
try {
    $pdo = db();
    if ($_SERVER['REQUEST_METHOD'] === 'GET') respond(['ok' => true, 'state' => readingState($pdo, $userId)]);
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') respond(['error' => 'Método não permitido.'], 405);
    $body = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($body)) respond(['error' => 'JSON inválido.'], 400);
    if (!hash_equals((string)($_SESSION['csrf_token'] ?? ''), (string)($body['csrfToken'] ?? ''))) respond(['error' => 'Token de segurança inválido. Actualiza a página.'], 403);
    $action = (string)($body['action'] ?? '');
    $pdo->beginTransaction();
    if ($action === 'chapter') {
        $book = trim((string)($body['book'] ?? ''));
        $chapter = filter_var($body['chapter'] ?? null, FILTER_VALIDATE_INT);
        $read = (bool)($body['read'] ?? false);
        if ($book === '' || !$chapter || $chapter < 1) throw new InvalidArgumentException('Livro ou capítulo inválido.');
        $stmt = $pdo->prepare('SELECT id FROM bible_books WHERE name = ?');
        $stmt->execute([$book]); $bookId = (int)$stmt->fetchColumn();
        if (!$bookId) throw new InvalidArgumentException('Livro não encontrado.');
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM bible_verses WHERE book_id = ? AND chapter_number = ?');
        $stmt->execute([$bookId, $chapter]);
        if ((int)$stmt->fetchColumn() === 0) throw new InvalidArgumentException('Capítulo não encontrado.');
        $cycle = activeCycle($pdo, $userId);
        if ($read) {
            $stmt = $pdo->prepare('INSERT IGNORE INTO reading_chapters (cycle_id, book_id, chapter_number, read_at) VALUES (?, ?, ?, NOW())');
            $stmt->execute([(int)$cycle['id'], $bookId, $chapter]);
        } else {
            $stmt = $pdo->prepare('DELETE FROM reading_chapters WHERE cycle_id = ? AND book_id = ? AND chapter_number = ?');
            $stmt->execute([(int)$cycle['id'], $bookId, $chapter]);
        }
        $total = (int)$pdo->query('SELECT COUNT(*) FROM (SELECT DISTINCT book_id, chapter_number FROM bible_verses) AS chapters')->fetchColumn();
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM reading_chapters WHERE cycle_id = ?');
        $stmt->execute([(int)$cycle['id']]); $count = (int)$stmt->fetchColumn();
        $completedNow = false;
        if ($total > 0 && $count >= $total) {
            $stmt = $pdo->prepare('UPDATE reading_cycles SET completed_at = NOW() WHERE id = ? AND completed_at IS NULL');
            $stmt->execute([(int)$cycle['id']]);
            if ($stmt->rowCount() > 0) {
                $stmt = $pdo->prepare('INSERT INTO reading_cycles (user_id, cycle_number, started_at) VALUES (?, ?, NOW())');
                $stmt->execute([$userId, (int)$cycle['cycle_number'] + 1]);
                $completedNow = true;
            }
        }
    } elseif ($action === 'verse') {
        $book = trim((string)($body['book'] ?? ''));
        $chapter = filter_var($body['chapter'] ?? null, FILTER_VALIDATE_INT);
        $verse = filter_var($body['verse'] ?? null, FILTER_VALIDATE_INT);
        $marked = (bool)($body['marked'] ?? false);
        if ($book === '' || !$chapter || !$verse || $chapter < 1 || $verse < 1) throw new InvalidArgumentException('Referência de versículo inválida.');
        $stmt = $pdo->prepare('SELECT id FROM bible_books WHERE name = ?'); $stmt->execute([$book]); $bookId = (int)$stmt->fetchColumn();
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM bible_verses WHERE book_id = ? AND chapter_number = ? AND verse_number = ?');
        $stmt->execute([$bookId, $chapter, $verse]);
        if (!$bookId || (int)$stmt->fetchColumn() === 0) throw new InvalidArgumentException('Versículo não encontrado.');
        if ($marked) {
            $stmt = $pdo->prepare('INSERT IGNORE INTO user_verse_marks (user_id, book_id, chapter_number, verse_number, created_at) VALUES (?, ?, ?, ?, NOW())');
            $stmt->execute([$userId, $bookId, $chapter, $verse]);
        } else {
            $stmt = $pdo->prepare('DELETE FROM user_verse_marks WHERE user_id = ? AND book_id = ? AND chapter_number = ? AND verse_number = ?');
            $stmt->execute([$userId, $bookId, $chapter, $verse]);
        }
        $completedNow = false;
    } else {
        throw new InvalidArgumentException('Acção desconhecida.');
    }
    $pdo->commit();
    respond(['ok' => true, 'completedNow' => $completedNow ?? false, 'state' => readingState($pdo, $userId)]);
} catch (InvalidArgumentException $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    respond(['error' => $e->getMessage()], 422);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log('Bible reading API error: ' . $e->getMessage());
    respond(['error' => 'Não foi possível guardar os dados. Verifica a ligação à base de dados.'], 500);
}
