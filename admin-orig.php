<?php
/**
 * KI-Didaktik Chat – RAG Admin-Interface
 *
 * PDF hochladen → Gemini analysiert → Chunks werden automatisch erstellt.
 *
 * @author  Steffen Schwabe
 * @license CC BY 4.0
 * @version 1.0.0
 */

session_start();

// ── Konfiguration laden ───────────────────────────────────────────────────────
$configFile = __DIR__ . '/config/config.php';
if (!file_exists($configFile)) {
    die('<p style="font-family:sans-serif;color:#c00;padding:20px">Konfigurationsdatei nicht gefunden.</p>');
}
require $configFile;

define('CHUNKS_DIR',    __DIR__ . '/rag/chunks');
define('MAX_PDF_BYTES', 15 * 1024 * 1024); // 15 MB

// ── Einrichtungs-Seite falls ADMIN_PASSWORD fehlt ────────────────────────────
if (!defined('ADMIN_PASSWORD') || ADMIN_PASSWORD === '1234') {
    setupPage(); exit;
}

// ── Aktionen ──────────────────────────────────────────────────────────────────
$msg     = '';          // Rückmeldungstext
$msgType = 'info';      // 'success' | 'error' | 'info'
$newChunks = [];        // Neu erstellte Chunks (Dateinamen)

// Login
if (isset($_POST['pw'])) {
    if ($_POST['pw'] === ADMIN_PASSWORD) {
        $_SESSION['rag_ok'] = true;
        header('Location: admin.php'); exit;
    }
    $msg = 'Falsches Passwort.'; $msgType = 'error';
}

// Logout
if (isset($_GET['logout'])) { session_destroy(); header('Location: admin.php'); exit; }

$auth = !empty($_SESSION['rag_ok']);

if ($auth) {

    // ── PDF verarbeiten ───────────────────────────────────────────────────────
    if (($_POST['action'] ?? '') === 'upload') {
        if (empty($_FILES['pdf']['tmp_name']) || $_FILES['pdf']['error'] !== UPLOAD_ERR_OK) {
            $msg = 'Upload-Fehler (Code ' . ($_FILES['pdf']['error'] ?? '?') . ').'; $msgType = 'error';
        } else {
            $f = $_FILES['pdf'];
            // Typ prüfen
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
            if ($mime !== 'application/pdf') {
                $msg = 'Nur PDF-Dateien erlaubt.'; $msgType = 'error';
            } elseif ($f['size'] > MAX_PDF_BYTES) {
                $msg = 'PDF zu groß (max. 15 MB).'; $msgType = 'error';
            } else {
                $result = processPdf($f['tmp_name'], $f['name']);
                $msg     = $result['msg'];
                $msgType = $result['type'];
                $newChunks = $result['chunks'];
            }
        }
    }

    // ── Chunk löschen ─────────────────────────────────────────────────────────
    if (($_POST['action'] ?? '') === 'delete') {
        $filename = basename($_POST['file'] ?? '');
        if (!preg_match('/^[\w\-]+\.md$/', $filename)) {
            $msg = 'Ungültiger Dateiname.'; $msgType = 'error';
        } else {
            $path = CHUNKS_DIR . '/' . $filename;
            if (file_exists($path) && unlink($path)) {
                $msg = 'Chunk „' . htmlspecialchars($filename) . '" gelöscht.'; $msgType = 'success';
            } else {
                $msg = 'Löschen fehlgeschlagen.'; $msgType = 'error';
            }
        }
    }
}

// ── PDF → Gemini → Chunks ─────────────────────────────────────────────────────
function processPdf(string $tmpPath, string $origName): array
{
    $pdfB64 = base64_encode(file_get_contents($tmpPath));

    $prompt = 'Analysiere das vorliegende PDF-Dokument und erstelle daraus strukturierte '
            . 'Wissens-Chunks fuer eine RAG-Wissensdatenbank zum Thema digitales '
            . 'Klassenraummanagement und Didaktik in der Sek I.'
            . "\n\n"
            . 'Regeln:'
            . "\n- Teile den Inhalt in 3 bis 10 thematisch eigenstaendige Chunks auf."
            . "\n- Jeder Chunk behandelt genau EIN Konzept oder Thema."
            . "\n- Schreibe ausschliesslich auf Deutsch."
            . "\n- Inhalt pro Chunk: 200 bis 500 Woerter, strukturiert mit Markdown (## Ueberschriften, - Listen)."
            . "\n- Mindestens 6 Schluesselwoerter im tags-Feld (kommagetrennt, Kleinbuchstaben)."
            . "\n\n"
            . 'Format – EXAKT so, ohne jeglichen Text davor oder danach:'
            . "\n\n"
            . 'CHUNK_START' . "\n"
            . '---' . "\n"
            . 'title: Praegnanter Titel auf Deutsch' . "\n"
            . 'tags: tag1, tag2, tag3, tag4, tag5, tag6' . "\n"
            . 'quelle: Autor, Titel, Jahr – so wie im Dokument erkennbar' . "\n"
            . '---' . "\n\n"
            . 'Inhalt des Chunks hier.' . "\n\n"
            . 'CHUNK_END' . "\n\n"
            . 'Gib NUR die Chunks aus. Kein einleitender oder abschliessender Text.';

    $payload = json_encode([
        'contents' => [[
            'parts' => [
                ['inline_data' => ['mime_type' => 'application/pdf', 'data' => $pdfB64]],
                ['text' => $prompt],
            ],
        ]],
        'generationConfig' => ['temperature' => 0.2, 'maxOutputTokens' => 8192],
    ], JSON_UNESCAPED_UNICODE);

    $url = 'https://generativelanguage.googleapis.com/v1beta/models/'
         . MODEL_NAME . ':generateContent?key=' . GEMINI_API_KEY;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 120,
    ]);
    $resp = curl_exec($ch);
    $err  = curl_errno($ch) ? curl_error($ch) : '';
    curl_close($ch);

    if ($err) return ['msg' => 'cURL-Fehler: ' . $err, 'type' => 'error', 'chunks' => []];

    $data = json_decode($resp, true);
    $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';

    if (empty($text)) {
        $apiMsg = $data['error']['message'] ?? 'Leere Antwort von Gemini.';
        return ['msg' => 'Gemini-Fehler: ' . $apiMsg, 'type' => 'error', 'chunks' => []];
    }

    $saved = saveChunks($text, $origName);

    if (empty($saved)) {
        return [
            'msg'    => 'Keine Chunks erkannt. Gemini hat möglicherweise das Format nicht eingehalten. '
                      . 'Bitte erneut versuchen.',
            'type'   => 'error',
            'chunks' => [],
        ];
    }

    $n = count($saved);
    return [
        'msg'    => $n . ' Chunk' . ($n !== 1 ? 's' : '') . ' erfolgreich aus „'
                  . htmlspecialchars(basename($origName)) . '" erstellt.',
        'type'   => 'success',
        'chunks' => $saved,
    ];
}

function saveChunks(string $geminiText, string $origName): array
{
    preg_match_all('/CHUNK_START\s*(.*?)\s*CHUNK_END/s', $geminiText, $m);
    if (empty($m[1])) return [];

    $base  = preg_replace('/[^a-z0-9]+/', '-', mb_strtolower(pathinfo($origName, PATHINFO_FILENAME)));
    $base  = trim($base, '-');
    $saved = [];

    foreach ($m[1] as $i => $raw) {
        $raw = trim($raw);
        if (empty($raw)) continue;

        // Dateinamen aus dem title-Feld ableiten
        $slug = $base . '-' . ($i + 1);
        if (preg_match('/^title:\s*(.+)$/mi', $raw, $t)) {
            $ts = preg_replace('/[^a-z0-9]+/', '-', mb_strtolower(trim($t[1])));
            $ts = trim($ts, '-');
            if (mb_strlen($ts) > 4) $slug = mb_substr($ts, 0, 60);
        }

        // Eindeutigen Dateinamen sicherstellen
        $file = $slug . '.md';
        $path = CHUNKS_DIR . '/' . $file;
        $n    = 2;
        while (file_exists($path)) {
            $file = $slug . '-' . $n++ . '.md';
            $path = CHUNKS_DIR . '/' . $file;
        }

        if (file_put_contents($path, $raw) !== false) {
            $saved[] = $file;
        }
    }
    return $saved;
}

function getChunks(): array
{
    $files = glob(CHUNKS_DIR . '/*.md') ?: [];
    $list  = [];
    foreach ($files as $f) {
        $c      = file_get_contents($f);
        $title  = basename($f);
        $tags   = '';
        $quelle = '';
        if (preg_match('/^title:\s*(.+)$/mi',  $c, $m)) $title  = trim($m[1]);
        if (preg_match('/^tags:\s*(.+)$/mi',   $c, $m)) $tags   = trim($m[1]);
        if (preg_match('/^quelle:\s*(.+)$/mi', $c, $m)) $quelle = trim($m[1]);
        $list[] = ['file' => basename($f), 'title' => $title, 'tags' => $tags, 'quelle' => $quelle, 'bytes' => strlen($c)];
    }
    usort($list, fn($a, $b) => strcmp($a['title'], $b['title']));
    return $list;
}

function setupPage(): void { ?>
<!DOCTYPE html><html lang="de"><head><meta charset="UTF-8"><title>Admin-Einrichtung</title></head>
<body style="font-family:system-ui;background:#f1f5f9;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0">
<div style="background:white;padding:36px;border-radius:16px;max-width:560px;width:90%;box-shadow:0 4px 24px rgba(0,0,0,.08);border:1px solid #e2e8f0">
    <div style="background:#e50046;color:white;padding:4px 12px;border-radius:6px;display:inline-block;font-size:11px;font-weight:700;letter-spacing:.08em;margin-bottom:16px">EINRICHTUNG</div>
    <h2 style="color:#0a192f;margin:0 0 16px">Admin-Passwort festlegen</h2>
    <p style="color:#475569;margin-bottom:16px">Ersetze in <code style="background:#f1f5f9;padding:2px 6px;border-radius:4px">config/config.php</code> den Platzhalter:</p>
    <pre style="background:#0a192f;color:#e2e8f0;padding:16px;border-radius:10px;overflow-x:auto;font-size:13px">define('ADMIN_PASSWORD', '<span style="color:#e50046">dein-sicheres-passwort</span>');</pre>
    <p style="color:#94a3b8;font-size:13px;margin-top:16px">Lade <code>config/config.php</code> danach per FTP erneut hoch. Dann ist das Admin-Interface zugänglich.</p>
</div></body></html>
<?php }

// ── Bestehende Chunks laden ───────────────────────────────────────────────────
$chunks = $auth ? getChunks() : [];

// ── HTML-Ausgabe ──────────────────────────────────────────────────────────────
?><!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>RAG Admin – KI-Didaktik Chat</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; }
        body   { font-family: system-ui, -apple-system, sans-serif; background: #f1f5f9; margin: 0; color: #1e293b; }
        a      { color: #e50046; }

        /* Layout */
        .wrap  { max-width: 900px; margin: 0 auto; padding: 0 16px 60px; }

        /* Header */
        .hdr   { background: #0a192f; color: white; padding: 14px 24px; display: flex; justify-content: space-between; align-items: center; }
        .hdr-l { display: flex; align-items: center; gap: 12px; }
        .logo  { background: #e50046; width: 36px; height: 36px; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-weight: 900; font-size: 18px; }
        .hdr h1 { margin: 0; font-size: 16px; font-weight: 700; }
        .hdr p  { margin: 2px 0 0; font-size: 10px; color: #94a3b8; text-transform: uppercase; letter-spacing: .08em; }
        .btn-logout { background: rgba(255,255,255,.1); color: #cbd5e1; border: 1px solid rgba(255,255,255,.15); padding: 6px 14px; border-radius: 6px; font-size: 12px; cursor: pointer; text-decoration: none; }
        .btn-logout:hover { background: rgba(255,255,255,.2); color: white; }

        /* Cards */
        .card  { background: white; border-radius: 16px; padding: 28px; margin-top: 24px; border: 1px solid #e2e8f0; }
        .card h2 { margin: 0 0 4px; font-size: 16px; color: #0a192f; display: flex; align-items: center; gap: 8px; }
        .card-sub { color: #94a3b8; font-size: 13px; margin: 0 0 20px; }

        /* Badge */
        .badge  { background: #e50046; color: white; font-size: 10px; font-weight: 700; letter-spacing: .06em; padding: 3px 8px; border-radius: 99px; text-transform: uppercase; }
        .badge-ok { background: #16a34a; }
        .badge-new { background: #0a192f; }

        /* Upload-Bereich */
        .drop-area { border: 2px dashed #cbd5e1; border-radius: 12px; padding: 40px 20px; text-align: center; cursor: pointer; transition: border-color .2s, background .2s; }
        .drop-area:hover, .drop-area.over { border-color: #e50046; background: #fff1f4; }
        .drop-area input[type=file] { display: none; }
        .drop-icon { font-size: 36px; margin-bottom: 10px; }
        .drop-area p { margin: 6px 0; color: #475569; font-size: 14px; }
        .drop-area small { color: #94a3b8; font-size: 12px; }
        #file-name { font-weight: 700; color: #0a192f; margin-top: 8px; font-size: 13px; min-height: 20px; }

        /* Buttons */
        .btn-primary { background: #e50046; color: white; border: none; padding: 12px 28px; border-radius: 10px; font-size: 14px; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; transition: opacity .2s; }
        .btn-primary:hover { opacity: .88; }
        .btn-primary:disabled { opacity: .4; cursor: not-allowed; }
        .btn-delete { background: none; border: 1px solid #fecaca; color: #ef4444; padding: 4px 10px; border-radius: 6px; font-size: 11px; cursor: pointer; }
        .btn-delete:hover { background: #fee2e2; }

        /* Alert */
        .alert { border-radius: 10px; padding: 14px 18px; margin-top: 20px; font-size: 14px; display: flex; align-items: flex-start; gap: 10px; }
        .alert-success { background: #f0fdf4; border: 1px solid #86efac; color: #166534; }
        .alert-error   { background: #fef2f2; border: 1px solid #fca5a5; color: #991b1b; }
        .alert-info    { background: #eff6ff; border: 1px solid #93c5fd; color: #1e40af; }
        .alert-icon    { font-size: 18px; line-height: 1; margin-top: 1px; }

        /* Neue Chunks nach Upload */
        .new-chunks { margin-top: 12px; }
        .new-chunk  { display: inline-flex; align-items: center; gap-6px; background: #f0fdf4; border: 1px solid #86efac; color: #166534; padding: 4px 10px; border-radius: 6px; font-size: 12px; font-family: monospace; margin: 3px; }

        /* Chunk-Tabelle */
        .tbl { width: 100%; border-collapse: collapse; font-size: 13px; margin-top: 4px; }
        .tbl th { text-align: left; padding: 10px 12px; font-size: 11px; text-transform: uppercase; letter-spacing: .06em; color: #64748b; border-bottom: 2px solid #f1f5f9; }
        .tbl td { padding: 12px; border-bottom: 1px solid #f1f5f9; vertical-align: top; }
        .tbl tr:last-child td { border-bottom: none; }
        .tbl tr:hover td { background: #f8fafc; }
        .chunk-title  { font-weight: 700; color: #0a192f; }
        .chunk-file   { font-family: monospace; font-size: 11px; color: #94a3b8; margin-top: 2px; }
        .chunk-tags   { color: #475569; font-size: 11px; margin-top: 3px; }
        .chunk-quelle { color: #64748b; font-size: 11px; margin-top: 3px; font-style: italic; max-width: 280px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .chunk-size   { color: #94a3b8; font-size: 11px; white-space: nowrap; }
        .empty-state  { text-align: center; padding: 48px 20px; color: #94a3b8; }
        .empty-state p { margin: 8px 0; font-size: 14px; }

        /* Login */
        .login-wrap { min-height: 100vh; display: flex; align-items: center; justify-content: center; }
        .login-box  { background: white; border-radius: 20px; padding: 40px; width: 340px; box-shadow: 0 4px 32px rgba(0,0,0,.1); border: 1px solid #e2e8f0; }
        .login-box h2 { margin: 0 0 6px; color: #0a192f; font-size: 20px; }
        .login-box p  { color: #64748b; font-size: 13px; margin: 0 0 24px; }
        .login-box input { width: 100%; padding: 12px 16px; border: 1px solid #e2e8f0; border-radius: 10px; font-size: 14px; outline: none; margin-bottom: 14px; }
        .login-box input:focus { border-color: #e50046; box-shadow: 0 0 0 3px rgba(229,0,70,.1); }
        .loading { display: none; }
        .loading.active { display: inline-flex; align-items: center; gap: 8px; }
        @keyframes spin { to { transform: rotate(360deg); } }
        .spinner { width: 16px; height: 16px; border: 2px solid rgba(255,255,255,.3); border-top-color: white; border-radius: 50%; animation: spin .7s linear infinite; }
    </style>
</head>
<body>

<?php if (!$auth): ?>
<!-- ── Login ─────────────────────────────────────────────────────────────── -->
<div class="login-wrap">
    <div class="login-box">
        <div style="background:#e50046;width:44px;height:44px;border-radius:10px;display:flex;align-items:center;justify-content:center;margin-bottom:20px">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 2H3v16h5v4l4-4h5l4-4V2zM11 11V7M16 11V7"/></svg>
        </div>
        <h2>RAG Admin</h2>
        <p>KI-Didaktik Chat · Wissensdatenbank verwalten</p>
        <?php if ($msg): ?>
        <div class="alert alert-error" style="margin-bottom:16px">
            <span class="alert-icon">✕</span> <?= htmlspecialchars($msg) ?>
        </div>
        <?php endif; ?>
        <form method="post">
            <input type="password" name="pw" placeholder="Passwort" autofocus autocomplete="current-password" required>
            <button type="submit" class="btn-primary" style="width:100%;justify-content:center">Anmelden</button>
        </form>
    </div>
</div>

<?php else: ?>
<!-- ── Admin-Interface ────────────────────────────────────────────────────── -->
<div class="hdr">
    <div class="hdr-l">
        <div class="logo">R</div>
        <div>
            <h1>RAG Admin</h1>
            <p>KI-Didaktik Chat · Wissensdatenbank</p>
        </div>
    </div>
    <a href="?logout" class="btn-logout">Abmelden</a>
</div>

<div class="wrap">

    <?php if ($msg): ?>
    <div class="alert alert-<?= $msgType ?>">
        <span class="alert-icon"><?= $msgType === 'success' ? '✓' : ($msgType === 'error' ? '✕' : 'ℹ') ?></span>
        <div>
            <?= htmlspecialchars($msg) ?>
            <?php if (!empty($newChunks)): ?>
            <div class="new-chunks">
                <?php foreach ($newChunks as $f): ?>
                <span class="new-chunk">✓ <?= htmlspecialchars($f) ?></span>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- PDF hochladen -->
    <div class="card">
        <h2>
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#e50046" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z"/><polyline points="14 2 14 8 20 8"/><path d="M12 18v-6"/><path d="m9 15 3 3 3-3"/></svg>
            PDF hochladen &amp; in Chunks umwandeln
        </h2>
        <p class="card-sub">Gemini liest die PDF automatisch aus und erstellt strukturierte Wissens-Chunks für die RAG-Datenbank.</p>

        <form method="post" enctype="multipart/form-data" id="upload-form">
            <input type="hidden" name="action" value="upload">

            <div class="drop-area" id="drop-area" onclick="document.getElementById('pdf-input').click()">
                <div class="drop-icon">📄</div>
                <p><strong>PDF hier ablegen</strong> oder klicken zum Auswählen</p>
                <small>Maximal 15 MB · Nur PDF-Dateien</small>
                <div id="file-name"></div>
                <input type="file" name="pdf" id="pdf-input" accept=".pdf,application/pdf">
            </div>

            <div style="margin-top:20px;display:flex;align-items:center;gap:16px;flex-wrap:wrap">
                <button type="submit" class="btn-primary" id="submit-btn" disabled>
                    <span id="btn-text">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:middle"><path d="M13 2 3 14h9l-1 8 10-12h-9l1-8z"/></svg>
                        Analyse starten
                    </span>
                    <span class="loading" id="btn-loading">
                        <span class="spinner"></span> Gemini analysiert…
                    </span>
                </button>
                <span style="color:#94a3b8;font-size:13px">Die Analyse kann 10–30 Sekunden dauern.</span>
            </div>
        </form>
    </div>

    <!-- Wissensdatenbank -->
    <div class="card">
        <h2>
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#0a192f" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m12.83 2.18a2 2 0 0 0-1.66 0L2.1 6.27a2 2 0 0 0 0 3.66l9.07 4.09a2 2 0 0 0 1.66 0l9.07-4.09a2 2 0 0 0 0-3.66Z"/><path d="m2.1 11.74 9.07 4.09a2 2 0 0 0 1.66 0l9.07-4.09"/><path d="m2.1 16.05 9.07 4.09a2 2 0 0 0 1.66 0l9.07-4.09"/></svg>
            Wissensdatenbank
            <span class="badge badge-new"><?= count($chunks) ?> Chunks</span>
        </h2>
        <p class="card-sub">Alle Chunks in <code>rag/chunks/</code> – werden bei jeder Anfrage automatisch durchsucht.</p>

        <?php if (empty($chunks)): ?>
        <div class="empty-state">
            <p style="font-size:36px;margin:0">📭</p>
            <p><strong>Noch keine Chunks vorhanden.</strong></p>
            <p>Lade eine PDF hoch oder erstelle Dateien manuell nach der Anleitung in <code>rag/ANLEITUNG.md</code>.</p>
        </div>
        <?php else: ?>
        <table class="tbl">
            <thead>
                <tr>
                    <th>Titel / Datei</th>
                    <th>Tags</th>
                    <th>Quelle</th>
                    <th>Größe</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($chunks as $c): ?>
                <tr>
                    <td>
                        <div class="chunk-title"><?= htmlspecialchars($c['title']) ?></div>
                        <div class="chunk-file"><?= htmlspecialchars($c['file']) ?></div>
                    </td>
                    <td><div class="chunk-tags"><?= htmlspecialchars($c['tags'] ?: '–') ?></div></td>
                    <td><div class="chunk-quelle" title="<?= htmlspecialchars($c['quelle']) ?>"><?= htmlspecialchars($c['quelle'] ?: '–') ?></div></td>
                    <td><div class="chunk-size"><?= number_format($c['bytes'] / 1024, 1) ?> KB</div></td>
                    <td>
                        <form method="post" onsubmit="return confirm('Chunk \'' + <?= json_encode($c['file']) ?> + '\' wirklich löschen?')">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="file" value="<?= htmlspecialchars($c['file']) ?>">
                            <button type="submit" class="btn-delete">Löschen</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

    <!-- Hinweis manuelle Chunks -->
    <div class="card" style="background:#f8fafc;border-style:dashed">
        <h2 style="font-size:14px;color:#64748b">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>
            Manuelle Chunks
        </h2>
        <p style="color:#64748b;font-size:13px;margin:0">
            Chunks können auch manuell als <code>.md</code>-Dateien erstellt und per FTP in
            <code>rag/chunks/</code> hochgeladen werden. Das Format und Beispiele sind in
            <code>rag/ANLEITUNG.md</code> dokumentiert.
        </p>
    </div>

</div>
<?php endif; ?>

<script>
// ── Drag & Drop und Dateiauswahl ──────────────────────────────────────────────
(function () {
    const drop   = document.getElementById('drop-area');
    const input  = document.getElementById('pdf-input');
    const label  = document.getElementById('file-name');
    const btn    = document.getElementById('submit-btn');
    const form   = document.getElementById('upload-form');
    const btnTxt = document.getElementById('btn-text');
    const btnLd  = document.getElementById('btn-loading');

    if (!drop) return;

    function setFile(name) {
        label.textContent = name ? '✓ ' + name : '';
        btn.disabled = !name;
    }

    input.addEventListener('change', () => setFile(input.files[0]?.name || ''));

    drop.addEventListener('dragover',  e => { e.preventDefault(); drop.classList.add('over'); });
    drop.addEventListener('dragleave', () => drop.classList.remove('over'));
    drop.addEventListener('drop', e => {
        e.preventDefault(); drop.classList.remove('over');
        const file = e.dataTransfer.files[0];
        if (file && file.type === 'application/pdf') {
            const dt = new DataTransfer(); dt.items.add(file); input.files = dt.files;
            setFile(file.name);
        } else {
            alert('Bitte nur PDF-Dateien ablegen.');
        }
    });

    // Lade-Indikator beim Absenden
    form.addEventListener('submit', () => {
        if (!input.files[0]) return;
        btn.disabled = true;
        btnTxt.style.display = 'none';
        btnLd.classList.add('active');
    });
})();
</script>

</body>
</html>
