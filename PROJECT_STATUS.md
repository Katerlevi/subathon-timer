# Projektstand und nächste Prüfungen

Stand: 23. September 2026

## Übernommene Referenz

- Das WordPress-Basisplugin meldet auf RoyalFamily.gg die Version `1.3.5-rf-planned1`.
- Die übergebene statische Referenz wurde dateiweise mit `https://royalfamily.gg/subathon/` verglichen: 20 von 20 Dateien waren identisch.
- Das ergänzende Plugin `royal-family-streamertools` liegt in der übernommenen Version `0.2.1-personal2` vor.
- Die zusätzlich übergebenen, unveröffentlichten Testpakete werden nur als Planungs- und Diagnosematerial aufbewahrt. Sie sind nicht Bestandteil eines freigegebenen Deployments.

## Neu vorbereitet

- Der Donation-Tracker verwendet dieselbe dunkle Kartenoptik, Typografie und grüne Akzentfarbe wie die übrige Oberfläche.
- Bei ausgeschaltetem Donations-Regler bleiben Verbindung, Zeitregel, Testberechnung und Token-Einstellungen vollständig verborgen.
- Beim Ausschalten wird der deaktivierte Zustand serverseitig gespeichert, ohne ungespeicherte Eingabefehler aus sichtbaren Feldern zu übernehmen.
- Die lokale Mock-API unterstützt den Donation-Konfigurationsfluss für reproduzierbare Oberflächentests.
- Der Schlaftimer besitzt eine eigene, persönliche OBS-Browserquelle und wird in der Haupt-Timerquelle nicht mehr doppelt dargestellt.
- Die Schlaftimerquelle zeigt nur im aktiven Schlafmodus den verbleibenden Schlaf-Countdown und den vollständigen Zuschauersatz zu Support und Timerverhalten.
- Die erklärenden Texte in den Timer-Browserquellen sind deutlich größer; die Größe der Zeit bleibt unverändert.
- Die lokale Mock-API liefert nun auch die serverseitig bestätigte Schlafplanung für den vollständigen OBS-Test.

## Vor einem echten Subathon noch live zu beweisen

1. Twitch EventSub: echte Zustellung für alle verwendeten Ereignisse, insbesondere beide Kanalpunkte-Arten.
2. StreamElements: echte Kontoverbindung, Anbietereignis, genau eine Timer-Gutschrift und keine Doppelzählung.
3. Donation-Freigabe: Die Oberfläche darf eine verbundene Anmeldung nicht mit bewiesener Live-Zustellung gleichsetzen.
4. OBS: Timer, Alert-Ton, Schlaftext, Aktionsfenster und optionale Medienquelle in der tatsächlich verwendeten OBS-Version prüfen.
5. Vor dem ersten produktiven Stream eine aktuelle, wiederherstellbare Sicherung von Plugin-Dateien und den eigenen `rfs_`-Tabellen erstellen.

Ein erfolgreicher Test-Alert oder eine Vorschau beweist nur den internen Timer-/OBS-Weg. Er ersetzt keinen echten Twitch- oder StreamElements-Ende-zu-Ende-Test.
