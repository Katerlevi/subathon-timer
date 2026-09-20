import { broadcasterIdFromEvent, computeEventDelta, RULE_KEYS, safeChannel, TWITCH_SCOPES, validateConfig } from "./core.js";

const JSON_HEADERS = { "content-type": "application/json; charset=utf-8", "cache-control": "no-store", "x-content-type-options": "nosniff" };
const SESSION_LIFETIME = 60 * 60 * 24 * 30;
const OAUTH_STATE_LIFETIME = 10 * 60;
const MAX_WEBHOOK_AGE_MS = 10 * 60 * 1000;

export default {
  async fetch(request, env, ctx) {
    try {
      validateEnvironment(env);
      const url = new URL(request.url);
      if (request.method === "OPTIONS") return preflight(request, env);
      if (url.pathname === "/" && request.method === "GET") return json({ status: "ok", service: "subathon-timer-api" });
      if (url.pathname === "/api/auth/start" && request.method === "GET") return startAuth(url, env);
      if (url.pathname === "/api/auth/callback" && request.method === "GET") return finishAuth(url, env, ctx);
      if (url.pathname === "/api/eventsub" && request.method === "POST") return handleEventSub(request, env, ctx);

      const overlayMatch = url.pathname.match(/^\/api\/overlay\/([^/]+)\/state$/);
      if (overlayMatch && request.method === "GET") return withCors(await getOverlayState(decodeURIComponent(overlayMatch[1]), env), request, env);

      if (url.pathname.startsWith("/api/")) {
        enforceOrigin(request, env);
        const auth = await authenticate(request, env);
        if (url.pathname === "/api/me" && request.method === "GET") return withCors(await getMe(auth, env), request, env);
        if (url.pathname === "/api/config" && request.method === "PUT") return withCors(await updateConfig(request, auth, env), request, env);
        if (url.pathname === "/api/timer/start" && request.method === "POST") return withCors(await timerAction("start", auth, env), request, env);
        if (url.pathname === "/api/timer/pause" && request.method === "POST") return withCors(await timerAction("pause", auth, env), request, env);
        if (url.pathname === "/api/timer/reset" && request.method === "POST") return withCors(await timerAction("reset", auth, env), request, env);
        if (url.pathname === "/api/timer/adjust" && request.method === "POST") return withCors(await adjustTimer(request, auth, env), request, env);
        if (url.pathname === "/api/disconnect" && request.method === "POST") return withCors(await disconnect(auth, env, ctx), request, env);
      }
      return json({ error: "Nicht gefunden." }, 404);
    } catch (error) {
      const status = Number(error.status) || 500;
      if (status >= 500) console.error("request_failed", error);
      return withCors(json({ error: status >= 500 ? "Interner Serverfehler." : error.message }, status), request, env);
    }
  },
};

async function startAuth(url, env) {
  const channel = safeChannel(url.searchParams.get("channel"));
  const state = randomToken(32);
  const now = epoch();
  await env.DB.prepare("INSERT INTO oauth_states (state_hash, expected_login, expires_at, created_at) VALUES (?, ?, ?, ?)")
    .bind(await sha256(state), channel, now + OAUTH_STATE_LIFETIME, now).run();
  const params = new URLSearchParams({
    response_type: "code",
    client_id: env.TWITCH_CLIENT_ID,
    redirect_uri: `${env.PUBLIC_API_ORIGIN}/api/auth/callback`,
    scope: TWITCH_SCOPES.join(" "),
    state,
    force_verify: "true",
  });
  return Response.redirect(`https://id.twitch.tv/oauth2/authorize?${params}`, 302);
}

async function finishAuth(url, env, ctx) {
  const code = url.searchParams.get("code");
  const state = url.searchParams.get("state");
  if (!code || !state || url.searchParams.get("error")) return authError(env, "Twitch-Freigabe wurde abgebrochen.");
  const stateHash = await sha256(state);
  const stored = await env.DB.prepare("SELECT expected_login, expires_at FROM oauth_states WHERE state_hash = ?").bind(stateHash).first();
  await env.DB.prepare("DELETE FROM oauth_states WHERE state_hash = ?").bind(stateHash).run();
  if (!stored || Number(stored.expires_at) < epoch()) return authError(env, "Anmeldung abgelaufen. Bitte erneut versuchen.");

  const tokenResponse = await fetch("https://id.twitch.tv/oauth2/token", {
    method: "POST",
    headers: { "content-type": "application/x-www-form-urlencoded" },
    body: new URLSearchParams({ client_id: env.TWITCH_CLIENT_ID, client_secret: env.TWITCH_CLIENT_SECRET, code, grant_type: "authorization_code", redirect_uri: `${env.PUBLIC_API_ORIGIN}/api/auth/callback` }),
  });
  if (!tokenResponse.ok) return authError(env, "Twitch-Anmeldung konnte nicht abgeschlossen werden.");
  const token = await tokenResponse.json();

  const validationResponse = await fetch("https://id.twitch.tv/oauth2/validate", { headers: { Authorization: `OAuth ${token.access_token}` } });
  if (!validationResponse.ok) return authError(env, "Twitch-Token konnte nicht validiert werden.");
  const validation = await validationResponse.json();
  const grantedScopes = new Set(validation.scopes || token.scope || []);
  if (TWITCH_SCOPES.some((scope) => !grantedScopes.has(scope))) return authError(env, "Nicht alle benötigten Twitch-Rechte wurden freigegeben.");

  const userResponse = await fetch("https://api.twitch.tv/helix/users", { headers: { Authorization: `Bearer ${token.access_token}`, "Client-Id": env.TWITCH_CLIENT_ID } });
  const userPayload = await userResponse.json();
  const user = userPayload.data?.[0];
  if (!user || user.login.toLowerCase() !== String(stored.expected_login).toLowerCase()) return authError(env, "Der freigegebene Twitch-Kanal stimmt nicht mit der Eingabe überein.");

  const now = epoch();
  let streamer = await env.DB.prepare("SELECT id, encrypted_overlay_key FROM streamers WHERE twitch_user_id = ?").bind(user.id).first();
  let streamerId;
  let overlayKey;
  if (streamer) {
    streamerId = streamer.id;
    overlayKey = await decrypt(streamer.encrypted_overlay_key, env.ENCRYPTION_KEY);
    await env.DB.prepare(`UPDATE streamers SET twitch_login=?, display_name=?, encrypted_access_token=?, encrypted_refresh_token=?, token_expires_at=?, scopes=?, setup_status='pending', active=1, updated_at=? WHERE id=?`)
      .bind(user.login, user.display_name, await encrypt(token.access_token, env.ENCRYPTION_KEY), await encrypt(token.refresh_token, env.ENCRYPTION_KEY), now + Number(token.expires_in || 0), JSON.stringify([...grantedScopes]), now, streamerId).run();
  } else {
    streamerId = crypto.randomUUID();
    overlayKey = randomToken(32);
    await env.DB.prepare(`INSERT INTO streamers (id,twitch_user_id,twitch_login,display_name,encrypted_access_token,encrypted_refresh_token,token_expires_at,scopes,overlay_key_hash,encrypted_overlay_key,setup_status,active,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,1,?,?)`)
      .bind(streamerId, user.id, user.login, user.display_name, await encrypt(token.access_token, env.ENCRYPTION_KEY), await encrypt(token.refresh_token, env.ENCRYPTION_KEY), now + Number(token.expires_in || 0), JSON.stringify([...grantedScopes]), await sha256(overlayKey), await encrypt(overlayKey, env.ENCRYPTION_KEY), "pending", now, now).run();
    await env.DB.batch([
      env.DB.prepare("INSERT INTO timer_configs (streamer_id, updated_at) VALUES (?, ?)").bind(streamerId, now),
      env.DB.prepare("INSERT INTO timer_states (streamer_id, updated_at) VALUES (?, ?)").bind(streamerId, now),
    ]);
  }

  const session = randomToken(32);
  await env.DB.prepare("INSERT INTO sessions (token_hash, streamer_id, expires_at, created_at) VALUES (?, ?, ?, ?)")
    .bind(await sha256(session), streamerId, now + SESSION_LIFETIME, now).run();
  ctx.waitUntil(Promise.allSettled([setupSubscriptions(user.id, env, streamerId), cleanupExpired(env)]));
  const target = new URL(normalizedFrontendPath(env), env.FRONTEND_ORIGIN);
  target.hash = new URLSearchParams({ session }).toString();
  return Response.redirect(target.toString(), 302);
}

async function setupSubscriptions(broadcasterId, env, streamerId) {
  try {
    const appToken = await getAppToken(env);
    const callback = `${env.PUBLIC_API_ORIGIN}/api/eventsub`;
    const subscriptions = [
      ["channel.subscribe", "1", { broadcaster_user_id: broadcasterId }],
      ["channel.subscription.message", "1", { broadcaster_user_id: broadcasterId }],
      ["channel.subscription.gift", "1", { broadcaster_user_id: broadcasterId }],
      ["channel.cheer", "1", { broadcaster_user_id: broadcasterId }],
      ["channel.follow", "2", { broadcaster_user_id: broadcasterId, moderator_user_id: broadcasterId }],
      ["channel.raid", "1", { to_broadcaster_user_id: broadcasterId }],
      ["channel.channel_points_custom_reward_redemption.add", "1", { broadcaster_user_id: broadcasterId }],
    ];
    for (const [type, version, condition] of subscriptions) {
      const response = await fetch("https://api.twitch.tv/helix/eventsub/subscriptions", {
        method: "POST",
        headers: { Authorization: `Bearer ${appToken}`, "Client-Id": env.TWITCH_CLIENT_ID, "content-type": "application/json" },
        body: JSON.stringify({ type, version, condition, transport: { method: "webhook", callback, secret: env.EVENTSUB_SECRET } }),
      });
      if (!response.ok && response.status !== 409) throw new Error(`EventSub ${type} failed: ${response.status} ${await response.text()}`);
    }
    await env.DB.prepare("UPDATE streamers SET setup_status='ready', updated_at=? WHERE id=?").bind(epoch(), streamerId).run();
  } catch (error) {
    console.error("eventsub_setup_failed", streamerId, error);
    await env.DB.prepare("UPDATE streamers SET setup_status='error', updated_at=? WHERE id=?").bind(epoch(), streamerId).run();
  }
}

async function handleEventSub(request, env, ctx) {
  const contentLength = Number(request.headers.get("content-length") || 0);
  if (contentLength > 100000) return new Response("Payload too large", { status: 413 });
  const rawBody = await request.text();
  if (rawBody.length > 100000) return new Response("Payload too large", { status: 413 });
  const messageId = request.headers.get("Twitch-Eventsub-Message-Id") || "";
  const timestamp = request.headers.get("Twitch-Eventsub-Message-Timestamp") || "";
  const signature = request.headers.get("Twitch-Eventsub-Message-Signature") || "";
  const messageType = request.headers.get("Twitch-Eventsub-Message-Type") || "";
  const sentAt = Date.parse(timestamp);
  if (!messageId || !Number.isFinite(sentAt) || Math.abs(Date.now() - sentAt) > MAX_WEBHOOK_AGE_MS) return new Response("Invalid timestamp", { status: 403 });
  if (!(await verifyHmac(`${messageId}${timestamp}${rawBody}`, signature, env.EVENTSUB_SECRET))) return new Response("Invalid signature", { status: 403 });

  let body;
  try { body = JSON.parse(rawBody); } catch { return new Response("Invalid JSON", { status: 400 }); }
  if (messageType === "webhook_callback_verification") return new Response(String(body.challenge || ""), { headers: { "content-type": "text/plain; charset=utf-8" } });
  if (messageType === "revocation") return new Response(null, { status: 204 });
  if (messageType !== "notification") return new Response("Unsupported message", { status: 400 });

  const dedupe = await env.DB.prepare("INSERT OR IGNORE INTO event_dedupe (message_id, received_at) VALUES (?, ?)").bind(messageId, epoch()).run();
  if (!dedupe.meta?.changes) return new Response(null, { status: 204 });
  const type = body.subscription?.type;
  const event = body.event || {};
  const broadcasterId = broadcasterIdFromEvent(type, event);
  if (!broadcasterId) return new Response(null, { status: 204 });
  const streamer = await env.DB.prepare("SELECT id FROM streamers WHERE twitch_user_id=? AND active=1").bind(broadcasterId).first();
  if (!streamer) return new Response(null, { status: 204 });
  const config = await getConfig(streamer.id, env);
  const delta = computeEventDelta(type, event, config);
  if (delta.seconds > 0) await applyDelta(streamer.id, delta.seconds, delta.label, config.maxSeconds, env);
  ctx.waitUntil(cleanupExpired(env));
  return new Response(null, { status: 204 });
}

async function getMe(auth, env) {
  const [config, timer] = await Promise.all([getConfig(auth.id, env), getTimer(auth.id, env)]);
  return json({
    streamer: { login: auth.twitch_login, displayName: auth.display_name, setupStatus: auth.setup_status },
    config,
    timer,
    overlayKey: await decrypt(auth.encrypted_overlay_key, env.ENCRYPTION_KEY),
  });
}

async function updateConfig(request, auth, env) {
  const input = validateConfig(await readJson(request));
  const values = [input.startSeconds, input.maxSeconds];
  for (const key of RULE_KEYS) values.push(input[`${key}Seconds`], input[`${key}Enabled`] ? 1 : 0);
  values.push(epoch(), auth.id);
  await env.DB.prepare(`UPDATE timer_configs SET start_seconds=?,max_seconds=?,tier1_seconds=?,tier1_enabled=?,tier2_seconds=?,tier2_enabled=?,tier3_seconds=?,tier3_enabled=?,gift_seconds=?,gift_enabled=?,bits_seconds=?,bits_enabled=?,follow_seconds=?,follow_enabled=?,raid_seconds=?,raid_enabled=?,reward_seconds=?,reward_enabled=?,updated_at=? WHERE streamer_id=?`).bind(...values).run();
  await env.DB.prepare("UPDATE timer_states SET remaining_seconds=min(remaining_seconds,?), ends_at=CASE WHEN running=1 THEN min(ends_at,? + ?) ELSE ends_at END, updated_at=? WHERE streamer_id=?")
    .bind(input.maxSeconds, epoch(), input.maxSeconds, epoch(), auth.id).run();
  return json({ config: await getConfig(auth.id, env) });
}

async function timerAction(action, auth, env) {
  const now = epoch();
  if (action === "start") {
    await env.DB.prepare("UPDATE timer_states SET running=0, remaining_seconds=0, ends_at=0, updated_at=? WHERE streamer_id=? AND running=1 AND ends_at<=?").bind(now, auth.id, now).run();
    await env.DB.prepare("UPDATE timer_states SET running=1, ends_at=? + remaining_seconds, updated_at=? WHERE streamer_id=? AND running=0 AND remaining_seconds>0").bind(now, now, auth.id).run();
  }
  if (action === "pause") await env.DB.prepare("UPDATE timer_states SET remaining_seconds=max(0,ends_at-?), running=0, updated_at=? WHERE streamer_id=? AND running=1").bind(now, now, auth.id).run();
  if (action === "reset") await env.DB.prepare("UPDATE timer_states SET remaining_seconds=(SELECT start_seconds FROM timer_configs WHERE streamer_id=?), running=0, ends_at=0, last_event=NULL, updated_at=? WHERE streamer_id=?").bind(auth.id, now, auth.id).run();
  return json({ timer: await getTimer(auth.id, env) });
}

async function adjustTimer(request, auth, env) {
  const input = await readJson(request);
  const seconds = Math.max(-43200, Math.min(43200, Math.round(Number(input.seconds) || 0)));
  const config = await getConfig(auth.id, env);
  await applyDelta(auth.id, seconds, seconds >= 0 ? `Manuell +${Math.round(seconds / 60)} Min.` : `Manuell ${Math.round(seconds / 60)} Min.`, config.maxSeconds, env);
  return json({ timer: await getTimer(auth.id, env) });
}

async function disconnect(auth, env, ctx) {
  const accessToken = await decrypt(auth.encrypted_access_token, env.ENCRYPTION_KEY).catch(() => null);
  await env.DB.batch([
    env.DB.prepare("UPDATE streamers SET active=0, encrypted_access_token='', encrypted_refresh_token='', setup_status='disconnected', updated_at=? WHERE id=?").bind(epoch(), auth.id),
    env.DB.prepare("DELETE FROM sessions WHERE streamer_id=?").bind(auth.id),
  ]);
  ctx.waitUntil(Promise.allSettled([accessToken ? revokeToken(accessToken, env) : Promise.resolve(), removeSubscriptions(auth.twitch_user_id, env)]));
  return json({ disconnected: true });
}

async function getOverlayState(key, env) {
  if (!/^[A-Za-z0-9_-]{30,60}$/.test(key)) return json({ error: "Overlay nicht gefunden." }, 404);
  const row = await env.DB.prepare(`SELECT s.twitch_login,t.running,t.remaining_seconds,t.ends_at,t.last_event FROM streamers s JOIN timer_states t ON t.streamer_id=s.id WHERE s.overlay_key_hash=? AND s.active=1`).bind(await sha256(key)).first();
  if (!row) return json({ error: "Overlay nicht gefunden." }, 404);
  return json({ channel: row.twitch_login, ...timerFromRow(row) });
}

async function authenticate(request, env) {
  const match = request.headers.get("authorization")?.match(/^Bearer ([A-Za-z0-9_-]{30,})$/);
  if (!match) throw httpError(401, "Anmeldung erforderlich.");
  const row = await env.DB.prepare(`SELECT s.*,se.expires_at AS session_expires_at FROM sessions se JOIN streamers s ON s.id=se.streamer_id WHERE se.token_hash=? AND se.expires_at>? AND s.active=1`).bind(await sha256(match[1]), epoch()).first();
  if (!row) throw httpError(401, "Sitzung abgelaufen.");
  return row;
}

async function getConfig(streamerId, env) {
  const row = await env.DB.prepare("SELECT * FROM timer_configs WHERE streamer_id=?").bind(streamerId).first();
  if (!row) throw httpError(404, "Konfiguration nicht gefunden.");
  const config = { startSeconds: row.start_seconds, maxSeconds: row.max_seconds };
  for (const key of RULE_KEYS) {
    config[`${key}Seconds`] = row[`${key}_seconds`];
    config[`${key}Enabled`] = Boolean(row[`${key}_enabled`]);
  }
  return config;
}

async function getTimer(streamerId, env) {
  const row = await env.DB.prepare("SELECT running,remaining_seconds,ends_at,last_event FROM timer_states WHERE streamer_id=?").bind(streamerId).first();
  if (!row) throw httpError(404, "Timer nicht gefunden.");
  return timerFromRow(row);
}

function timerFromRow(row) {
  const wasRunning = Boolean(row.running);
  const running = wasRunning && Number(row.ends_at) > epoch();
  const remainingSeconds = wasRunning ? Math.max(0, Number(row.ends_at) - epoch()) : Math.max(0, Number(row.remaining_seconds));
  return { running, remainingSeconds, endsAt: Number(row.ends_at), lastEvent: row.last_event || null };
}

async function applyDelta(streamerId, seconds, label, maxSeconds, env) {
  const now = epoch();
  await env.DB.prepare(`UPDATE timer_states SET remaining_seconds=CASE WHEN running=1 THEN remaining_seconds ELSE min(?,max(0,remaining_seconds+?)) END, ends_at=CASE WHEN running=1 THEN ?+min(?,max(0,max(0,ends_at-?)+?)) ELSE ends_at END, last_event=?, updated_at=? WHERE streamer_id=?`)
    .bind(maxSeconds, seconds, now, maxSeconds, now, seconds, label || null, now, streamerId).run();
}

async function getAppToken(env) {
  const response = await fetch("https://id.twitch.tv/oauth2/token", { method: "POST", headers: { "content-type": "application/x-www-form-urlencoded" }, body: new URLSearchParams({ client_id: env.TWITCH_CLIENT_ID, client_secret: env.TWITCH_CLIENT_SECRET, grant_type: "client_credentials" }) });
  if (!response.ok) throw new Error(`App token failed: ${response.status}`);
  return (await response.json()).access_token;
}

async function removeSubscriptions(broadcasterId, env) {
  try {
    const appToken = await getAppToken(env);
    let cursor = null;
    do {
      const url = new URL("https://api.twitch.tv/helix/eventsub/subscriptions");
      if (cursor) url.searchParams.set("after", cursor);
      const response = await fetch(url, { headers: { Authorization: `Bearer ${appToken}`, "Client-Id": env.TWITCH_CLIENT_ID } });
      if (!response.ok) return;
      const payload = await response.json();
      const mine = (payload.data || []).filter((sub) => Object.values(sub.condition || {}).includes(broadcasterId));
      await Promise.all(mine.map((sub) => fetch(`https://api.twitch.tv/helix/eventsub/subscriptions?id=${encodeURIComponent(sub.id)}`, { method: "DELETE", headers: { Authorization: `Bearer ${appToken}`, "Client-Id": env.TWITCH_CLIENT_ID } })));
      cursor = payload.pagination?.cursor || null;
    } while (cursor);
  } catch (error) { console.error("subscription_cleanup_failed", error); }
}

async function revokeToken(token, env) {
  await fetch("https://id.twitch.tv/oauth2/revoke", { method: "POST", headers: { "content-type": "application/x-www-form-urlencoded" }, body: new URLSearchParams({ client_id: env.TWITCH_CLIENT_ID, token }) });
}

async function cleanupExpired(env) {
  const now = epoch();
  const oldEvents = now - 86400;
  await env.DB.batch([
    env.DB.prepare("DELETE FROM oauth_states WHERE expires_at<?").bind(now),
    env.DB.prepare("DELETE FROM sessions WHERE expires_at<?").bind(now),
    env.DB.prepare("DELETE FROM event_dedupe WHERE received_at<?").bind(oldEvents),
  ]);
}

async function encrypt(plainText, base64Key) {
  const key = await importEncryptionKey(base64Key, ["encrypt"]);
  const iv = crypto.getRandomValues(new Uint8Array(12));
  const cipher = await crypto.subtle.encrypt({ name: "AES-GCM", iv }, key, new TextEncoder().encode(plainText));
  return `${toBase64(iv)}.${toBase64(new Uint8Array(cipher))}`;
}

async function decrypt(value, base64Key) {
  const [ivPart, cipherPart] = String(value).split(".");
  if (!ivPart || !cipherPart) throw new Error("Encrypted value is invalid");
  const key = await importEncryptionKey(base64Key, ["decrypt"]);
  const plain = await crypto.subtle.decrypt({ name: "AES-GCM", iv: fromBase64(ivPart) }, key, fromBase64(cipherPart));
  return new TextDecoder().decode(plain);
}

async function importEncryptionKey(base64Key, usages) {
  const bytes = fromBase64(base64Key);
  if (bytes.byteLength !== 32) throw new Error("ENCRYPTION_KEY must decode to 32 bytes");
  return crypto.subtle.importKey("raw", bytes, "AES-GCM", false, usages);
}

async function verifyHmac(message, supplied, secret) {
  if (!supplied.startsWith("sha256=")) return false;
  const key = await crypto.subtle.importKey("raw", new TextEncoder().encode(secret), { name: "HMAC", hash: "SHA-256" }, false, ["sign"]);
  const expected = `sha256=${toHex(new Uint8Array(await crypto.subtle.sign("HMAC", key, new TextEncoder().encode(message))))}`;
  return timingSafeEqual(expected, supplied);
}

function timingSafeEqual(a, b) {
  const left = new TextEncoder().encode(a);
  const right = new TextEncoder().encode(b);
  if (left.length !== right.length) return false;
  let diff = 0;
  for (let i = 0; i < left.length; i += 1) diff |= left[i] ^ right[i];
  return diff === 0;
}

async function sha256(value) { return toHex(new Uint8Array(await crypto.subtle.digest("SHA-256", new TextEncoder().encode(value)))); }
function randomToken(bytes) { return toBase64Url(crypto.getRandomValues(new Uint8Array(bytes))); }
function toHex(bytes) { return [...bytes].map((byte) => byte.toString(16).padStart(2, "0")).join(""); }
function toBase64(bytes) { return btoa(String.fromCharCode(...bytes)); }
function fromBase64(value) { return Uint8Array.from(atob(value), (char) => char.charCodeAt(0)); }
function toBase64Url(bytes) { return toBase64(bytes).replace(/\+/g, "-").replace(/\//g, "_").replace(/=+$/, ""); }
function epoch() { return Math.floor(Date.now() / 1000); }

async function readJson(request) {
  if (Number(request.headers.get("content-length") || 0) > 20000) throw httpError(413, "Anfrage zu groß.");
  try { return await request.json(); } catch { throw httpError(400, "Ungültige JSON-Anfrage."); }
}

function authError(env, message) {
  const target = new URL(normalizedFrontendPath(env), env.FRONTEND_ORIGIN);
  target.searchParams.set("error", message);
  return Response.redirect(target.toString(), 302);
}

function normalizedFrontendPath(env) {
  const path = String(env.FRONTEND_PATH || "/");
  return path.startsWith("/") ? path : `/${path}`;
}

function enforceOrigin(request, env) {
  const origin = request.headers.get("origin");
  if (origin && origin !== env.FRONTEND_ORIGIN) throw httpError(403, "Origin nicht erlaubt.");
}

function preflight(request, env) {
  const origin = request.headers.get("origin");
  if (origin !== env.FRONTEND_ORIGIN) return new Response(null, { status: 403 });
  return new Response(null, { status: 204, headers: { "access-control-allow-origin": origin, "access-control-allow-methods": "GET,POST,PUT,OPTIONS", "access-control-allow-headers": "Authorization,Content-Type", "access-control-max-age": "86400", vary: "Origin" } });
}

function withCors(response, request, env) {
  if (!(response instanceof Response)) return response;
  const origin = request?.headers?.get("origin");
  if (origin === env?.FRONTEND_ORIGIN) {
    const headers = new Headers(response.headers);
    headers.set("access-control-allow-origin", origin);
    headers.set("vary", "Origin");
    return new Response(response.body, { status: response.status, statusText: response.statusText, headers });
  }
  return response;
}

function json(payload, status = 200) { return new Response(JSON.stringify(payload), { status, headers: JSON_HEADERS }); }
function httpError(status, message) { const error = new Error(message); error.status = status; return error; }

function validateEnvironment(env) {
  for (const key of ["DB", "TWITCH_CLIENT_ID", "TWITCH_CLIENT_SECRET", "EVENTSUB_SECRET", "ENCRYPTION_KEY", "PUBLIC_API_ORIGIN", "FRONTEND_ORIGIN"]) {
    if (!env[key]) throw new Error(`Missing environment value: ${key}`);
  }
  if (!/^https:\/\//.test(env.PUBLIC_API_ORIGIN) || !/^https:\/\//.test(env.FRONTEND_ORIGIN)) throw new Error("Origins must use HTTPS");
  if (env.EVENTSUB_SECRET.length < 10 || env.EVENTSUB_SECRET.length > 100) throw new Error("EVENTSUB_SECRET must have 10-100 characters");
}
