const params = new URLSearchParams(location.search);
const api = String(params.get("api") || window.SUBATHON_CONFIG?.apiBase || "").replace(/\/$/, "");
const key = params.get("key") || "";
let snapshot = { running: false, remainingSeconds: 0, endsAt: 0 };

function formatTime(totalSeconds) {
  const seconds = Math.max(0, Math.floor(Number(totalSeconds) || 0));
  const hours = Math.floor(seconds / 3600);
  return [hours, Math.floor((seconds % 3600) / 60), seconds % 60].map((value) => String(value).padStart(2, "0")).join(":");
}

function remaining() {
  return snapshot.running ? Math.max(0, Math.floor(snapshot.endsAt - Date.now() / 1000)) : snapshot.remainingSeconds;
}

function render() {
  const value = remaining();
  document.querySelector("#timer").textContent = formatTime(value);
  document.querySelector("#overlay").classList.toggle("ended", value <= 0);
}

async function refresh() {
  if (!api || !key) return;
  try {
    const response = await fetch(`${api}/api/overlay/${encodeURIComponent(key)}/state`, { cache: "no-store" });
    if (!response.ok) throw new Error("Overlay nicht gefunden");
    snapshot = await response.json();
    document.querySelector("#channel").textContent = snapshot.channel ? `${snapshot.channel.toUpperCase()} · SUBATHON` : "SUBATHON";
    document.querySelector("#event").textContent = snapshot.lastEvent || (snapshot.running ? "Timer laeuft" : "Timer bereit");
    render();
  } catch { document.querySelector("#event").textContent = "Verbindung wird wiederhergestellt …"; }
}

render();
refresh();
setInterval(render, 250);
setInterval(refresh, 3000);

