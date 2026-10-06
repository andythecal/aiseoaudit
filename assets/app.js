'use strict';
const $ = (selector) => document.querySelector(selector);
const escapeHtml = (s) => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
let csrf = '', currentUser = null, currentReport = null, authMode = 'login', registrationEnabled = true, historyCursor = null, activeFilter = 'all', busy = false, allowance = null, pendingMemberAction = null;
let professionalRoles={}, adminView='activity', adminCursor=null, adminRequest=0;
let browserAudits={count:0,shouldPrompt:false}, accountCursor=null, accountRequest=0, accountData=null, importResolve=null;
const statusLabel = {pass:'Pass',warn:'Review',fail:'Fix'};
const statusIcon = {pass:'✓',warn:'!',fail:'×'};
async function api(action, data, query = '') {
  const options = {credentials:'same-origin',headers:{Accept:'application/json'}};
  if (data !== undefined) { options.method='POST'; options.headers['Content-Type']='application/json'; options.headers['X-CSRF-Token']=csrf; options.body=JSON.stringify(data); }
  const response = await fetch('api.php?action='+encodeURIComponent(action)+query,options);
  let result; try { result=await response.json(); } catch (_) { throw new Error('The server returned an unexpected response. Please try again.'); }
  if (result.browserAudits) browserAudits=result.browserAudits;
  if (result.allowance) { allowance=result.allowance; renderAllowance(); }
  if (!response.ok) { const error=new Error(result.error || 'The request could not complete.');error.code=result.code;throw error; }
  return result;
}
function showError(selector, message) { const el=$(selector); el.textContent=message; el.hidden=!message; }
let toastTimer;
function toast(message) { $('#toast').textContent=message; $('#toast').hidden=false; clearTimeout(toastTimer); toastTimer=setTimeout(()=>{$('#toast').hidden=true;},4200); }
function userUI() { renderAllowance(); $('#account-button').textContent=currentUser ? 'My account' : 'Sign in'; $('#logout-button').hidden=!currentUser; $('#admin-button').hidden=!Number(currentUser?.is_super_admin); $('#history-button').textContent=currentUser?'My saved audits':'Browser history'; }
function chip(status) { return '<span class="chip '+status+'">'+statusLabel[status]+'</span>'; }
function dateLabel(value) { const date = new Date(value.replace(' ','T').replace(/Z?$/, 'Z')); return isNaN(date.getTime()) ? value : date.toLocaleString(undefined,{dateStyle:'medium',timeStyle:'short'}); }
function showInfo(title,html) { $('#info-title').textContent=title; $('#info-content').innerHTML=html; $('#info-dialog').showModal(); }
function openAccount(mode='login',intent=null) {
  pendingMemberAction=intent;
  if (currentUser) { openMyAccount(); return; }
  authMode=mode; renderAuth(); $('#account-dialog').showModal();
}
function renderAuth() {
  const registering=authMode==='register';
  $('#professional-role-label').hidden=!registering;$('#professional-role').required=registering;$('#registration-research-note').hidden=!registering;
  $('#auth-title').textContent=registering ? 'Keep the bigger picture.' : 'Welcome back.';
  $('#auth-description').textContent=registering ? 'Create an account to keep your audit history across devices.' : 'Sign in to revisit your audits on any device.';
  $('#auth-submit').textContent=registering ? 'Create account' : 'Sign in';
  $('#auth-switch-text').textContent=registering ? 'Already have an account?' : 'New here?';
  $('#auth-toggle').textContent=registering ? 'Sign in' : 'Create an account';
  $('#auth-toggle').hidden=!registrationEnabled && !registering;
  $('#consent-label').hidden=!registering; $('#password').autocomplete=registering ? 'new-password' : 'current-password';
  if (registering && pendingMemberAction?.kind==='pdf') $('#auth-title').textContent='Register to get PDF audits';
  if (registering && pendingMemberAction?.kind==='accessibility') $('#auth-title').textContent='Register for accessibility details';
  if (registering) $('#auth-description').textContent='Free registration: 100 basic audits, 3 in-depth audits per day, PDF downloads and detailed accessibility checks. No payment details required.';
  showError('#auth-error','');
}
function snapshot(r) {
  let html='<section class="card snapshot" aria-label="HTML x-ray snapshot"><div class="browser-bar"><span class="browser-dots"><i></i><i></i><i></i></span><span class="browser-url">'+escapeHtml(r.finalUrl)+'</span><span>HTML X-RAY</span></div><div class="snapshot-body"><div class="snapshot-meta">';
  for(const [key,value] of Object.entries(r.metadata || {})) html+='<div><b>'+escapeHtml(key)+'</b> · '+escapeHtml(value || 'not declared')+'</div>';
  html+='</div>';
  for(const block of r.blocks || []) {
    const type=/^(h[1-6]|p|nav|img)$/.test(block.type) ? block.type : 'p';
    html+='<div class="snapshot-block '+type+'"><span class="tag">'+type.toUpperCase()+'</span><span>'+escapeHtml(block.text || (type==='img' ? 'Image without alt text' : '(empty)'))+'</span></div>';
  }
  return html+'<div class="scan-line"></div></div></section>';
}
function fileCard(title,f) {
  if (!f) return '';
  return '<article class="card aux-card"><h3>'+title+chip(f.statusLabel)+'</h3><p class="file-url">'+escapeHtml(f.url)+'</p><div class="file-meta">HTTP '+(f.status || 'unavailable')+' · '+Number(f.contentLength).toLocaleString()+' bytes<br>'+escapeHtml(f.contentType || 'Content type unavailable')+'</div><ul>'+f.notes.map(n=>'<li>'+escapeHtml(n)+'</li>').join('')+'</ul></article>';
}
function renderReport(r, animate=false) {
  showAuditWorkspace(false); currentReport=r; activeFilter='all'; $('#empty-state').hidden=true;
  const host = (()=>{try{return new URL(r.finalUrl).hostname;}catch(_){return r.finalUrl;}})();
  let html='<div class="report-top"><div><h2>Your page, under the lens.</h2><p><span class="saved-label">✓ Saved to '+(r.savedTo==='account' ? 'your account' : 'this browser’s history')+'</span> · '+escapeHtml(dateLabel(r.createdAt))+'</p></div><div class="report-actions"><button class="button small secondary" data-report-action="rerun">↻ Run again</button>';
  if (!r.locked) html+='<a class="button small secondary" href="api.php?action=export&amp;id='+encodeURIComponent(r.id)+'">Export JSON</a><button class="button small secondary" data-report-action="pdf">Download PDF</button>';
  if(currentUser && r.savedTo==='browser') html+='<button class="button small secondary" data-report-action="save-browser">Save to My Account</button>';
  html+='</div></div>';
  if (!r.locked && !r.incomplete) html+=snapshot(r);
  const summary=r.incomplete ? 'We couldn’t complete this scan.' : (r.checks || []).some(c=>c.category==='Crawlability'&&c.status==='fail') ? 'Crawlability needs urgent attention.' : r.score>=80 ? 'A strong foundation. A few opportunities.' : r.score>=50 ? 'Good groundwork. Room to grow.' : 'Start with the foundations.';
  html+='<div class="report-content"><div class="summary-grid"><div class="card score-card"><div class="gauge'+(typeof r.score==='number' && r.score<40 ? ' gauge-low' : '')+'"><svg viewBox="0 0 120 120" aria-hidden="true"><circle cx="60" cy="60" r="52"/><circle class="progress" cx="60" cy="60" r="52" style="stroke-dashoffset:'+(327-327*(r.score || 0)/100)+'"/></svg><div class="gauge-value"><b>'+ (r.score===null ? '—' : r.score)+'</b><span>'+(r.score===null ? 'not scored' : 'SEO / 100')+'</span></div></div><div class="score-legend"><h3>Audit results</h3><p class="pass"><i class="status-dot"></i>'+r.passed+' passed</p><p class="fail"><i class="status-dot"></i>'+r.failed+' to fix</p><p class="warn"><i class="status-dot"></i>'+r.warned+' to review</p></div></div><div class="card summary-card"><h2>'+summary+'</h2><p>'+escapeHtml(r.finalUrl)+'</p><div class="summary-facts"><span>Response<b>'+(r.httpStatus ? 'HTTP '+r.httpStatus : 'Unavailable')+'</b></span><span>Content words<b>'+Number(r.wordCount).toLocaleString()+'</b></span><span>GEO indicators<b>'+(r.geoScore===null ? '—' : r.geoScore+'/100')+'</b></span></div></div></div>';
  if (r.locked) {
    html+='<section class="card gate"><h2>Your detailed findings are saved.</h2><p>Sign in'+(registrationEnabled ? ' or create a free account' : '')+' to explore your recommendations, heading structure and crawlability checks.</p><button class="button primary" data-report-action="unlock">View full report →</button></section>';
  } else {
    html+=accessibilitySection(r);
    if (!r.incomplete) html+=memberPanel(r);
    const priorities=(r.checks || []).filter(c=>c.status!=='pass').sort((a,b)=>((a.status==='fail'?0:2)+(a.category==='Crawlability'?0:1))-((b.status==='fail'?0:2)+(b.category==='Crawlability'?0:1))).slice(0,3);
    if (priorities.length) html+='<section class="card priority"><h2>'+(r.incomplete ? 'What happened' : 'Where to start')+'</h2><div class="priority-list">'+priorities.map(c=>'<div class="priority-item"><b>'+escapeHtml(c.title)+'</b><p>'+escapeHtml(c.recommendation || c.detail)+'</p></div>').join('')+'</div></section>';
    html+=crawlabilitySection(r);
    if (!r.incomplete) {
      html+='<div class="section-heading"><h2>Heading structure</h2><p>'+(r.headingCount || 0)+' headings · '+r.headingIssues.length+' issue(s)</p></div><section class="card"><div class="heading-tree">';
      for (const h of r.headings) html+='<div class="heading-row '+(h.issue?'issue':'')+'" style="padding-left:'+((h.level-1)*17)+'px"><span class="tag">H'+h.level+'</span><span>'+escapeHtml(h.text || '(empty)')+(h.issue?' ⚠':'')+'</span></div>';
      if (!r.headings.length) html+='<p>No heading tags found.</p>';
      html+='</div>';
      if ((r.headingCount || 0)>500) html+='<p class="field-hint">Showing the first 500 headings.</p>';
      if (r.headingIssues.length) html+='<div class="heading-issues">'+r.headingIssues.map(i=>'<div>'+escapeHtml(i.message)+'</div>').join('')+'</div>';
      html+='</section>';
    }
    if (r.advanced?.result) html+=imageInventory(r.advanced.result.images);
    html+='<div class="section-heading"><h2>The complete checklist</h2><div class="filters" aria-label="Filter findings">'+[['all','All findings'],['fail','To fix'],['warn','To review'],['pass','Passed']].map(([key,label])=>'<button class="filter '+(key==='all'?'active':'')+'" data-filter="'+key+'" aria-pressed="'+(key==='all')+'">'+label+'</button>').join('')+'</div></div><div id="checks"></div>';
  }
  html+='<p class="report-footnote">' + escapeHtml(scoringNote(r)) + ' GEO indicators are scored separately. These are page-level heuristics, not ranking guarantees. Engine '+escapeHtml(r.engineVersion)+'.</p></div>';
  $('#report').innerHTML=html; $('#report').hidden=false;
  if (!r.locked) renderChecks();
  history.replaceState(null,'',location.pathname+'?audit='+encodeURIComponent(r.id));
  document.title='Audit: '+host+' · X-Ray SEO';
  if (animate && !r.locked && !r.incomplete && !matchMedia('(prefers-reduced-motion: reduce)').matches) {
    $('#report').classList.add('scanning');
    setTimeout(()=>{$('#report').classList.remove('scanning');toast('Your audit is complete and saved.');},2400);
  } else $('#report').classList.remove('scanning');
}
function checkRow(c) { const extra=currentReport?.advanced?.result?.guidance?.checks?.[c.id]; return '<details class="check-row"><summary><span class="check-symbol '+c.status+'" aria-hidden="true">'+statusIcon[c.status]+'</span><span class="check-copy"><span class="check-title"><strong>'+escapeHtml(c.title)+'</strong>'+chip(c.status)+'</span><span class="check-detail">'+escapeHtml(c.detail)+'</span></span><span class="chevron" aria-hidden="true">›</span></summary><div class="check-expanded"><div><h4>Finding</h4><p>'+escapeHtml(c.detail)+'</p></div><div><h4>'+(c.status==='pass'?'What this means':'Recommendation')+'</h4><p>'+escapeHtml(c.recommendation || 'This check meets the tool’s criteria. Review the finding in the context of the page’s purpose.')+'</p></div></div>'+guidanceHtml(extra)+'</details>'; }
function crawlabilitySection(r) {
  const checks=(r.checks || []).filter(c=>c.category==='Crawlability');
  if (!checks.length && !r.robotsTxt && !r.sitemapXml) return '';
  const urgent=checks.some(c=>c.status==='fail');
  let html='<section class="crawlability-section'+(urgent?' urgent':'')+'" aria-labelledby="crawlability-title"><div class="section-heading"><h2 id="crawlability-title">Crawlability</h2>';
  html+=urgent?'<span class="urgent-label"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3 2 21h20L12 3Z"/><path d="M12 9v5m0 3v1"/></svg>Needs urgent attention</span>':'<p>Discovery starts here.</p>';
  html+='</div>';
  if (urgent) html+='<p class="crawlability-warning">Resolve these failures first if this page is intended to appear in search. Indexing restrictions and crawling problems can outweigh otherwise good on-page SEO.</p>';
  const guide=r.advanced?.result?.guidance?.sections?.Crawlability;
  if (guide) html+='<p class="section-guide">'+escapeHtml(guide.why)+'</p>';
  const contentIssue=checks.find(c=>c.id==='content-accessibility');
  if (contentIssue) html+='<aside class="content-accessibility-note"><strong>Looks complete in your browser, but almost empty to this scan?</strong><p>'+escapeHtml(contentIssue.recommendation)+'</p></aside>';
  if (r.scoreCap != null) html+='<p class="crawlability-cap">A critical crawlability failure limits the SEO score to '+r.scoreCap+'/100. '+escapeHtml((r.scoreCapReasons || []).join(' '))+'</p>';
  if (r.robotsTxt || r.sitemapXml) html+='<div class="aux-grid">'+fileCard('robots.txt',r.robotsTxt)+fileCard('XML sitemap',r.sitemapXml)+'</div>';
  if (r.advanced?.result) html+=sitemapDetails(r.advanced.result.sitemap);
  return html+'<div class="crawlability-checks">'+checks.map(checkRow).join('')+'</div></section>';
}
function accessibilitySection(r) {
  const a=r.accessibility || {status:'not_checked',summary:'This saved audit predates accessibility checks. Run a new audit to check this page.'};
  const status=['pass','fail'].includes(a.status)?a.status:'not_checked';
  const label={pass:'Pass',fail:'Fail',not_checked:'Not checked'}[status];
  let html='<section class="card accessibility-section" aria-labelledby="accessibility-title"><div class="section-heading"><h2 id="accessibility-title">Accessibility &amp; ARIA</h2><span class="chip '+(status==='not_checked'?'warn':status)+'">'+label+'</span></div><p>'+escapeHtml(a.summary)+'</p><p class="field-hint">Automated HTML checks only. A pass means no definite failures were found by these checks; manual review may still be needed. This is not a WCAG conformance assessment and does not affect your SEO score.</p>';
  if (a.detailsLocked || !currentUser) {
    html+='<div class="accessibility-gate"><p>Get the affected elements, practical fixes and a manual testing checklist with a free account. Included in PDF reports. No in-depth credits needed.</p><button class="button primary" data-report-action="accessibility">'+(registrationEnabled?'Register for accessibility details':'Sign in for accessibility details')+'</button></div>';
  } else {
    if (a.counts) html+='<p class="accessibility-counts">'+Number(a.counts.fail)+' to fix &middot; '+Number(a.counts.warn)+' to review</p>';
    if (a.truncated) html+='<p class="crawlability-warning">This is a limited scan: at most 5,000 elements are checked and 150 findings stored. Review the full page manually.</p>';
    for (const f of a.findings || []) {
      const state=f.status==='fail'?'fail':'warn';
      html+='<details class="check-row"><summary><span class="check-symbol '+state+'" aria-hidden="true">'+statusIcon[state]+'</span><span class="check-copy"><span class="check-title"><strong>'+escapeHtml(f.title)+'</strong>'+chip(state)+'</span><span class="check-detail">'+escapeHtml(f.detail)+'</span></span><span class="chevron" aria-hidden="true">&rsaquo;</span></summary><div class="accessibility-evidence"><h3>Affected element</h3><p><code>'+escapeHtml(f.element)+'</code></p><pre><code>'+escapeHtml(f.snippet)+'</code></pre><h3>How to fix it</h3><p>'+escapeHtml(f.fix)+'</p><pre><code>'+escapeHtml(f.example)+'</code></pre><h3>Verify the fix</h3><p>'+escapeHtml(f.verify)+'</p></div></details>';
    }
    if (a.manualChecks?.length) html+='<details class="accessibility-manual"><summary>What to check in a browser</summary><ul>'+a.manualChecks.map(item=>'<li>'+escapeHtml(item)+'</li>').join('')+'</ul><p class="field-hint">'+escapeHtml(a.scope)+'</p></details>';
  }
  if (status==='not_checked') html+='<button class="button small secondary" data-report-action="rerun">Run a new audit</button>';
  return html+'</section>';
}
function scoringNote(r) {
  if (r.scoringVersion !== 'crawlability-weighted-v2') return 'Saved with the original scoring model: pass = 1, review = 0.5, fix = 0; equal SEO weights. Run again for stricter crawlability scoring.';
  return 'Crawlability checks carry 5x weight. Pass = 1, review = 0.5, fix = 0. Critical failures cap the score: noindex at 20, robots.txt failures at 35, page-level nofollow at 40. The lowest applicable cap wins.';
}
function renderChecks() {
  const visible=currentReport.checks.filter(c=>c.category!=='Crawlability'&&(activeFilter==='all'||c.status===activeFilter));
  let html='';
  for(const category of [...new Set(visible.map(c=>c.category))]) {
    html+='<h3 class="category-title">'+escapeHtml(category)+'</h3>';
    const guide=currentReport.advanced?.result?.guidance?.sections?.[category];
    if (guide) html+='<p class="section-guide">'+escapeHtml(guide.why)+'</p>';
    if (category==='GEO signals') html+='<p class="field-hint">Observable signals only. This does not test mentions, citations, AI rankings or crawler access for individual AI providers.</p>';
    html+=visible.filter(c=>c.category===category).map(checkRow).join('');
  }
  $('#checks').innerHTML=html || '<p class="card">No findings in this filter. Crawlability findings are shown in their own section above.</p>';
}
async function openReport(id) {
  try { const data=await api('report',undefined,'&id='+encodeURIComponent(id)); renderReport(data.report); if ($('#history-dialog').open) $('#history-dialog').close(); $('#report').scrollIntoView({behavior:'smooth',block:'start'}); }
  catch(e) { showError($('#my-account').hidden?'#error':'#account-error',e.message); }
}
async function loadHistory(more=false) {
  if (!more) { $('#history-list').innerHTML='<p>Loading your audits…</p>'; historyCursor=null; }
  $('#history-more').disabled=true;
  try {
    const data=await api('history',undefined,'&scope=browser'+(more&&historyCursor?'&before='+encodeURIComponent(historyCursor):''));
    if (!more) $('#history-list').innerHTML='';
    if (!data.audits.length && !more) $('#history-list').innerHTML='<p>No audits saved yet. Run your first scan to get started.</p>';
    for (const r of data.audits) {
      const row=document.createElement('div');row.className='history-row';
      row.innerHTML='<span class="history-score">'+(r.score===null?'—':r.score)+'</span><button class="history-open" data-audit-id="'+r.id+'"><b>'+escapeHtml(r.final_url || r.requested_url)+'</b><span>'+escapeHtml(dateLabel(r.created_at))+' · '+escapeHtml(r.state==='running'?'in progress or interrupted':r.state)+'</span></button><button class="delete-button" data-delete-id="'+r.id+'" aria-label="Delete audit of '+escapeHtml(r.requested_url)+'">Delete</button>';
      $('#history-list').append(row);
    }
    historyCursor=data.nextCursor; $('#history-more').hidden=!historyCursor;
  } catch(e) { $('#history-list').textContent=e.message; } finally { $('#history-more').disabled=false; }
}
function renderAllowance() {
  const el=$('#allowance-note');if(!el||!allowance)return;
  el.textContent=allowance.basic.remaining+' of '+allowance.basic.limit+' basic audits left today'+(allowance.deep.limit?' | '+allowance.deep.remaining+' of 3 in-depth audits left':' | Register for 100 basic + 3 in-depth audits daily')+'. Resets at midnight UK time. Basic attempts, including failed fetches, count.';
}
function memberPanel(r) {
  if(r.advanced?.state==='complete') return '<section class="card member-panel"><span class="member-kicker">IN-DEPTH REVIEW INCLUDED</span><h2>Your closer look is ready.</h2><p>'+escapeHtml(r.advanced.result.note)+'</p><p class="field-hint">Reviewed '+escapeHtml(dateLabel(r.advanced.result.checkedAt))+'. Reopening this review or downloading its PDF uses no credits.</p></section>';
  if(r.advanced?.state==='running') return '<section class="card member-panel"><h2>In-depth review in progress</h2><p>You can reopen this saved report shortly.</p><button class="button secondary" data-report-action="refresh-deep">Check progress</button></section>';
  return '<section class="card member-panel"><span class="member-kicker">GO BEYOND THE BASICS</span><h2>Find the details behind the score.</h2><p>Review suspicious sitemap URLs, identify images missing alt text, and get step-by-step explanations for each finding.</p>'+(r.advanced?.error?'<p class="error-message">'+escapeHtml(r.advanced.error)+'</p>':'')+'<button class="button primary" data-report-action="deepen">'+(currentUser?'Run in-depth audit - 1 credit':'Register for 3 in-depth audits a day')+'</button><p class="field-hint">'+(currentUser?'Uses one in-depth credit, not a basic credit. Failed page fetches return the in-depth credit.':'Free account. 100 basic audits and 3 in-depth audits each day, plus PDF downloads.')+'</p></section>';
}
function guidanceHtml(g) {
  if(!g)return '';
  return '<div class="advanced-guidance"><span class="member-kicker">IN-DEPTH GUIDANCE</span><p>'+escapeHtml(g.meaning)+'</p><h4>'+escapeHtml(g.priority)+'</h4><ol>'+g.steps.map(step=>'<li>'+escapeHtml(step)+'</li>').join('')+'</ol><p><strong>How to verify:</strong> '+escapeHtml(g.verify)+'</p></div>';
}
function sitemapDetails(m) {
  return '<div class="advanced-detail"><h3>In-depth sitemap review</h3><p>'+m.entriesReviewed+' entries reviewed across '+m.files.length+' sitemap files. '+(m.containsAuditedUrl?'The audited URL was found.':'The audited URL was not found in the reviewed entries.')+'</p><p class="field-hint">'+escapeHtml(m.limits)+(m.truncated?' This review is partial.':'')+'</p>'+m.notes.map(n=>'<p class="warn">'+escapeHtml(n)+'</p>').join('')+(m.findings.length?'<div class="inventory-scroll"><table class="inventory-table"><thead><tr><th>URL to review</th><th>Why it was flagged</th></tr></thead><tbody>'+m.findings.map(f=>'<tr><td>'+escapeHtml(f.url)+'</td><td>'+f.reasons.map(escapeHtml).join('<br>')+'</td></tr>').join('')+'</tbody></table></div>':'<p>No potential issues were flagged in the reviewed entries. This does not certify unreviewed URLs.</p>')+'</div>';
}
function imageInventory(images) {
  return '<section class="card image-inventory"><div class="section-heading"><h2>Image alternative text</h2><span class="member-kicker">IN-DEPTH</span></div><p>'+images.total+' images: <strong class="fail">'+images.missing+' missing alt</strong>, '+images.empty+' empty alt, '+images.present+' with alt text.</p><p class="field-hint">'+escapeHtml(images.note)+(images.truncated?' Showing the first 250 images.':'')+'</p><div class="filters"><button class="filter active" data-image-filter="all">All images</button><button class="filter" data-image-filter="missing">Missing alt</button><button class="filter" data-image-filter="empty">Empty alt</button></div><div class="inventory-scroll"><table class="inventory-table"><thead><tr><th>Image</th><th>Alt status</th><th>Current alt text</th></tr></thead><tbody>'+images.items.map(i=>'<tr data-image-status="'+i.status+'"><td><strong>Image '+i.position+'</strong><br>'+escapeHtml(i.sourceLabel)+'</td><td><span class="chip '+(i.status==='missing'?'fail':i.status==='empty'?'warn':'pass')+'">'+i.status+'</span></td><td>'+escapeHtml(i.alt||'(none)')+'</td></tr>').join('')+'</tbody></table></div></section>';
}
async function downloadPdf(id) {
  if(!currentUser){openAccount('register',{kind:'pdf',reportId:id});return;}
  toast('Preparing your PDF...');
  try{
    const res=await fetch('api.php?action=pdf&id='+encodeURIComponent(id),{credentials:'same-origin'});
    if(!res.ok){let data=await res.json();throw new Error(data.error||'PDF download failed.');}
    const blob=await res.blob();const url=URL.createObjectURL(blob);const a=document.createElement('a');a.href=url;a.download='xray-audit-'+id+'.pdf';a.click();setTimeout(()=>URL.revokeObjectURL(url),1000);toast('PDF ready.');
  }catch(e){toast(e.message);}
}
async function runDeep() {
  if(!currentUser){openAccount('register',{kind:'deep'});return;}
  if(currentReport.savedTo==='browser' && !(await askBrowserImport())) return;
  if(busy||!currentReport)return;
  if(!confirm('Use 1 of your 3 daily in-depth credits for this page? Reopening the result will be free.'))return;
  busy=true;$('#loading').hidden=false;$('#loading-title').textContent='Taking a closer look...';
  try{const data=await api('deepen',{id:currentReport.id});renderReport(data.report);toast('In-depth report saved.');}
  catch(e){toast(e.message);}
  finally{busy=false;$('#loading').hidden=true;$('#loading-title').textContent='Reading the page...';}
}


function showAuditWorkspace(resetUrl=true) {
  accountRequest++;adminRequest++;$('#admin-panel').hidden=true;
  $('#my-account').hidden=true;$('#audit-workspace').hidden=false;
  if(resetUrl){history.replaceState(null,'',location.pathname);document.title='On-Page SEO Audit - X-Ray SEO';}
}
function openBrowserHistory() {
  $('#history-note').textContent='Earlier audits linked to this browser. They are not in your account until you choose to save them.';
  $('#history-dialog').showModal();loadHistory();
}
function renderAccountSummary(account) {
  accountData=account;browserAudits=account.browserAudits;
  $('#account-email').textContent=account.email;
  $('#account-joined').textContent=account.plan+' | Member since '+dateLabel(account.createdAt);
  const basic=allowance?.basic, deep=allowance?.deep;
  const stat=(title,value,note)=>'<article class="card account-stat"><p>'+escapeHtml(title)+'</p><strong>'+escapeHtml(value)+'</strong><span>'+escapeHtml(note)+'</span></article>';
  $('#account-stats').innerHTML=stat('Saved audits',account.counts.total,account.counts.completed+' complete | '+account.counts.failed+' failed | '+account.counts.running+' in progress')+stat('Basic audits remaining',basic?.remaining??'-','Of '+(basic?.limit??100)+' today')+stat('In-depth audits remaining',deep?.remaining??'-','Of '+(deep?.limit??3)+' today');
  $('#account-import').hidden=browserAudits.count===0;
  $('#account-import-note').textContent=browserAudits.count+' earlier audit'+(browserAudits.count===1?' is':'s are')+' available in this browser.';
}
async function openMyAccount() {
  if(!currentUser){openAccount('login',{kind:'account'});return;}
  adminRequest++;$('#admin-panel').hidden=true;$('#audit-workspace').hidden=true;$('#my-account').hidden=false;
  history.replaceState(null,'',location.pathname+'?view=account');document.title='My Account - X-Ray SEO';
  $('#my-account-title').focus();$('#my-account').scrollIntoView({behavior:'smooth',block:'start'});
  await refreshMyAccount();
}
async function refreshMyAccount() {
  const userId=currentUser?.id;
  showError('#account-error','');
  try {
    const data=await api('account');
    if(!currentUser || currentUser.id!==userId || $('#my-account').hidden)return;
    renderAccountSummary(data.account);await loadAccountHistory();
  }catch(e){if(currentUser)showError('#account-error',e.message);}
}
function accountAuditRow(r) {
  const url=r.final_url||r.requested_url, id=escapeHtml(r.id);
  return '<article class="account-audit"><div class="account-audit-score '+(r.score!==null&&Number(r.score)<40?'low':'')+'"><strong>'+escapeHtml(r.score===null?'--':r.score)+'</strong><span>SEO / 100</span></div><div class="account-audit-info"><button class="account-audit-title" data-account-report="'+id+'">'+escapeHtml(url)+'</button><p>'+escapeHtml(dateLabel(r.created_at))+' &middot; '+escapeHtml({complete:'Complete',failed:'Failed',running:'In progress or interrupted'}[r.state]||r.state)+(Number(r.has_deep)?' &middot; In-depth review saved':'')+'</p></div><div class="account-audit-actions"><button class="button small secondary" data-account-report="'+id+'">View report</button><button class="text-button" data-account-pdf="'+id+'">PDF</button><button class="delete-button" data-account-delete="'+id+'" aria-label="Delete audit of '+escapeHtml(url)+'">Delete</button></div></article>';
}
async function loadAccountHistory(more=false) {
  if(!currentUser || (more&&!accountCursor))return;
  const request=++accountRequest;
  if(!more){accountCursor=null;$('#account-list').innerHTML='<p>Loading saved audits...</p>';}
  $('#account-more').disabled=true;showError('#account-error','');
  const query=new URLSearchParams({scope:'account',q:$('#account-search').value.trim(),state:$('#account-state').value});
  if(more)query.set('before',accountCursor);
  try {
    const data=await api('history',undefined,'&'+query.toString());
    if(request!==accountRequest || !currentUser)return;
    if(!more)$('#account-list').innerHTML='';
    if(!data.audits.length&&!more)$('#account-list').innerHTML='<div class="account-empty"><h3>No saved audits found</h3><p>'+(query.get('q')||query.get('state')!=='all'?'Try another search or clear your filters.':'Run an audit while signed in, or save your earlier browser history to this account.')+'</p></div>';
    $('#account-list').insertAdjacentHTML('beforeend',data.audits.map(accountAuditRow).join(''));
    $('#account-history-status').textContent=data.total+' saved audit'+(data.total===1?'':'s')+' matching this view. Newest first.';
    accountCursor=data.nextCursor;$('#account-more').hidden=!accountCursor;
  }catch(e){if(request===accountRequest){showError('#account-error',e.message);if(!more)$('#account-list').innerHTML='';}}
  finally{if(request===accountRequest)$('#account-more').disabled=false;}
}
async function refreshImportedReport() {
  if(!currentReport)return;
  try {
    const data=await api('report',undefined,'&id='+encodeURIComponent(currentReport.id));
    currentReport=data.report;
    if($('#my-account').hidden)renderReport(data.report);
  }catch(e){toast(e.message);}
}
async function askBrowserImport() {
  if(!currentUser)return false;
  if(importResolve)return false;
  try {
    const data=await api('account');browserAudits=data.account.browserAudits;
    if(!$('#my-account').hidden)renderAccountSummary(data.account);
  }catch(e){toast(e.message);return false;}
  if(!browserAudits.count){await refreshImportedReport();return currentReport?.savedTo==='account';}
  $('#browser-import-description').textContent='We found '+browserAudits.count+' earlier audit'+(browserAudits.count===1?'':'s')+' linked to this browser. Save '+(browserAudits.count===1?'it':'them')+' to '+currentUser.email+'?';
  $('#browser-import-confirm').textContent='Save all '+browserAudits.count+' audit'+(browserAudits.count===1?'':'s')+' to my account';
  showError('#browser-import-error','');
  return new Promise(resolve=>{importResolve=resolve;$('#browser-import-dialog').showModal();});
}
async function chooseBrowserImport(decision) {
  if($('#browser-import-confirm').disabled)return;
  $('#browser-import-confirm').disabled=true;$('#browser-import-decline').disabled=true;
  showError('#browser-import-error','');
  try {
    const data=await api('browser-audits',{decision});
    if(decision==='import')await refreshImportedReport();
    if(!$('#my-account').hidden)await refreshMyAccount();
    toast(decision==='import'?data.imported+' audit'+(data.imported===1?'':'s')+' saved to your account.':'Earlier audits have been kept in this browser history.');
    const resolve=importResolve;importResolve=null;$('#browser-import-dialog').close();if(resolve)resolve(decision==='import');
  }catch(e){showError('#browser-import-error',e.message);}
  finally{$('#browser-import-confirm').disabled=false;$('#browser-import-decline').disabled=false;}
}


function setAdminDates() {
  const today=new Date(),from=new Date(today);from.setUTCDate(today.getUTCDate()-29);
  $('#admin-from').value=from.toISOString().slice(0,10);$('#admin-to').value=today.toISOString().slice(0,10);
}
function adminQuery(more=false) {
  const q=new URLSearchParams({view:adminView,from:$('#admin-from').value,to:$('#admin-to').value,q:$('#admin-search').value.trim(),role:$('#admin-role').value||'all',state:$('#admin-state').value||'all',event:$('#admin-event').value||'all'});
  if(more&&adminCursor)q.set('before',adminCursor);return q.toString();
}
async function openAdmin() {
  if(!currentUser){openAccount('login',{kind:'admin'});return;}
  if(!Number(currentUser.is_super_admin)){toast('Super-admin access is required.');await openMyAccount();return;}
  accountRequest++;$('#audit-workspace').hidden=true;$('#my-account').hidden=true;$('#admin-panel').hidden=false;
  history.replaceState(null,'',location.pathname+'?view=admin');document.title='Research admin - X-Ray SEO';
  if(!$('#admin-from').value)setAdminDates();
  $('#admin-title').focus();$('#admin-panel').scrollIntoView({behavior:'smooth',block:'start'});await loadAdmin();
}
function adminTime(value) {return value?escapeHtml(String(value).replace('T',' ').replace(/Z$/,''))+' UTC':'Not recorded';}
function adminPerson(r) {return '<strong>'+escapeHtml(r.email||'No linked account')+'</strong><br><span class="field-hint">'+escapeHtml(professionalRoles[r.professional_role]||'Role not recorded')+(r.visitor_hash?' | Browser '+escapeHtml(r.visitor_hash.slice(0,12)):'')+'</span>';}
function adminClient(r) {
  return '<span>'+escapeHtml(r.connection_ip||'Not recorded')+'</span><br><span class="field-hint">'+escapeHtml([r.os_name,r.browser_name,r.device_type].filter(Boolean).join(' / ')||'Client details not recorded')+'</span><details><summary>Client details</summary><p>Language: '+escapeHtml(r.language||'Not recorded')+'</p><p class="admin-user-agent">'+escapeHtml(r.user_agent||'User-agent not recorded')+'</p></details>';
}
function adminRow(r) {
  if(adminView==='users')return '<tr><td>'+adminPerson(r)+(Number(r.is_super_admin)?'<p class="member-kicker">SUPER ADMIN</p>':'')+'<p class="field-hint">Marketing: '+(r.marketing_consent===null?'Not recorded':Number(r.marketing_consent)?'Opted in':'Not opted in')+'</p></td><td>'+adminTime(r.created_at)+'<p class="field-hint">Last recorded activity<br>'+adminTime(r.last_seen)+'</p></td><td>'+Number(r.audit_count)+' saved</td><td>'+adminClient(r)+'</td></tr>';
  const reportId=adminView==='audits'?r.id:r.audit_id;
  const report=reportId?'<button class="inline-button" data-admin-report="'+escapeHtml(reportId)+'">View saved report</button>':'';
  const url='<p class="admin-url">'+escapeHtml(r.page_url||'No page URL')+'</p>'+report;
  if(adminView==='audits')return '<tr><td>'+adminTime(r.created_at)+'<p class="field-hint">Completed<br>'+adminTime(r.completed_at)+'</p></td><td>'+url+'<p class="field-hint">'+escapeHtml(r.state)+' | SEO '+escapeHtml(r.score===null?'Not scored':r.score+'/100')+'</p></td><td>'+adminPerson(r)+'</td><td>'+adminClient(r)+'</td></tr>';
  return '<tr><td>'+adminTime(r.occurred_at)+'</td><td><strong>'+escapeHtml(r.event_type.replaceAll('_',' '))+'</strong>'+url+(r.metadata?'<details><summary>Event details</summary><pre>'+escapeHtml(JSON.stringify(r.metadata,null,2))+'</pre></details>':'')+'</td><td>'+adminPerson(r)+'</td><td>'+adminClient(r)+'</td></tr>';
}
function renderAdminOverview(o) {
  $('#admin-stats').innerHTML=Object.entries({registrations:'Registrations',audits:'Saved audit attempts',visitors:'Browser identities',events:'Activity events'}).map(([key,label])=>'<article class="card account-stat"><p>'+label+'</p><strong>'+Number(o.counts[key]).toLocaleString()+'</strong><span>'+escapeHtml(o.from)+' to '+escapeHtml(o.to)+'</span></article>').join('');
  $('#admin-overview').innerHTML='<details class="card admin-breakdown"><summary>Top checked sites and registration roles in this period</summary><div class="admin-breakdown-grid"><div><h3>Top 20 sites with saved audits</h3>'+(o.sites.length?'<ul>'+o.sites.map(s=>'<li>'+escapeHtml(s.site)+' <strong>'+Number(s.total)+'</strong></li>').join('')+'</ul>':'<p>No saved audits in this period.</p>')+'</div><div><h3>Roles at registration</h3>'+(o.roles.length?'<ul>'+o.roles.map(r=>'<li>'+escapeHtml(professionalRoles[r.professional_role]||'Not recorded')+' <strong>'+Number(r.total)+'</strong></li>').join('')+'</ul>':'<p>No registrations in this period.</p>')+'</div></div></details>';
}
async function loadAdmin(more=false) {
  if(!Number(currentUser?.is_super_admin)||(more&&!adminCursor))return;
  const request=++adminRequest;$('#admin-more').disabled=true;showError('#admin-error','');
  if(!more){adminCursor=null;$('#admin-content').innerHTML='<p>Loading research records...</p>';}
  try {
    const data=await api('admin-data',undefined,'&'+adminQuery(more));
    if(request!==adminRequest||!Number(currentUser?.is_super_admin))return;
    professionalRoles=data.roles;
    if($('#admin-role').options.length===2){const selected=$('#admin-role').value;$('#admin-role').insertAdjacentHTML('beforeend',Object.entries(data.roles).map(([v,l])=>'<option value="'+escapeHtml(v)+'">'+escapeHtml(l)+'</option>').join(''));$('#admin-role').value=selected;}
    if($('#admin-event').options.length===1)$('#admin-event').insertAdjacentHTML('beforeend',data.events.map(e=>'<option value="'+escapeHtml(e)+'">'+escapeHtml(e.replaceAll('_',' '))+'</option>').join(''));
    renderAdminOverview(data.overview);
    if(!more){const headings=adminView==='users'?['User / role','Registered / last active','Audits','Registration client']:['Time',adminView==='audits'?'Checked page / result':'Activity / page','User / role','Connection / client'];$('#admin-content').innerHTML='<table class="admin-table"><thead><tr>'+headings.map(h=>'<th scope="col">'+h+'</th>').join('')+'</tr></thead><tbody id="admin-rows"></tbody></table>';}
    $('#admin-rows').insertAdjacentHTML('beforeend',data.results.items.map(adminRow).join(''));
    if(!data.results.items.length&&!more)$('#admin-rows').innerHTML='<tr><td colspan="4">No records match these filters.</td></tr>';
    $('#admin-status').textContent=Number(data.results.total).toLocaleString()+' matching '+adminView+' records. Newest first. All times UTC.';
    adminCursor=data.results.nextCursor;$('#admin-more').hidden=!adminCursor;
  }catch(e){if(request===adminRequest){showError('#admin-error',e.message);if(!more)$('#admin-content').innerHTML='';}}
  finally{if(request===adminRequest)$('#admin-more').disabled=false;}
}
async function openAdminReport(id) {
  try {
    const data=await api('admin-report',undefined,'&id='+encodeURIComponent(id)),r=data.report;
    let html='<p class="member-kicker">READ-ONLY RESEARCH VIEW</p><p>'+escapeHtml(r.finalUrl)+'</p><p>Saved '+adminTime(r.createdAt)+' | '+escapeHtml(r.state)+' | SEO '+escapeHtml(r.score===null?'Not scored':r.score+'/100')+'</p>';
    html+=(r.checks||[]).map(c=>'<details class="admin-finding"><summary>'+escapeHtml(c.title)+' - '+escapeHtml(c.status)+'</summary><p>'+escapeHtml(c.detail)+'</p><p>'+escapeHtml(c.recommendation)+'</p></details>').join('');
    html+='<details class="admin-finding"><summary>Full saved report, including accessibility and in-depth findings</summary><pre class="admin-json">'+escapeHtml(JSON.stringify(r,null,2))+'</pre></details>';
    showInfo('Saved audit - research view',html);
  }catch(e){showError('#admin-error',e.message);}
}
async function exportAdmin() {
  if(!Number(currentUser?.is_super_admin))return;$('#admin-export').disabled=true;
  try {
    const response=await fetch('api.php?action=admin-csv&'+adminQuery(),{credentials:'same-origin'});
    if(!response.ok){const error=await response.json();throw new Error(error.error||'Export failed.');}
    const blob=await response.blob(),url=URL.createObjectURL(blob),a=document.createElement('a');a.href=url;a.download='research-'+adminView+'-'+$('#admin-from').value+'-'+$('#admin-to').value+'.csv';a.click();setTimeout(()=>URL.revokeObjectURL(url),1000);
    toast(response.headers.get('X-Export-Truncated')==='1'?'Exported the first 5,000 records. Narrow your filters for the rest.':'Research CSV downloaded.');
  }catch(e){showError('#admin-error',e.message);}finally{$('#admin-export').disabled=false;}
}

$('#audit-form').addEventListener('submit',async e=>{
  e.preventDefault(); if (busy) return;
  if (!csrf) { showError('#error','The service is not ready. Refresh the page and try again.'); return; }
  busy=true; $('#scan-button').disabled=true; $('#scan-label').textContent='Scanning…'; showError('#error',''); $('#loading').hidden=false; $('#empty-state').hidden=true; $('#report').hidden=true;
  try { const data=await api('audit',{url:$('#url').value.trim()}); renderReport(data.report,true); }
  catch(e) { if(e.code==='basic_limit'&&!currentUser)openAccount('register',{kind:'quota'});showError('#error',e.message); $('#report').hidden=!currentReport; $('#empty-state').hidden=!!currentReport; }
  finally { busy=false; $('#loading').hidden=true; $('#scan-button').disabled=false; $('#scan-label').textContent='Run X-Ray Scan'; }
});
$('#report').addEventListener('click',async e=>{
  const imageFilter=e.target.closest('[data-image-filter]');
  if(imageFilter){document.querySelectorAll('[data-image-filter]').forEach(b=>b.classList.toggle('active',b===imageFilter));document.querySelectorAll('[data-image-status]').forEach(row=>row.hidden=imageFilter.dataset.imageFilter!=='all'&&row.dataset.imageStatus!==imageFilter.dataset.imageFilter);return;}
  const filter=e.target.closest('[data-filter]');
  if (filter) { activeFilter=filter.dataset.filter; document.querySelectorAll('[data-filter]').forEach(b=>{b.classList.toggle('active',b===filter);b.setAttribute('aria-pressed',String(b===filter));}); renderChecks(); return; }
  const action=e.target.closest('[data-report-action]')?.dataset.reportAction;
  if (action==='rerun') { $('#url').value=currentReport.url; $('#audit-form').requestSubmit(); window.scrollTo({top:0,behavior:'smooth'}); }
  if (action==='unlock') openAccount(registrationEnabled?'register':'login');
  if (action==='accessibility') openAccount(registrationEnabled?'register':'login',{kind:'accessibility',reportId:currentReport.id});
  if (action==='save-browser') await askBrowserImport();
  if (action==='pdf') { await downloadPdf(currentReport.id); }
  if (action==='deepen') { await runDeep(); }
  if (action==='refresh-deep') { await openReport(currentReport.id); }

});
$('#history-button').addEventListener('click',()=>currentUser?openMyAccount():openBrowserHistory());
$('#history-more').addEventListener('click',()=>loadHistory(true));
$('#history-list').addEventListener('click',async e=>{
  const open=e.target.closest('[data-audit-id]'); if(open) { await openReport(open.dataset.auditId); return; }
  const del=e.target.closest('[data-delete-id]'); if(!del) return;
  if (!confirm('Permanently delete this audit?')) return;
  try { await api('delete',{id:del.dataset.deleteId}); if(currentReport?.id===del.dataset.deleteId) { currentReport=null;$('#report').hidden=true;$('#empty-state').hidden=false;history.replaceState(null,'',location.pathname); } await loadHistory();toast('Audit deleted.'); } catch(err) { toast(err.message); }
});
$('#account-button').addEventListener('click',()=>openAccount());
$('#auth-toggle').addEventListener('click',()=>{authMode=authMode==='login'?'register':'login';renderAuth();});
$('#auth-form').addEventListener('submit',async e=>{
  e.preventDefault();$('#auth-submit').disabled=true;showError('#auth-error','');
  const form=new FormData(e.target);
  try {
    const data=await api(authMode,{email:form.get('email'),password:form.get('password'),marketingConsent:form.get('consent')==='on',professionalRole:form.get('professionalRole')});
    csrf=data.csrf;currentUser=data.user;userUI();$('#account-dialog').close();e.target.reset();toast('You are signed in.');
    const pending=pendingMemberAction;pendingMemberAction=null;
    if(currentReport) await openReport(currentReport.id);
    if(!pending || pending.kind==='account') await openMyAccount();
    if(pending?.kind==='admin') await openAdmin();
    if(browserAudits.shouldPrompt) await askBrowserImport();
    if(pending?.kind==='pdf') await downloadPdf(pending.reportId);
    if(pending?.kind==='deep') {
      if(currentReport?.savedTo==='account') await runDeep();
      else toast('Save this browser audit to your account when you are ready for an in-depth review.');
    }
    if(pending?.kind==='accessibility') $('#accessibility-title')?.scrollIntoView({behavior:'smooth',block:'start'});
  } catch(err) { showError('#auth-error',err.message); } finally { $('#auth-submit').disabled=false; }
});
$('#logout-button').addEventListener('click',async()=>{
  try { const data=await api('logout',{});csrf=data.csrf;currentUser=null;currentReport=null;browserAudits={count:0,shouldPrompt:false};accountData=null;accountRequest++;adminRequest++;$('#admin-content').innerHTML='';$('#admin-stats').innerHTML='';$('#admin-overview').innerHTML='';$('#admin-search').value='';$('#info-content').innerHTML='';$('#report').innerHTML='';$('#account-list').innerHTML='';$('#account-stats').innerHTML='';$('#account-email').textContent='';$('#account-joined').textContent='';$('#history-list').innerHTML='';showAuditWorkspace(false);userUI();$('#report').hidden=true;$('#empty-state').hidden=false;history.replaceState(null,'',location.pathname);document.title='On-Page SEO Audit · X-Ray Readiness Scanner';toast('You’re signed out. Account audits stay private.'); } catch(e) { toast(e.message); }
});
$('#admin-button').addEventListener('click',openAdmin);
$('#research-privacy-button').addEventListener('click',()=>$('#privacy-button').click());
$('#admin-filters').addEventListener('submit',e=>{e.preventDefault();loadAdmin();});
$('#admin-reset').addEventListener('click',()=>{setAdminDates();$('#admin-search').value='';$('#admin-role').value='all';$('#admin-state').value='all';$('#admin-event').value='all';loadAdmin();});
$('#admin-export').addEventListener('click',exportAdmin);
$('#admin-more').addEventListener('click',()=>loadAdmin(true));
$('#admin-panel').addEventListener('click',e=>{const tab=e.target.closest('[data-admin-tab]');if(tab){adminView=tab.dataset.adminTab;document.querySelectorAll('[data-admin-tab]').forEach(b=>{b.classList.toggle('active',b===tab);b.setAttribute('aria-pressed',String(b===tab));});$('#admin-event-label').hidden=adminView!=='activity';$('#admin-state-label').hidden=adminView!=='audits';$('#admin-search').placeholder=adminView==='users'?'Search email address':adminView==='audits'?'Search email or checked URL':'Email, page URL or connection IP';loadAdmin();}const report=e.target.closest('[data-admin-report]');if(report)openAdminReport(report.dataset.adminReport);});
$('#account-new-audit').addEventListener('click',()=>{showAuditWorkspace();$('#url').focus();window.scrollTo({top:0,behavior:'smooth'});});
$('#account-browser-history').addEventListener('click',openBrowserHistory);
$('#account-import-button').addEventListener('click',askBrowserImport);
$('#account-search-form').addEventListener('submit',e=>{e.preventDefault();loadAccountHistory();});
$('#account-state').addEventListener('change',()=>loadAccountHistory());
$('#account-clear-search').addEventListener('click',()=>{$('#account-search').value='';$('#account-state').value='all';loadAccountHistory();});
$('#account-more').addEventListener('click',()=>loadAccountHistory(true));
$('#browser-import-confirm').addEventListener('click',()=>chooseBrowserImport('import'));
$('#browser-import-decline').addEventListener('click',()=>chooseBrowserImport('decline'));
$('#browser-import-dialog').addEventListener('cancel',e=>{if($('#browser-import-confirm').disabled)e.preventDefault();});
$('#browser-import-dialog').addEventListener('close',()=>{const resolve=importResolve;importResolve=null;if(resolve)resolve(false);});
$('#account-list').addEventListener('click',async e=>{
  const report=e.target.closest('[data-account-report]');if(report){await openReport(report.dataset.accountReport);return;}
  const pdf=e.target.closest('[data-account-pdf]');if(pdf){await downloadPdf(pdf.dataset.accountPdf);return;}
  const del=e.target.closest('[data-account-delete]');if(!del)return;
  if(!confirm('Permanently delete this audit and its saved in-depth review?'))return;
  del.disabled=true;
  try{await api('delete',{id:del.dataset.accountDelete});if(currentReport?.id===del.dataset.accountDelete){currentReport=null;$('#report').innerHTML='';$('#report').hidden=true;$('#empty-state').hidden=false;}await refreshMyAccount();toast('Audit deleted.');}
  catch(err){toast(err.message);del.disabled=false;}
});
document.querySelectorAll('[data-close]').forEach(b=>b.addEventListener('click',()=>b.closest('dialog').close()));
$('#method-button').addEventListener('click',()=>showInfo('A useful snapshot, not a crystal ball.','<p>We fetch a single public page and inspect its initial HTML, HTTP indexing headers, robots.txt, a declared sitemap (or /sitemap.xml), and the alternate trailing-slash URL. We never execute the audited page’s scripts.</p><p>The SEO score gives crawlability checks five times the weight of other checks: pass = 1, review = 0.5, fix = 0. Critical failures cap the score: noindex at 20/100, robots.txt failures at 35/100, and page-level nofollow at 40/100. The lowest applicable cap wins. These are diagnostic rules used by this tool. Existing saved reports keep their original scores; run again to use the new model. GEO indicators have a separate score. Failed fetches are saved but receive no score.</p><p>Word count includes only paragraph and H1–H6 text, including any of those tags inside navigation or footers. It is a space-separated token count, so it is less useful for languages that do not separate words with spaces. The thresholds are guidelines, not search-engine requirements.</p><p>We do not measure Core Web Vitals, backlinks, ranking positions, full-site health or AI citations. JSON-LD syntax is checked; rich-result eligibility and content accuracy need manual review. Decorative images may correctly have empty alt text.</p><p>Read <a href="https://developers.google.com/search/docs/appearance/ai-features" target="_blank" rel="noopener noreferrer">Google’s AI search guidance</a> and its <a href="https://developers.google.com/search/docs/fundamentals/creating-helpful-content" target="_blank" rel="noopener noreferrer">helpful-content guidance</a>.</p>'));
$('#privacy-button').addEventListener('click',()=>showInfo('Your audits, saved privately.','<p>For usage research, we record your selected professional role, connection IP, browser user-agent, estimated OS/browser/device, language and timestamped audit/account activity. These details are available only to authorised super admins. Research event URLs omit query strings, fragments and embedded credentials. Research activity logs are retained separately and remain when a saved report is deleted.</p><p>Submitted URLs, audit findings and extracted text snippets are stored on this application’s server until you delete the audit. Failed attempts are recorded too. Do not submit private or token-bearing URLs.</p><p>An essential signed browser cookie reconnects you to unclaimed audits for up to one year. Clearing it removes your browser’s access, but does not delete server records. Signing in adds unclaimed browser audits to your account.</p><p>Accounts store your email address and a password hash. Optional marketing consent is recorded separately. No lead information is currently sent to GHL or another CRM. The site does not include analytics or advertising trackers; Google Fonts is requested for typography.</p><p>Audit requests contact the website you submit. Daily limits use keyed hashes of your IP address and browser identity, or your registered account. Usage counters remain separate from reports, so deleting a report does not restore an allowance. The audit crawler does not forward your cookies or login details.</p>'));
(async()=>{
  try {
    const data=await api('session');csrf=data.csrf;currentUser=data.user;registrationEnabled=data.registrationEnabled;professionalRoles=data.professionalRoles||{};$('#professional-role').innerHTML='<option value="">Choose your role</option>'+Object.entries(professionalRoles).map(([value,label])=>'<option value="'+escapeHtml(value)+'">'+escapeHtml(label)+'</option>').join('');allowance=data.allowance;renderAllowance();userUI();
    const params=new URLSearchParams(location.search), id=params.get('audit');
    if(params.get('view')==='admin') { if(currentUser) await openAdmin(); else openAccount('login',{kind:'admin'}); }
    else if(params.get('view')==='account') { if(currentUser) await openMyAccount(); else openAccount('login',{kind:'account'}); }
    else if(id) await openReport(id);
    if(currentUser && browserAudits.shouldPrompt) await askBrowserImport();
  } catch(e) { showError('#error',e.message);$('#scan-button').disabled=true; }
})();
