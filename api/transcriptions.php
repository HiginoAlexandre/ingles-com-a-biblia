<?php
declare(strict_types=1);
require dirname(__DIR__) . '/config.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, max-age=3600');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error' => 'Método não permitido']); exit; }
try {
    $payload = json_decode((string)file_get_contents('php://input'), true, 512, JSON_THROW_ON_ERROR);
    $words = $payload['words'] ?? null;
    if (!is_array($words)) { http_response_code(400); echo json_encode(['error' => 'Envie uma lista words.']); exit; }
    $normalized = [];
    foreach (array_slice($words, 0, 500) as $word) {
        if (!is_string($word)) continue;
        $word = mb_strtolower(trim(str_replace(['’', '‘'], "'", $word)), 'UTF-8');
        if ($word !== '' && mb_strlen($word, 'UTF-8') <= 255) $normalized[$word] = true;
    }
    if (!$normalized) { echo json_encode(['transcriptions' => new stdClass()], JSON_UNESCAPED_UNICODE); exit; }
    $items = array_keys($normalized);
    $placeholders = implode(',', array_fill(0, count($items), '?'));
    $stmt = db()->prepare("SELECT LOWER(original_word) AS word, transcription FROM transcription_table WHERE LOWER(original_word) IN ({$placeholders})");
    $stmt->execute($items);
    $result = [];
    foreach ($stmt->fetchAll() as $row) {
        $value = trim((string)$row['transcription']);
        $result[(string)$row['word']] = ($value === '' || $value === '---' || $value === '-') ? null : $value;
    }
    echo json_encode(['transcriptions' => $result], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => envValue('APP_DEBUG', 'false') === 'true' ? $e->getMessage() : 'Erro ao consultar as transcrições.']);
}
