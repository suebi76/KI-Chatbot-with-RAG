# KI-Chatbot with RAG

> **Spezialisierter KI-Assistent für den Fortbildungskurs
> „Digitales Klassenraummanagement in der Sekundarstufe I"**

Entwickelt von **Steffen Schwabe** · Lizenz: [CC BY 4.0](https://creativecommons.org/licenses/by/4.0/)

---

## Überblick

Eine vollständige, serverlose Chat-Anwendung mit:

- **Retrieval-Augmented Generation (RAG)** – lokale Wissensdatenbank aus Markdown-Chunks wird automatisch durchsucht und dem Modell als Kontext übergeben
- **Google Gemini API** als KI-Backend (serverseitig über PHP-Proxy, kein API-Key im Browser)
- **Streaming-Antworten** per Server-Sent Events (SSE)
- **Admin-Interface** mit PDF-Upload → automatischer Chunk-Erstellung über Gemini
- **Keine Build-Pipeline** – läuft direkt auf Apache/PHP-Shared-Hosting (z. B. IONOS)
- **DSGVO-konform** – kein CDN, alle Abhängigkeiten lokal in `vendor/`

---

## Dateistruktur

```
index.html          Komplette React-App (Babel, Tailwind, marked, jsPDF – alles inline)
proxy.php           Gemini-API-Proxy: empfängt POST, injiziert RAG-Chunks, streamt SSE
admin.php           Admin-Interface: Passwort-Setup, PDF-Upload → Gemini → Chunks
config/
  config.php        GEMINI_API_KEY + MODEL_NAME  ⚠️ nicht im Repo – siehe config.php.example
  config.php.example  Vorlage für die Konfigurationsdatei
  .htaccess         Blockiert HTTP-Zugriff auf config/
rag/
  .htaccess         Blockiert HTTP-Zugriff auf rag/
  .admin_password   bcrypt-Hash des Admin-Passworts (wird automatisch erstellt)
  ANLEITUNG.md      Format-Dokumentation für manuelle Chunks
  chunks/*.md       Wissensdatenbank – werden bei jeder Anfrage automatisch durchsucht
vendor/             Lokale Kopien: React, ReactDOM, Babel, Tailwind, marked, jsPDF
```

---

## Setup

### 1. Repository klonen

```bash
git clone https://github.com/suebi76/KI-Chatbot-with-RAG.git
cd KI-Chatbot-with-RAG
```

### 2. Konfiguration anlegen

```bash
cp config/config.php.example config/config.php
```

`config/config.php` öffnen und den echten Gemini API-Key eintragen:

```php
define('GEMINI_API_KEY', 'DEIN_KEY_AUS_GOOGLE_AI_STUDIO');
define('MODEL_NAME',     'gemini-2.5-flash');
```

API-Key erstellen: [Google AI Studio](https://aistudio.google.com/app/apikey)

### 3. Deployment (IONOS / Apache Shared Hosting)

Gesamten Ordner per FTP hochladen. Wichtig: `.htaccess`-Dateien in `config/` und `rag/` müssen vorhanden sein – ohne sie ist der API-Key über HTTP abrufbar.

### 4. Admin-Interface einrichten

`https://deine-domain.de/admin.php` aufrufen → beim ersten Aufruf Passwort setzen.

---

## RAG-Wissensdatenbank

### Chunk-Format

Jede `.md`-Datei in `rag/chunks/` beginnt mit diesem Frontmatter:

```markdown
---
title: Aussagekräftiger Titel
tags: tag1, tag2, tag3, tag4, tag5
quelle: Autor, Titel, Jahr, Verlag
---

Inhalt (200–500 Wörter, Markdown mit ## Überschriften und - Listen).
```

**Tags werden 3× stärker gewichtet als Fließtext** – sorgfältige Tag-Auswahl verbessert die Trefferqualität erheblich.

### Neue Chunks hinzufügen

**Manuell:** `.md`-Datei mit obigem Format in `rag/chunks/` per FTP hochladen → sofort aktiv.

**Automatisch:** In `admin.php` PDF hochladen → Gemini extrahiert und erstellt Chunks automatisch.

---

## Request-Flow

```
Browser  →  POST proxy.php
               ↓  letzte Nutzernachricht extrahieren
               ↓  Schlüsselwörter aus rag/chunks/*.md scoren (Frontmatter 3×, Body 1×)
               ↓  Top-4-Chunks an System-Instruktion anhängen
               ↓  POST → Gemini API (streamGenerateContent?alt=sse)
               ↓  SSE-Stream weiterleiten
Browser  ←  Streaming-Antwort
```

---

## Technologie-Stack

| Komponente | Technologie | Warum lokal? |
|---|---|---|
| UI-Framework | React 18 (via Babel im Browser) | DSGVO, kein CDN |
| Styling | Tailwind CSS | DSGVO, kein CDN |
| Markdown-Rendering | marked.js | DSGVO, kein CDN |
| PDF-Export | jsPDF | DSGVO, kein CDN |
| KI-Backend | Google Gemini 2.5 Flash | Leistung + Kosten |
| Hosting | Apache + PHP (Shared Hosting) | Einfaches Deployment |

---

## Lizenz

**CC BY 4.0** – Namensnennung erforderlich: *Steffen Schwabe, KI-Chatbot with RAG, 2025*

Vollständiger Lizenztext: [creativecommons.org/licenses/by/4.0](https://creativecommons.org/licenses/by/4.0/)
