/* Shared multi-streamer panel. No account names, provider tokens or destination IDs are embedded. */
(function(root){
  'use strict';
  let mounted=false, revision=0, mediaRevision=0, savedDonationConfig=null;
  const q=s=>document.querySelector(s);
  function message(text){q('#rfIntegrationMessage').textContent=text;}
  function base(){const u=new URL(window.SUBATHON_CONFIG?.apiBase||'/wp-json/royal-family-subathon/v1',location.origin);if(u.origin!==location.origin||u.search||u.hash)throw Error('API_ORIGIN');return u.href.replace(/\/$/,'');}
  async function request(path,method='GET',body=null){
    // Existing dashboard session, never a caller-supplied streamer_id or an OBS key.
    const token=sessionStorage.getItem('subathon_session');if(!token)throw Error('Bitte Twitch erneut verbinden.');
    const res=await fetch(base()+path,{method,headers:{Authorization:`Bearer ${token}`,'Content-Type':'application/json'},credentials:'same-origin',cache:'no-store',...(body===null?{}:{body:JSON.stringify(body)})});
    const data=await res.json().catch(()=>({}));if(!res.ok)throw Error(data.message||'Die Erweiterung ist noch nicht bereit.');return data;
  }
  function parseTipURL(value){
    if(typeof value!=='string'||value.length>300)throw Error('Ungültiger Donation-Link.');
    const s=value.trim();if(!s)return '';
    const m=s.match(/^https:\/\/streamelements\.com\/([A-Za-z0-9_]{3,25})\/tip\/?$/i);
    if(!m)throw Error('Bitte den öffentlichen StreamElements-Tipping-Link ohne zusätzliche Parameter eintragen. Andere Anbieter sind noch nicht angebunden.');
    return 'https://streamelements.com/'+m[1].toLowerCase()+'/tip';
  }
  function parseSeconds(value){if(!/^(0|[1-9]\d{0,4})([,.]\d{1,2})?$/.test(value.trim()))throw Error('Bitte gültige Minuten eingeben.');const [a,b='']=value.trim().replace(',','.').split('.');const n=Number(BigInt(a)*60n+(BigInt(b.padEnd(2,'0'))*60n)/100n);if(n>2592000)throw Error('Maximal 720 Stunden.');return n;}
  function extractClip(value){
    value=value.trim();if(/^[A-Za-z0-9_-]{1,100}$/.test(value))return value;
    const u=new URL(value);const rawPath=value.match(/^https:\/\/[^/?#]+([^?#]*)/)?.[1];if(rawPath!==u.pathname)throw Error('Ungültiger Twitch-Clip-Pfad.');if(u.protocol!=='https:'||u.username||u.password||u.port)throw Error('Nur offizielle Twitch-Clips.');
    let slug=null;if(u.hostname==='clips.twitch.tv')slug=u.pathname.slice(1);else if(u.hostname==='www.twitch.tv'||u.hostname==='twitch.tv')slug=u.pathname.match(/^\/[A-Za-z0-9_]+\/clip\/([A-Za-z0-9_-]+)$/)?.[1];
    if(!slug||['embed','create'].includes(slug.toLowerCase())||!/^[A-Za-z0-9_-]{1,100}$/.test(slug))throw Error('Ungültiger Twitch-Clip.');return slug;
  }
  function formatMinutes(seconds){if(!Number.isSafeInteger(seconds)||seconds<0||seconds>2592000)throw Error('Ungültige gespeicherte Zeit.');const c=(BigInt(seconds)*100n+59n)/60n;return `${c/100n},${String(c%100n).padStart(2,'0')}`;}
  function syncDonationVisibility(){
    const enabled=q('#rfDonationEnabled').checked;
    q('#rfDonationSettings').hidden=!enabled;
    q('#rfDonationOffNote').hidden=enabled;
  }
  function displayRule(r){
    revision=r.revision;
    savedDonationConfig={rule:{...r.rule},tip_url:r.tip_url||''};
    q('#rfDonationEnabled').checked=r.rule.enabled;
    q('#rfDonationBase').value=(r.rule.base_amount_minor/100).toFixed(2).replace('.',',');
    q('#rfDonationMinutes').value=formatMinutes(r.rule.seconds_per_base);
    q('#rfTipURL').value=r.tip_url||'';
    syncDonationVisibility();
  }
  function donationPayload(enabled=q('#rfDonationEnabled').checked,useSaved=false){
    if(useSaved&&savedDonationConfig){
      return {rule:RFDonationRule.rule({...savedDonationConfig.rule,enabled}),revision,tip_url:savedDonationConfig.tip_url};
    }
    const rule=RFDonationRule.rule({enabled,base_amount_minor:RFDonationRule.parseEuroAmount(q('#rfDonationBase').value),seconds_per_base:parseSeconds(q('#rfDonationMinutes').value)});
    return {rule,revision,tip_url:parseTipURL(q('#rfTipURL').value)};
  }
  async function saveDonationRule(enabled=q('#rfDonationEnabled').checked,useSaved=false){
    const r=await request('/donations/config','PUT',donationPayload(enabled,useSaved));displayRule(r);return r;
  }
  async function load(){
    const [r,s,m]=await Promise.all([request('/donations/config'),request('/donations/status'),request('/media/control')]);displayRule(r);mediaRevision=m.revision;
    q('#rfConnectSE').disabled=!s.oauth_application_configured;
    q('#rfSEStatus').textContent=s.account_connected?(s.connection_method==='personal_jwt'?'Dein StreamElements-Kanal ist direkt verbunden. Die Live-Zustellung ist noch separat zu prüfen.':'Dein StreamElements-Konto ist verbunden. Die Live-Zeitgutschrift ist noch separat zu bestätigen.'):!s.oauth_application_configured?'Zentrale OAuth-App noch nicht eingerichtet; die optionale Direktverbindung ist unten verfügbar.':'StreamElements noch nicht verbunden.';
    q('#rfCreditStatus').textContent=s.credits_operator_released?'Live-Empfang freigegeben; letzte echte Zustellung separat prüfen.':'Automatische Donations noch nicht für den Livebetrieb freigegeben.';
    q('#rfDonationPanelFields').disabled=false;
    q('#rfPersonalFields').disabled=s.personal_connection_supported!==true;
    // Read-only overlay capability stays only in the local field, never in logs.
    const overlay=q('#overlayUrl')?.value;if(overlay){const u=new URL(overlay);const key=new URLSearchParams(u.hash.slice(1)).get('key');if(key)q('#rfMediaURL').value=new URL('media.html',location.href).href+'#key='+encodeURIComponent(key);}
  }
  async function busy(button,fn){if(button)button.disabled=true;try{await fn();}catch(e){message(e.message);}finally{if(button)button.disabled=false;}}
  function mount(){
    const dash=q('#dashboard');if(mounted||!dash||dash.hidden)return;mounted=true;
    const panel=document.createElement('section');panel.id='rfIntegrations';panel.className='panel rf-integrations';
    panel.innerHTML=`<div class="rf-tools-head"><div><p class="eyebrow">ROYAL FAMILY TOOLS</p><h2>Verbindungen & Medien</h2></div><p>Diese Einstellungen gelten ausschließlich für deinen angemeldeten Twitch-Kanal.</p></div>
      <section class="rf-tool-card rf-donation-card" aria-labelledby="rfDonationTitle"><fieldset id="rfDonationPanelFields" disabled>
      <div class="rf-donation-toggle"><div class="rf-donation-title"><span class="rf-tool-icon" aria-hidden="true">€</span><div class="rf-donation-copy"><p class="eyebrow">DONATION-TRACKER</p><h3 id="rfDonationTitle">Support über StreamElements</h3><p>Donations automatisch in zusätzliche Streamzeit umrechnen.</p></div></div><label class="switch rf-donation-switch" aria-label="Donations aktivieren"><input id="rfDonationEnabled" type="checkbox"><span></span></label></div>
      <p class="rf-donation-off-note" id="rfDonationOffNote">Aktiviere Donations, um Verbindung, Zeitregel und Testberechnung einzurichten.</p>
      <div class="rf-donation-settings" id="rfDonationSettings" hidden>
      <div class="rf-status-card"><p id="rfSEStatus" role="status">StreamElements-Verbindung wird geprüft …</p><p id="rfCreditStatus" role="status">Live-Zustellung noch nicht geprüft.</p></div>
      <div class="rf-provider-actions"><button id="rfConnectSE" type="button" class="secondary-button" disabled>StreamElements verbinden</button><button id="rfDisconnectSE" type="button" class="ghost-button">Nur StreamElements trennen</button></div>
      <details id="rfPersonalAccess"><summary>Alternativ: eigenen StreamElements-Kanal direkt verbinden</summary>
      <p>Offizieller persönlicher Token-Zugang ohne zentrale OAuth-App. Ein persönlicher JWT kann weitergehende Kontorechte besitzen als die für diesen Timer nötigen Leserechte. OAuth bleibt die bevorzugte Verbindung mit begrenzten Berechtigungen, sobald unsere App eingerichtet ist.</p>
      <p>Den JWT ausschließlich aus deinem eigenen StreamElements-Konto für den passenden Twitch-Kanal übernehmen. Kein Overlay-Token und keine Anmeldedaten eines anderen Kontos. Niemals hierüber im Chat oder in OBS teilen. Der Server prüft die Kanalzuordnung über StreamElements und speichert den Token verschlüsselt.</p>
      <form id="rfPersonalForm"><fieldset id="rfPersonalFields" disabled><label>Persönlicher StreamElements-JWT<input id="rfPersonalToken" type="password" maxlength="8192" autocomplete="new-password" spellcheck="false" required></label><label><input id="rfPersonalConsent" type="checkbox" required> Ich erlaube Royal Family, diesen persönlichen Token für meinen eigenen Kanal serverseitig zu verwenden, und verstehe die möglicherweise weitergehenden Tokenrechte.</label><button type="submit" class="secondary-button">Eigenen Kanal sicher verbinden</button></fieldset></form>
      <p>Die Direktverbindung gilt lokal maximal 30 Tage; eine Anbieterfrist kann sie weiter begrenzen. Bei Ablauf oder Widerruf ist eine erneute Verbindung erforderlich. Lokales Trennen löscht die gespeicherte Verbindung, widerruft aber nicht den Token beim Anbieter. Eine Kontoverbindung bestätigt noch keine funktionierende Ereigniszustellung.</p></details>
      <form id="rfDonationForm"><label>Dein öffentlicher StreamElements-Tipping-Link<input id="rfTipURL" type="url" maxlength="300" placeholder="https://streamelements.com/deinkanal/tip" autocomplete="off"></label><p class="rf-help">Der Link wird als Einrichtungshinweis gespeichert. Er verbindet kein Konto und gibt allein keine Zahlungsdaten frei.</p>
      <div class="rf-integration-grid"><label>Bezugsbetrag in Euro<input id="rfDonationBase" inputmode="decimal" value="5,00" required></label><label>Minuten je Bezugsbetrag<input id="rfDonationMinutes" inputmode="decimal" value="10" required></label></div>
      <p class="rf-help">Anteilig in Cents; Bruchteile einer Sekunde werden je Donation abgerundet. Andere Währungen werden nicht umgerechnet.</p><div class="rf-form-actions"><button class="primary-button" type="submit">Donation-Regel speichern</button></div></form>
      <div class="rf-preview-card"><label>Testbetrag in Euro<input id="rfDonationPreview" inputmode="decimal" value="7,50"></label><button id="rfPreviewButton" class="secondary-button" type="button">Berechnen — ohne Zeitgutschrift</button><output id="rfDonationResult"></output></div>
      </div></fieldset></section>
      <section class="rf-tool-card rf-media-card"><details><summary>Optional: eigene Clip-Browserquelle</summary><p>Nur von dir ausgewählte Twitch-Clips. Der Wechsel erfolgt nach deiner eingestellten Anzeigedauer, nicht nach einem behaupteten Player-Endsignal.</p><form id="rfMediaForm"><label>Twitch-Clip oder Clip-ID<input id="rfClip" type="text" maxlength="300" required></label><label>Anzeigedauer in Sekunden<input id="rfClipDuration" type="number" min="1" max="300" step="1" value="60" required></label><label><input id="rfClipMuted" type="checkbox" checked> Stumm starten</label><button type="submit" class="secondary-button">Clip anzeigen</button> <button type="button" id="rfStopClip" class="ghost-button">Clip stoppen</button></form><label>OBS-Link — nur lesend, mindestens 400 × 300<input id="rfMediaURL" readonly></label><p>Ein blockierter Player beeinflusst weder Timer noch Donations. Für Ton ggf. OBS-Interaktion erforderlich.</p></details></section><p id="rfIntegrationMessage" role="status"></p>`;
    dash.append(panel);
    q('#rfPersonalForm').addEventListener('submit',ev=>{ev.preventDefault();busy(ev.submitter,async()=>{
      if(location.protocol!=='https:')throw Error('Die Direktverbindung ist nur über HTTPS erlaubt.');
      if(!q('#rfPersonalConsent').checked)throw Error('Bitte die bewusste Zustimmung zum persönlichen Token-Zugang bestätigen.');
      let token=q('#rfPersonalToken').value.trim();q('#rfPersonalToken').value='';q('#rfPersonalConsent').checked=false;
      try{await request('/providers/streamelements/personal/connect','POST',{token,consent:true});await load();message('Kanalzuordnung von StreamElements bestätigt. Automatische Gutschriften bleiben separat zu prüfen.');}
      finally{token='';q('#rfPersonalToken').value='';}
    });});
    q('#rfDonationEnabled').addEventListener('change',ev=>{
      const toggle=ev.currentTarget;syncDonationVisibility();if(toggle.checked){message('Donation-Einstellungen geöffnet. Speichere die Regel nach deiner Einrichtung.');return;}
      busy(toggle,async()=>{try{await saveDonationRule(false,true);message('Donation-Tracker deaktiviert.');}catch(error){toggle.checked=true;syncDonationVisibility();throw error;}});
    });
    q('#rfDonationForm').addEventListener('submit',ev=>{ev.preventDefault();busy(ev.submitter,async()=>{await saveDonationRule();message('Deine Donation-Regel wurde serverseitig gespeichert.');});});
    q('#rfPreviewButton').addEventListener('click',ev=>busy(ev.currentTarget,async()=>{const r=await request('/donations/preview','POST',{amount_minor:RFDonationRule.parseEuroAmount(q('#rfDonationPreview').value),currency:'EUR'});q('#rfDonationResult').textContent=`${Math.floor(r.seconds/60)} Min. ${r.seconds%60} Sek. — reine Vorschau der gespeicherten Regel.`;}));
    q('#rfConnectSE').addEventListener('click',ev=>busy(ev.currentTarget,async()=>{const r=await request('/providers/streamelements/oauth/start','POST',{});const u=new URL(r.authorize_url);if(u.origin!=='https://api.streamelements.com'||u.pathname!=='/oauth2/authorize')throw Error('Unerwartete Anmeldeadresse.');location.assign(u.href);}));
    q('#rfDisconnectSE').addEventListener('click',ev=>{if(!confirm('StreamElements für deinen Timer trennen und Donation-Regel deaktivieren?'))return;busy(ev.currentTarget,async()=>{await request('/providers/streamelements/disconnect','POST',{});await load();message('Lokale StreamElements-Verbindung getrennt.');});});
    q('#rfMediaForm').addEventListener('submit',ev=>{ev.preventDefault();busy(ev.submitter,async()=>{const duration=Number(q('#rfClipDuration').value);if(!Number.isSafeInteger(duration)||duration<1||duration>300)throw Error('1 bis 300 Sekunden.');const r=await request('/media/control','PUT',{revision:mediaRevision,media:{kind:'twitch_clip',clip:extractClip(q('#rfClip').value),duration_seconds:duration,muted:q('#rfClipMuted').checked}});mediaRevision=r.revision;message('Clip-Anzeige für deinen Kanal gestartet.');});});
    q('#rfStopClip').addEventListener('click',ev=>busy(ev.currentTarget,async()=>{const r=await request('/media/control','PUT',{revision:mediaRevision,media:null});mediaRevision=r.revision;message('Clip gestoppt.');}));
    load().catch(e=>message('Erweiterung noch nicht verfügbar: '+e.message));
  }
  root.RFIntegrationHelpers=Object.freeze({parseSeconds,formatMinutes,extractClip,parseTipURL});
  if(root.document){mount();new MutationObserver(mount).observe(document.documentElement,{attributes:true,childList:true,subtree:true});}
})(globalThis);
