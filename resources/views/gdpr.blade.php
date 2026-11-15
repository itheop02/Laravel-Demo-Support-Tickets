<!doctype html>
<html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>Privacy checks</title>
<style>body{font:16px system-ui;max-width:950px;margin:35px auto;padding:20px;background:#f5f7fa;color:#172b4d}button,select,textarea{font:inherit;padding:10px;margin:6px 0}textarea,pre{box-sizing:border-box;width:100%}pre{white-space:pre-wrap;background:white;padding:20px;overflow-wrap:anywhere}button{cursor:pointer}</style>
<h1>Privacy checks</h1><p>Signed in as {{ auth()->user()->name }}. <a href="{{ route('tickets.index') }}">Back to tickets</a></p>
<p>Consent is saved. Other operations return decisions and proposed plans; account changes, erasure, transfers and notifications are not executed. Exports include your profile, owned tickets and your own messages on those tickets.</p>
<button id="grant">Grant consent</button> <button id="withdraw">Withdraw consent</button>
<p><label>Operation <select id="policy">@foreach(['consent','lawful','information','access','rectification','erasure','restriction','portability','objection','complaint','breach'] as $operation)<option>{{ $operation }}</option>@endforeach</select></label></p>
<label for="body">Request JSON</label><textarea id="body" rows="7">{}</textarea><br><button id="evaluate">Evaluate</button>
<pre id="output" role="status">Choose an operation.</pre>
<script>
const token=@json(csrf_token()),base=@json(url('/gdpr'));
const policy=document.querySelector('#policy'),body=document.querySelector('#body'),output=document.querySelector('#output');
const examples={rectification:{updates:{name:'Updated Name'}},erasure:{fields:['name'],reason:'withdrawn_consent'},complaint:{authority_id:'cy-0000',description:'Example only; no message sent'},breach:{incident_id:'example-incident'}};
policy.onchange=()=>body.value=JSON.stringify(examples[policy.value]||{},null,2);
async function send(operation,value,method='POST'){try{const r=await fetch(base+'/'+encodeURIComponent(operation),{method,headers:{'Accept':'application/json','Content-Type':'application/json','X-CSRF-TOKEN':token},body:JSON.stringify(value)});output.textContent='HTTP '+r.status+'\n'+JSON.stringify(await r.json(),null,2)}catch(e){output.textContent=e.message}}
document.querySelector('#evaluate').onclick=()=>{try{send(policy.value,JSON.parse(body.value))}catch(e){output.textContent=e.message}};
document.querySelector('#grant').onclick=()=>send('consent',{value:'yes',withdrawn:false},'PATCH');document.querySelector('#withdraw').onclick=()=>send('consent',{value:'yes',withdrawn:true},'PATCH');
</script></html>
