/** Hosted-only diagnostic: PLAYWRIGHT_MODULE=/path/to/playwright node playground/probe-hosted.cjs [main|stable|minimal] [output-dir]
 * Each invocation uses a fresh browser context. Retry/reload are diagnostic observations, never fixes.
 */
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const fs = require('node:fs');
const path = require('node:path');
(async () => {
 const mode = process.argv[2] || 'main';
 if (!['main','stable','minimal'].includes(mode)) throw new Error('Expected main, stable, or minimal');
 const out = process.argv[3] || '/tmp/keel-playground-evidence'; fs.mkdirSync(out,{recursive:true});
 const readme = fs.readFileSync(path.join(__dirname,'../README.md'),'utf8');
 const links = [...readme.matchAll(/https:\/\/playground\.wordpress\.net\/\?blueprint-url=[^)\s]+/g)].map(m=>m[0]);
 const url = mode === 'minimal' ? 'https://playground.wordpress.net/#'+encodeURIComponent(JSON.stringify({preferredVersions:{php:'8.3',wp:'latest'},login:true,landingPage:'/wp-admin/'})) : links.find(u=>u.includes(mode==='stable'?'blueprint-stable':'blueprint-hosted'));
 const browser = await chromium.launch({headless:true, ...(process.env.CHROMIUM_PATH?{executablePath:process.env.CHROMIUM_PATH}:{})});
 const context = await browser.newContext(); const page=await context.newPage();
 const events=[]; const start=Date.now(); const log=(type,data)=>events.push({ms:Date.now()-start,type,...data});
 context.on('serviceworker',worker=>log('serviceworker',{url:worker.url()}));
 page.on('console',m=>log('console',{level:m.type(),text:m.text()}));
 page.on('pageerror',e=>log('pageerror',{text:e.stack}));
 page.on('requestfailed',r=>log('requestfailed',{url:r.url(),error:r.failure()}));
 page.on('response',async r=>{log('response',{url:r.url(),status:r.status(),sw:r.fromServiceWorker(),headers:await r.allHeaders().catch(()=>({}))});});
 async function snapshot(stage){
 const frames=[]; for(const frame of page.frames()) frames.push(await frame.evaluate(async()=>({url:location.href,text:document.body?.innerText.slice(0,1800),readyState:document.readyState,bodyFont:document.body && getComputedStyle(document.body).fontFamily,styles:[...document.querySelectorAll('link[rel=stylesheet]')].map(l=>({href:l.href,loaded:!!l.sheet})),sw: navigator.serviceWorker?.controller?.scriptURL,registrations: await navigator.serviceWorker?.getRegistrations().then(rs=>rs.map(r=>({scope:r.scope,active:r.active?.scriptURL}))).catch(()=>[])})).catch(e=>({url:frame.url(),error:e.message})));
 log('snapshot',{stage,frames}); await page.screenshot({path:path.join(out,`${mode}-${stage}.png`)}); console.log(JSON.stringify({stage,frames:frames.map(f=>({url:f.url,text:f.text?.slice(0,120),styles:f.styles?.length,missingStyles:f.styles?.filter(s=>!s.loaded),sw:f.sw,readyState:f.readyState,bodyFont:f.bodyFont}))}));
 }
 async function settle(){await page.waitForFunction(()=>document.body?.innerText.includes('Retry') || (!document.body?.innerText.includes('Loading…') && [...document.querySelectorAll('iframe')].some(f=>{try{return !!f.contentDocument?.querySelector('iframe')?.contentDocument?.querySelector('#wpbody')}catch{return false}})),{},{timeout:120000}).catch(e=>{log('settle-timeout',{text:e.message});process.exitCode=2;});}
 try {log('start',{url,browserVersion:browser.version(),nodeVersion:process.version});await page.goto(url,{waitUntil:'domcontentloaded',timeout:60000});await settle();await snapshot('initial');
 const wpFrame=page.frames().find(f=>f.url().includes('/wp-admin/'));
 if(wpFrame && !events.some(e=>e.type==='settle-timeout')) {
  const base=wpFrame.url().split('/wp-admin/')[0];
  await wpFrame.goto(base+'/wp-admin/plugins.php',{waitUntil:'load'});
  log('plugin-verification',{rows:await wpFrame.locator('tr[data-slug="keel-defaults"],tr[data-slug="keel"]').evaluateAll(rows=>rows.map(row=>({classes:row.className,text:row.innerText}))) });
  await wpFrame.goto(base+'/wp-admin/edit.php',{waitUntil:'load'});
  log('sample-post-verification',{present:await wpFrame.getByRole('link',{name:'A post to try the defaults against',exact:true}).count()>0});
 } 
 const retry=page.getByRole('button',{name:/retry/i});if(await retry.count()){await retry.first().click();await settle();await snapshot('retry');}
 if(process.env.PROBE_RELOAD==='1'){await page.reload({waitUntil:'domcontentloaded'});await settle();await snapshot('reload');}
 } finally {fs.writeFileSync(path.join(out,`${mode}-${Date.now()}.json`),JSON.stringify(events,null,2));await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
