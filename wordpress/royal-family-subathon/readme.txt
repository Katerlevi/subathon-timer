=== Royal Family Subathon Timer ===
Contributors: royalfamily
Requires at least: 6.0
Requires PHP: 7.4
Stable tag: 1.3.1
License: Proprietary

Sicherer Twitch-Subathon-Timer für die versteckte RoyalFamily.gg-Seite.

Version 1.1 ergänzt Regeltests mit OBS-Alerts, einen konfigurierbaren Schlafmodus und eine Social-Media-Regelgrafik.

Version 1.2 ergänzt den öffentlichen Stream-Zeitplan mit offenem oder festem spätestem Ende.

Version 1.3 ergänzt eine offene maximale Laufzeit, verbessert die OBS-Tonausgabe und liefert ein separates Aktionsfenster für die letzten drei Zeitgeber.

Version 1.3.1 ergänzt automatische Twitch-Kanalpunkt-Belohnungen und eine Schaltfläche zum erneuten Anmelden der Twitch-Ereignisse.

== Installation ==

1. Plugin-ZIP in WordPress hochladen und aktivieren.
2. Unter Einstellungen > Subathon Timer die Twitch Client-ID und das Client-Secret speichern.
3. In der Twitch Developer Console exakt die im Plugin angezeigte OAuth-Weiterleitungs-URL eintragen.
4. Den Inhalt des site-Ordners unter /subathon/ veröffentlichen.

== Datenschutz und Sicherheit ==

Das Plugin speichert OAuth-Tokens und geheime Schlüssel verschlüsselt in der WordPress-Datenbank. Dashboard- und OBS-Schlüssel haben getrennte Berechtigungen. EventSub-Nachrichten werden signiert geprüft und gegen doppelte Verarbeitung geschützt.
