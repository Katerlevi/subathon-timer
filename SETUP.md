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
- `FRONTEND_ORIGIN`: finale GitHub-Pages-Origin, zum Beispiel `https://name.github.io`
- `FRONTEND_PATH`: Repository-Pfad, zum Beispiel `/subathon-timer/`

## 3. GitHub Pages

In `site/config.js` die Worker-URL eintragen. Das Repository zu GitHub pushen und unter **Settings > Pages > Source** `GitHub Actions` auswählen. Der Workflow `.github/workflows/pages.yml` veröffentlicht den Ordner `site`.

## 4. Twitch verbinden

Die veröffentlichte Seite öffnen, Twitch-Kanalnamen eingeben und **Mit Twitch verbinden** wählen. Nach erfolgreicher Freigabe erscheinen Dashboard und OBS-URL. In OBS eine Browserquelle mit 1920 x 220 Pixeln anlegen und diese URL einfügen.

## 5. Funktionstest

Vor dem ersten echten Stream:

1. Timer starten.
2. Im Dashboard eine Minute manuell hinzufügen.
3. Prüfen, dass OBS innerhalb weniger Sekunden aktualisiert.
4. Mit der Twitch CLI oder einem Testkanal mindestens Sub-, Bits- und Follow-Ereignisse testen.
5. Danach die echten Minutenwerte und das Zeitlimit festlegen.
