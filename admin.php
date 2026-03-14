<?php
/**
 * KI-Didaktik Chat – RAG Admin-Interface
 *
 * Erster Aufruf: Passwort direkt im Browser festlegen.
 * Danach: Login → PDFs hochladen → Chunks automatisch erstellen.
 *
 * @author  Steffen Schwabe
 * @license CC BY 4.0
 * @version 1.1.0
 */

session_start();

// ── Konfiguration laden ───────────────────────────────────────────────────────
$configFile = __DIR__ . '/config/config.php';
if (!file_exists($configFile)) {
    die('<p style="font-family:sans-serif;color:#c00;padding:20px">Konfigurationsdatei nicht gefunden.</p>');
}
require $configFile;

define('CHUNKS_DIR',  __DIR__ . '/rag/chunks');
define('PASS_FILE',   __DIR__ . '/rag/.admin_password'); // bcrypt-Hash, via .htaccess geschützt
define('MAX_PDF_BYTES', 15 * 1024 * 1024);

// ── Modus bestimmen ───────────────────────────────────────────────────────────
$firstRun = !file_exists(PASS_FILE);

$msg      = '';
$msgType  = 'info';
$newChunks = [];

// ── Erster Start: Passwort festlegen ─────────────────────────────────────────
if ($firstRun) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'setup') {
        $pw1 = $_POST['pw1'] ?? '';
        $pw2 = $_POST['pw2'] ?? '';
        if (mb_strlen($pw1) < 6) {
            $msg = 'Das Passwort muss mindestens 6 Zeichen lang sein.'; $msgType = 'error';
        } elseif ($pw1 !== $pw2) {
            $msg = 'Die Passwörter stimmen nicht überein.'; $msgType = 'error';
        } elseif (!is_dir(dirname(PASS_FILE))) {
            $msg = 'Ordner rag/ nicht gefunden. Bitte Serverstruktur prüfen.'; $msgType = 'error';
        } else {
            file_put_contents(PASS_FILE, password_hash($pw1, PASSWORD_DEFAULT));
            header('Location: admin.php?ready=1'); exit;
        }
    }
    renderFirstRun($msg, $msgType); exit;
}

// ── Login ─────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'login') {
    $hash = trim(file_get_contents(PASS_FILE));
    if (password_verify($_POST['pw'] ?? '', $hash)) {
        $_SESSION['rag_ok'] = true;
        header('Location: admin.php'); exit;
    }
    $msg = 'Falsches Passwort.'; $msgType = 'error';
}

// Logout
if (isset($_GET['logout'])) { session_destroy(); header('Location: admin.php'); exit; }

$auth = !empty($_SESSION['rag_ok']);

// ── Passwort zurücksetzen (nur wenn eingeloggt) ───────────────────────────────
if ($auth && ($_POST['action'] ?? '') === 'reset_password') {
    $pw1 = $_POST['pw1'] ?? '';
    $pw2 = $_POST['pw2'] ?? '';
    if (mb_strlen($pw1) < 6) {
        $msg = 'Mindestens 6 Zeichen.'; $msgType = 'error';
    } elseif ($pw1 !== $pw2) {
        $msg = 'Passwörter stimmen nicht überein.'; $msgType = 'error';
    } else {
        file_put_contents(PASS_FILE, password_hash($pw1, PASSWORD_DEFAULT));
        session_destroy();
        header('Location: admin.php?pw_changed=1'); exit;
    }
}

// ── PDF verarbeiten ───────────────────────────────────────────────────────────
if ($auth && ($_POST['action'] ?? '') === 'upload') {
    if (empty($_FILES['pdf']['tmp_name']) || $_FILES['pdf']['error'] !== UPLOAD_ERR_OK) {
        $msg = 'Upload-Fehler (Code ' . ($_FILES['pdf']['error'] ?? '?') . ').'; $msgType = 'error';
    } else {
        $f    = $_FILES['pdf'];
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
        if ($mime !== 'application/pdf') {
            $msg = 'Nur PDF-Dateien erlaubt.'; $msgType = 'error';
        } elseif ($f['size'] > MAX_PDF_BYTES) {
            $msg = 'PDF zu groß (max. 15 MB).'; $msgType = 'error';
        } else {
            $result    = processPdf($f['tmp_name'], $f['name']);
            $msg       = $result['msg'];
            $msgType   = $result['type'];
            $newChunks = $result['chunks'];
        }
    }
}

// ── Chunk löschen ─────────────────────────────────────────────────────────────
if ($auth && ($_POST['action'] ?? '') === 'delete') {
    $filename = basename($_POST['file'] ?? '');
    if (!preg_match('/^[\w\-]+\.md$/', $filename)) {
        $msg = 'Ungültiger Dateiname.'; $msgType = 'error';
    } else {
        $path = CHUNKS_DIR . '/' . $filename;
        if (file_exists($path) && unlink($path)) {
            $msg = '„' . htmlspecialchars($filename) . '" gelöscht.'; $msgType = 'success';
        } else {
            $msg = 'Löschen fehlgeschlagen.'; $msgType = 'error';
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
            . "\n\nRegeln:"
            . "\n- Teile den Inhalt in 3 bis 10 thematisch eigenstaendige Chunks auf."
            . "\n- Jeder Chunk behandelt genau EIN Konzept oder Thema."
            . "\n- Schreibe ausschliesslich auf Deutsch."
            . "\n- Inhalt pro Chunk: 200 bis 500 Woerter, strukturiert mit ## Ueberschriften und - Listen."
            . "\n- Mindestens 6 Schluesselwoerter im tags-Feld (kommagetrennt, Kleinbuchstaben)."
            . "\n\nFormat – EXAKT einhalten, kein Text davor oder danach:\n\n"
            . "CHUNK_START\n---\ntitle: Praegnanter Titel auf Deutsch\n"
            . "tags: tag1, tag2, tag3, tag4, tag5, tag6\n"
            . "quelle: Autor, Titel, Jahr – wie im Dokument erkennbar\n---\n\n"
            . "Inhalt des Chunks hier.\n\nCHUNK_END";

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
        return ['msg' => 'Keine Chunks erkannt. Gemini hat möglicherweise das Format nicht eingehalten. Bitte erneut versuchen.', 'type' => 'error', 'chunks' => []];
    }

    $n = count($saved);
    return [
        'msg'    => $n . ' Chunk' . ($n !== 1 ? 's' : '') . ' erfolgreich aus „' . htmlspecialchars(basename($origName)) . '" erstellt.',
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

        $slug = $base . '-' . ($i + 1);
        if (preg_match('/^title:\s*(.+)$/mi', $raw, $t)) {
            $ts = preg_replace('/[^a-z0-9]+/', '-', mb_strtolower(trim($t[1])));
            $ts = trim($ts, '-');
            if (mb_strlen($ts) > 4) $slug = mb_substr($ts, 0, 60);
        }

        $file = $slug . '.md';
        $path = CHUNKS_DIR . '/' . $file;
        $n    = 2;
        while (file_exists($path)) { $file = $slug . '-' . $n++ . '.md'; $path = CHUNKS_DIR . '/' . $file; }

        if (file_put_contents($path, $raw) !== false) $saved[] = $file;
    }
    return $saved;
}

function getChunks(): array
{
    $files = glob(CHUNKS_DIR . '/*.md') ?: [];
    $list  = [];
    foreach ($files as $f) {
        $c = file_get_contents($f);
        $title = basename($f); $tags = ''; $quelle = '';
        if (preg_match('/^title:\s*(.+)$/mi',  $c, $m)) $title  = trim($m[1]);
        if (preg_match('/^tags:\s*(.+)$/mi',   $c, $m)) $tags   = trim($m[1]);
        if (preg_match('/^quelle:\s*(.+)$/mi', $c, $m)) $quelle = trim($m[1]);
        $list[] = ['file' => basename($f), 'title' => $title, 'tags' => $tags, 'quelle' => $quelle, 'bytes' => strlen($c)];
    }
    usort($list, fn($a, $b) => strcmp($a['title'], $b['title']));
    return $list;
}

// ── Erste-Einrichtung rendern ─────────────────────────────────────────────────
function renderFirstRun(string $msg, string $msgType): void { ?>
<!DOCTYPE html><html lang="de"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Admin einrichten – KI-Didaktik Chat</title>
<?php inlineStyles(); ?>
</head><body>
<div class="center-wrap">
    <div class="auth-box">
        <div class="brand-dot"></div>
        <h2>Admin einrichten</h2>
        <p>Lege beim ersten Start dein Passwort fest. Du kannst es später im Admin-Bereich ändern.</p>
        <?php if ($msg): ?><div class="alert alert-<?= $msgType ?>"><span><?= $msgType === 'error' ? '✕' : '✓' ?></span><?= htmlspecialchars($msg) ?></div><?php endif; ?>
        <form method="post">
            <input type="hidden" name="action" value="setup">
            <label>Passwort <span style="color:#94a3b8;font-size:11px">(mindestens 6 Zeichen)</span></label>
            <input type="password" name="pw1" placeholder="Passwort wählen" required autofocus autocomplete="new-password">
            <label>Passwort wiederholen</label>
            <input type="password" name="pw2" placeholder="Passwort bestätigen" required autocomplete="new-password">
            <button type="submit" class="btn-primary" style="width:100%;justify-content:center;margin-top:8px">Passwort festlegen &amp; starten</button>
        </form>
        <p style="font-size:11px;color:#94a3b8;margin-top:16px;text-align:center">Das Passwort wird verschlüsselt in <code>rag/.admin_password</code> gespeichert.</p>
    </div>
</div>
</body></html>
<?php }

// ── Inline-Styles (geteilt) ───────────────────────────────────────────────────
function inlineStyles(): void { ?>
<style>
*,*::before,*::after{box-sizing:border-box}
body{font-family:system-ui,-apple-system,sans-serif;background:#f1f5f9;margin:0;color:#1e293b}
label{display:block;font-size:12px;font-weight:700;color:#475569;margin-bottom:4px;margin-top:14px}
.center-wrap{min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}
.auth-box{background:white;border-radius:20px;padding:40px;width:360px;box-shadow:0 4px 32px rgba(0,0,0,.1);border:1px solid #e2e8f0}
.auth-box h2{margin:0 0 6px;color:#0a192f;font-size:20px}
.auth-box p{color:#64748b;font-size:13px;margin:0 0 20px;line-height:1.55}
.auth-box input[type=password],.auth-box input[type=text]{width:100%;padding:11px 14px;border:1px solid #e2e8f0;border-radius:10px;font-size:14px;outline:none;margin-bottom:4px}
.auth-box input:focus{border-color:#e50046;box-shadow:0 0 0 3px rgba(229,0,70,.1)}
.brand-dot{background:#e50046;width:44px;height:44px;border-radius:10px;display:flex;align-items:center;justify-content:center;margin-bottom:20px;font-size:22px;font-weight:900;color:white;content:'R'}
.wrap{max-width:900px;margin:0 auto;padding:0 16px 60px}
.hdr{background:#0a192f;color:white;padding:14px 24px;display:flex;justify-content:space-between;align-items:center}
.hdr-l{display:flex;align-items:center;gap:12px}
.logo{background:#e50046;width:36px;height:36px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-weight:900;font-size:16px;color:white}
.hdr h1{margin:0;font-size:16px;font-weight:700}
.hdr p{margin:2px 0 0;font-size:10px;color:#94a3b8;text-transform:uppercase;letter-spacing:.08em}
.btn-logout{background:rgba(255,255,255,.1);color:#cbd5e1;border:1px solid rgba(255,255,255,.15);padding:6px 14px;border-radius:6px;font-size:12px;cursor:pointer;text-decoration:none}
.btn-logout:hover{background:rgba(255,255,255,.2);color:white}
.card{background:white;border-radius:16px;padding:28px;margin-top:24px;border:1px solid #e2e8f0}
.card h2{margin:0 0 4px;font-size:16px;color:#0a192f;display:flex;align-items:center;gap:8px}
.card-sub{color:#94a3b8;font-size:13px;margin:0 0 20px}
.badge{background:#e50046;color:white;font-size:10px;font-weight:700;letter-spacing:.06em;padding:3px 8px;border-radius:99px;text-transform:uppercase}
.badge-n{background:#0a192f}
.drop-area{border:2px dashed #cbd5e1;border-radius:12px;padding:36px 20px;text-align:center;cursor:pointer;transition:border-color .2s,background .2s}
.drop-area:hover,.drop-area.over{border-color:#e50046;background:#fff1f4}
.drop-area input[type=file]{display:none}
.drop-area p{margin:6px 0;color:#475569;font-size:14px}
.drop-area small{color:#94a3b8;font-size:12px}
#file-name{font-weight:700;color:#0a192f;margin-top:8px;font-size:13px;min-height:20px}
.btn-primary{background:#e50046;color:white;border:none;padding:11px 26px;border-radius:10px;font-size:14px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:8px;transition:opacity .2s}
.btn-primary:hover{opacity:.88}
.btn-primary:disabled{opacity:.4;cursor:not-allowed}
.btn-delete{background:none;border:1px solid #fecaca;color:#ef4444;padding:4px 10px;border-radius:6px;font-size:11px;cursor:pointer}
.btn-delete:hover{background:#fee2e2}
.btn-sm{background:#f1f5f9;color:#475569;border:1px solid #e2e8f0;padding:6px 14px;border-radius:8px;font-size:12px;font-weight:600;cursor:pointer}
.btn-sm:hover{background:#e2e8f0}
.alert{border-radius:10px;padding:13px 16px;margin-top:20px;font-size:13px;display:flex;align-items:flex-start;gap:10px}
.alert-success{background:#f0fdf4;border:1px solid #86efac;color:#166534}
.alert-error{background:#fef2f2;border:1px solid #fca5a5;color:#991b1b}
.alert-info{background:#eff6ff;border:1px solid #93c5fd;color:#1e40af}
.new-chunk{display:inline-flex;align-items:center;background:#f0fdf4;border:1px solid #86efac;color:#166534;padding:3px 9px;border-radius:6px;font-size:11px;font-family:monospace;margin:3px 3px 0 0}
.tbl{width:100%;border-collapse:collapse;font-size:13px;margin-top:4px}
.tbl th{text-align:left;padding:10px 12px;font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:#64748b;border-bottom:2px solid #f1f5f9}
.tbl td{padding:11px 12px;border-bottom:1px solid #f1f5f9;vertical-align:top}
.tbl tr:last-child td{border-bottom:none}
.tbl tr:hover td{background:#f8fafc}
.t-title{font-weight:700;color:#0a192f}
.t-file{font-family:monospace;font-size:11px;color:#94a3b8;margin-top:2px}
.t-tags{color:#475569;font-size:11px;margin-top:3px}
.t-src{color:#64748b;font-size:11px;margin-top:3px;font-style:italic;max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.t-size{color:#94a3b8;font-size:11px;white-space:nowrap}
.empty{text-align:center;padding:48px 20px;color:#94a3b8}
.empty p{margin:8px 0;font-size:14px}
@keyframes spin{to{transform:rotate(360deg)}}
.spinner{width:15px;height:15px;border:2px solid rgba(255,255,255,.3);border-top-color:white;border-radius:50%;animation:spin .7s linear infinite}
details summary{cursor:pointer;font-size:13px;color:#64748b;font-weight:600;padding:8px 0}
details summary:hover{color:#0a192f}
</style>
<?php }

// ── Chunks laden ──────────────────────────────────────────────────────────────
$chunks = $auth ? getChunks() : [];
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>RAG Admin – KI-Didaktik Chat</title>
    <?php inlineStyles(); ?>
</head>
<body>

<?php if (!$auth): ?>
<!-- ── Login ─────────────────────────────────────────────────────────────── -->
<div class="center-wrap">
    <div class="auth-box">
        <div class="brand-dot">R</div>
        <h2>RAG Admin</h2>
        <p>KI-Didaktik Chat · Wissensdatenbank verwalten</p>

        <?php if (isset($_GET['ready'])): ?>
        <div class="alert alert-success" style="margin-bottom:16px"><span>✓</span> Passwort gespeichert. Jetzt anmelden.</div>
        <?php endif; ?>
        <?php if (isset($_GET['pw_changed'])): ?>
        <div class="alert alert-success" style="margin-bottom:16px"><span>✓</span> Passwort geändert. Bitte neu anmelden.</div>
        <?php endif; ?>
        <?php if ($msg): ?>
        <div class="alert alert-<?= $msgType ?>" style="margin-bottom:16px"><span><?= $msgType === 'error' ? '✕' : 'ℹ' ?></span><?= htmlspecialchars($msg) ?></div>
        <?php endif; ?>

        <form method="post">
            <input type="hidden" name="action" value="login">
            <label>Passwort</label>
            <input type="password" name="pw" placeholder="Dein Admin-Passwort" required autofocus autocomplete="current-password">
            <button type="submit" class="btn-primary" style="width:100%;justify-content:center;margin-top:12px">Anmelden</button>
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
        <span><?= $msgType === 'success' ? '✓' : ($msgType === 'error' ? '✕' : 'ℹ') ?></span>
        <div>
            <?= htmlspecialchars($msg) ?>
            <?php if (!empty($newChunks)): ?>
            <div style="margin-top:8px">
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
            <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="#e50046" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z"/><polyline points="14 2 14 8 20 8"/><path d="M12 18v-6"/><path d="m9 15 3 3 3-3"/></svg>
            PDF hochladen &amp; in Chunks umwandeln
        </h2>
        <p class="card-sub">Gemini liest die PDF aus und erstellt strukturierte Wissens-Chunks für die RAG-Datenbank.</p>

        <form method="post" enctype="multipart/form-data" id="upload-form">
            <input type="hidden" name="action" value="upload">
            <div class="drop-area" id="drop-area" onclick="document.getElementById('pdf-input').click()">
                <div style="font-size:34px;margin-bottom:8px">📄</div>
                <p><strong>PDF hier ablegen</strong> oder klicken zum Auswählen</p>
                <small>Max. 15 MB · Nur PDF-Dateien</small>
                <div id="file-name"></div>
                <input type="file" name="pdf" id="pdf-input" accept=".pdf,application/pdf">
            </div>
            <div style="margin-top:18px;display:flex;align-items:center;gap:16px;flex-wrap:wrap">
                <button type="submit" class="btn-primary" id="submit-btn" disabled>
                    <span id="btn-text">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M13 2 3 14h9l-1 8 10-12h-9l1-8z"/></svg>
                        Analyse starten
                    </span>
                    <span id="btn-loading" style="display:none;align-items:center;gap:8px">
                        <span class="spinner"></span> Gemini analysiert…
                    </span>
                </button>
                <span style="color:#94a3b8;font-size:12px">Analyse dauert 10–30 Sekunden.</span>
            </div>
        </form>
    </div>

    <!-- Wissensdatenbank -->
    <div class="card">
        <h2>
            <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="#0a192f" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m12.83 2.18a2 2 0 0 0-1.66 0L2.1 6.27a2 2 0 0 0 0 3.66l9.07 4.09a2 2 0 0 0 1.66 0l9.07-4.09a2 2 0 0 0 0-3.66Z"/><path d="m2.1 11.74 9.07 4.09a2 2 0 0 0 1.66 0l9.07-4.09"/><path d="m2.1 16.05 9.07 4.09a2 2 0 0 0 1.66 0l9.07-4.09"/></svg>
            Wissensdatenbank
            <span class="badge badge-n"><?= count($chunks) ?> Chunks</span>
        </h2>
        <p class="card-sub">Alle Dateien in <code>rag/chunks/</code> – automatisch bei jeder Anfrage durchsucht.</p>

        <?php if (empty($chunks)): ?>
        <div class="empty">
            <p style="font-size:32px;margin:0">📭</p>
            <p><strong>Noch keine Chunks vorhanden.</strong></p>
            <p>Lade eine PDF hoch oder lege Dateien manuell nach <code>rag/ANLEITUNG.md</code> an.</p>
        </div>
        <?php else: ?>
        <table class="tbl">
            <thead>
                <tr><th>Titel</th><th>Tags</th><th>Quelle</th><th>Größe</th><th></th></tr>
            </thead>
            <tbody>
            <?php foreach ($chunks as $c): ?>
                <tr>
                    <td>
                        <div class="t-title"><?= htmlspecialchars($c['title']) ?></div>
                        <div class="t-file"><?= htmlspecialchars($c['file']) ?></div>
                    </td>
                    <td><div class="t-tags"><?= htmlspecialchars($c['tags'] ?: '–') ?></div></td>
                    <td><div class="t-src" title="<?= htmlspecialchars($c['quelle']) ?>"><?= htmlspecialchars($c['quelle'] ?: '–') ?></div></td>
                    <td><div class="t-size"><?= number_format($c['bytes'] / 1024, 1) ?> KB</div></td>
                    <td>
                        <form method="post" onsubmit="return confirm('Chunk wirklich löschen?')">
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

    <!-- Passwort ändern -->
    <div class="card" style="background:#f8fafc;border-style:dashed">
        <details>
            <summary>
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:middle;margin-right:5px"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                Passwort ändern
            </summary>
            <form method="post" style="margin-top:16px;max-width:360px">
                <input type="hidden" name="action" value="reset_password">
                <label>Neues Passwort</label>
                <input type="password" name="pw1" placeholder="Neues Passwort" required autocomplete="new-password" style="width:100%;padding:10px 13px;border:1px solid #e2e8f0;border-radius:9px;font-size:13px;outline:none;margin-bottom:10px">
                <label>Wiederholen</label>
                <input type="password" name="pw2" placeholder="Bestätigen" required autocomplete="new-password" style="width:100%;padding:10px 13px;border:1px solid #e2e8f0;border-radius:9px;font-size:13px;outline:none;margin-bottom:14px">
                <button type="submit" class="btn-sm">Passwort ändern</button>
            </form>
        </details>
    </div>

</div><!-- /wrap -->
<?php endif; ?>

<script>
(function(){
    const drop=document.getElementById('drop-area'),
          inp =document.getElementById('pdf-input'),
          lbl =document.getElementById('file-name'),
          btn =document.getElementById('submit-btn'),
          form=document.getElementById('upload-form'),
          bt  =document.getElementById('btn-text'),
          bl  =document.getElementById('btn-loading');

    if(!drop) return;

    function setFile(name){ lbl.textContent = name ? '✓ '+name : ''; btn.disabled = !name; }

    inp.addEventListener('change', ()=> setFile(inp.files[0]?.name||''));
    drop.addEventListener('dragover', e=>{ e.preventDefault(); drop.classList.add('over'); });
    drop.addEventListener('dragleave', ()=> drop.classList.remove('over'));
    drop.addEventListener('drop', e=>{
        e.preventDefault(); drop.classList.remove('over');
        const f=e.dataTransfer.files[0];
        if(f && f.type==='application/pdf'){
            const dt=new DataTransfer(); dt.items.add(f); inp.files=dt.files; setFile(f.name);
        } else { alert('Bitte nur PDF-Dateien ablegen.'); }
    });
    form.addEventListener('submit', ()=>{
        if(!inp.files[0]) return;
        btn.disabled=true; bt.style.display='none';
        bl.style.display='inline-flex';
    });
})();
</script>
</body>
</html>
