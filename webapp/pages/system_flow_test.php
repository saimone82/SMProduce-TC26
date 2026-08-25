<?php
require_once __DIR__ . '/../config/user_functions.php';
if (session_status() === PHP_SESSION_NONE) session_start();
requireLogin();

$role=strtolower((string)($_SESSION['role']??$_SESSION['user']['role']??''));
if (function_exists('userHasRole')) {
    if (!userHasRole('admin')) { http_response_code(403); exit('Admin only'); }
} elseif ($role!=='admin') { http_response_code(403); exit('Admin only'); }

include __DIR__ . '/../includes/header.php';
?>
<style>
.e2e{max-width:1200px;margin:auto;padding:20px}.e2e h1{font-weight:900}.muted{color:#64748b}
.top{display:flex;justify-content:space-between;gap:16px;align-items:flex-start;flex-wrap:wrap}
.runbox,.step,.resultbox{background:#fff;border:1px solid #dfe7ef;border-radius:16px;box-shadow:0 7px 22px rgba(15,23,42,.05)}
.runbox{padding:16px;margin:14px 0}.controls{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
.btnx{border:0;border-radius:10px;padding:10px 15px;font-weight:800;cursor:pointer}.primary{background:#2563eb;color:#fff}.green{background:#15803d;color:#fff}.red{background:#b91c1c;color:#fff}.neutral{background:#e2e8f0;color:#1e293b}
.printtoggle{display:inline-flex;align-items:center;gap:8px;font-weight:700}.runkey{font-family:monospace;font-weight:900;color:#2563eb}
.steps{display:grid;grid-template-columns:1fr;gap:10px}.step{padding:14px 16px;display:grid;grid-template-columns:52px 1fr auto;gap:13px;align-items:center}
.num{width:42px;height:42px;border-radius:50%;display:grid;place-items:center;background:#eef2ff;color:#4338ca;font-weight:900}
.stitle{font-weight:900;font-size:16px}.sdesc{font-size:12px;color:#64748b;margin-top:2px}.state{font-size:12px;font-weight:900;border-radius:999px;padding:5px 9px;background:#f1f5f9;color:#64748b}.state.ok{background:#dcfce7;color:#166534}.state.bad{background:#fee2e2;color:#991b1b}
.step button{min-width:125px}.step button:disabled{opacity:.4;cursor:not-allowed}.resultbox{margin-top:15px;padding:15px}.resultbox pre{background:#0f172a;color:#dbeafe;padding:12px;border-radius:10px;max-height:300px;overflow:auto;font-size:11px}
.summary{display:grid;grid-template-columns:repeat(5,1fr);gap:8px;margin-top:12px}.kpi{background:#f8fafc;border:1px solid #e2e8f0;border-radius:11px;padding:10px;text-align:center}.kv{font-size:20px;font-weight:900}.kl{font-size:10px;color:#64748b;text-transform:uppercase}
.links{display:flex;gap:8px;flex-wrap:wrap;margin-top:8px}.links a{padding:8px 10px;background:#e0f2fe;border-radius:8px;text-decoration:none;font-weight:800;font-size:12px}
@media(max-width:700px){.step{grid-template-columns:42px 1fr}.step>button{grid-column:1/-1;width:100%}.summary{grid-template-columns:repeat(2,1fr)}}
</style>
<div class="e2e">
 <div class="top">
  <div><h1>🧪 End-to-End Flow Test</h1><div class="muted">Order → Full Bins → Dump → CASE → Production → Pallet → Shipment → BOL</div></div>
  <a class="btnx neutral" href="/chooser.php">← Main Menu</a>
 </div>

 <div class="runbox">
  <div class="controls">
   <label class="printtoggle"><input type="checkbox" id="physical"> Physical Printing</label>
   <button class="btnx primary" onclick="startRun()">Start New Test Run</button>
   <span>Run: <span class="runkey" id="runKey">—</span></span>
   <button class="btnx neutral" id="refreshBtn" onclick="refreshStatus()" disabled>Refresh</button>
   <button class="btnx red" id="cleanupBtn" onclick="cleanup()" disabled>Delete Test Data</button>
  </div>
  <div class="muted" style="font-size:12px;margin-top:8px">Physical Printing OFF tests database, workflows and PDF/ZPL generation without intentionally sending labels/reports to printers.</div>
  <div class="summary">
   <div class="kpi"><div class="kv" id="kOrder">—</div><div class="kl">Order</div></div>
   <div class="kpi"><div class="kv" id="kBins">—</div><div class="kl">Full Bins</div></div>
   <div class="kpi"><div class="kv" id="kCases">—</div><div class="kl">Cases Scanned</div></div>
   <div class="kpi"><div class="kv" id="kPallet">—</div><div class="kl">Pallet</div></div>
   <div class="kpi"><div class="kv" id="kShip">—</div><div class="kl">Shipment</div></div>
  </div>
 </div>

 <div class="steps">
  <div class="step"><div class="num">1</div><div><div class="stitle">Create Test Order</div><div class="sdesc">Uses the same orders_create_sql() used by orders_add.php. Creates a PO with Dest City for BOL Consignee.</div><span class="state" id="st-create_order">WAITING</span></div><button class="btnx primary act" onclick="runStep('create_order',this)">Run</button></div>
  <div class="step"><div class="num">2</div><div><div class="stitle">Receive Full Bins</div><div class="sdesc">Creates two AVAILABLE full bins with test Grower / Variety / Lot.</div><span class="state" id="st-receive_bins">WAITING</span></div><button class="btnx primary act" onclick="runStep('receive_bins',this)">Run</button></div>
  <div class="step"><div class="num">3</div><div><div class="stitle">Dump Full Bins</div><div class="sdesc">Moves the test bins to DUMPED and writes movement log when available.</div><span class="state" id="st-dump_bins">WAITING</span></div><button class="btnx primary act" onclick="runStep('dump_bins',this)">Run</button></div>
  <div class="step"><div class="num">4</div><div><div class="stitle">Create CASE Barcodes</div><div class="sdesc">Creates four real Uxxxxxxx records in casecodes using the label engine.</div><span class="state" id="st-create_cases">WAITING</span></div><button class="btnx primary act" onclick="runStep('create_cases',this)">Run</button></div>
  <div class="step"><div class="num">5</div><div><div class="stitle">Production Scan + Duplicate Test</div><div class="sdesc">Runs the same pscan_ingest() used by Keyence/HID and confirms the duplicate is rejected.</div><span class="state" id="st-scan_cases">WAITING</span></div><button class="btnx primary act" onclick="runStep('scan_cases',this)">Run</button></div>
  <div class="step"><div class="num">6</div><div><div class="stitle">Create Pallet + Add CASES</div><div class="sdesc">Uses the real TC26 pallet backend and adds all four CASES.</div><span class="state" id="st-create_pallet">WAITING</span></div><button class="btnx primary act" onclick="runStep('create_pallet',this)">Run</button></div>
  <div class="step"><div class="num">7</div><div><div class="stitle">Partial Pallet</div><div class="sdesc">Marks the pallet PARTIAL. With Physical Printing ON it also exercises the configured pallet printer.</div><span class="state" id="st-partial_pallet">WAITING</span></div><button class="btnx primary act" onclick="runStep('partial_pallet',this)">Run</button></div>
  <div class="step"><div class="num">8</div><div><div class="stitle">Reopen Partial</div><div class="sdesc">Reopens the PARTIAL pallet for completion.</div><span class="state" id="st-reopen_partial">WAITING</span></div><button class="btnx primary act" onclick="runStep('reopen_partial',this)">Run</button></div>
  <div class="step"><div class="num">9</div><div><div class="stitle">Close Pallet + Report</div><div class="sdesc">Closes the pallet and generates the pallet PDF report when the installed report module is present.</div><span class="state" id="st-close_pallet">WAITING</span></div><button class="btnx primary act" onclick="runStep('close_pallet',this)">Run</button></div>
  <div class="step"><div class="num">10</div><div><div class="stitle">Create Shipment + Add Pallet</div><div class="sdesc">Creates a real shipment, connects the test PO, and adds the closed pallet.</div><span class="state" id="st-create_shipment">WAITING</span></div><button class="btnx primary act" onclick="runStep('create_shipment',this)">Run</button></div>
  <div class="step"><div class="num">11</div><div><div class="stitle">Close Shipment</div><div class="sdesc">Uses the TC26 shipping backend. Physical Printing ON also exercises the saved shipment-label printer.</div><span class="state" id="st-close_shipment">WAITING</span></div><button class="btnx primary act" onclick="runStep('close_shipment',this)">Run</button></div>
  <div class="step"><div class="num">12</div><div><div class="stitle">Verify / Review BOL</div><div class="sdesc">Checks shipment data, Dest City → Consignee default, pallet/case totals, then opens the real Desktop or TC26 BOL editor.</div><span class="state" id="st-verify_bol">WAITING</span><div class="links" id="bolLinks"></div></div><button class="btnx green act" onclick="runStep('verify_bol',this)">Verify</button></div>
 </div>

 <div class="resultbox">
  <strong>Last Result</strong>
  <pre id="out">Start a new test run.</pre>
 </div>
</div>
<script>
const API='api/system_flow_test_api.php';
let RUN='';

async function call(action,extra={}){
 const fd=new FormData(); fd.append('action',action);
 if(RUN)fd.append('run_key',RUN);
 Object.entries(extra).forEach(([k,v])=>fd.append(k,v));
 const r=await fetch(API,{method:'POST',body:fd,credentials:'same-origin',cache:'no-store'});
 const text=await r.text();
 try{return JSON.parse(text)}catch(e){throw new Error(text.slice(0,800)||'Invalid server response')}
}
function output(d){document.getElementById('out').textContent=JSON.stringify(d,null,2)}
function mark(id,ok,label){
 const e=document.getElementById('st-'+id);if(!e)return;
 e.textContent=label||(ok?'PASS':'FAIL');e.className='state '+(ok?'ok':'bad');
}
async function startRun(){
 try{
  const d=await call('start',{physical_printing:document.getElementById('physical').checked?'1':'0'});
  if(!d.ok)throw new Error(d.err||'Unable to start');
  RUN=d.run_key;document.getElementById('runKey').textContent=RUN;
  document.getElementById('refreshBtn').disabled=false;document.getElementById('cleanupBtn').disabled=false;
  output(d);await refreshStatus();
 }catch(e){alert(e.message)}
}
async function runStep(action,btn){
 if(!RUN){alert('Start a test run first.');return}
 btn.disabled=true;btn.textContent='Running…';
 try{
  const d=await call(action);output(d);
  mark(action,!!d.ok,d.ok?'PASS':'FAIL');
  if(action==='scan_cases' && d.duplicate_test){
    if(d.duplicate_test.duplicate!==true)mark(action,false,'DUPLICATE FAILED');
  }
  if(action==='verify_bol' && d.ok){
    const checks=d.checks||[];
    mark(action,checks.every(x=>x.ok),checks.every(x=>x.ok)?'PASS':'CHECK');
    const b=document.getElementById('bolLinks');b.innerHTML='';
    if(d.desktop_bol_url)b.innerHTML+=`<a target="_blank" href="${d.desktop_bol_url}">Desktop BOL Review</a>`;
    if(d.tc26_bol_url)b.innerHTML+=`<a target="_blank" href="${d.tc26_bol_url}">TC26 BOL Review</a>`;
  }
  await refreshStatus(false);
 }catch(e){mark(action,false);output({ok:false,error:e.message})}
 finally{btn.disabled=false;btn.textContent=action==='verify_bol'?'Verify':'Run'}
}
async function refreshStatus(show=true){
 if(!RUN)return;
 try{
  const d=await call('status');if(show)output(d);
  document.getElementById('kOrder').textContent=d.order?d.order.po:'—';
  document.getElementById('kBins').textContent=d.bins?`${d.bins.dumped}/${d.bins.total}`:'—';
  document.getElementById('kCases').textContent=(d.cases||[]).filter(x=>Number(x.scanned)).length+'/'+(d.cases||[]).length;
  document.getElementById('kPallet').textContent=d.pallet?(d.pallet.status+' · '+d.pallet.actual_cases):'—';
  document.getElementById('kShip').textContent=d.shipment?(d.shipment.status+' · '+d.shipment.actual_pallets):'—';
 }catch(e){if(show)output({ok:false,error:e.message})}
}
async function cleanup(){
 if(!RUN)return;
 if(!confirm('Delete ONLY the data tracked for '+RUN+'?'))return;
 try{
  const d=await call('cleanup');output(d);
  if(d.ok){document.querySelectorAll('.act').forEach(b=>b.disabled=true);document.getElementById('cleanupBtn').disabled=true;}
 }catch(e){output({ok:false,error:e.message})}
}
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
