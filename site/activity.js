const params = new URLSearchParams(location.hash.slice(1));
const api = String(params.get("api") || window.SUBATHON_CONFIG?.apiBase || "").replace(/\/$/, "");
const key = params.get("key") || "";
const list = document.querySelector("#activityList");
let lastSignature = "";

function escapeHtml(value) {
  return String(value).replace(/[&<>\"]/g, (char) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '\"': "&quot;" })[char]);
}

function formatDelta(totalSeconds) {
  const seconds = Math.max(0, Math.round(Number(totalSeconds) || 0));
  if (seconds >= 3600) {
    const hours = Math.floor(seconds / 3600);
    return `+${hours}:${String(Math.floor((seconds % 3600) / 60)).padStart(2, "0")}:${String(seconds % 60).padStart(2, "0")}`;
  }
  return `+${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, "0")}`;
}

function formatClock(timestamp) {
  return new Intl.DateTimeFormat("de-DE", { hour: "2-digit", minute: "2-digit" }).format(new Date(Number(timestamp) * 1000));
}

function render(actions) {
  const visible = (actions || []).filter((action) => Number(action.seconds) > 0).slice(0, 3);
  const signature = visible.map((action) => `${action.id}:${action.seconds}`).join("|");
  if (signature === lastSignature) return;
  lastSignature = signature;
  list.innerHTML = visible.length ? visible.map((action, index) => `
    <li>
      <span class="rank">0${index + 1}</span>
      <span class="copy"><strong class="label">${escapeHtml(action.label)}</strong><span class="time">${formatClock(action.createdAt)} Uhr</span></span>
      <b class="added">${formatDelta(action.seconds)}</b>
    </li>`).join("") : '<li class="empty">Noch keine Zeit hinzugefügt</li>';
}

async function refresh() {
  if (!api || !key) {
    list.innerHTML = '<li class="empty error">Aktionsfenster-Link ungültig</li>';
    return;
  }
  try {
    const response = await fetch(`${api}/overlay/state`, { cache:"no-store", headers:{ "X-RFS-Overlay-Key":key }, credentials:"omit" });
    if (!response.ok) throw new Error();
    const snapshot = await response.json();
    render(snapshot.recentActions);
  } catch {
    list.innerHTML = '<li class="empty error">Verbindung wird wiederhergestellt …</li>';
  }
}

refresh();
setInterval(refresh, 3000);
