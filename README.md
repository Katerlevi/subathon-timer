# Subathon Timer

Ein sicherer, quelloffener Subathon-Timer für Twitch. Das Dashboard wird als nicht gelistete Anwendung unter `royalfamily.gg/subathon/` ausgeliefert; ein kleiner Cloudflare Worker verarbeitet Twitch OAuth, EventSub-Webhooks und den persistenten Timerzustand.

## Enthalten

- Twitch-Verbindung per Authorization Code Flow
- Automatische Events für Subs, Resubs, Gift-Subs, Bits, Follows, Raids und Channel-Points-Einlösungen
- Frei einstellbare Zeit je Aktion
- Start, Pause, Reset und manuelle Korrektur
- Persönlicher Dashboard-Link mit Schreibrechten und getrennter OBS-Link mit reinen Leserechten
- Geheime, schreibgeschützte OBS-Browserquellen-URL
- Signaturprüfung und Replay-Schutz für Twitch EventSub
- Verschlüsselte Twitch-Tokens in D1
- Responsive deutsche Bedienoberfläche

## Architektur

Die öffentliche Weboberfläche darf keine Twitch-Geheimnisse enthalten. Daher besteht die Anwendung aus zwei Teilen:

1. `site/` ist die statische Oberfläche für `/subathon/` auf RoyalFamily.gg. GitHub Pages kann zusätzlich als Vorschau dienen.
2. `worker/` ist die serverseitige API auf Cloudflare Workers mit D1.

Die vollständige Einrichtung steht in [SETUP.md](SETUP.md). Sicherheitsdetails und Meldeweg stehen in [SECURITY.md](SECURITY.md).

## Lokal prüfen

Die statische Seite kann direkt über einen beliebigen lokalen HTTP-Server geöffnet werden. Der Worker wird mit Wrangler gestartet:

```powershell
cd worker
pnpm install
pnpm run db:local
pnpm run dev
```

Danach in `site/config.js` für die lokale Entwicklung `http://127.0.0.1:8787` eintragen.
