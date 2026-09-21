import { createServer } from "node:http";
import { readFile } from "node:fs/promises";
import { extname, join, normalize } from "node:path";
import { fileURLToPath } from "node:url";

const root = normalize(join(fileURLToPath(new URL(".", import.meta.url)), "..", "site"));
const session = "dashboard_test_token_1234567890123456789012";
const overlayKey = "overlay_test_token_123456789012345678901234";
const api = "/wp-json/royal-family-subathon/v1";
const port = Number(process.env.RFS_MOCK_PORT || 4174);
let timer = { running: false, remainingSeconds: 14400, endsAt: 0, lastEvent: null, sleeping: false, sleepStartedAt: 0, alertId: 0, alertLabel: null, alertSeconds: 0, alertCreatedAt: 0 };
let sleepResumeTimer = false;
let alerts = [];
let config = {
	startSeconds: 14400, maxSeconds: 259200, sleepAdditionsEnabled: true, sleepTimerContinues: true,
  tier1Seconds: 240, tier1Enabled: true, tier2Seconds: 480, tier2Enabled: true,
  tier3Seconds: 900, tier3Enabled: true, giftSeconds: 240, giftEnabled: true,
  bitsSeconds: 60, bitsEnabled: true, followSeconds: 0, followEnabled: false,
  raidSeconds: 6, raidEnabled: true, rewardSeconds: 120, rewardEnabled: true,
};

function json(response, status, value) {
  response.writeHead(status, { "Content-Type": "application/json", "Cache-Control": "no-store" });
  response.end(status === 204 ? "" : JSON.stringify(value));
}

async function body(request) {
  let value = "";
  for await (const chunk of request) value += chunk;
  return value ? JSON.parse(value) : {};
}

createServer(async (request, response) => {
  const url = new URL(request.url, `http://127.0.0.1:${port}`);
  if (url.pathname.startsWith(api)) {
    if (url.pathname === `${api}/health`) return json(response, 200, { ok: true, configured: true });
    if (url.pathname === `${api}/auth/start`) {
      response.writeHead(302, { Location: `/#session=${session}`, "Cache-Control": "no-store" });
      return response.end();
    }
    if (url.pathname === `${api}/overlay/state`) {
      if (request.headers["x-rfs-overlay-key"] !== overlayKey) return json(response, 404, { message: "OBS-Link ungültig." });
			const after = Number(url.searchParams.get("after") || 0);
			return json(response, 200, { ...timer, channel: "testkanal", sleepAdditionsEnabled: config.sleepAdditionsEnabled, sleepTimerContinues: config.sleepTimerContinues, alerts: alerts.filter((alert) => alert.id > after) });
    }
    if (request.headers.authorization !== `Bearer ${session}`) return json(response, 401, { message: "Sitzung abgelaufen." });
    if (url.pathname === `${api}/me`) return json(response, 200, { streamer: { login: "testkanal", displayName: "Testkanal", setupStatus: "ready" }, config, timer, overlayKey });
		if (url.pathname === `${api}/timer/state`) return json(response, 200, { timer });
    if (url.pathname === `${api}/config` && request.method === "PUT") {
      config = await body(request);
      return json(response, 200, { config });
    }
    if (url.pathname === `${api}/timer/adjust`) {
      const input = await body(request);
      timer.remainingSeconds = Math.max(0, timer.remainingSeconds + Number(input.seconds || 0));
      if (timer.running) timer.endsAt = Math.floor(Date.now() / 1000) + timer.remainingSeconds;
      timer.lastEvent = "Manuelle Anpassung";
      return json(response, 200, { timer });
    }
		if (url.pathname === `${api}/test-event`) {
			const input = await body(request);
			const titles = { tier1: "T1 / Prime Sub", tier2: "Tier 2 Sub", tier3: "Tier 3 Sub", gift: "Gift-Sub", bits: "100 Bits", follow: "Follow", raid: "1 Raid-Zuschauer", reward: "Channel Points" };
			if (!titles[input.key] || !config[`${input.key}Enabled`]) return json(response, 409, { message: "Aktiviere und speichere diese Regel zuerst." });
			const configuredSeconds = Number(config[`${input.key}Seconds`] || 0);
			const blocked = timer.sleeping && !config.sleepAdditionsEnabled;
			const seconds = blocked ? 0 : configuredSeconds;
			timer.remainingSeconds = Math.min(config.maxSeconds, Math.max(0, timer.remainingSeconds + seconds));
			if (timer.running) timer.endsAt = Math.floor(Date.now() / 1000) + timer.remainingSeconds;
			timer.lastEvent = `Test · ${titles[input.key]}${blocked ? " · im Schlafmodus nicht addiert" : ""}`;
			timer.alertId += 1;
			timer.alertLabel = timer.lastEvent;
			timer.alertSeconds = seconds;
			timer.alertCreatedAt = Math.floor(Date.now() / 1000);
			alerts.push({ id: timer.alertId, label: timer.alertLabel, seconds, createdAt: timer.alertCreatedAt });
			return json(response, 200, { timer, testedSeconds: seconds });
		}
		if (url.pathname === `${api}/sleep/start`) {
			if (!timer.sleeping) {
				timer.sleeping = true;
				timer.sleepStartedAt = Math.floor(Date.now() / 1000);
				if (!config.sleepTimerContinues && timer.running) {
					timer.remainingSeconds = Math.max(0, timer.endsAt - Math.floor(Date.now() / 1000));
					timer.running = false;
					timer.endsAt = 0;
					sleepResumeTimer = true;
				}
			}
			return json(response, 200, { timer });
		}
		if (url.pathname === `${api}/sleep/end`) {
			timer.sleeping = false;
			timer.sleepStartedAt = 0;
			if (sleepResumeTimer && timer.remainingSeconds > 0) {
				timer.running = true;
				timer.endsAt = Math.floor(Date.now() / 1000) + timer.remainingSeconds;
			}
			sleepResumeTimer = false;
			return json(response, 200, { timer });
		}
    if (url.pathname === `${api}/timer/start`) {
      timer.running = true;
      timer.endsAt = Math.floor(Date.now() / 1000) + timer.remainingSeconds;
      return json(response, 200, { timer });
    }
    if (url.pathname === `${api}/timer/pause`) {
      timer.remainingSeconds = Math.max(0, timer.endsAt - Math.floor(Date.now() / 1000));
      timer.running = false;
      timer.endsAt = 0;
      return json(response, 200, { timer });
    }
    if (url.pathname === `${api}/timer/reset`) {
			timer = { ...timer, running: false, remainingSeconds: config.startSeconds, endsAt: 0, lastEvent: null, alertCreatedAt: 0 };
      return json(response, 200, { timer });
    }
    if (url.pathname === `${api}/disconnect`) return json(response, 200, { ok: true });
    return json(response, 404, { message: "Nicht gefunden." });
  }

  const relative = url.pathname === "/" ? "index.html" : url.pathname.replace(/^\/+/, "");
  const target = normalize(join(root, relative));
  if (!target.startsWith(root)) return json(response, 403, { message: "Verboten." });
  try {
    const data = await readFile(target);
    const types = { ".html": "text/html; charset=utf-8", ".js": "text/javascript; charset=utf-8", ".css": "text/css; charset=utf-8", ".svg": "image/svg+xml" };
    response.writeHead(200, { "Content-Type": types[extname(target)] || "application/octet-stream" });
    response.end(data);
  } catch {
    json(response, 404, { message: "Nicht gefunden." });
  }
}).listen(port, "127.0.0.1", () => {
  console.log(`Mock bereit: http://127.0.0.1:${port}/#session=${session}`);
});
