const apiBase = String(window.SUBATHON_CONFIG?.apiBase || "").replace(/\/$/, "");
const sessionKey = "subathon_session";
const state = { session: sessionStorage.getItem(sessionKey), streamer: null, timer: null, config: null, tick: null, poll: null };

const rules = [
  { key: "tier1", icon: "T1", title: "T1 / Prime Sub", detail: "Neue Subs und Resubs", color: "#7cff4f", defaultMinutes: 4 },
  { key: "tier2", icon: "T2", title: "Tier 2 Sub", detail: "Neue Subs und Resubs", color: "#23d5ff", defaultMinutes: 8 },
  { key: "tier3", icon: "T3", title: "Tier 3 Sub", detail: "Neue Subs und Resubs", color: "#b77cff", defaultMinutes: 15 },
  { key: "gift", icon: "GS", title: "Gift Sub", detail: "Pro verschenktes Abo", color: "#ff73b5", defaultMinutes: 4 },
  { key: "bits", icon: "B", title: "100 Bits", detail: "Je vollem 100er-Schritt", color: "#ffd05c", defaultMinutes: 1 },
  { key: "follow", icon: "F", title: "Follow", detail: "Neuer Kanal-Follow", color: "#5ee6c4", defaultMinutes: 0 },
  { key: "raid", icon: "R", title: "Raid-Zuschauer", detail: "Pro Raid-Zuschauer", color: "#ff835c", defaultMinutes: 0.1 },
  { key: "reward", icon: "CP", title: "Channel Points", detail: "Je Reward-Einlösung", color: "#7795ff", defaultMinutes: 2 },
];

const $ = (selector) => document.querySelector(selector);
const connectView = $("#connectView");
const dashboard = $("#dashboard");
const toast = $("#toast");

function escapeHtml(value) {
  return String(value).replace(/[&<>"]/g, (char) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;" })[char]);
}

function showToast(message) {
  toast.textContent = message;
  toast.classList.add("show");
  clearTimeout(showToast.timer);
  showToast.timer = setTimeout(() => toast.classList.remove("show"), 2600);
}

function formatTime(totalSeconds) {
  const seconds = Math.max(0, Math.floor(Number(totalSeconds) || 0));
  const hours = Math.floor(seconds / 3600);
  const minutes = Math.floor((seconds % 3600) / 60);
  const rest = seconds % 60;
  return [hours, minutes, rest].map((value) => String(value).padStart(2, "0")).join(":");
}

function formatDelta(totalSeconds) {
  const value = Math.round(Number(totalSeconds) || 0);
  const sign = value < 0 ? "−" : "+";
  const seconds = Math.abs(value);
  if (seconds >= 3600) return `${sign}${formatTime(seconds)}`;
  return `${sign}${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, "0")}`;
}

function currentRemaining() {
  if (!state.timer) return 0;
  if (!state.timer.running) return state.timer.remainingSeconds;
  return Math.max(0, Math.floor(state.timer.endsAt - Date.now() / 1000));
}

function renderTimer() {
  const formatted = formatTime(currentRemaining());
  $("#timerDisplay").textContent = formatted;
  $("#miniTimer").textContent = formatted;
	const sleeping = Boolean(state.timer?.sleeping);
  const running = Boolean(state.timer?.running && currentRemaining() > 0);
  $("#timerStatus").classList.toggle("active", running);
	$("#timerStatus").classList.toggle("sleeping", sleeping);
	$("#timerStatus").innerHTML = `<i></i>${sleeping ? (running ? "SCHLAF · LÄUFT" : "SCHLAF · PAUSE") : (running ? "LIVE" : "PAUSIERT")}`;
	$("#startPauseButton").disabled = sleeping;
	$("#resetButton").disabled = sleeping;
	$("#startPauseButton").textContent = sleeping ? "Im Schlafmodus gesperrt" : (running ? "Timer pausieren" : "Timer starten");
}

function renderSleep() {
	if (!state.timer || !state.config) return;
	const sleeping = Boolean(state.timer.sleeping);
	const additions = state.config.sleepAdditionsEnabled ? "Support wird weiter addiert" : "Support löst Alerts aus, addiert aber keine Zeit";
	const countdown = state.config.sleepTimerContinues ? "der Countdown läuft weiter" : "der Countdown ist eingefroren";
	$("#sleepStatus").textContent = sleeping ? `Aktiv: ${additions}; ${countdown}.` : "Der Schlafmodus ist aus.";
	$("#sleepButton").textContent = sleeping ? "Schlafmodus beenden" : "Schlafmodus aktivieren";
	$("#sleepButton").classList.toggle("active", sleeping);
	$("#sleepAdditionsEnabled").disabled = sleeping;
	$("#sleepTimerContinues").disabled = sleeping;
}

function applyTimer(timer) {
	state.timer = timer;
	$("#lastEvent").textContent = timer.lastEvent || "Noch kein Event empfangen";
	renderTimer();
	renderSleep();
}

function renderRules(config = {}) {
  $("#rulesGrid").innerHTML = rules.map((rule) => {
    const seconds = Number(config[`${rule.key}Seconds`] ?? rule.defaultMinutes * 60);
    const enabled = config[`${rule.key}Enabled`] ?? seconds > 0;
    return `<article class="rule-card" style="--rule-color:${rule.color}">
      <header><span class="rule-icon">${rule.icon}</span><label class="switch" aria-label="${escapeHtml(rule.title)} aktiv"><input name="${rule.key}Enabled" type="checkbox" ${enabled ? "checked" : ""}><span></span></label></header>
      <h3>${escapeHtml(rule.title)}</h3><p>${escapeHtml(rule.detail)}</p>
			<label class="minute-field"><input name="${rule.key}Minutes" type="number" min="0" max="720" step="0.1" value="${seconds / 60}" aria-label="Minuten für ${escapeHtml(rule.title)}"><span>Minuten</span></label>
			<button class="test-rule-button" data-test-rule="${rule.key}" type="button" ${enabled ? "" : "disabled"}>Test-Alert <span>${formatDelta(seconds)}</span></button>
    </article>`;
  }).join("");
}

async function api(path, options = {}) {
  const headers = new Headers(options.headers || {});
  if (state.session) headers.set("Authorization", `Bearer ${state.session}`);
  if (options.body && !headers.has("Content-Type")) headers.set("Content-Type", "application/json");
  const response = await fetch(`${apiBase}${path}`, { ...options, headers, credentials: "omit" });
  if (response.status === 401) {
    sessionStorage.removeItem(sessionKey);
    state.session = null;
    throw new Error("SESSION_EXPIRED");
  }
  const payload = await response.json().catch(() => ({}));
  if (!response.ok) throw new Error(payload.message || payload.error || `HTTP ${response.status}`);
  return payload;
}

function consumeAuthFragment() {
  const errorMessage = new URLSearchParams(location.search).get("error");
  const noticeMessage = new URLSearchParams(location.search).get("notice");
  if (errorMessage) {
    $("#connectError").textContent = errorMessage;
    history.replaceState(null, "", location.pathname);
  }
  if (noticeMessage) setTimeout(() => showToast(noticeMessage), 200);
  const params = new URLSearchParams(location.hash.slice(1));
  const token = params.get("session");
  if (token) {
    state.session = token;
    sessionStorage.setItem(sessionKey, token);
    history.replaceState(null, "", `${location.pathname}${location.search}`);
  }
}

async function loadDashboard() {
  if (!state.session) return;
	if (!apiBase) throw new Error("API_NOT_CONFIGURED");
  const data = await api("/me");
	state.streamer = data.streamer;
  state.config = data.config;
  state.timer = data.timer;
  connectView.hidden = true;
  dashboard.hidden = false;
  $("#connectionBadge").classList.add("online");
  const setupReady = data.streamer.setupStatus === "ready";
  $("#connectionBadge").innerHTML = `<span></span>${setupReady ? "Twitch verbunden" : "Twitch-Verbindung prüfen"}`;
  $("#displayName").textContent = data.streamer.displayName;
  $("#lastEvent").textContent = data.timer.lastEvent || "Noch kein Event empfangen";
  const appBase = `${location.origin}${location.pathname.replace(/[^/]*$/, "")}`;
  $("#dashboardUrl").value = `${appBase}#session=${encodeURIComponent(state.session)}`;
  $("#overlayUrl").value = `${appBase}overlay.html#api=${encodeURIComponent(apiBase)}&key=${encodeURIComponent(data.overlayKey)}`;
  renderRules(data.config);
  const form = $("#settingsForm");
  form.elements.startHours.value = data.config.startSeconds / 3600;
  form.elements.maxHours.value = data.config.maxSeconds / 3600;
	form.elements.sleepAdditionsEnabled.value = String(data.config.sleepAdditionsEnabled);
	form.elements.sleepTimerContinues.value = String(data.config.sleepTimerContinues);
  renderTimer();
	renderSleep();
  if (!setupReady) showToast("Twitch-Events sind noch nicht vollständig aktiviert");
  clearInterval(state.tick);
  state.tick = setInterval(renderTimer, 250);
	clearInterval(state.poll);
	state.poll = setInterval(async () => {
		try {
			const fresh = await api("/timer/state");
			applyTimer(fresh.timer);
		} catch (error) {
			if (error.message === "SESSION_EXPIRED") clearInterval(state.poll);
		}
	}, 3000);
}

$("#connectForm").addEventListener("submit", async (event) => {
  event.preventDefault();
  const channel = $("#channelName").value.trim().toLowerCase();
  const error = $("#connectError");
  error.textContent = "";
	if (!apiBase) {
		error.textContent = "Die RoyalFamily-Verbindung ist noch nicht eingerichtet.";
    return;
  }
  if (!/^[a-z0-9_]{3,25}$/.test(channel)) {
    error.textContent = "Bitte gib einen gültigen Twitch-Kanalnamen ein.";
    return;
  }
  const button = event.currentTarget.querySelector("button[type='submit']");
  button.disabled = true;
  button.textContent = "Verbindung wird geprüft …";
  try {
    const response = await fetch(`${apiBase}/health`, { cache: "no-store", credentials: "omit" });
    const health = await response.json().catch(() => ({}));
    if (!response.ok || !health.ok) throw new Error("SERVER_UNAVAILABLE");
    if (!health.configured) throw new Error("TWITCH_NOT_CONFIGURED");
    location.assign(`${apiBase}/auth/start?channel=${encodeURIComponent(channel)}`);
  } catch (failure) {
    error.textContent = failure.message === "TWITCH_NOT_CONFIGURED"
      ? "Die Twitch-Verbindung ist auf RoyalFamily.gg noch nicht eingerichtet."
      : "Der RoyalFamily-Server ist momentan nicht erreichbar.";
    button.disabled = false;
    button.innerHTML = "Mit Twitch verbinden <span aria-hidden='true'>↗</span>";
  }
});

function settingsPayload() {
	const form = new FormData($("#settingsForm"));
  const payload = {
    startSeconds: Math.round(Number(form.get("startHours")) * 3600),
    maxSeconds: Math.round(Number(form.get("maxHours")) * 3600),
		sleepAdditionsEnabled: $("#sleepAdditionsEnabled").value === "true",
		sleepTimerContinues: $("#sleepTimerContinues").value === "true",
  };
  for (const rule of rules) {
    payload[`${rule.key}Enabled`] = form.get(`${rule.key}Enabled`) === "on";
    payload[`${rule.key}Seconds`] = Math.round(Number(form.get(`${rule.key}Minutes`)) * 60);
  }
	return payload;
}

async function saveSettings(showConfirmation = true) {
	const data = await api("/config", { method: "PUT", body: JSON.stringify(settingsPayload()) });
	state.config = data.config;
	renderSleep();
	if (showConfirmation) showToast("Änderungen gespeichert");
	return data.config;
}

$("#settingsForm").addEventListener("submit", async (event) => {
	event.preventDefault();
	try { await saveSettings(true); }
	catch (error) { showToast(error.message === "SESSION_EXPIRED" ? "Bitte Twitch neu verbinden" : error.message || "Speichern fehlgeschlagen"); }
});

$("#startPauseButton").addEventListener("click", async () => {
  const action = state.timer?.running ? "pause" : "start";
  try { const data = await api(`/timer/${action}`, { method: "POST" }); applyTimer(data.timer); }
  catch { showToast("Timer konnte nicht aktualisiert werden"); }
});

$("#resetButton").addEventListener("click", async () => {
  if (!confirm("Timer wirklich auf die Startzeit zurücksetzen?")) return;
  try { const data = await api("/timer/reset", { method: "POST" }); applyTimer(data.timer); showToast("Timer zurückgesetzt"); }
  catch { showToast("Zurücksetzen fehlgeschlagen"); }
});

document.querySelectorAll("[data-adjust]").forEach((button) => button.addEventListener("click", async () => {
  try {
    const data = await api("/timer/adjust", { method: "POST", body: JSON.stringify({ seconds: Number(button.dataset.adjust) }) });
		applyTimer(data.timer);
  } catch { showToast("Timer konnte nicht angepasst werden"); }
}));

$("#rulesGrid").addEventListener("change", (event) => {
	if (!event.target.matches("input[type='checkbox']")) return;
	const button = event.target.closest(".rule-card")?.querySelector("[data-test-rule]");
	if (button) button.disabled = !event.target.checked;
});

$("#rulesGrid").addEventListener("click", async (event) => {
	const button = event.target.closest("[data-test-rule]");
	if (!button || button.disabled) return;
	button.disabled = true;
	try {
		await saveSettings(false);
		const data = await api("/test-event", { method: "POST", body: JSON.stringify({ key: button.dataset.testRule }) });
		applyTimer(data.timer);
		showToast(`Test ausgelöst: ${formatDelta(data.testedSeconds)}`);
	} catch (error) {
		showToast(error.message || "Test konnte nicht ausgelöst werden");
	} finally {
		const checkbox = button.closest(".rule-card")?.querySelector("input[type='checkbox']");
		button.disabled = checkbox ? !checkbox.checked : false;
	}
});

$("#sleepButton").addEventListener("click", async () => {
	const action = state.timer?.sleeping ? "end" : "start";
	const button = $("#sleepButton");
	button.disabled = true;
	try {
		if (action === "start") await saveSettings(false);
		const data = await api(`/sleep/${action}`, { method: "POST" });
		applyTimer(data.timer);
		showToast(action === "start" ? "Schlafmodus aktiviert" : "Schlafmodus beendet");
	} catch (error) {
		showToast(error.message || "Schlafmodus konnte nicht geändert werden");
	} finally {
		button.disabled = false;
	}
});

function graphicTime(seconds) {
	const value = Math.max(0, Math.round(seconds));
	if (value < 60) return `${value} Sek.`;
	if (value % 60 === 0) return `${value / 60} Min.`;
	return `${String(value / 60).replace(".", ",")} Min.`;
}

function roundRect(context, x, y, width, height, radius) {
	context.beginPath();
	context.roundRect(x, y, width, height, radius);
	context.fill();
	context.stroke();
}

function createRulesGraphic() {
	const form = new FormData($("#settingsForm"));
	const activeRules = rules.filter((rule) => form.get(`${rule.key}Enabled`) === "on").map((rule) => ({
		...rule,
		seconds: Math.round(Number(form.get(`${rule.key}Minutes`)) * 60),
	}));
	if (!activeRules.length) {
		showToast("Aktiviere mindestens eine Regel für die Grafik");
		return;
	}

	const canvas = document.createElement("canvas");
	canvas.width = 1080;
	canvas.height = 1350;
	const context = canvas.getContext("2d");
	const background = context.createLinearGradient(0, 0, 1080, 1350);
	background.addColorStop(0, "#070a0f");
	background.addColorStop(0.55, "#101722");
	background.addColorStop(1, "#07120b");
	context.fillStyle = background;
	context.fillRect(0, 0, 1080, 1350);
	context.fillStyle = "rgba(35,213,255,.08)";
	context.beginPath(); context.arc(930, 130, 340, 0, Math.PI * 2); context.fill();
	context.fillStyle = "rgba(124,255,79,.06)";
	context.beginPath(); context.arc(100, 1220, 420, 0, Math.PI * 2); context.fill();

	context.fillStyle = "#7cff4f";
	context.font = "800 24px Segoe UI, sans-serif";
	context.letterSpacing = "5px";
	context.fillText("ROYAL FAMILY", 74, 92);
	context.letterSpacing = "0px";
	context.fillStyle = "#f4f7fb";
	context.font = "900 88px Segoe UI, sans-serif";
	context.fillText("SUBATHON", 68, 190);
	context.fillStyle = "#23d5ff";
	context.font = "700 34px Segoe UI, sans-serif";
	context.fillText("SO VERLÄNGERT SICH DER STREAM", 74, 242);
	context.fillStyle = "#8e99aa";
	context.font = "500 24px Segoe UI, sans-serif";
	context.fillText(`twitch.tv/${state.streamer?.login || "deinkanal"}`, 76, 286);

	const top = 342;
	const gap = 20;
	const cardWidth = 456;
	const cardHeight = 166;
	activeRules.slice(0, 8).forEach((rule, index) => {
		const column = index % 2;
		const row = Math.floor(index / 2);
		const x = 74 + column * (cardWidth + gap);
		const y = top + row * (cardHeight + gap);
		context.fillStyle = "rgba(14,19,27,.94)";
		context.strokeStyle = "#283140";
		context.lineWidth = 2;
		roundRect(context, x, y, cardWidth, cardHeight, 18);
		context.fillStyle = rule.color;
		context.fillRect(x, y, 5, cardHeight);
		context.fillStyle = "#8e99aa";
		context.font = "800 20px Segoe UI, sans-serif";
		context.fillText(rule.icon, x + 30, y + 43);
		context.fillStyle = "#f4f7fb";
		context.font = "700 27px Segoe UI, sans-serif";
		context.fillText(rule.title, x + 30, y + 86);
		context.fillStyle = rule.color;
		context.font = "900 38px Segoe UI, sans-serif";
		context.textAlign = "right";
		context.fillText(`+ ${graphicTime(rule.seconds)}`, x + cardWidth - 26, y + 135);
		context.textAlign = "left";
	});

	const sleepY = 1110;
	context.fillStyle = "rgba(9,20,27,.96)";
	context.strokeStyle = "rgba(35,213,255,.5)";
	context.lineWidth = 2;
	roundRect(context, 74, sleepY, 932, 150, 20);
	context.fillStyle = "#23d5ff";
	context.font = "800 20px Segoe UI, sans-serif";
	context.fillText("SCHLAFMODUS", 105, sleepY + 42);
	context.fillStyle = "#f4f7fb";
	context.font = "700 25px Segoe UI, sans-serif";
	const additions = $("#sleepAdditionsEnabled").value === "true" ? "Support fügt weiter Zeit hinzu" : "Support fügt keine Zeit hinzu";
	const countdown = $("#sleepTimerContinues").value === "true" ? "Countdown läuft weiter" : "Countdown wird eingefroren";
	context.fillText(additions, 105, sleepY + 84);
	context.fillText(countdown, 105, sleepY + 120);
	context.fillStyle = "#6f7b8d";
	context.font = "600 18px Segoe UI, sans-serif";
	context.fillText("Die aktuellen Regeln des Streams · Änderungen sind im Live-Dashboard sichtbar", 74, 1310);

	const link = document.createElement("a");
	link.download = `${state.streamer?.login || "subathon"}-regeln.png`;
	link.href = canvas.toDataURL("image/png");
	link.click();
	showToast("Regelgrafik als PNG erstellt");
}

$("#shareImageButton").addEventListener("click", createRulesGraphic);

async function copySecret(inputSelector, successMessage) {
  const input = $(inputSelector);
  try { await navigator.clipboard.writeText(input.value); showToast(successMessage); }
  catch { input.select(); showToast("URL markiert – bitte kopieren"); }
}

$("#copyDashboardButton").addEventListener("click", () => copySecret("#dashboardUrl", "Dashboard-Link kopiert"));
$("#copyOverlayButton").addEventListener("click", () => copySecret("#overlayUrl", "OBS-Link kopiert"));

$("#disconnectButton").addEventListener("click", async () => {
  if (!confirm("Twitch-Verbindung und gespeicherte Tokens entfernen?")) return;
  try { await api("/disconnect", { method: "POST" }); } catch {}
  sessionStorage.removeItem(sessionKey);
  location.reload();
});

consumeAuthFragment();
renderRules();
loadDashboard().catch((error) => {
  if (error.message === "SESSION_EXPIRED") showToast("Sitzung abgelaufen – bitte neu verbinden");
	else if (error.message === "API_NOT_CONFIGURED") $("#connectError").textContent = "Die RoyalFamily-Verbindung ist noch nicht eingerichtet.";
  else if (state.session) showToast("Server momentan nicht erreichbar");
});
