<?php
/**
 * KI-Didaktik Chat – Serverseitiger Gemini-API-Proxy mit RAG
 *
 * @author  Steffen Schwabe
 * @license CC BY 4.0 – Creative Commons Attribution 4.0 International
 *          https://creativecommons.org/licenses/by/4.0/
 * @version 2.1.0
 *
 * Ablauf:
 *  1. Payload aus dem Browser empfangen (enthält Nachrichten + System-Instruktion)
 *  2. Letzte Nutzer-Nachricht extrahieren
 *  3. Relevante Wissens-Chunks aus rag/chunks/*.md suchen
 *  4. Chunks an die System-Instruktion anhängen
 *  5. Angereichertes Payload per Streaming an Gemini API weiterleiten
 */

// ── Konfiguration aus per .htaccess gesperrtem Unterverzeichnis ──────────────
$configFile = __DIR__ . '/config/config.php';
if (!file_exists($configFile)) {
    http_response_code(500);
    exit(json_encode(['error' => 'Serverkonfiguration fehlt.']));
}
require $configFile;

// ── Nur POST erlauben ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method Not Allowed');
}

// ── Anfrage-Body lesen ────────────────────────────────────────────────────────
$body = file_get_contents('php://input');
if (empty($body)) {
    http_response_code(400);
    exit('Bad Request');
}

// ── RAG-Hilfsfunktionen ───────────────────────────────────────────────────────

/**
 * Extrahiert bedeutungsrelevante Schlüsselwörter aus einem Text.
 * Entfernt deutsche Stoppwörter und zu kurze Tokens.
 */
function rag_extractKeywords(string $text): array
{
    $stopwords = [
        'ich', 'du', 'er', 'sie', 'es', 'wir', 'ihr', 'und', 'oder', 'aber',
        'auch', 'noch', 'dann', 'wenn', 'dass', 'weil', 'obwohl', 'damit',
        'in', 'an', 'auf', 'fur', 'mit', 'von', 'zu', 'bei', 'nach', 'aus',
        'uber', 'unter', 'vor', 'hinter', 'neben', 'zwischen', 'durch', 'gegen',
        'was', 'wie', 'ist', 'sind', 'war', 'waren', 'hat', 'haben', 'hatte',
        'mir', 'dir', 'ihm', 'den', 'dem', 'des', 'die', 'das', 'der',
        'ein', 'eine', 'einen', 'einem', 'einer', 'nicht', 'kein', 'keine',
        'sehr', 'sich', 'mal', 'mehr', 'nur', 'schon', 'bitte', 'gib', 'gibt',
        'mich', 'dich', 'ihn', 'uns', 'euch', 'zum', 'zur', 'beim', 'vom',
        'ins', 'am', 'im', 'um', 'als', 'so', 'ja', 'nein', 'hier', 'dort',
        'welche', 'welcher', 'welches', 'mein', 'meine', 'dein', 'ihre',
        'kann', 'soll', 'muss', 'darf', 'werden', 'wurde', 'wird',
    ];

    $words    = preg_split('/[\s\.,!?\-:;()\[\]"\'\/\\\\]+/u', mb_strtolower(trim($text)));
    $keywords = array_filter($words, function ($w) use ($stopwords) {
        return mb_strlen($w) > 3 && !in_array($w, $stopwords);
    });

    return array_values(array_unique($keywords));
}

/**
 * Berechnet einen Relevanz-Score: Treffer im Frontmatter (title/tags)
 * werden dreifach gewichtet, da sie besonders signifikant sind.
 */
function rag_scoreChunk(string $content, array $keywords): int
{
    if (empty($keywords)) return 0;

    $lower       = mb_strtolower($content);
    $fmEnd       = strpos($lower, "\n\n");
    $frontmatter = $fmEnd !== false ? mb_substr($lower, 0, $fmEnd) : mb_substr($lower, 0, 300);
    $bodyText    = mb_substr($lower, mb_strlen($frontmatter));

    $score = 0;
    foreach ($keywords as $kw) {
        $score += substr_count($frontmatter, $kw) * 3;
        $score += substr_count($bodyText,    $kw);
    }
    return $score;
}

/**
 * Liest alle *.md-Dateien aus rag/chunks/, bewertet sie gegen die Suchanfrage
 * und gibt die $maxChunks besten zurück.
 */
function rag_findRelevantChunks(string $query, string $chunksDir, int $maxChunks = 4): array
{
    if (!is_dir($chunksDir)) return [];

    $keywords = rag_extractKeywords($query);
    if (empty($keywords)) return [];

    $scored = [];
    foreach (glob($chunksDir . '/*.md') as $file) {
        $content = @file_get_contents($file);
        if ($content === false) continue;

        $score = rag_scoreChunk($content, $keywords);
        if ($score > 0) {
            $scored[] = ['score' => $score, 'content' => $content];
        }
    }

    usort($scored, fn($a, $b) => $b['score'] - $a['score']);
    return array_slice($scored, 0, $maxChunks);
}

// ── Payload parsen und RAG-Kontext einbetten ──────────────────────────────────
$payload = json_decode($body, true);

if (is_array($payload)) {

    // Letzte Nutzer-Nachricht extrahieren
    $lastUserQuery = '';
    if (!empty($payload['contents'])) {
        foreach (array_reverse($payload['contents']) as $msg) {
            if (($msg['role'] ?? '') === 'user') {
                $lastUserQuery = $msg['parts'][0]['text'] ?? '';
                break;
            }
        }
    }

    // Relevante Chunks suchen und einbetten
    $chunksDir = __DIR__ . '/rag/chunks';
    $chunks    = rag_findRelevantChunks($lastUserQuery, $chunksDir, 4);

    if (!empty($chunks)) {
        $ragBlock  = "\n\n=== WISSENSDATENBANK (gesicherte Kursquellen) ===\n";
        $ragBlock .= "Beziehe dich inhaltlich auf diese Quellen, soweit sie zur Frage passen. ";
        $ragBlock .= "Nenne am Ende deiner Antwort unter '**Quellen:**' welche Quellen du genutzt hast.\n\n";

        foreach ($chunks as $chunk) {
            $ragBlock .= $chunk['content'] . "\n\n---\n\n";
        }

        $ragBlock .= "=== ENDE WISSENSDATENBANK ===";

        // An bestehende System-Instruktion anhängen
        if (!empty($payload['systemInstruction']['parts'][0]['text'])) {
            $payload['systemInstruction']['parts'][0]['text'] .= $ragBlock;
        }
    }

    $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
}

// ── Output-Buffering vollständig deaktivieren ─────────────────────────────────
while (ob_get_level()) ob_end_clean();

// ── SSE-Header ────────────────────────────────────────────────────────────────
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no');   // nginx: Pufferung deaktivieren
header('Content-Encoding: none');  // Kompression deaktivieren (würde puffern)

// ── Streaming-Request an Gemini API ──────────────────────────────────────────
$url = 'https://generativelanguage.googleapis.com/v1beta/models/'
     . MODEL_NAME . ':streamGenerateContent?key=' . GEMINI_API_KEY . '&alt=sse';

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $body,
    CURLOPT_RETURNTRANSFER => false,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_TIMEOUT        => 60,
    CURLOPT_WRITEFUNCTION  => function ($ch, $data) {
        echo $data;
        if (ob_get_level()) ob_flush();
        flush();
        return strlen($data);
    },
]);

$curlError = '';
curl_exec($ch);
if (curl_errno($ch)) {
    $curlError = curl_error($ch);
}
curl_close($ch);

if ($curlError) {
    echo "data: " . json_encode(['error' => $curlError]) . "\n\n";
    flush();
}
