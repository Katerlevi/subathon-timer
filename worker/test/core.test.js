import test from "node:test";
import assert from "node:assert/strict";
import { broadcasterIdFromEvent, computeEventDelta, safeChannel, validateConfig } from "../src/core.js";

const config = {
  tier1Enabled: true, tier1Seconds: 240,
  tier2Enabled: true, tier2Seconds: 480,
  tier3Enabled: true, tier3Seconds: 900,
  giftEnabled: true, giftSeconds: 240,
  bitsEnabled: true, bitsSeconds: 60,
  followEnabled: false, followSeconds: 60,
  raidEnabled: true, raidSeconds: 6,
  rewardEnabled: true, rewardSeconds: 120,
};

test("gifted subscribe is ignored to prevent double counting", () => {
  assert.equal(computeEventDelta("channel.subscribe", { is_gift: true, tier: "1000" }, config).seconds, 0);
  assert.equal(computeEventDelta("channel.subscription.gift", { total: 5, user_name: "Mia" }, config).seconds, 1200);
});

test("bits are counted in complete 100-bit steps", () => {
  assert.equal(computeEventDelta("channel.cheer", { bits: 299 }, config).seconds, 120);
});

test("raid uses destination broadcaster and viewer multiplier", () => {
  const event = { to_broadcaster_user_id: "42", viewers: 80 };
  assert.equal(broadcasterIdFromEvent("channel.raid", event), "42");
  assert.equal(computeEventDelta("channel.raid", event, config).seconds, 480);
});

test("configuration is bounded and requires cap above start", () => {
  assert.throws(() => validateConfig({ startSeconds: 7200, maxSeconds: 3600 }));
  const valid = validateConfig({ ...config, startSeconds: 3600, maxSeconds: 7200 });
  assert.equal(valid.maxSeconds, 7200);
});

test("channel names are normalized and validated", () => {
  assert.equal(safeChannel("  Test_Channel "), "test_channel");
  assert.throws(() => safeChannel("bad-channel"));
});

