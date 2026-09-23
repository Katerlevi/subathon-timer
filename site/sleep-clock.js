/* Planned sleep display. Server snapshot + monotonic elapsed time; never mutates a timer. */
(function(root){
'use strict';
const anchors=new WeakMap(), MAX=2592000;
function readDuration(hours,minutes,seconds){
 const values=[hours,minutes,seconds].map(v=>{const s=String(v).trim();if(!/^(0|[1-9][0-9]*)$/.test(s))throw Error('Bitte ganze Stunden, Minuten und Sekunden eingeben.');const n=Number(s);if(!Number.isSafeInteger(n))throw Error('Ungültige Schlafzeit.');return n;});
 const [h,m,s]=values,total=h*3600+m*60+s;
 if(m>59||s>59||total<1||total>MAX)throw Error('Bitte 1 Sekunde bis 720 Stunden einstellen.');return total;
}
function accept(snapshot,receivedAt=root.performance.now()){
 if(snapshot&&typeof snapshot==='object'&&!anchors.has(snapshot))anchors.set(snapshot,receivedAt);return snapshot;
}
function duration(snapshot,at=root.performance.now()){
 if(!snapshot||snapshot.sleeping!==true)return {visible:false,state:'awake',text:''};
 const unknown={visible:true,state:'unknown',text:'Keine bestätigte Schlafplanung verfügbar',seconds:null};
 const n=snapshot.sleepDurationSeconds,start=snapshot.sleepStartedAt,server=snapshot.serverNow;
 if(snapshot.sleepPlanKnown!==true||![n,start,server].every(Number.isSafeInteger)||n<1||n>MAX||start<1||server<start||snapshot.sleepEndsAt!==start+n)return unknown;
 accept(snapshot,at);const received=anchors.get(snapshot);
 if(!Number.isFinite(at)||!Number.isFinite(received)||at<received)return unknown;
 const left=Math.max(0,Math.ceil(start+n-server-(at-received)/1000));
 const time=[Math.floor(left/3600),Math.floor(left%3600/60),left%60].map(v=>String(v).padStart(2,'0')).join(':');
 return {visible:true,state:left?'planned_sleep':'elapsed',seconds:left,time,text:left?`Schläft noch · ${time}`:'Geplante Schlafzeit beendet',stale:at-received>15000};
}
function render(snapshot,target,at=root.performance.now()){
 const el=typeof target==='string'?root.document.querySelector(target):target,r=duration(snapshot,at);if(!el)return r;
 el.hidden=!r.visible;el.textContent=r.text;el.dataset.state=r.state;el.dataset.stale=r.stale?'true':'false';return r;
}
root.RFSleepClock=Object.freeze({readDuration,accept,duration,render,maxSeconds:MAX});
})(globalThis);
