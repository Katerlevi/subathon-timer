const apiBase = String(window.SUBATHON_CONFIG?.apiBase || "").replace(/\/$/, "");
const sessionKey = "subathon_session";
const state = { session: sessionStorage.getItem(sessionKey), timer: null, config: null, tick: null };

const rules = [
  { key: "tier1", icon: "T1", title: "T1 / Prime Sub", detail: "Neue Subs und Resubs", color: "#7cff4f", defaultMinutes: 4 },
  { key: "tier2", icon: "T2", title: "Tier 2 Sub", detail: "Neue Subs und Resubs", color: "#23d5ff", defaultMinutes: 8 },
  { key: "tier3", icon: "T3", title: "Tier 3 Sub", detail: "Neue Subs und Resubs", color: "#b77cff", defaultMinutes: 15 },
  { key: "gift", icon: "GS", title: "Gift Sub", detail: "Pro verschenktes Abo", color: "#ff73b5", defaultMinutes: 4 },
  { key: "bits", icon: "B", title: "100 Bits", detail: "Je vollem 100er-Schritt", color: "#ffd05c", defaultMinutes: 1 },
  { key: "follow", icon: "F", title: "Follow", detail: "Neuer Kanal-Follow", color: "#5ee6c4", defaultMinutes: 0 },
  { key: "raid", icon: "R", title: "Raid-Zuschauer", detail: "Pro Raid-Zuschauer", color: "#ff835c", defaultMinutes: 0.1 },
  { key: "reward", icon: "CP", title: "Channel Points", detail: "Je Reward-Einloesung", color: "#7795ff", defaultMinutes: 2 },
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

function currentRemaining() {
  if (!state.timer) return 0;
  if (!state.timer.running) return state.timer.remainingSeconds;
  return Math.max(0, Math.floor(state.timer.endsAt - Date.now() / 1000));
}

function renderTimer() {
  const formatted = formatTime(currentRemaining());
  $("#timerDisplay").textContent = formatted;
  $("#miniTimer").textContent = formatted;
  const running = Boolean(state.timer?.running && currentRemaining() > 0);
  $("#timerStatus").classList.toggle("active", running);
  $("#timerStatus").innerHTML = `<i></i>${running ? "LIVE" : "PAUSIERT"}`;
  $("#startPauseButton").textContent = running ? "Timer pausieren" : "Timer starten";
}

function renderRules(config = {}) {
  $("#rulesGrid").innerHTML = rules.map((rule) => {
    const seconds = Number(config[`${rule.key}Seconds`] ?? rule.defaultMinutes * 60);
    const enabled = config[`${rule.key}Enabled`] ?? seconds > 0;
    return `<article class="rule-card" style="--rule-color:${rule.color}">
      <header><span class="rule-icon">${rule.icon}</span><label class="switch" aria-label="${escapeHtml(rule.title)} aktiv"><input name="${rule.key}Enabled" type="checkbox" ${enabled ? "checked" : ""}><span></span></label></header>
      <h3>${escapeHtml(rule.title)}</h3><p>${escapeHtml(rule.detail)}</p>
      <label class="minute-field"><input name="${rule.key}Minutes" type="number" min="0" max="720" step="0.1" value="${seconds / 60}" aria-label="Minuten fuer ${escapeHtml(rule.title)}"><span>Minuten</span></label>
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
  if (!response.ok) throw new Error(payload.error || `HTTP ${response.status}`);
  return payload;
}

function consumeAuthFragment() {
  const errorMessage = new URLSearchParams(location.search).get("error");
  if (errorMessage) {
    $("#connectError").textContent = errorMessage;
    history.replaceState(null, "", location.pathname);
  }
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
  if (!apiBase || apiBase.includes("DEIN-WORKER")) throw new Error("API_NOT_CONFIGURED");
  const data = await api("/api/me");
  state.config = data.config;
  state.timer = data.timer;
  connectView.hidden = true;
  dashboard.hidden = false;
  $("#connectionBadge").classList.add("online");
  $("#connectionBadge").innerHTML = "<span></span>Twitch verbunden";
  $("#displayName").textContent = data.streamer.displayName;
  $("#lastEvent").textContent = data.timer.lastEvent || "Noch kein Event empfangen";
  $("#overlayUrl").value = `${location.origin}${location.pathname.replace(/[^/]*$/, "")}overlay.html?api=${encodeURIComponent(apiBase)}&key=${encodeURIComponent(data.overlayKey)}`;
  renderRules(data.config);
  const form = $("#settingsForm");
  form.elements.startHours.value = data.config.startSeconds / 3600;
  form.elements.maxHours.value = data.config.maxSeconds / 3600;
  renderTimer();
  clearInterval(state.tick);
  state.tick = setInterval(renderTimer, 250);
}

$("#connectForm").addEventListener("submit", (event) => {
  event.preventDefault();
  const channel = $("#channelName").value.trim().toLowerCase();
  const error = $("#connectError");
  error.textContent = "";
  if (!apiBase || apiBase.includes("DEIN-WORKER")) {
    error.textContent = "Die Server-URL ist noch nicht eingerichtet.";
    return;
  }
  if (!/^[a-z0-9_]{3,25}$/.test(channel)) {
    error.textContent = "Bitte gib einen gueltigen Twitch-Kanalnamen ein.";
    return;
  }
  location.assign(`${apiBase}/api/auth/start?channel=${encodeURIComponent(channel)}`);
});

$("#settingsForm").addEventListener("submit", async (event) => {
  event.preventDefault();
  const form = new FormData(event.currentTarget);
  const payload = {
    startSeconds: Math.round(Number(form.get("startHours")) * 3600),
    maxSeconds: Math.round(Number(form.get("maxHours")) * 3600),
  };
  for (const rule of rules) {
    payload[`${rule.key}Enabled`] = form.get(`${rule.key}Enabled`) === "on";
    payload[`${rule.key}Seconds`] = Math.round(Number(form.get(`${rule.key}Minutes`)) * 60);
  }
  try {
    const data = await api("/api/config", { method: "PUT", body: JSON.stringify(payload) });
    state.config = data.config;
    showToast("Aenderungen gespeichert");
  } catch (error) { showToast(error.message === "SESSION_EXPIRED" ? "Bitte Twitch neu verbinden" : "Speichern fehlgeschlagen"); }
});

$("#startPauseButton").addEventListener("click", async () => {
  const action = state.timer?.running ? "pause" : "start";
  try { const data = await api(`/api/timer/${action}`, { method: "POST" }); state.timer = data.timer; renderTimer(); }
  catch { showToast("Timer konnte nicht aktualisiert werden"); }
});

$("#resetButton").addEventListener("click", async () => {
  if (!confirm("Timer wirklich auf die Startzeit zuruecksetzen?")) return;
  try { const data = await api("/api/timer/reset", { method: "POST" }); state.timer = data.timer; renderTimer(); showToast("Timer zurueckgesetzt"); }
  catch { showToast("Zuruecksetzen fehlgeschlagen"); }
});

document.querySelectorAll("[data-adjust]").forEach((button) => button.addEventListener("click", async () => {
  try {
    const data = await api("/api/timer/adjust", { method: "POST", body: JSON.stringify({ seconds: Number(button.dataset.adjust) }) });
    state.timer = data.timer;
    renderTimer();
  } catch { showToast("Timer konnte nicht angepasst werden"); }
}));

$("#copyOverlayButton").addEventListener("click", async () => {
  try { await navigator.clipboard.writeText($("#overlayUrl").value); showToast("OBS-URL kopiert"); }
  catch { $("#overlayUrl").select(); showToast("URL markiert – bitte kopieren"); }
});

$("#disconnectButton").addEventListener("click", async () => {
  if (!confirm("Twitch-Verbindung und gespeicherte Tokens entfernen?")) return;
  try { await api("/api/disconnect", { method: "POST" }); } catch {}
  sessionStorage.removeItem(sessionKey);
  location.reload();
});

consumeAuthFragment();
renderRules();
loadDashboard().catch((error) => {
  if (error.message === "SESSION_EXPIRED") showToast("Sitzung abgelaufen – bitte neu verbinden");
  else if (error.message === "API_NOT_CONFIGURED") $("#connectError").textContent = "Die Server-URL ist noch nicht eingerichtet.";
  else if (state.session) showToast("Server momentan nicht erreichbar");
});
