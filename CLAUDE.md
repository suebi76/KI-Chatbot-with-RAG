# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

---

## Projekt

**KI-Didaktik Chat v2** — Spezialisierter KI-Assistent für den Fortbildungskurs
„Digitales Klassenraummanagement in der Sekundarstufe I" von Steffen Schwabe.
Lizenz: CC BY 4.0. Deployment-Ziel: Apache Shared Hosting (PHP, kein Node/Python).

---

## Kein Build-Schritt

`index.html` ist eine einzelne HTML-Datei mit eingebettetem `<script type="text/babel">`.
Babel kompiliert JSX zur Laufzeit im Browser. Alle Abhängigkeiten liegen lokal in `vendor/` —
kein CDN, kein npm, keine Kompilierung nötig. Änderungen an `index.html` sind sofort aktiv.

---

## Dateistruktur

```
index.html          Komplette React-App (Babel, Tailwind, marked, jsPDF — alles inline)
proxy.php           Gemini-API-Proxy: empfängt POST, injiziert RAG-Chunks, streamt SSE zurück
admin.php           Admin-Interface: Passwort-Setup, PDF-Upload → Gemini → Chunks
config/
  config.php        GEMINI_API_KEY + MODEL_NAME (via .htaccess gegen HTTP gesperrt)
  .htaccess         Blockiert HTTP-Zugriff auf config/
rag/
  .htaccess         Blockiert HTTP-Zugriff auf rag/
  .admin_password   bcrypt-Hash des Admin-Passworts (wird bei erstem admin.php-Aufruf erstellt)
  ANLEITUNG.md      Format-Dokumentation für manuelle Chunks
  chunks/*.md       Wissensdatenbank – werden bei jeder Anfrage automatisch durchsucht
vendor/             Lokale Kopien: react, react-dom, babel, tailwind, marked, jspdf
```

---

## Request-Flow

```
Browser  →  POST proxy.php
               ↓ letzte Nutzernachricht extrahieren
               ↓ Schlüsselwörter aus rag/chunks/*.md scoren (Frontmatter 3×, Body 1×)
               ↓ Top-4-Chunks an System-Instruktion anhängen
               ↓ POST → Gemini API (streamGenerateContent?alt=sse)
               ↓ SSE-Stream weiterleiten
Browser  ←  Streaming-Antwort
```

---

## index.html – Aufbau der Haupt-App

Alle App-Logik in einem einzigen `<script type="text/babel">`-Block, Abschnitte in dieser Reihenfolge:

| Abschnitt | Inhalt |
|---|---|
| CSS `<style>` | `.md`-Markdown-Stile, `.streaming-cursor`-Animation |
| `Icon` | Inline-SVG-Komponente (Map von Name → SVG) |
| `App` states | `messages`, `inputText`, `isLoading`, `isStreaming`, `theme`, `exampleCount`, `copiedIdx`, `showPrivacy`, `showDSB` |
| `systemInstruction` | Gemini-System-Prompt: Themenscope, PII-Ablehnung, Jailbreak-Schutz, Qualitätsvorgaben |
| `fetchAIResponse` | SSE-Parser: buffer → split on `\n` → `data: ` prefix → JSON → chunk text |
| `templates` | 5 Kategorien, jede mit `basePrompt` und Platzhaltern `[ANZAHL]`, `[OPTION]`, `[THEMA]` |
| `downloadAsPdf` | Direktes jsPDF-Text-API (kein html2canvas) — Markdown-Parser Zeile für Zeile |
| `applyTemplate` | Ersetzt Platzhalter, schreibt in `inputText`, wechselt zu Chat-Ansicht |
| `PrivacyModal` | Datenschutzhinweis für Nutzer |
| `DSBModal` | Technische Datenschutzdokumentation für Datenschutzbeauftragte |
| JSX return | `<React.Fragment>` wrapping (Pflicht wegen zwei Root-Elementen: App + Modals) |

---

## RAG – Chunk-Format

Jede `.md`-Datei in `rag/chunks/` muss mit diesem Frontmatter beginnen:

```markdown
---
title: Aussagekräftiger Titel
tags: tag1, tag2, tag3, tag4, tag5, tag6
quelle: Autor, Titel, Jahr, Verlag
---

Inhalt (200–500 Wörter, Markdown mit ## Überschriften und - Listen).
```

- **Tags sind entscheidend** für die Suche — sie werden 3× stärker gewichtet als der Fließtext
- Neue Chunks per FTP in `rag/chunks/` hochladen → sofort aktiv, kein Neustart
- Chunks können auch automatisch über `admin.php` aus PDFs erstellt werden

---

## Admin-Interface (admin.php)

- **Erster Aufruf**: Passwort-Setup-Formular direkt im Browser → Hash wird in `rag/.admin_password` gespeichert
- **Passwort zurücksetzen**: `rag/.admin_password` per FTP löschen → nächster Aufruf startet Ersteinrichtung
- **PDF-Verarbeitung**: PDF wird base64-kodiert und inline an Gemini geschickt; Gemini erstellt Chunks im `CHUNK_START … CHUNK_END`-Format; PHP parst und speichert als `.md`

---

## Wichtige Konventionen

- **Sprache**: Alles auf Deutsch — UI, Kommentare, System-Instruktion, Chunk-Inhalte
- **DSGVO**: Keine externen Browser-Requests — kein CDN, kein Google Fonts, alle Vendor-Dateien lokal
- **System-Instruktion**: Nie den Themen-Scope entfernen oder abschwächen; PII-Ablehnung und Jailbreak-Schutz sind Pflicht
- **PDF-Export**: Immer mit direktem jsPDF-Text-API arbeiten — `jsPDF.html()` mit html2canvas erzeugt leere Seiten
- **JSX-Rückgabe**: Immer in `<React.Fragment>` wrappen, da Modals außerhalb des Haupt-`<div>` gerendert werden
- **Streaming**: `autoScroll` ist ein `useRef(true)`, kein State — vermeidet Re-Renders beim Scrollen

---

## Deployment

Gesamten `KI-v2/`-Ordner per FTP hochladen. `config/.htaccess` und `rag/.htaccess` müssen vorhanden sein — ohne sie ist der API-Key über HTTP abrufbar.
