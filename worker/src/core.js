export const TWITCH_SCOPES = [
  "bits:read",
  "channel:read:redemptions",
  "channel:read:subscriptions",
  "moderator:read:followers",
];

export const RULE_KEYS = ["tier1", "tier2", "tier3", "gift", "bits", "follow", "raid", "reward"];

export function computeEventDelta(type, event, config) {
  const enabled = (key) => Boolean(config[`${key}Enabled`]);
  const seconds = (key) => Math.max(0, Number(config[`${key}Seconds`]) || 0);
  if (type === "channel.subscribe") {
    if (event.is_gift) return { seconds: 0, label: "" };
    const key = tierKey(event.tier);
    return enabled(key) ? { seconds: seconds(key), label: `${event.user_name || "Jemand"}: ${tierLabel(event.tier)} Sub` } : { seconds: 0, label: "" };
  }
  if (type === "channel.subscription.message") {
    const key = tierKey(event.tier);
    return enabled(key) ? { seconds: seconds(key), label: `${event.user_name || "Jemand"}: ${tierLabel(event.tier)} Resub` } : { seconds: 0, label: "" };
  }
  if (type === "channel.subscription.gift") {
    const count = boundedInt(event.total, 0, 1000);
    return enabled("gift") ? { seconds: seconds("gift") * count, label: `${event.user_name || "Anonym"}: ${count} Gift Sub${count === 1 ? "" : "s"}` } : { seconds: 0, label: "" };
  }
  if (type === "channel.cheer") {
    const bits = boundedInt(event.bits, 0, 10000000);
    return enabled("bits") ? { seconds: seconds("bits") * Math.floor(bits / 100), label: `${event.user_name || "Anonym"}: ${bits} Bits` } : { seconds: 0, label: "" };
  }
  if (type === "channel.follow") return enabled("follow") ? { seconds: seconds("follow"), label: `${event.user_name || "Jemand"} folgt jetzt` } : { seconds: 0, label: "" };
  if (type === "channel.raid") {
    const viewers = boundedInt(event.viewers, 0, 10000000);
    return enabled("raid") ? { seconds: seconds("raid") * viewers, label: `${event.from_broadcaster_user_name || "Raid"}: ${viewers} Zuschauer` } : { seconds: 0, label: "" };
  }
  if (type === "channel.channel_points_custom_reward_redemption.add") return enabled("reward") ? { seconds: seconds("reward"), label: `${event.user_name || "Jemand"}: ${event.reward?.title || "Channel Points"}` } : { seconds: 0, label: "" };
  return { seconds: 0, label: "" };
}

export function validateConfig(input) {
  const result = {
    startSeconds: boundedInt(input.startSeconds, 0, 2592000),
    maxSeconds: boundedInt(input.maxSeconds, 3600, 2592000),
  };
  if (result.maxSeconds < result.startSeconds) throw new Error("Das Zeitlimit muss mindestens so gross wie die Startzeit sein.");
  for (const key of RULE_KEYS) {
    result[`${key}Seconds`] = boundedInt(input[`${key}Seconds`], 0, 43200);
    result[`${key}Enabled`] = Boolean(input[`${key}Enabled`]);
  }
  return result;
}

export function safeChannel(value) {
  const channel = String(value || "").trim().toLowerCase();
  if (!/^[a-z0-9_]{3,25}$/.test(channel)) throw new Error("Ungueltiger Twitch-Kanalname.");
  return channel;
}

export function broadcasterIdFromEvent(type, event) {
  if (type === "channel.raid") return event.to_broadcaster_user_id || null;
  return event.broadcaster_user_id || null;
}

function tierKey(tier) { return tier === "3000" ? "tier3" : tier === "2000" ? "tier2" : "tier1"; }
function tierLabel(tier) { return tier === "3000" ? "Tier 3" : tier === "2000" ? "Tier 2" : "T1 / Prime"; }
function boundedInt(value, min, max) {
  const number = Number(value);
  if (!Number.isFinite(number)) throw new Error("Ungueltiger Zahlenwert.");
  return Math.min(max, Math.max(min, Math.round(number)));
}

