import {chromium} from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import {spawnSync} from 'node:child_process';
import {createHmac} from 'node:crypto';
import fs from 'node:fs';
import assert from 'node:assert/strict';
const base=process.env.BRNMAIL_QA_URL || 'http://127.0.0.1:8876';
assert.equal(base,'http://127.0.0.1:8876','Browser QA only targets the isolated BRN Mail localhost');
const identity=spawnSync('php',['scripts/qa-browser-identity.php'],{encoding:'utf8'});
assert.equal(identity.status,0,'Synthetic identity setup failed');
const user=JSON.parse(identity.stdout);
const report=[]; const record=(name,extra={})=>report.push({name,passed:true,...extra});
fs.mkdirSync('test-results',{recursive:true});
function totp(secret){
 const chars='ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; let bits=''; for(const c of secret)bits+=chars.indexOf(c).toString(2).padStart(5,'0');
 const key=Buffer.from((bits.match(/.{8}/g)||[]).map(x=>parseInt(x,2)));const step=Buffer.alloc(8);step.writeBigUInt64BE(BigInt(Math.floor(Date.now()/30000)));
 const h=createHmac('sha1',key).update(step).digest();const o=h[19]&15;return ((h.readUInt32BE(o)&0x7fffffff)%1000000).toString().padStart(6,'0');
}
const browser=await chromium.launch({headless:true,channel:process.env.BRNMAIL_BROWSER_CHANNEL || 'chrome'});
const context=await browser.newContext({viewport:{width:1440,height:1050}});
await context.grantPermissions(['clipboard-read','clipboard-write'],{origin:base});
const errors=[]; const external=[];
context.on('page',p=>p.on('pageerror',e=>errors.push(e.name)));
await context.route('**/*',route=>{if(!route.request().url().startsWith(base+'/')) {external.push(new URL(route.request().url()).hostname);return route.abort();}return route.continue();});
try {
 const page=await context.newPage();
 await page.goto(base+'/login');
 await page.locator('[name=email]').fill(user.email);await page.locator('[name=password]').fill(user.password);
 await Promise.all([page.waitForURL('**/two-factor'),page.getByRole('button',{name:/Continuar com/}).click()]);
 const denied=await context.request.get(base+'/mail',{maxRedirects:0});assert.equal(denied.status(),302);record('password-alone-cannot-read');
 const key=await page.getByLabel('Chave de configuração do autenticador').inputValue();
 assert(await page.locator('.totp-qr svg').isVisible(),'Local QR is visible');
 for(const width of [390,1440]){
   await page.setViewportSize({width,height:1050});
   assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'MFA overflow');
   const qrBounds=await page.locator('.totp-qr svg').boundingBox();
   assert(qrBounds.width>=200 && qrBounds.height>=200,'QR must remain scannable size');record('qr-enrollment-layout',{width});
   const axe=await new AxeBuilder({page}).withTags(['wcag2a','wcag2aa']).analyze();
   assert.equal(axe.violations.length,0,'MFA accessibility');record('qr-enrollment-accessibility',{width});
 }
 await page.locator('.manual-key summary').click();
 await page.getByRole('button',{name:'Copiar chave de configuração'}).click();
 assert(await page.evaluate(async()=>navigator.clipboard.readText())===key,'Copy matches current enrollment key');record('copy-enrollment-key');
 await page.locator('[name=code]').fill(totp(key));
 await Promise.all([page.waitForNavigation(),page.getByRole('button',{name:'Verificar e entrar'}).click()]);
 assert.equal(await page.locator('.recovery li').count(),8);record('real-login-totp-enrollment-recovery');
 const recovery=await page.locator('.recovery code').allTextContents();
 const downloadPromise=page.waitForEvent('download');await page.getByRole('button',{name:'Baixar códigos de recuperação'}).click();
 const download=await downloadPromise;const file=await download.path();const downloaded=fs.readFileSync(file,'utf8');
 assert(recovery.every(code=>downloaded.includes(code))&&!downloaded.includes(key),'Recovery download includes eight codes and never the authenticator secret');
 assert.equal(download.suggestedFilename(),'brnmail-codigos-recuperacao.txt');await download.delete();record('one-time-recovery-download');
 await page.evaluate(()=>navigator.clipboard.writeText('')); // Erase the synthetic secret after the copy check.
 await page.getByRole('link',{name:/Guardei os códigos/}).click(); await page.waitForURL('**/mail');
 const forbidden=[];context.on('request',request=>{if(request.url().includes('/qa-forbidden-'))forbidden.push(request.url());});
 await page.goto(base+'/mail?box='+user.box+'&message='+user.html_message);
 const reader=page.frameLocator('iframe.mail-html');
 await reader.getByRole('link',{name:'Aceitar convite',exact:true}).waitFor();
 assert.equal(await reader.getByRole('link',{name:'Aceitar convite',exact:true}).evaluate(el=>getComputedStyle(el).backgroundColor),'rgb(23, 92, 211)');
 assert.equal(await reader.locator('script,img,form,input,iframe').count(),0);
 assert.equal(await page.locator('body').getAttribute('data-compromised'),null);
 assert.equal(await page.locator('iframe.mail-html').getAttribute('sandbox'),'allow-popups allow-popups-to-escape-sandbox');
 for(const width of [390,1440]) {
   await page.setViewportSize({width,height:1050});
   assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'HTML parent layout overflow');
   assert(await reader.getByRole('link',{name:'Aceitar convite',exact:true}).isVisible());
   record('isolated-html-layout',{width});
 }
 const popupPromise=page.waitForEvent('popup');
 await reader.getByRole('link',{name:'Aceitar convite',exact:true}).click();
 const popup=await popupPromise;await popup.waitForLoadState('domcontentloaded');
 assert.equal(new URL(popup.url()).searchParams.get('qa'),'html-link');
 assert.equal(await popup.evaluate(()=>window.opener===null),true);
 await popup.close();assert.equal(forbidden.length,0);record('html-button-synthetic-link-and-no-active-content');
 await page.getByRole('button',{name:'Marcar como spam',exact:true}).click();
 await page.getByRole('navigation',{name:'Pastas'}).getByRole('link',{name:/Spam/}).click();
 await page.getByRole('link').filter({hasText:'QA HTML e Spam'}).click();
 assert(await page.getByRole('button',{name:'Não é spam',exact:true}).isVisible());
 await page.getByRole('button',{name:'Não é spam',exact:true}).click();
 assert(await page.getByRole('link').filter({hasText:'QA HTML e Spam'}).isVisible());record('spam-roundtrip');
 for(const theme of ['light','dark']) for(const width of [360,390,768,1024,1440]) {
   await page.setViewportSize({width,height:1000});
   await page.evaluate(t=>{document.documentElement.dataset.theme=t;localStorage.setItem('brnmail-theme',t)},theme);
   await page.goto(base+'/mail?box='+user.box);
   await page.waitForSelector('.message-row');
   assert.equal(await page.locator('h1').first().innerText(),'Entrada');
   assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'horizontal overflow');
   record('inbox-layout',{theme,width});
   if(width===1440 || width===390)await page.screenshot({path:'test-results/inbox-'+theme+'-'+width+'.png',fullPage:true});
   const axe=await new AxeBuilder({page}).withTags(['wcag2a','wcag2aa','wcag21aa']).analyze();
   const problems=axe.violations.map(v=>({id:v.id,impact:v.impact,nodes:v.nodes.length}));
   fs.writeFileSync('test-results/axe-'+theme+'-'+width+'.json',JSON.stringify(problems,null,2));
   assert.equal(problems.length,0,'A11y: '+JSON.stringify(problems));record('inbox-accessibility',{theme,width});
   await page.locator('.message-row').first().click();await page.waitForSelector('.reading-content h1');
   assert(await page.locator('.reading').isVisible());assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1));
   if(width<850)assert.equal(await page.locator('.message-list').isVisible(),false);
   record('message-reading-navigation',{theme,width});
   if(width===1440 || width===390) await page.screenshot({path:'test-results/mail-'+theme+'-'+width+'.png',fullPage:true});
 }
 await page.setViewportSize({width:1440,height:1050});await page.goto(base+'/mail?box='+user.box);
 const switcher=page.getByTestId('workspace-switcher');
 for(const viewport of [{width:390,height:667},{width:733,height:790},{width:1440,height:1050}]){
   await page.setViewportSize(viewport);await switcher.locator('summary').click();
   const bounds=await page.locator('.workspace-panel').boundingBox();
   assert(bounds.x>=0&&bounds.y>=0&&bounds.x+bounds.width<=viewport.width+1&&bounds.y+bounds.height<=viewport.height+1,'Workspace popup fits viewport');
   await page.keyboard.press('Escape');record('workspace-popup-viewport',viewport);
 }
 await switcher.locator('summary').click();await page.getByLabel('Filtrar empresas, domínios e caixas').fill('impossivel-caixa-inexistente');
 assert(await page.locator('#workspace-no-results').isVisible());record('workspace-search-empty');
 await page.getByLabel('Filtrar empresas, domínios e caixas').fill('comunicacao');
 assert(await page.getByTestId('workspace-option').filter({visible:true}).count()>=1);record('workspace-search-accent-insensitive');
 await page.keyboard.press('Escape');assert.equal(await switcher.getAttribute('open'),null);record('workspace-escape-focus');
 if(user.secondary_box){
   await switcher.locator('summary').click();await page.getByLabel('Filtrar empresas, domínios e caixas').fill('workspace');
   await page.getByTestId('workspace-option').filter({hasText:'comunicacao@workspace.test'}).click();
   assert(new URL(page.url()).searchParams.get('box')===String(user.secondary_box));
   assert.match(await page.locator('.active-address').innerText(),/workspace.test/);record('switch-company-domain-mailbox');
   await page.getByRole('button',{name:'Escrever e-mail'}).click();await page.waitForSelector('#draft-form');
   assert.match(await page.locator('.from-line').innerText(),/comunicacao@workspace.test/);record('composer-shows-correct-company-sender');
 }
 await page.setViewportSize({width:1440,height:1050});await page.goto(base+'/mail?box='+user.box);
 await page.getByRole('button',{name:/Escrever e-mail/}).click();await page.waitForSelector('#draft-form');
 await page.locator('#draft-form [name=to]').fill('destinatario@example.test');
 const subject='QA navegador '+Date.now();
 await page.locator('#draft-form [name=subject]').fill(subject);await page.locator('#draft-form [name=body_text]').fill('Mensagem sintética. Nenhum envio externo.');
 // A single Send persists the current fields; invalid recipients stay in the composer.
 await page.locator('#draft-form [name=to]').fill('endereco-invalido');
 await page.locator('#send-form button').click();
 await page.waitForFunction(()=>document.getElementById('send-state').textContent.includes('texto continua'));
 assert.equal(await page.locator('#draft-form [name=body_text]').inputValue(),'Mensagem sintética. Nenhum envio externo.');record('send-validation-preserves-current-body');
 await page.locator('#draft-form [name=to]').fill('destinatario@example.test');
 await page.locator('#draft-form [name=body_text]').fill('Última edição enviada sem salvar separadamente.');
 await Promise.all([page.waitForNavigation(),page.locator('#send-form button').click()]);
 const pump=spawnSync('php',['artisan','brnmail:pump','--inline'],{encoding:'utf8'});assert.equal(pump.status,0);
 await page.goto(base+'/mail?box='+user.box+'&folder=sent');await page.getByRole('heading',{name:subject}).click();
 assert.match(await page.locator('.reading-toolbar').innerText(),/Processado localmente/);
 assert.match(await page.locator('.mail-body').innerText(),/Última edição enviada sem salvar separadamente/);record('one-click-save-and-local-send');
 // Real upload remains quarantined in this CI; the controlled status response tests failure UX.
 await page.getByRole('button',{name:/Escrever e-mail/}).click();await page.waitForSelector('#draft-form');
 await page.locator('#draft-form [name=to]').fill('destinatario@example.test');
 await page.locator('#draft-form [name=subject]').fill('QA anexo controlado');
 await page.locator('#draft-form [name=body_text]').fill('Preservar texto e anexo no bloqueio.');
 await page.locator('.upload-form input[type=file]').setInputFiles({name:'qa.txt',mimeType:'text/plain',buffer:Buffer.from('Arquivo sintético de QA')});
 let sends=0;const sendObserver=request=>{if(request.method()==='POST'&&request.url().endsWith('/send'))sends++;};page.on('request',sendObserver);
 await page.route('**/drafts/*/status',async route=>{
   const original=await route.fetch();const data=await original.json();
   assert.equal(data.attachments.length,1,'Selected file uploaded exactly once');
   data.attachments=data.attachments.map(a=>({...a,status:'blocked'}));
   await route.fulfill({response:original,json:data});
 });
 await page.locator('#send-form button').click();
 await page.waitForFunction(()=>document.getElementById('send-state').textContent.includes('anexo bloqueado'));
 assert.equal(await page.locator('.upload-form input[type=file]').inputValue(),'');
 assert.equal(await page.locator('#draft-form [name=body_text]').inputValue(),'Preservar texto e anexo no bloqueio.');
 assert.equal(sends,0);record('selected-file-uploaded-and-send-stopped-before-unsafe-attachment');
 await page.locator('#send-form button').click();
 await page.waitForFunction(()=>!document.querySelector('#send-form button').disabled);
 assert.equal(sends,0);record('retry-does-not-reupload-or-send-blocked-attachment');
 await page.unroute('**/drafts/*/status');page.off('request',sendObserver);
 await page.reload();assert.equal(await page.locator('.attachment-list .attachment').count(),1);
 await page.getByRole('button',{name:'Remover qa.txt',exact:true}).click();
 // A lost save response must preserve the text and prevent an uncertain send/retry.
 await page.locator('#draft-form [name=body_text]').fill('Texto preservado na falha de conexão.');
 const saveUrl=await page.locator('#draft-form').getAttribute('action');
 await page.route(base+saveUrl,route=>route.abort());
 await page.locator('#send-form button').click();
 await page.waitForFunction(()=>document.getElementById('send-state').textContent.includes('conexão não confirmou'));
 assert.equal(await page.locator('#draft-form [name=body_text]').inputValue(),'Texto preservado na falha de conexão.');
 await page.locator('#send-form button').click();
 assert.match(await page.locator('#send-state').innerText(),/operação anterior não foi confirmada/);record('network-failure-preserves-text-and-prevents-blind-retry');
 await page.unroute(base+saveUrl);page.on('dialog',dialog=>dialog.accept());
 await page.goto(base+'/admin');await page.getByRole('heading',{name:'Administração',exact:true}).waitFor();
 for(const width of [390,1440]) {await page.setViewportSize({width,height:1000});assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1));const axe=await new AxeBuilder({page}).withTags(['wcag2a','wcag2aa']).analyze();assert.equal(axe.violations.length,0,'Admin a11y: '+axe.violations.map(v=>v.id).join(','));record('admin-layout-accessibility',{width});}
 assert.equal(external.length,0,'No external network permitted during browser QA');assert.equal(errors.length,0,'Browser script errors');
 record('no-external-requests-or-script-errors');
 fs.writeFileSync('test-results/browser.json',JSON.stringify({checks:report.length,report},null,2));
 console.log(JSON.stringify({browser_checks:report.length,external_requests:external.length,script_errors:errors.length}));
} finally {await browser.close();}
