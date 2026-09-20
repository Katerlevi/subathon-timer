# Subathon Timer

Ein sicherer, quelloffener Subathon-Timer für Twitch. Das Dashboard wird über GitHub Pages ausgeliefert; ein kleiner Cloudflare Worker verarbeitet Twitch OAuth, EventSub-Webhooks und den persistenten Timerzustand.

## Enthalten

- Twitch-Verbindung per Authorization Code Flow
- Automatische Events für Subs, Resubs, Gift-Subs, Bits, Follows, Raids und Channel-Points-Einlösungen
- Frei einstellbare Zeit je Aktion
- Start, Pause, Reset und manuelle Korrektur
- Geheime, schreibgeschützte OBS-Browserquellen-URL
- Signaturprüfung und Replay-Schutz für Twitch EventSub
- Verschlüsselte Twitch-Tokens in D1
- Responsive deutsche Bedienoberfläche

## Architektur

GitHub Pages kann keine Geheimnisse sicher speichern. Daher besteht die Anwendung aus zwei Teilen:

1. `site/` ist die statische GitHub-Pages-Oberfläche.
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
