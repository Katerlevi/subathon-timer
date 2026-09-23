# Einrichtung

## 1. WordPress-Plugins installieren

Den Ordner `wordpress/royal-family-subathon` als ZIP packen und in WordPress unter **Plugins > Installieren > Plugin hochladen** installieren und aktivieren. Bei der Aktivierung werden ausschließlich eigene Tabellen mit dem Präfix `rfs_` angelegt.

Für Donations, geplanten Schlaf und die optionale Medienquelle anschließend auch `wordpress/royal-family-streamertools` als eigenes ZIP installieren und aktivieren. Das Ergänzungsplugin benötigt das aktive Basisplugin. Beide Plugins verändern weder das aktive Theme noch Menüs oder bestehende Seiten.

## 2. Twitch-Anwendung anlegen

In der Twitch Developer Console eine Anwendung registrieren. Als OAuth-Weiterleitung exakt eintragen:

`https://royalfamily.gg/wp-json/royal-family-subathon/v1/auth/callback`

Anschließend in WordPress unter **Einstellungen > Subathon Timer** die Client-ID und das Client-Secret speichern. Das Secret und alle späteren Twitch-Tokens werden verschlüsselt gespeichert und nie im Frontend ausgegeben.

## 3. Versteckte Seite auf RoyalFamily.gg

Den Inhalt des Ordners `site` als eigenständigen statischen Ordner `/subathon/` in der WordPress-Webroot ablegen. Es wird bewusst keine WordPress-Seite und kein Menüeintrag angelegt. Die enthaltene `.htaccess` verhindert Verzeichnislisten und setzt zusätzliche `noindex`-Header.

Die feste Backend-Adresse in `site/config.js` lautet:

`/wp-json/royal-family-subathon/v1`

Cloudflare, D1 und ein separater Server sind nicht erforderlich.

## 4. Twitch verbinden

Die nicht gelistete Seite unter `https://royalfamily.gg/subathon/` öffnen, Twitch-Kanalnamen eingeben und **Mit Twitch verbinden** wählen. Nach erfolgreicher Freigabe erscheinen ein persönlicher Dashboard-Link, der OBS-Timer-Link, ein Link für die letzten drei Zeitgeber und ein eigener Schlaftimer-Link. Der Dashboard-Link kann den Timer steuern und muss geheim bleiben. In OBS die Timerquelle mit 1920 x 220 Pixeln, optional das Aktionsfenster mit 620 x 360 Pixeln und den Schlaftimer mit 1100 x 360 Pixeln anlegen. Jeweils den passenden Link vollständig einfügen.

## 5. Funktionstest

Vor dem ersten echten Stream:

1. Timer starten.
2. Für jede aktivierte Regel den zugehörigen **Test-Alert** auslösen und prüfen, dass die richtige Zeit addiert wird.
3. In OBS Bild, Warteschlange, die letzten drei Zeitgeber und den Ton der Test-Alerts kontrollieren; für den Ton bei der Timer-Browserquelle **OBS-Audio steuern** aktivieren.
4. Beide Schlafvarianten testen: Countdown weiterlaufen/einfrieren sowie Support addieren/nicht addieren. Die eigene Schlaftimer-Browserquelle muss im Schlafmodus die verbleibende Schlafzeit und einen vollständigen erklärenden Satz anzeigen; nach dem Aufwachen muss sie vollständig verschwinden.
5. Startdatum und Uhrzeit eintragen. Danach sowohl ein offenes Ende als auch ein festes spätestes Ende testen. Bei festem Ende darf kein Event den Timer über diese Uhrzeit hinaus verlängern.
6. Die Social-Media-Regelgrafik erzeugen und inhaltlich prüfen.
7. Im Dashboard prüfen, ob **8 von 8 Twitch-Ereignissen aktiv** angezeigt werden und beide Kanalpunkte-Abos als aktiv erscheinen. Bei ausstehender oder fehlgeschlagener Webhook-Bestätigung ist die Anmeldung noch nicht erfolgreich, auch wenn der Twitch-Login geklappt hat. Erst nach Beseitigung des Zustellproblems **Twitch-Ereignisse aktualisieren** wählen und den Status erneut prüfen. Eine eigene und eine automatische Kanalpunkt-Belohnung tatsächlich einlösen; erst das prüft die Twitch-Verbindung Ende zu Ende. Zusätzlich Sub-, Bits- und Follow-Ereignisse testen.
8. Danach die echten Minutenwerte, den Zeitplan und das Zeitlimit festlegen.

## 6. Donations einrichten und prüfen

Die Donation-Einstellungen werden erst angezeigt, wenn **Donations aktivieren** eingeschaltet ist. Danach StreamElements verbinden, den öffentlichen Tipping-Link sowie Bezugsbetrag und Minuten eintragen und die Regel speichern.

Die Schaltfläche **Berechnen — ohne Zeitgutschrift** prüft nur die gespeicherte Rechenregel. Vor dem Livebetrieb muss zusätzlich eine echte Test-Donation über das verbundene StreamElements-Konto eingehen. Dabei kontrollieren:

1. Das Ereignis gehört zum angemeldeten Twitch-/StreamElements-Kanal.
2. Es wird genau einmal verarbeitet.
3. Der berechnete Betrag fügt genau die erwartete Zeit hinzu.
4. OBS zeigt den passenden Alert und den Eintrag im Aktionsfenster.
5. Das Dashboard behauptet erst nach bestätigter Zustellung, dass Live-Gutschriften freigegeben sind.

Beim Ausschalten des Reglers wird die Donation-Regel serverseitig deaktiviert und der gesamte Einstellungsbereich verborgen. Bereits gespeicherte Werte bleiben für eine spätere Reaktivierung erhalten.

Die Oberfläche allein beweist noch nicht, dass echte Twitch-Events ankommen. Das muss nach dem Eintragen der Twitch-Anwendung einmal live geprüft werden.

Wenn Twitch-Abos bei `webhook_callback_verification_failed` bleiben, den öffentlichen Webhook `https://royalfamily.gg/wp-json/royal-family-subathon/v1/eventsub` in der Hosting-/WAF-Verwaltung auf Bot-Sperren, Rate-Limits und 5xx-Antworten prüfen. Nur diesen konkreten Webhook für Twitch-Zustellung freigeben; die Signaturprüfung im Plugin darf nicht deaktiviert werden. Ein erfolgreicher Test-Alert beweist lediglich die interne Timer-/OBS-Strecke und ersetzt diese Prüfung nicht.
