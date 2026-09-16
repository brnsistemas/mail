'use strict';
const theme = document.getElementById('theme');
try { document.documentElement.dataset.theme = localStorage.getItem('brnmail-theme') || 'light'; } catch {}
theme?.addEventListener('click', () => { const t=document.documentElement.dataset.theme==='dark'?'light':'dark'; document.documentElement.dataset.theme=t; try{localStorage.setItem('brnmail-theme',t);}catch{} });
const draft=document.getElementById('draft-form');
let dirty=false; let revision=0; let saving=false;
draft?.addEventListener('input',()=>{dirty=true;revision++;document.getElementById('draft-state').textContent='Alterações não salvas';});
draft?.addEventListener('submit',async event=>{
 event.preventDefault(); if(saving)return;
 saving=true; const started=revision; const state=document.getElementById('draft-state'); const button=draft.querySelector('button'); button.disabled=true;
 state.textContent='Salvando no servidor…';
 try {
   const response=await fetch(draft.action,{method:'POST',body:new FormData(draft),credentials:'same-origin',headers:{Accept:'application/json'}});
   if(!response.ok){state.textContent=response.status===409?'Conflito de versão. Seu texto foi preservado nesta janela; copie-o antes de reabrir.':(response.status===422?'Confira os destinatários e campos. Seu texto continua nesta janela.':'Não foi possível salvar. Seu texto continua nesta janela; tente novamente.');dirty=true;return;}
   const result=await response.json();
   if(result.saved!==true || !Number.isSafeInteger(result.version))throw new Error('save-contract');
   draft.querySelector('[name=version]').value=result.version; document.querySelector('#send-form [name=version]').value=result.version;
   dirty=revision!==started;
   state.textContent=dirty?'Há alterações novas ainda não salvas.':'Versão salva: '+result.version;
 } catch {dirty=true;state.textContent='Conexão interrompida. Seu texto permanece nesta janela; não feche antes de salvar.';}
 finally {saving=false;button.disabled=false;}
});
document.getElementById('send-form')?.addEventListener('submit',event=>{if(dirty){event.preventDefault();document.getElementById('draft-state').textContent='Salve o rascunho antes de enviar.';draft.querySelector('button').focus();}});
document.querySelector('.upload-form')?.addEventListener('submit',event=>{if(dirty){event.preventDefault();document.getElementById('draft-state').textContent='Salve o rascunho antes de anexar.';draft.querySelector('button').focus();}});
window.addEventListener('beforeunload',event=>{if(dirty){event.preventDefault();event.returnValue='';}});
// Context switching changes only the URL mailbox; no content or credentials enter browser storage.
const popovers=[...document.querySelectorAll('[data-popover]')];
popovers.forEach(panel=>{
 panel.addEventListener('toggle',()=>{if(panel.open)popovers.forEach(other=>{if(other!==panel)other.open=false;});});
});
document.addEventListener('click',event=>popovers.forEach(panel=>{if(panel.open&&!panel.contains(event.target))panel.open=false;}));
document.addEventListener('keydown',event=>{if(event.key==='Escape'){popovers.forEach(panel=>{if(panel.open){panel.open=false;panel.querySelector('summary').focus();}});}});
const workspaceFilter=document.getElementById('workspace-filter');
workspaceFilter?.addEventListener('input',()=>{
 const normalize=value=>value.normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLocaleLowerCase('pt-BR').trim();
 const query=normalize(workspaceFilter.value);let found=0;
 document.querySelectorAll('.workspace-option').forEach(option=>{option.hidden=!normalize(option.dataset.search||'').includes(query);if(!option.hidden)found++;});
 document.querySelectorAll('.domain-group').forEach(group=>{group.hidden=![...group.querySelectorAll('.workspace-option')].some(option=>!option.hidden);});
 document.querySelectorAll('.workspace-group').forEach(group=>{group.hidden=![...group.querySelectorAll('.domain-group')].some(domain=>!domain.hidden);});
 document.getElementById('workspace-no-results').hidden=found!==0;
});
