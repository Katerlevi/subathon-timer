const params = new URLSearchParams(location.hash.slice(1));
const api = String(params.get("api") || window.SUBATHON_CONFIG?.apiBase || "").replace(/\/$/, "");
const key = params.get("key") || "";
const panel = document.querySelector("#sleepOverlay");
let snapshot = null;

function ruleText(value) {
  const additions = value.sleepAdditionsEnabled
    ? "Support wird während des Schlafs weiterhin zum Subathon-Timer addiert"
    : "Support wird während des Schlafs nicht zum Subathon-Timer addiert";
  const countdown = value.sleepTimerContinues
    ? "der Subathon-Countdown läuft weiter"
    : "der Subathon-Countdown ist angehalten";
  return `Der Streamer schläft. ${additions} und ${countdown}.`;
}

function render() {
  if (!snapshot?.sleeping) { panel.hidden = true; return; }
  panel.hidden = false;
  panel.classList.remove("error");
  document.querySelector("#sleepTitle").textContent = snapshot.channel ? `${snapshot.channel} schläft` : "Der Streamer schläft";
  document.querySelector("#sleepRule").textContent = ruleText(snapshot);
  const result = globalThis.RFSleepClock?.duration(snapshot);
  document.querySelector("#sleepLabel").textContent = result?.state === "elapsed" ? "Geplante Schlafzeit beendet" : result?.state === "unknown" ? "Schlafmodus ist aktiv" : "Noch geplante Schlafzeit";
  document.querySelector("#sleepTimer").textContent = result?.time || (result?.state === "elapsed" ? "00:00:00" : "—");
  document.querySelector("#sleepConnection").textContent = result?.stale ? "Synchronisierung wird aktualisiert …" : "";
}

async function refresh() {
  if (!api || !key) {
    panel.hidden = false; panel.classList.add("error");
    document.querySelector("#sleepTitle").textContent = "Schlaftimer-Link ungültig";
    document.querySelector("#sleepRule").textContent = "Bitte den persönlichen Schlaftimer-Link aus dem Dashboard vollständig in OBS einfügen.";
    return;
  }
  try {
    const response = await fetch(`${api}/overlay/state`, { cache:"no-store", headers:{ "X-RFS-Overlay-Key":key }, credentials:"omit" });
    if (!response.ok) throw new Error();
    snapshot = await response.json();
    globalThis.RFSleepClock?.accept(snapshot);
    render();
  } catch {
    if (snapshot?.sleeping) document.querySelector("#sleepConnection").textContent = "Verbindung wird wiederhergestellt …";
  }
}

render();
refresh();
setInterval(render,250);
setInterval(refresh,3000);
