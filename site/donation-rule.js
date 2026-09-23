/* Integer-cent/second rule for the future settings panel; no API or storage calls. */
(function (root) {
  'use strict';
  const MAX_AMOUNT_MINOR=100000000, MAX_SECONDS=2592000;
  function fail(code) { throw new Error(code); }
  function parseEuroAmount(text) {
    if (typeof text !== 'string') fail('AMOUNT_REQUIRES_TEXT');
    text=text.trim().replace(',', '.');
    if (!/^(0|[1-9][0-9]{0,6})(\.[0-9]{1,2})?$/.test(text)) fail('AMOUNT_INVALID_OR_SUBCENT');
    const [a,b='']=text.split('.');
    const cents=Number(BigInt(a)*100n+BigInt(b.padEnd(2,'0')));
    if (cents<1 || cents>MAX_AMOUNT_MINOR) fail('AMOUNT_OUT_OF_BOUNDS');
    return cents;
  }
  function rule(input={}) {
    if (!input || typeof input!=='object' || Array.isArray(input)) fail('RULE_INVALID');
    const defaults={enabled:false,currency:'EUR',base_amount_minor:500,seconds_per_base:600,mode:'proportional',rounding:'floor_per_tip'};
    if (Object.keys(input).some(k=>!Object.hasOwn(defaults,k))) fail('RULE_UNKNOWN_FIELD');
    const r={...defaults,...input};
    if (typeof r.enabled!=='boolean' || r.currency!=='EUR' || r.mode!=='proportional' || r.rounding!=='floor_per_tip' ||
        !Number.isSafeInteger(r.base_amount_minor) || r.base_amount_minor<1 || r.base_amount_minor>MAX_AMOUNT_MINOR ||
        !Number.isSafeInteger(r.seconds_per_base) || r.seconds_per_base<0 || r.seconds_per_base>MAX_SECONDS) fail('RULE_INVALID');
    return Object.freeze(r);
  }
  function seconds(amountMinor, currency='EUR', input={}) {
    const r=rule(input);
    if (!Number.isSafeInteger(amountMinor)||amountMinor<1||amountMinor>MAX_AMOUNT_MINOR) fail('AMOUNT_OUT_OF_BOUNDS');
    if (currency!==r.currency) fail('CURRENCY_NOT_SUPPORTED');
    if (!r.enabled) return 0;
    const n=BigInt(amountMinor)*BigInt(r.seconds_per_base)/BigInt(r.base_amount_minor);
    if(n>BigInt(MAX_SECONDS)) fail('CREDIT_EXCEEDS_720_HOURS');
    return Number(n);
  }
  root.RFDonationRule=Object.freeze({parseEuroAmount,rule,seconds});
})(globalThis);
