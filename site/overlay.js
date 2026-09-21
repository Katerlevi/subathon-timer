const params = new URLSearchParams(location.hash.slice(1));
const api = String(params.get("api") || window.SUBATHON_CONFIG?.apiBase || "").replace(/\/$/, "");
const key = params.get("key") || "";
let snapshot = { running: false, remainingSeconds: 0, endsAt: 0, alertId: 0 };
let lastAlertId = 0;
let audioContext;
let alertQueue = [];
let alertShowing = false;

function formatTime(totalSeconds) {
  const seconds = Math.max(0, Math.floor(Number(totalSeconds) || 0));
  const hours = Math.floor(seconds / 3600);
  return [hours, Math.floor((seconds % 3600) / 60), seconds % 60].map((value) => String(value).padStart(2, "0")).join(":");
}

function remaining() {
  return snapshot.running ? Math.max(0, Math.floor(snapshot.endsAt - Date.now() / 1000)) : snapshot.remainingSeconds;
}

function formatDelta(totalSeconds) {
	const seconds = Math.max(0, Math.round(Number(totalSeconds) || 0));
	if (seconds === 0) return "KEINE ZEIT";
	if (seconds >= 3600) return `+${formatTime(seconds)}`;
	return `+${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, "0")}`;
}

function formatScheduleDate(timestamp) {
	return new Intl.DateTimeFormat("de-DE", { dateStyle: "medium", timeStyle: "short" }).format(new Date(Number(timestamp) * 1000));
}

async function playAlertSound() {
	try {
		audioContext ||= new (window.AudioContext || window.webkitAudioContext)();
		if (audioContext.state === "suspended") await audioContext.resume();
		const start = audioContext.currentTime;
		for (const [delay, frequency] of [[0, 523.25], [0.12, 659.25], [0.24, 783.99]]) {
			const oscillator = audioContext.createOscillator();
			const gain = audioContext.createGain();
			oscillator.type = "sine";
			oscillator.frequency.value = frequency;
			gain.gain.setValueAtTime(0.0001, start + delay);
			gain.gain.exponentialRampToValueAtTime(0.22, start + delay + 0.02);
			gain.gain.exponentialRampToValueAtTime(0.0001, start + delay + 0.42);
			oscillator.connect(gain).connect(audioContext.destination);
			oscillator.start(start + delay);
			oscillator.stop(start + delay + 0.45);
		}
	} catch {}
}

function showAlert(data) {
	const alert = document.querySelector("#alert");
	const label = data.label || data.alertLabel || "Support";
	const seconds = data.seconds ?? data.alertSeconds;
	document.querySelector("#alertType").textContent = String(label).startsWith("Test ·") ? "TEST-ALERT" : "TWITCH-EVENT";
	document.querySelector("#alertLabel").textContent = label;
	document.querySelector("#alertTime").textContent = formatDelta(seconds);
	alert.classList.remove("active");
	void alert.offsetWidth;
	alert.classList.add("active");
	playAlertSound();
}

function playNextAlert() {
	if (alertShowing || !alertQueue.length) return;
	alertShowing = true;
	showAlert(alertQueue.shift());
	setTimeout(() => {
		document.querySelector("#alert").classList.remove("active");
		alertShowing = false;
		playNextAlert();
	}, 6200);
}

function enqueueAlerts(alerts) {
	for (const alert of alerts || []) {
		lastAlertId = Math.max(lastAlertId, Number(alert.id || 0));
		if (Math.abs(Date.now() / 1000 - Number(alert.createdAt || 0)) < 60) alertQueue.push(alert);
	}
	playNextAlert();
}

function render() {
  const value = remaining();
  document.querySelector("#timer").textContent = formatTime(value);
  document.querySelector("#overlay").classList.toggle("ended", value <= 0);
	document.querySelector("#overlay").classList.toggle("sleeping", Boolean(snapshot.sleeping));
}

async function refresh() {
  if (!api || !key) return;
  try {
		const response = await fetch(`${api}/overlay/state?after=${encodeURIComponent(lastAlertId)}`, {
      cache: "no-store",
      headers: { "X-RFS-Overlay-Key": key },
      credentials: "omit",
    });
    if (!response.ok) throw new Error("Overlay nicht gefunden");
		const incoming = await response.json();
		snapshot = incoming;
    document.querySelector("#channel").textContent = snapshot.channel ? `${snapshot.channel.toUpperCase()} · SUBATHON` : "SUBATHON";
		document.querySelector("#event").textContent = snapshot.sleeping ? "Streamer schläft" : (snapshot.lastEvent || (snapshot.running ? "Timer läuft" : "Timer bereit"));
		const startText = snapshot.streamStartAt ? `Start am ${formatScheduleDate(snapshot.streamStartAt)}.` : "";
		const endText = snapshot.endMode === "fixed" && snapshot.streamEndAt ? `Spätestes Ende am ${formatScheduleDate(snapshot.streamEndAt)}.` : "Das Streamende ist offen.";
		document.querySelector("#scheduleRule").textContent = `${startText} ${endText}`.trim();
		const sleepRule = document.querySelector("#sleepRule");
		sleepRule.hidden = !snapshot.sleeping;
		if (snapshot.sleeping) {
			const additions = snapshot.sleepAdditionsEnabled
				? "Support wird weiterhin zum Timer addiert"
				: "Support wird währenddessen nicht zum Timer addiert";
			const countdown = snapshot.sleepTimerContinues
				? "der Countdown läuft währenddessen weiter"
				: "der Countdown ist währenddessen angehalten";
			sleepRule.textContent = `Schlafmodus aktiv: ${additions} und ${countdown}.`;
		}
		enqueueAlerts(snapshot.alerts);
    render();
  } catch { document.querySelector("#event").textContent = "Verbindung wird wiederhergestellt …"; }
}

render();
refresh();
setInterval(render, 250);
setInterval(refresh, 3000);
