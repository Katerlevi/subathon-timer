# Subathon Timer

Ein sicherer, quelloffener Subathon-Timer für Twitch. Dashboard und OBS-Ansicht werden als nicht gelistete Anwendung unter `royalfamily.gg/subathon/` ausgeliefert. Ein eigenes WordPress-Plugin auf RoyalFamily.gg verarbeitet Twitch OAuth, EventSub-Webhooks und den persistenten Timerzustand.

## Enthalten

- Twitch-Verbindung per Authorization Code Flow
- Automatische Events für Subs, Resubs, Gift-Subs, Bits, Follows, Raids und Channel-Points-Einlösungen
- Frei einstellbare Zeit je Aktion
- Start, Pause, Reset und manuelle Korrektur
- Testknopf pro aktivierter Regel mit echter Zeitaddition, Alert-Ton und animierter OBS-Anzeige
- Schlafmodus mit getrennten Regeln für Zeitaddition und Countdown
- Vollständig ausformulierter Schlafhinweis in der OBS-Browserquelle, der nach dem Aufwachen automatisch verschwindet
- Optionaler Streambeginn sowie offenes oder festes spätestes Streamende
- Harte Begrenzung des Timers auf das gewählte späteste Streamende
- Social-Media-Regelgrafik als lokal erzeugte PNG-Datei (1080 × 1350 px)
- Persönlicher Dashboard-Link mit Schreibrechten und getrennter OBS-Link mit reinen Leserechten
- Geheime, schreibgeschützte OBS-Browserquellen-URL
- Signaturprüfung, Replay-Schutz und Alert-Warteschlange für Twitch EventSub
- Verschlüsselte Twitch-Tokens in der WordPress-Datenbank
- Responsive deutsche Bedienoberfläche

## Architektur

Die öffentliche Weboberfläche darf keine Twitch-Geheimnisse enthalten. Daher besteht die Anwendung aus zwei Teilen:

1. `site/` ist die statische Oberfläche für `/subathon/` auf RoyalFamily.gg.
2. `wordpress/royal-family-subathon/` ist das isolierte Backend-Plugin für RoyalFamily.gg. Es verändert weder Theme noch Navigation.

Der Ordner `worker/` enthält nur noch den früheren Cloudflare-Prototyp und wird für die RoyalFamily-Installation nicht benötigt.

Die vollständige Einrichtung steht in [SETUP.md](SETUP.md). Sicherheitsdetails und Meldeweg stehen in [SECURITY.md](SECURITY.md).

## Lokal prüfen

Die statische Seite kann über einen beliebigen lokalen HTTP-Server geöffnet werden. Ohne WordPress-Backend lässt sich dabei nur die Oberfläche prüfen. Die JavaScript-Logiktests laufen mit:

```powershell
node --test
```

Für einen vollständigen Test werden eine WordPress-Testinstallation, das Plugin und eine Twitch-Testanwendung benötigt.
