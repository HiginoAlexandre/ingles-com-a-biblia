<?php
declare(strict_types=1);
require __DIR__ . '/config.php';

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Execute este script pelo terminal, não pelo navegador.\n"); }

/** Divide dumps comuns por ponto e vírgula no fim da linha, preservando texto SQL. */
function sqlStatements(string $sql): array {
    $sql = preg_replace('/^\s*(?:--|#).*$/m', '', $sql) ?? $sql;
    $parts = preg_split('/;\s*(?:\r?\n|$)/', $sql) ?: [];
    return array_values(array_filter(array_map('trim', $parts), static fn(string $s): bool => $s !== ''));
}
try {
    $pdo = db();
    $schemaPath = __DIR__ . '/schema/schema.sql';
    $schema = file_get_contents($schemaPath);
    if ($schema === false) throw new RuntimeException('Não foi possível ler schema/schema.sql.');
    foreach (sqlStatements($schema) as $statement) $pdo->exec($statement);
    echo "Estrutura da base de dados verificada.\n";

    $booksFile = __DIR__ . '/Books/Books.json';
    if (!is_file($booksFile)) throw new RuntimeException('Não encontrei Books/Books.json.');
    $bookList = json_decode((string)file_get_contents($booksFile), true, 512, JSON_THROW_ON_ERROR);
    foreach (['books', 'Books', 'data'] as $key) {
        if (isset($bookList[$key]) && is_array($bookList[$key])) { $bookList = $bookList[$key]; break; }
    }
    $orderedNames = [];
    foreach ($bookList as $item) {
        $name = is_string($item) ? trim($item) : (is_array($item) ? trim((string)($item['book'] ?? $item['name'] ?? $item['title'] ?? '')) : '');
        if ($name !== '') $orderedNames[] = $name;
    }
    if (!$orderedNames) throw new RuntimeException('Books/Books.json não contém nomes de livros reconhecíveis.');

    $pdo->beginTransaction();
    $upsertBook = $pdo->prepare('INSERT INTO bible_books (name, sort_order, chapter_count) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE sort_order=VALUES(sort_order), chapter_count=VALUES(chapter_count)');
    $findBook = $pdo->prepare('SELECT id FROM bible_books WHERE name = ?');
    $deleteVerses = $pdo->prepare('DELETE FROM bible_verses WHERE book_id = ?');
    $insertVerse = $pdo->prepare('INSERT INTO bible_verses (book_id, chapter_number, verse_number, verse_text) VALUES (?, ?, ?, ?)');
    $booksImported = 0; $versesImported = 0; $missingFiles = [];
    $contentDirectory = __DIR__ . '/Books/contentBook';
    $candidates = glob($contentDirectory . '/*.json') ?: [];
    foreach ($orderedNames as $index => $name) {
        $path = $contentDirectory . DIRECTORY_SEPARATOR . $name . '.json';
        if (!is_file($path)) $path = __DIR__ . DIRECTORY_SEPARATOR . $name . '.json';
        if (!is_file($path)) {
            foreach ($candidates as $candidatePath) {
                $candidate = json_decode((string)file_get_contents($candidatePath), true);
                if (is_array($candidate) && strcasecmp((string)($candidate['book'] ?? ''), $name) === 0) { $path = $candidatePath; break; }
            }
        }
        if (!is_file($path)) { $missingFiles[] = $name; continue; }
        $data = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $chapters = is_array($data['chapters'] ?? null) ? $data['chapters'] : [];
        $upsertBook->execute([$name, $index + 1, count($chapters)]);
        $findBook->execute([$name]); $bookId = (int)$findBook->fetchColumn();
        $deleteVerses->execute([$bookId]);
        foreach ($chapters as $chapter) {
            $chapterNo = (int)($chapter['chapter'] ?? 0);
            foreach (($chapter['verses'] ?? []) as $verse) {
                $verseNo = (int)($verse['verse'] ?? 0);
                if ($chapterNo > 0 && $verseNo > 0) {
                    $insertVerse->execute([$bookId, $chapterNo, $verseNo, (string)($verse['text'] ?? '')]);
                    $versesImported++;
                }
            }
        }
        $booksImported++;
    }
    $pdo->commit();

    $sqlFile = trim((string)envValue('TRANSCRIPTION_SQL_FILE', 'schema/transcription_table.sql'));
    $sqlPath = __DIR__ . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $sqlFile);
    if ($sqlFile !== '' && is_file($sqlPath)) {
        $sql = file_get_contents($sqlPath);
        if ($sql === false) throw new RuntimeException('Não foi possível ler o ficheiro SQL do dicionário.');
        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, true);
        foreach (sqlStatements($sql) as $statement) {
            // Permite executar o mesmo dump mais do que uma vez sem duplicar palavras existentes.
            $statement = preg_replace('/^INSERT\s+INTO\s+/i', 'INSERT IGNORE INTO ', $statement, 1) ?? $statement;
            $pdo->exec($statement);
        }
        echo "Dicionário de transcrições importado de {$sqlFile}.\n";
    } else {
        echo "Aviso: não encontrei {$sqlFile}; a tabela transcription_table está criada, mas o dicionário não foi importado.\n";
    }
    echo "Migração concluída. Livros importados: {$booksImported}; versículos importados: {$versesImported}.\n";
    if ($missingFiles) echo "Livros sem ficheiro correspondente: " . implode(', ', $missingFiles) . "\n";
    if ($missingFiles) echo "Confirma se os JSON estão em Books/contentBook/ e se o campo 'book' corresponde ao nome em Books/Books.json.\n";
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, "Erro na migração: " . $e->getMessage() . "\n"); exit(1);
}
