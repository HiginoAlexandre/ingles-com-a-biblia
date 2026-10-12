<?php
declare(strict_types=1);
require __DIR__ . '/config.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
function h(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
$pdo = db();
$booksStmt = $pdo->query('SELECT id, name, sort_order, chapter_count FROM bible_books ORDER BY sort_order');
$totalChapters = (int)$pdo->query('SELECT COUNT(*) FROM (SELECT DISTINCT book_id, chapter_number FROM bible_verses) AS chapters')->fetchColumn();
$isLoggedIn = !empty($_SESSION['user_id']);
if ($isLoggedIn && empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$userName = (string)($_SESSION['user_name'] ?? '');
$booksData = $booksStmt->fetchAll();
$books = array_column($booksData, 'name');
$requestedBook = trim((string)($_GET['book'] ?? ($books[0] ?? 'Genesis')));
$book = in_array($requestedBook, $books, true) ? $requestedBook : ($books[0] ?? 'Genesis');
$bookRow = null;
foreach ($booksData as $row) if ($row['name'] === $book) { $bookRow = $row; break; }
$bookId = (int)($bookRow['id'] ?? 0);
$chapterStmt = $pdo->prepare('SELECT DISTINCT chapter_number FROM bible_verses WHERE book_id = ? ORDER BY chapter_number');
$chapterStmt->execute([$bookId]);
$chapterNumbers = array_map('intval', $chapterStmt->fetchAll(PDO::FETCH_COLUMN));
$requestedChapter = filter_input(INPUT_GET, 'chapter', FILTER_VALIDATE_INT);
$chapterNumber = $requestedChapter && in_array($requestedChapter, $chapterNumbers, true) ? $requestedChapter : (int)($chapterNumbers[0] ?? 1);
$verseStmt = $pdo->prepare('SELECT verse_number AS verse, verse_text AS text FROM bible_verses WHERE book_id = ? AND chapter_number = ? ORDER BY verse_number');
$verseStmt->execute([$bookId, $chapterNumber]);
$verseList = $verseStmt->fetchAll();
$chapterData = $verseList ? ['chapter' => $chapterNumber, 'verses' => $verseList] : null;
$bookIndex = array_search($book, $books, true);
$bookIndex = $bookIndex === false ? 0 : $bookIndex;
$prevBook = $bookIndex > 0 ? $books[$bookIndex - 1] : null;
$nextBook = $bookIndex < count($books) - 1 ? $books[$bookIndex + 1] : null;
$chapterIndex = array_search($chapterNumber, $chapterNumbers, true);
$chapterIndex = $chapterIndex === false ? 0 : $chapterIndex;
$previousChapter = $chapterIndex > 0 ? $chapterNumbers[$chapterIndex - 1] : null;
$nextChapter = $chapterIndex < count($chapterNumbers) - 1 ? $chapterNumbers[$chapterIndex + 1] : null;
$prevUrl = $previousChapter !== null ? '?book=' . rawurlencode($book) . '&chapter=' . $previousChapter : ($prevBook !== null ? '?book=' . rawurlencode($prevBook) . '&chapter=1' : null);
$nextUrl = $nextChapter !== null ? '?book=' . rawurlencode($book) . '&chapter=' . $nextChapter : ($nextBook !== null ? '?book=' . rawurlencode($nextBook) . '&chapter=1' : null);
$chapterCount = count($chapterNumbers);
$firstVerse = $verseList[0]['verse'] ?? 1;
$lastVerse = $verseList ? ($verseList[count($verseList) - 1]['verse'] ?? count($verseList)) : 0;
?>
<!doctype html>
<html lang="pt">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#285b48">
    <title><?= h($book) ?> <?= h($chapterNumber) ?> — Leitor Bíblico</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
<div class="app-shell">
    <header class="topbar">
        <a class="brand" href="index.php" aria-label="Leitor Bíblico - início">
            <span class="brand-icon">✝</span>
            <span><strong>Leitor Bíblico</strong><small>LEITURA · MEDITAÇÃO · PROGRESSO</small></span>
        </a>
        <div class="top-actions">
            <button class="icon-button" id="themeToggle" type="button" title="Alternar tema" aria-label="Alternar tema">◐</button>
            <?php if ($isLoggedIn): ?>
                <span class="account-label">Olá, <?= h($userName) ?></span>
                <form method="post" action="logout.php" class="logout-form"><input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>"><button class="account-link" type="submit">Sair</button></form>
            <?php else: ?>
                <a class="account-link" href="auth.php?mode=login">Iniciar sessão</a>
                <a class="account-link account-primary" href="auth.php?mode=register">Criar conta</a>
            <?php endif; ?>
        </div>
    </header>

    <section class="welcome">
        <div>
            <p class="eyebrow">UM MOMENTO COM A PALAVRA</p>
            <h1>Leia com calma.<br><em>Medite na Palavra.</em></h1>
            <p class="welcome-copy">Um capítulo de cada vez, ao seu ritmo.</p>
        </div>
        <div class="welcome-mark" aria-hidden="true">✝</div>
    </section>

    <section class="reader-controls" aria-label="Escolher passagem bíblica">
        <form method="get" action="index.php" class="selectors">
            <label for="bookSelect">Livro da Bíblia
                <select id="bookSelect" name="book">
                    <?php foreach ($books as $bookOption): ?>
                        <option value="<?= h($bookOption) ?>" <?= $bookOption === $book ? 'selected' : '' ?>><?= h($bookOption) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label for="chapterSelect">Capítulo
                <select id="chapterSelect" name="chapter">
                    <?php foreach ($chapterNumbers as $number): ?>
                        <option value="<?= h($number) ?>" <?= $number === $chapterNumber ? 'selected' : '' ?>>Capítulo <?= h($number) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button class="go-button" type="submit">Abrir capítulo <span>→</span></button>
        </form>
        <div class="reader-tools">
            <button class="tool-button" id="fontDown" type="button" aria-label="Diminuir letra">A− <span>Letra</span></button>
            <button class="tool-button" id="fontUp" type="button" aria-label="Aumentar letra">A+ <span>Letra</span></button>
            <button class="tool-button" id="pronunciationToggle" type="button" aria-pressed="true">Aa <span>Ocultar fonética</span></button>
            <button class="tool-button" id="focusToggle" type="button">◎ <span>Modo de foco</span></button>
            <button class="tool-button" id="copyReference" type="button">▣ <span>Copiar referência</span></button>
        </div>
    </section>

    <main class="reading-layout">
        <aside class="side-card">
            <div class="side-heading">
                <span class="mini-icon">✓</span>
                <div><h2>O teu progresso</h2><p>Continua firme na leitura</p></div>
            </div>
            <div class="progress-number"><strong id="progressCount">0</strong><span>de <?= h($totalChapters) ?> capítulos neste ciclo</span></div>
            <div class="progress-track"><div class="progress-bar" id="progressBar"></div></div>
            <p class="progress-note" id="progressPercent">0% da Bíblia marcada como lida</p>
            <p class="reading-total">Leituras completas: <strong id="completedReadings">0</strong></p>
            <p class="progress-note" id="cycleLabel">Ciclo de leitura actual</p>
            <button class="text-button" id="markChapter" type="button">♧ <span>Marcar capítulo</span></button>
            <div class="side-divider"></div>
            <button class="text-button" id="showBookmarks" type="button">Ver capítulos marcados <span>→</span></button>
            <div class="bookmarks-panel" id="bookmarksPanel" hidden></div>
            <button class="text-button" id="showHistory" type="button">Histórico de leituras <span>→</span></button>
            <div class="bookmarks-panel" id="historyPanel" hidden></div>
            <p class="progress-note" id="storageNote"><?= $isLoggedIn ? 'O progresso e o histórico estão guardados na tua conta.' : 'Sem sessão: o progresso fica guardado apenas neste navegador.' ?></p>
        </aside>

        <article class="reading-card">
            <?php if ($chapterData): ?>
                <div class="chapter-title">
                    <p class="chapter-meta"><?= h($book) ?> · CAPÍTULO <?= h($chapterNumber) ?></p>
                    <h2><?= h($book) ?></h2>
                    <p class="chapter-subtitle">Capítulo <?= h($chapterNumber) ?> · <?= h(count($verseList)) ?> versículos</p>
                </div>
                <div class="verses">
                    <?php foreach ($verseList as $verse): ?>
                        <?php if (!is_array($verse)) continue; ?>
                        <p class="verse" data-verse="<?= h($verse['verse'] ?? '') ?>">
                            <button class="verse-number" type="button" title="Marcar versículo"><?= h($verse['verse'] ?? '') ?></button>
                            <span class="verse-text"><?= h($verse['text'] ?? '') ?></span>
                        </p>
                    <?php endforeach; ?>
                </div>
                <nav class="chapter-navigation" aria-label="Navegação entre capítulos">
                    <?php if ($prevUrl): ?><a class="nav-button" href="<?= h($prevUrl) ?>"><span>←</span><span><small>ANTERIOR</small><strong><?= h($previousChapter !== null ? 'Capítulo ' . $previousChapter : ($prevBook ?? '')) ?></strong></span></a><?php else: ?><span></span><?php endif; ?>
                    <?php if ($nextUrl): ?><a class="nav-button" href="<?= h($nextUrl) ?>"><span><small>SEGUINTE</small><strong><?= h($nextChapter !== null ? 'Capítulo ' . $nextChapter : ($nextBook ?? '')) ?></strong></span><span>→</span></a><?php endif; ?>
                </nav>
            <?php else: ?>
                <div class="empty-state">
                    <h3>Não foi possível carregar este livro.</h3>
                    <p>Confirma se o ficheiro <strong><?= h($book) ?>.json</strong> está na mesma pasta que o index.php e se contém os capítulos e versículos.</p>
                </div>
            <?php endif; ?>
        </article>
    </main>

    <footer><span>Leitor Bíblico · ferramenta pessoal de leitura</span><span>Leia, medite e pratique.</span></footer>
</div>
<div class="toast" id="toast" role="status" aria-live="polite"></div>
<script>window.BIBLE_READER = <?= json_encode(['book' => $book, 'chapter' => $chapterNumber, 'loggedIn' => $isLoggedIn, 'csrfToken' => $isLoggedIn ? (string)$_SESSION['csrf_token'] : '', 'totalChapters' => $totalChapters, 'userName' => $userName], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
<script src="assets/app.js" defer></script>
<script src="assets/pronunciation.js" defer></script>
<script>
document.getElementById('chapterSelect')?.addEventListener('change', function () {
    this.form.requestSubmit();
});
</script>
</body>
</html>
