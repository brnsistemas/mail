'use strict';
const theme = document.getElementById('theme');
try { document.documentElement.dataset.theme = localStorage.getItem('brnmail-theme') || 'light'; } catch {}
theme?.addEventListener('click', () => { const t=document.documentElement.dataset.theme==='dark'?'light':'dark'; document.documentElement.dataset.theme=t; try{localStorage.setItem('brnmail-theme',t);}catch{} });
const draft=document.getElementById('draft-form');
const sendForm=document.getElementById('send-form');
const uploadForm=document.querySelector('.upload-form');
let dirty=false; let revision=0; let busy=false; let uncertain=false;
const messageState=text=>{
 for(const id of ['draft-state','send-state']) {const node=document.getElementById(id);if(node)node.textContent=text;}
};
draft?.addEventListener('input',()=>{dirty=true;revision++;messageState('Alterações não salvas. Enviar salva automaticamente.');});
uploadForm?.querySelector('input[type=file]')?.addEventListener('change',()=>{revision++;});
async function composeRequest(url,options={}) {
 const response=await fetch(url,{...options,credentials:'same-origin',headers:{Accept:'application/json'},signal:AbortSignal.timeout(30000)});
 if(response.redirected || [401,419].includes(response.status))throw new Error('Sua sessão expirou. Seu texto continua nesta janela; copie-o antes de entrar novamente.');
 const data=await response.json().catch(()=>({}));
 if(!response.ok) {
   const texts={403:'Permissão de envio indisponível. Seu texto continua nesta janela.',404:'Rascunho ou permissão indisponível. Seu texto continua nesta janela.',409:'Conflito de versão. Seu texto foi preservado nesta janela; copie-o antes de reabrir.',429:'Limite temporário de envio. Aguarde antes de tentar novamente.'};
   throw new Error(texts[response.status] || (response.status===422 ? (Object.values(data.errors||{}).flat().join(' ') || data.message || 'Confira os campos e anexos.')+' Seu texto continua nesta janela.' : 'Não foi possível concluir. Seu texto continua nesta janela.'));
 }
 return data;
}
async function saveDraft() {
 messageState('Salvando no servidor…');
 let result;
 try {result=await composeRequest(draft.action,{method:'POST',body:new FormData(draft)});}
 catch(error){if(error.name==='TimeoutError'||error.name==='AbortError'||error instanceof TypeError)uncertain=true;throw error;}
 if(result.saved!==true || !Number.isSafeInteger(result.version)){uncertain=true;throw new Error('Salvamento não confirmado. Copie seu texto antes de reabrir o rascunho.');}
 draft.querySelector('[name=version]').value=result.version;sendForm.querySelector('[name=version]').value=result.version;
 dirty=false;
 messageState('Versão salva: '+result.version);
}
function showAttachments(attachments) {
 const progress=document.getElementById('upload-progress');
 if(progress)progress.textContent=attachments.map(a=>a.filename+' — '+({clean:'Verificado',quarantine:'Em verificação',blocked:'Bloqueado',unavailable:'Indisponível'}[a.status]||'Aguardando')).join('; ');
}
async function uploadSelected() {
 const input=uploadForm?.querySelector('input[type=file]');
 if(!input?.files.length)return;
 const uploadData=new FormData(uploadForm);uploadData.set('attachment',input.files[0]);
 messageState('Anexando o arquivo selecionado…');
 let result;
 try {result=await composeRequest(uploadForm.action,{method:'POST',body:uploadData});}
 catch(error){if(error.name==='TimeoutError'||error.name==='AbortError'||error instanceof TypeError)uncertain=true;throw error;}
 if(result.uploaded!==true||!result.attachment?.id){uncertain=true;throw new Error('Upload não confirmado. Reabra o rascunho antes de anexar novamente para evitar duplicação.');}
 input.value='';showAttachments([result.attachment]);
}
async function waitForAttachments() {
 for(let attempt=0;attempt<15;attempt++) {
   const result=await composeRequest(draft.action+'/status');
   if(result.status!=='draft'||result.version!==Number(draft.querySelector('[name=version]').value))throw new Error('O rascunho mudou em outra janela. Reabra para conferir antes de enviar.');
   showAttachments(result.attachments);
   if(result.attachments.every(a=>a.status==='clean'))return;
   if(result.attachments.some(a=>['blocked','unavailable'].includes(a.status)))throw new Error('Há anexo bloqueado ou indisponível. Nada foi enviado. Reabra o rascunho para revisar ou remover o arquivo.');
   messageState('Aguardando a verificação dos anexos…');
   await new Promise(resolve=>setTimeout(resolve,2000));
 }
 throw new Error('A verificação dos anexos ainda não terminou. Nada foi enviado. Aguarde e clique em Enviar novamente; não é preciso anexar de novo.');
}
async function composeAction(action) {
 if(busy)return;
 if(uncertain){messageState('A operação anterior não foi confirmada. Copie seu texto e reabra o rascunho para conferir antes de repetir.');return;}
 if(!draft.reportValidity()){messageState('Preencha destinatário, assunto e mensagem antes de continuar.');return;}
 busy=true;const started=revision;
 const fileInput=uploadForm?.querySelector('input[type=file]');if(fileInput)fileInput.disabled=true;
 // Read-only fields still enter FormData; buttons prevent duplicate submissions.
 const fields=[...draft.querySelectorAll('input:not([type=hidden]),textarea')];
 const buttons=[...document.querySelectorAll('#draft-form button,#send-form button,.upload-form button,.attachment-list button')];
 fields.forEach(field=>field.readOnly=true);buttons.forEach(button=>button.disabled=true);
 try {
   await saveDraft();
   if(action!=='save')await uploadSelected();
   if(action==='send') {
     await waitForAttachments();
     if(revision!==started)throw new Error('O arquivo selecionado mudou. Confira e clique em Enviar novamente.');
     messageState('Colocando a mensagem na fila…');
     let result;
     try {result=await composeRequest(sendForm.action,{method:'POST',body:new FormData(sendForm)});}
     catch(error){if(error.name==='TimeoutError'||error.name==='AbortError'||error instanceof TypeError)uncertain=true;throw error;}
     if(result.queued!==true||typeof result.redirect!=='string'||!result.redirect.startsWith('/mail?')){uncertain=true;throw new Error('Envio não confirmado. Reabra o rascunho e confira o estado antes de repetir.');}
     dirty=false;busy=false;window.location.assign(result.redirect);return;
   }
   if(action==='upload')messageState('Arquivo anexado. A verificação será conferida antes de enviar.');
 } catch(error) {
   messageState(error.name==='TimeoutError'||error.name==='AbortError'||error instanceof TypeError ? 'A conexão não confirmou a operação. Seu texto continua nesta janela; copie-o e reabra o rascunho para conferir antes de repetir.' : error.message);
 } finally {busy=false;if(fileInput)fileInput.disabled=false;fields.forEach(field=>field.readOnly=false);buttons.forEach(button=>button.disabled=false);}
}
draft?.addEventListener('submit',event=>{event.preventDefault();void composeAction('save');});
sendForm?.addEventListener('submit',event=>{event.preventDefault();void composeAction('send');});
uploadForm?.addEventListener('submit',event=>{event.preventDefault();void composeAction('upload');});
window.addEventListener('beforeunload',event=>{if(dirty||busy||uploadForm?.querySelector('input[type=file]')?.files.length){event.preventDefault();event.returnValue='';}});
const queuedState=document.querySelector('[data-send-status-url]');
if(queuedState)void (async()=>{
 try {
   for(let attempt=0;attempt<30;attempt++) {
     await new Promise(resolve=>setTimeout(resolve,2000));
     const result=await composeRequest(queuedState.dataset.sendStatusUrl);
     if(result.status!=='queued') {
       if(typeof result.redirect!=='string'||!result.redirect.startsWith('/mail?'))throw new Error('Estado não confirmado.');
       window.location.replace(result.redirect);return;
     }
   }
   queuedState.textContent='O envio continua na fila. Use Atualizar mensagens para conferir; não crie outro envio.';
 } catch {queuedState.textContent='Não foi possível atualizar o resultado. Use Atualizar mensagens antes de repetir o envio.';}
})();
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
