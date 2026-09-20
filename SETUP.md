# Einrichtung

## 1. Twitch-Anwendung anlegen

In der Twitch Developer Console eine Anwendung registrieren. Als OAuth-Weiterleitung exakt eintragen:

`https://DEIN-WORKER.workers.dev/api/auth/callback`

Client-ID und Client-Secret werden später ausschließlich als Worker-Secrets hinterlegt.

## 2. Cloudflare Worker und D1

Im Ordner `worker`:

```powershell
pnpm install
pnpm exec wrangler d1 create subathon-timer-db
```

Die ausgegebene Datenbank-ID in `worker/wrangler.toml` eintragen. Danach:

```powershell
pnpm exec wrangler d1 migrations apply DB --remote
pnpm exec wrangler secret put TWITCH_CLIENT_ID
pnpm exec wrangler secret put TWITCH_CLIENT_SECRET
pnpm exec wrangler secret put EVENTSUB_SECRET
pnpm exec wrangler secret put ENCRYPTION_KEY
pnpm exec wrangler deploy
```

`EVENTSUB_SECRET` muss ein kryptografisch zufälliger ASCII-Wert mit 10 bis 100 Zeichen sein. `ENCRYPTION_KEY` ist ein Base64-kodierter 32-Byte-Schlüssel.

In `wrangler.toml` müssen außerdem diese öffentlichen Werte stimmen:

- `PUBLIC_API_ORIGIN`: URL des Workers ohne abschließenden Slash
- `FRONTEND_ORIGIN`: `https://royalfamily.gg`
- `FRONTEND_PATH`: `/subathon/`

## 3. RoyalFamily.gg

In `site/config.js` die Worker-URL eintragen. Anschließend den Inhalt des Ordners `site` als eigenständigen statischen Ordner `/subathon/` in der WordPress-Webroot ablegen. Es wird keine WordPress-Seite und kein Menüeintrag angelegt. Die enthaltene `.htaccess` verhindert Verzeichnislisten und setzt zusätzliche `noindex`-Header. Der Workflow `.github/workflows/pages.yml` kann weiterhin eine Vorschau über GitHub Pages bereitstellen.

## 4. Twitch verbinden

Die nicht gelistete Seite unter `https://royalfamily.gg/subathon/` öffnen, Twitch-Kanalnamen eingeben und **Mit Twitch verbinden** wählen. Nach erfolgreicher Freigabe erscheinen ein persönlicher Dashboard-Link und ein separater OBS-Link. Der Dashboard-Link kann den Timer steuern und muss geheim bleiben. In OBS eine Browserquelle mit 1920 x 220 Pixeln anlegen und ausschließlich den OBS-Link einfügen.

## 5. Funktionstest

Vor dem ersten echten Stream:

1. Timer starten.
2. Im Dashboard eine Minute manuell hinzufügen.
3. Prüfen, dass OBS innerhalb weniger Sekunden aktualisiert.
4. Mit der Twitch CLI oder einem Testkanal mindestens Sub-, Bits- und Follow-Ereignisse testen.
5. Danach die echten Minutenwerte und das Zeitlimit festlegen.
