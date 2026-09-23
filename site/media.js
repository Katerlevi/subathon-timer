(function(root){'use strict';
function activeMedia(payload,nowSeconds){
  if(!payload||!Number.isSafeInteger(payload.revision)||payload.revision<0||!payload.media)return null;
  const m=payload.media;
  if(m.kind!=='twitch_clip'||typeof m.clip!=='string'||!/^[A-Za-z0-9_-]{1,100}$/.test(m.clip)||!Number.isSafeInteger(m.duration_seconds)||m.duration_seconds<1||m.duration_seconds>300||!Number.isSafeInteger(m.starts_at)||typeof m.muted!=='boolean'||!Number.isFinite(nowSeconds))return null;
  if(nowSeconds<m.starts_at||nowSeconds>=m.starts_at+m.duration_seconds)return null;
  return {...m,revision:payload.revision};
}
function embed(clip,parent,muted){if(!/^[A-Za-z0-9_-]{1,100}$/.test(clip)||!/^[A-Za-z0-9.-]+$/.test(parent))throw Error('INVALID_CLIP');const u=new URL('https://clips.twitch.tv/embed');u.search=new URLSearchParams({clip,parent,autoplay:'true',muted:String(muted)});return u.href;}
root.RFMedia=Object.freeze({activeMedia,embed});if(!root.document)return;
const container=document.querySelector('#rfMedia'),status=document.querySelector('#rfMediaStatus');const key=new URLSearchParams(location.hash.slice(1)).get('key');let current=null,timer=null,offset=0,lastOK=0;
function clear(){container.replaceChildren();container.hidden=true;current=null;clearTimeout(timer);}
async function poll(){try{
  if(!key||!/^[A-Za-z0-9_-]{40,60}$/.test(key))throw Error('Ungültiger Clip-Overlay-Link.');
  const api=new URL(window.SUBATHON_CONFIG?.apiBase||'/wp-json/royal-family-subathon/v1',location.origin);if(api.origin!==location.origin)throw Error('Ungültige API-Adresse.');
  const res=await fetch(api.href.replace(/\/$/,'')+'/media/state',{headers:{'X-RFS-Overlay-Key':key},credentials:'omit',cache:'no-store'});if(!res.ok)throw Error('Clip-Verbindung nicht verfügbar.');
  const p=await res.json();if(!Number.isSafeInteger(p.server_now))throw Error('Ungültige Serverzeit.');offset=p.server_now-Date.now()/1000;lastOK=Date.now();
  const m=activeMedia(p,Date.now()/1000+offset);status.textContent='';
  if(!m){clear();return;}if(current===m.revision)return;
  clear();const frame=document.createElement('iframe');frame.src=embed(m.clip,location.hostname,m.muted);frame.title='Vom Streamer ausgewählter Twitch-Clip';frame.allow='autoplay; fullscreen';frame.referrerPolicy='no-referrer';
  container.append(frame);container.hidden=false;current=m.revision;timer=setTimeout(clear,Math.max(0,(m.starts_at+m.duration_seconds-(Date.now()/1000+offset))*1000));
}catch(e){status.textContent=e.message;if(Date.now()-lastOK>10000)clear();}}
poll();setInterval(poll,3000);
})(globalThis);
