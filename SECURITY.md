# Sicherheit

## Sicherheitsmodell

- Twitch-Client-Secret, OAuth-Tokens, Webhook-Secret und OBS-Schlüssel werden serverseitig mit einem aus den WordPress-Salts abgeleiteten Schlüssel verschlüsselt gespeichert.
- OAuth-Zustände sind einmalig, zeitlich begrenzt und gehasht gespeichert.
- Sitzungs- und Overlay-Schlüssel werden für die Prüfung zusätzlich nur gehasht abgelegt.
- Für die Verschlüsselung wird bevorzugt Sodium Secretbox und ersatzweise AES-256-GCM verwendet.
- EventSub-Nachrichten werden per HMAC-SHA256, Zeitfenster und Nachrichten-ID geprüft.
- OBS-Alerts werden kurzzeitig serverseitig gepuffert und nacheinander angezeigt, damit schnelle Ereignisse einander nicht überschreiben.
- Schreibende API-Aufrufe benötigen eine gültige, zufällige Bearer-Sitzung.
- Die OBS-URL erlaubt nur das Lesen des Timerzustands. Wer sie kennt, kann den Timer sehen, aber nicht steuern. Ihr Schlüssel steht im URL-Fragment und wird beim Laden der statischen Seite nicht an den Webserver übertragen; die API erhält ihn anschließend in einem eigenen Request-Header.
- Der persönliche Dashboard-Link ist ein Bearer-Token mit Schreibrechten. Er liegt im URL-Fragment, wird dadurch nicht an WordPress übertragen und wird nach 30 Tagen Inaktivität oder sofort beim Trennen der Twitch-Verbindung ungültig.
- Die Seite unter `/subathon/` ist nicht verlinkt und mit `noindex` gekennzeichnet. Das ersetzt keine Zugriffskontrolle; geschützt wird das Dashboard durch seinen zufälligen Token.
- Die Social-Media-Grafik wird ausschließlich lokal im Browser erzeugt; Regelwerte werden dafür nicht an einen Bilddienst übertragen.
- Ein festes spätestes Streamende wird zusätzlich serverseitig erzwungen. Manuelle Anpassungen, Tests und Twitch-Ereignisse können den Timer nicht über diese Uhrzeit hinaus verlängern.

## Umgang mit Zugangsdaten

Niemals Twitch-Secrets, Datenbank-Backups, Sitzungslinks oder OBS-URLs committen oder in Screenshots veröffentlichen. Bei Verdacht auf Offenlegung die betroffenen Secrets sofort erneuern und Twitch neu verbinden.

## Schwachstellen melden

Bitte Sicherheitsprobleme nicht als öffentliches Issue mit ausnutzbaren Details melden. Nutze stattdessen GitHub Private Vulnerability Reporting, sobald es für das Repository aktiviert ist.
