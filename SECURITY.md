# Sicherheit

## Sicherheitsmodell

- Twitch-Client-Secret und Verschlüsselungsschlüssel liegen nur in Worker-Secrets.
- OAuth-Zustände sind einmalig, zeitlich begrenzt und gehasht gespeichert.
- Sitzungs- und Overlay-Schlüssel werden nur gehasht in der Datenbank abgelegt.
- Twitch-Tokens werden vor dem Speichern mit AES-GCM verschlüsselt.
- EventSub-Nachrichten werden per HMAC-SHA256, Zeitfenster und Nachrichten-ID geprüft.
- Schreibende API-Aufrufe benötigen eine gültige Bearer-Sitzung und eine erlaubte Origin.
- Die OBS-URL erlaubt nur das Lesen des Timerzustands. Wer sie kennt, kann den Timer sehen, aber nicht steuern.
- Der persönliche Dashboard-Link ist ein Bearer-Token mit Schreibrechten. Er liegt im URL-Fragment, wird dadurch nicht an WordPress übertragen und wird nach 30 Tagen Inaktivität oder sofort beim Trennen der Twitch-Verbindung ungültig.
- Die Seite unter `/subathon/` ist nicht verlinkt und mit `noindex` gekennzeichnet. Das ersetzt keine Zugriffskontrolle; geschützt wird das Dashboard durch seinen zufälligen Token.

## Umgang mit Zugangsdaten

Niemals `.dev.vars`, Twitch-Secrets, Cloudflare-Tokens, Sitzungslinks oder OBS-URLs committen oder in Screenshots veröffentlichen. Bei Verdacht auf Offenlegung die betroffenen Secrets sofort erneuern und Twitch neu verbinden.

## Schwachstellen melden

Bitte Sicherheitsprobleme nicht als öffentliches Issue mit ausnutzbaren Details melden. Nutze stattdessen GitHub Private Vulnerability Reporting, sobald es für das Repository aktiviert ist.
