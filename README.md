# Subathon Timer

Ein sicherer, quelloffener Subathon-Timer fuer Twitch. Das Dashboard wird ueber GitHub Pages ausgeliefert; ein kleiner Cloudflare Worker verarbeitet Twitch OAuth, EventSub-Webhooks und den persistenten Timerzustand.

## Enthalten

- Twitch-Verbindung per Authorization Code Flow
- Automatische Events fuer Subs, Resubs, Gift-Subs, Bits, Follows, Raids und Channel-Points-Einloesungen
- Frei einstellbare Zeit je Aktion
- Start, Pause, Reset und manuelle Korrektur
- Geheime, schreibgeschuetzte OBS-Browserquellen-URL
- Signaturpruefung und Replay-Schutz fuer Twitch EventSub
- Verschluesselte Twitch-Tokens in D1
- Responsive deutsche Bedienoberflaeche

## Architektur

GitHub Pages kann keine Geheimnisse sicher speichern. Daher besteht die Anwendung aus zwei Teilen:

1. `site/` ist die statische GitHub-Pages-Oberflaeche.
2. `worker/` ist die serverseitige API auf Cloudflare Workers mit D1.

Die vollstaendige Einrichtung steht in [SETUP.md](SETUP.md). Sicherheitsdetails und Meldeweg stehen in [SECURITY.md](SECURITY.md).

## Lokal pruefen

Die statische Seite kann direkt ueber einen beliebigen lokalen HTTP-Server geoeffnet werden. Der Worker wird mit Wrangler gestartet:

```powershell
cd worker
pnpm install
pnpm run db:local
pnpm run dev
```

Danach in `site/config.js` fuer die lokale Entwicklung `http://127.0.0.1:8787` eintragen.

