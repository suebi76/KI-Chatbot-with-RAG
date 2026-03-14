# RAG-Wissensdatenbank – Anleitung

## So funktioniert das System

Jede `.md`-Datei im Ordner `chunks/` ist ein **Wissens-Chunk**.
Wenn eine Lehrkraft eine Frage stellt, sucht der Server automatisch nach den
passendsten Chunks und gibt sie der KI als Quellmaterial mit.
Die KI antwortet dann auf Basis dieser Quellen statt nur aus ihrem allgemeinen Wissen.

---

## Neue Chunks hinzufügen

1. Erstelle eine neue `.md`-Datei in `chunks/` (z. B. `mein-thema.md`)
2. Verwende exakt dieses Format:

```
---
title: Sprechender Titel des Chunks
tags: schlüsselwort1, schlüsselwort2, schlüsselwort3, fachbegriff
quelle: Autor, Titel, Jahr, Verlag / oder URL
---

Hier kommt dein Inhalt. Schreib den Text so, wie er im Original steht
oder fasse ihn sinnvoll zusammen. Fachbegriffe aus dem `tags:`-Feld
werden für die Suche besonders stark gewichtet.

Empfohlene Länge: 200–600 Wörter pro Chunk.
Ein Chunk sollte genau ein Thema / ein Konzept behandeln.
```

---

## Tipps für gute Chunks

- **Ein Chunk = ein Konzept.** Nicht zu viel in eine Datei packen.
  Lieber `hilbert-meyer-01-strukturierung.md` und
  `hilbert-meyer-02-methodenvielfalt.md` als eine riesige Datei.

- **Tags sind entscheidend für die Suche.** Trage alle Begriffe ein,
  nach denen Lehrkräfte fragen könnten:
  `tags: kognitive aktivierung, tiefenstruktur, lernaktivierung, denkaufgaben`

- **Quellen immer angeben** – die KI nennt sie dann in der Antwort.

- **Sprache**: Deutsch. Fachbegriffe so schreiben wie Lehrkräfte sie eingeben.

---

## Dateien hochladen

Einfach per FTP in den Ordner `rag/chunks/` hochladen – fertig.
Kein Neustart, kein Build-Schritt nötig. Der Server liest die Dateien
bei jeder Anfrage neu ein.

---

## Welche Inhalte eignen sich?

- Buchkapitel (zusammengefasst / paraphrasiert, kein direktes Kopieren
  bei urheberrechtlich geschützten Texten)
- Eigene Kurs-Handouts und Folien (als Text)
- Artikel aus Fachzeitschriften (Zusammenfassungen)
- KMK-Dokumente, Medienkompetenzrahmen (sind frei verfügbar)
- Eigene Definitionen, Erklärungen, Beispielaufgaben

---

## Maximale Chunk-Größe

Technisch unbegrenzt, aber: Je größer ein Chunk, desto unschärfer wird
die Suche. **Ideal: 300–500 Wörter.** Lange Texte besser auf mehrere
Chunks aufteilen.
