# Sicherheit

## Sicherheitsmodell

- Twitch-Client-Secret und Verschluesselungsschluessel liegen nur in Worker-Secrets.
- OAuth-Zustaende sind einmalig, zeitlich begrenzt und gehasht gespeichert.
- Sitzungs- und Overlay-Schluessel werden nur gehasht in der Datenbank abgelegt.
- Twitch-Tokens werden vor dem Speichern mit AES-GCM verschluesselt.
- EventSub-Nachrichten werden per HMAC-SHA256, Zeitfenster und Nachrichten-ID geprueft.
- Schreibende API-Aufrufe benoetigen eine gueltige Bearer-Sitzung und eine erlaubte Origin.
- Die OBS-URL erlaubt nur das Lesen des Timerzustands. Wer sie kennt, kann den Timer sehen, aber nicht steuern.

## Umgang mit Zugangsdaten

Niemals `.dev.vars`, Twitch-Secrets, Cloudflare-Tokens, Sitzungslinks oder OBS-URLs committen oder in Screenshots veroeffentlichen. Bei Verdacht auf Offenlegung die betroffenen Secrets sofort erneuern und Twitch neu verbinden.

## Schwachstellen melden

Bitte Sicherheitsprobleme nicht als oeffentliches Issue mit ausnutzbaren Details melden. Nutze stattdessen GitHub Private Vulnerability Reporting, sobald es fuer das Repository aktiviert ist.

