import { createServer } from "node:http";
import { readFile } from "node:fs/promises";
import { extname, join, normalize } from "node:path";
import { fileURLToPath } from "node:url";

const root = normalize(join(fileURLToPath(new URL(".", import.meta.url)), "..", "site"));
const session = "dashboard_test_token_1234567890123456789012";
const overlayKey = "overlay_test_token_123456789012345678901234";
const api = "/wp-json/royal-family-subathon/v1";
const port = Number(process.env.RFS_MOCK_PORT || 4174);
const eventStatus = process.env.RFS_MOCK_EVENT_STATUS === "pending" ? "webhook_callback_verification_pending" : "enabled";
let timer = { running: false, remainingSeconds: 14400, endsAt: 0, lastEvent: null, sleeping: false, sleepStartedAt: 0, alertId: 0, alertLabel: null, alertSeconds: 0, alertCreatedAt: 0 };
let sleepResumeTimer = false;
let alerts = [];
let donation = {
	rule: { enabled: process.env.RFS_MOCK_DONATIONS === "enabled", currency: "EUR", base_amount_minor: 500, seconds_per_base: 600, mode: "proportional", rounding: "floor_per_tip" },
	revision: 1, credit_from_ms: 0, tip_url: "", tip_url_verification: "format_only_not_authorization",
};
let media = { revision: 0, media: null };
let config = {
	startSeconds: 14400, maxSeconds: 259200, maxMode: "limited", streamStartAt: 0, endMode: "open", streamEndAt: 0, sleepAdditionsEnabled: true, sleepTimerContinues: true,
  tier1Seconds: 240, tier1Enabled: true, tier2Seconds: 480, tier2Enabled: true,
  tier3Seconds: 900, tier3Enabled: true, giftSeconds: 240, giftEnabled: true,
  bitsSeconds: 60, bitsEnabled: true, followSeconds: 0, followEnabled: false,
  raidSeconds: 6, raidEnabled: true, rewardSeconds: 120, rewardEnabled: true,
};

function json(response, status, value) {
  response.writeHead(status, { "Content-Type": "application/json", "Cache-Control": "no-store" });
  response.end(status === 204 ? "" : JSON.stringify(value));
}

function timerLimit() {
	const maximum = config.maxMode === "open" ? Number.MAX_SAFE_INTEGER : config.maxSeconds;
	const untilEnd = config.endMode === "fixed" ? Math.max(0, config.streamEndAt - Math.floor(Date.now() / 1000)) : maximum;
	return Math.min(maximum, untilEnd);
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
			return json(response, 200, { ...timer, channel: "testkanal", sleepAdditionsEnabled: config.sleepAdditionsEnabled, sleepTimerContinues: config.sleepTimerContinues, streamStartAt: config.streamStartAt, endMode: config.endMode, streamEndAt: config.streamEndAt, alerts: alerts.filter((alert) => alert.id > after), recentActions: alerts.filter((alert) => alert.seconds > 0).slice(-3).reverse() });
    }
    if (request.headers.authorization !== `Bearer ${session}`) return json(response, 401, { message: "Sitzung abgelaufen." });
    if (url.pathname === `${api}/me`) return json(response, 200, { streamer: { login: "testkanal", displayName: "Testkanal", setupStatus: "ready" }, config, timer, overlayKey });
		if (url.pathname === `${api}/events/refresh` && request.method === "POST") return json(response, 200, { ok: true, message: "Twitch-Ereignisse angefordert. Die Aktivierung kann kurz dauern." });
		if (url.pathname === `${api}/events/status`) return json(response, 200, {
			subscribe: eventStatus, resub: eventStatus, gift: eventStatus, cheer: eventStatus,
			follow: eventStatus, raid: eventStatus, custom: eventStatus, automatic: eventStatus,
			overall: eventStatus === "enabled" ? "enabled" : "pending",
			activeCount: eventStatus === "enabled" ? 8 : 0, requiredCount: 8,
		});
		if (url.pathname === `${api}/donations/config` && request.method === "GET") return json(response, 200, donation);
		if (url.pathname === `${api}/donations/config` && request.method === "PUT") {
			const input = await body(request);
			donation = { ...donation, rule: input.rule, tip_url: input.tip_url || "", revision: donation.revision + 1 };
			return json(response, 200, donation);
		}
		if (url.pathname === `${api}/donations/status`) return json(response, 200, { provider: "streamelements", personal_connection_supported: true, connection_method: null, oauth_application_configured: false, account_connected: false, credits_operator_released: false });
		if (url.pathname === `${api}/donations/preview` && request.method === "POST") {
			const input = await body(request);
			const seconds = donation.rule.enabled ? Math.floor(Number(input.amount_minor || 0) * donation.rule.seconds_per_base / donation.rule.base_amount_minor) : 0;
			return json(response, 200, { seconds, revision: donation.revision, timer_changed: false, provider_event: false });
		}
		if (url.pathname === `${api}/media/control` && request.method === "GET") return json(response, 200, media);
		if (url.pathname === `${api}/media/control` && request.method === "PUT") {
			const input = await body(request); media = { revision: media.revision + 1, media: input.media || null }; return json(response, 200, media);
		}
		if (url.pathname === `${api}/timer/state`) return json(response, 200, { timer });
    if (url.pathname === `${api}/config` && request.method === "PUT") {
      config = await body(request);
			timer.remainingSeconds = Math.min(timerLimit(), timer.remainingSeconds);
			if (timer.running) timer.endsAt = Math.floor(Date.now() / 1000) + timer.remainingSeconds;
      return json(response, 200, { config });
    }
    if (url.pathname === `${api}/timer/adjust`) {
      const input = await body(request);
			timer.remainingSeconds = Math.min(timerLimit(), Math.max(0, timer.remainingSeconds + Number(input.seconds || 0)));
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
			const before = timer.remainingSeconds;
			timer.remainingSeconds = Math.min(timerLimit(), Math.max(0, timer.remainingSeconds + seconds));
			const actualAdded = Math.max(0, timer.remainingSeconds - before);
			if (timer.running) timer.endsAt = Math.floor(Date.now() / 1000) + timer.remainingSeconds;
			timer.lastEvent = `Test · ${titles[input.key]}${blocked ? " · im Schlafmodus nicht addiert" : ""}`;
			timer.alertId += 1;
			timer.alertLabel = timer.lastEvent;
			timer.alertSeconds = actualAdded;
			timer.alertCreatedAt = Math.floor(Date.now() / 1000);
			alerts.push({ id: timer.alertId, label: timer.alertLabel, seconds: actualAdded, createdAt: timer.alertCreatedAt });
			return json(response, 200, { timer, testedSeconds: actualAdded });
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
			timer.remainingSeconds = Math.min(timerLimit(), timer.remainingSeconds);
			if (sleepResumeTimer && timer.remainingSeconds > 0) {
				timer.running = true;
				timer.endsAt = Math.floor(Date.now() / 1000) + timer.remainingSeconds;
			}
			sleepResumeTimer = false;
			return json(response, 200, { timer });
		}
    if (url.pathname === `${api}/timer/start`) {
			timer.remainingSeconds = Math.min(timerLimit(), timer.remainingSeconds);
			timer.running = timer.remainingSeconds > 0;
			timer.endsAt = timer.running ? Math.floor(Date.now() / 1000) + timer.remainingSeconds : 0;
      return json(response, 200, { timer });
    }
    if (url.pathname === `${api}/timer/pause`) {
      timer.remainingSeconds = Math.max(0, timer.endsAt - Math.floor(Date.now() / 1000));
      timer.running = false;
      timer.endsAt = 0;
      return json(response, 200, { timer });
    }
    if (url.pathname === `${api}/timer/reset`) {
			timer = { ...timer, running: false, remainingSeconds: Math.min(timerLimit(), config.startSeconds), endsAt: 0, lastEvent: null, alertCreatedAt: 0 };
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
