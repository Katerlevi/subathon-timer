# Einrichtung

## 1. WordPress-Plugin installieren

Den Ordner `wordpress/royal-family-subathon` als ZIP packen und in WordPress unter **Plugins > Installieren > Plugin hochladen** installieren und aktivieren. Bei der Aktivierung werden ausschließlich eigene Tabellen mit dem Präfix `rfs_` angelegt.

Das Plugin verändert weder das aktive Theme noch Menüs oder bestehende Seiten.

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

Die nicht gelistete Seite unter `https://royalfamily.gg/subathon/` öffnen, Twitch-Kanalnamen eingeben und **Mit Twitch verbinden** wählen. Nach erfolgreicher Freigabe erscheinen ein persönlicher Dashboard-Link und ein separater OBS-Link. Der Dashboard-Link kann den Timer steuern und muss geheim bleiben. In OBS eine Browserquelle mit 1920 x 220 Pixeln anlegen und ausschließlich den OBS-Link einfügen.

## 5. Funktionstest

Vor dem ersten echten Stream:

1. Timer starten.
2. Für jede aktivierte Regel den zugehörigen **Test-Alert** auslösen und prüfen, dass die richtige Zeit addiert wird.
3. In OBS Bild, Warteschlange und Ton der Test-Alerts kontrollieren; für den Ton bei der Browserquelle **OBS-Audio steuern** aktivieren.
4. Beide Schlafvarianten testen: Countdown weiterlaufen/einfrieren sowie Support addieren/nicht addieren. Im Schlafmodus muss ein vollständiger erklärender Satz in der Browserquelle stehen; nach dem Aufwachen muss er verschwinden.
5. Startdatum und Uhrzeit eintragen. Danach sowohl ein offenes Ende als auch ein festes spätestes Ende testen. Bei festem Ende darf kein Event den Timer über diese Uhrzeit hinaus verlängern.
6. Die Social-Media-Regelgrafik erzeugen und inhaltlich prüfen.
7. Mit einem Twitch-Testkanal mindestens Sub-, Bits- und Follow-Ereignisse testen.
8. Danach die echten Minutenwerte, den Zeitplan und das Zeitlimit festlegen.

Die Oberfläche allein beweist noch nicht, dass echte Twitch-Events ankommen. Das muss nach dem Eintragen der Twitch-Anwendung einmal live geprüft werden.
