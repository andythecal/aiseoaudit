'use strict';
const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const elements=new Map();
const document={querySelector(s){if(!elements.has(s))elements.set(s,{innerHTML:'',textContent:'',value:s==='#admin-role'||s==='#admin-event'||s==='#admin-state'?'all':'',hidden:true,disabled:false,open:false,options:s==='#admin-role'?[{},{}]:[{}],classList:{add(){},remove(){}},focus(){},scrollIntoView(){},showModal(){this.open=true;},insertAdjacentHTML(_,html){this.innerHTML+=html;if(html.includes('<option'))this.options.push({});}});return elements.get(s);}};
const context=vm.createContext({document,URL,URLSearchParams,console,history:{replaceState(){}},location:{pathname:'/'},setTimeout,clearTimeout});
const source=fs.readFileSync('assets/app.js','utf8');vm.runInContext(source.slice(0,source.indexOf("\n$('#audit-form').addEventListener")),context);
const el=s=>document.querySelector(s);const calls=[];
context.mockApi=async(action,data,query)=>{
 calls.push({action,query});
 if(action==='admin-report')return {report:{finalUrl:'https://example.test/<script>x</script>',createdAt:'2026-10-06 12:00:00Z',score:20,state:'complete',checks:[{title:'<img src=x onerror=bad()>',status:'fail',detail:'Unsafe HTML shown as text',recommendation:'Fix'}]}};
 return {roles:{agency:'Agency'},events:['registered','report_viewed'],overview:{counts:{registrations:1,audits:2,visitors:1,events:5},from:'2026-10-01',to:'2026-10-06',roles:[{professional_role:'agency',total:1}],sites:[{site:'<script>bad</script>',total:2}]},results:{total:1,nextCursor:null,items:[{id:1,occurred_at:'2026-10-06 12:00:00',event_type:'registered',email:'<img src=x onerror=bad()>',professional_role:'agency',connection_ip:'127.0.0.1',os_name:'Windows',browser_name:'Chrome',user_agent:'<script>UA</script>',metadata:{text:'<svg onload=bad()>'}}]}};
};
vm.runInContext('api=mockApi;toast=()=>{}',context);
(async()=>{
 vm.runInContext("authMode='register';renderAuth()",context);assert.equal(el('#professional-role').required,true);assert.equal(el('#professional-role-label').hidden,false);
 vm.runInContext("authMode='login';renderAuth()",context);assert.equal(el('#professional-role').required,false);assert.equal(el('#professional-role-label').hidden,true);
 vm.runInContext("currentUser={id:3,email:'normal@example.test',is_super_admin:0};userUI();openMyAccount=async()=>{}",context);
 assert.equal(el('#admin-button').hidden,true);await vm.runInContext('openAdmin()',context);assert.equal(calls.length,0);
 vm.runInContext("currentUser={id:4,email:'admin@example.test',is_super_admin:1};userUI()",context);assert.equal(el('#admin-button').hidden,false);
 await vm.runInContext('openAdmin()',context);
 assert.equal(el('#admin-panel').hidden,false);assert.equal(el('#my-account').hidden,true);assert.equal(el('#audit-workspace').hidden,true);
 assert(calls[0].action==='admin-data'&&calls[0].query.includes('view=activity'));
 const rows=el('#admin-rows').innerHTML;assert(rows.includes('&lt;script&gt;UA&lt;/script&gt;')&&!rows.includes('<script>'));
 assert(rows.includes('&lt;svg onload=bad()&gt;')&&!rows.includes('<svg onload='));
 assert(el('#admin-overview').innerHTML.includes('&lt;script&gt;bad&lt;/script&gt;'));assert(rows.includes('12:00:00 UTC'));
 await vm.runInContext("openAdminReport('abc')",context);assert(el('#info-content').innerHTML.includes('READ-ONLY RESEARCH VIEW'));assert(!el('#info-content').innerHTML.includes('<img src=x'));
 vm.runInContext('showAuditWorkspace()',context);assert.equal(el('#admin-panel').hidden,true);
 console.log('PASS: Registration role requirement, admin-only navigation, UTC rendering, research evidence escaping and read-only report display.');
})().catch(e=>{console.error(e);process.exitCode=1;});
