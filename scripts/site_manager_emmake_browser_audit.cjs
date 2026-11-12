#!/usr/bin/env node
'use strict';

/* Read-only browser QA for all 12 fixed EMMAKE published pages. No arbitrary URLs. */
const fs = require('node:fs');
const path = require('node:path');
const ORIGIN = 'https://emmake.com/nuevaweb/';
const PAGES = [
  ['home',''], ['blog','blog/'], ['services','servicios/'],
  ['about','sobre-nosotros/'], ['contact','contacto/'],
  ['brands','nuestras-marcas/'], ['blog-digital','blog-transformacion-digital/'],
  ['privacy','politica-de-privacidad/'], ['calculator','calculadora-de-donativos/'],
  ['careers','trabaja-con-nosotros/'], ['voice','asistentes-virtuales-de-voz/'],
  ['cases','casos-de-exito/'],
];
const SIZES = [['desktop', 1440, 900, false], ['mobile', 390, 844, true]];
const OUT = 'artifacts/emmake-browser';
const ROOT = 'audits/emmake/browser';
const H1 = 'Conectamos estrategia, datos y tecnología para impulsar lo que viene.';
const ID_PATTERN = /^visual-emmake-202611(?:11|12)-[0-9]{2}$/;

function broken(href) {
  return /%category%|%postname%|%25category%25|%25postname%25|\{[a-f0-9]{30,}\}(?:category|postname)/i.test(href);
}
function classifyPublicArticleLink(href) {
  // Classification only; never return or log the user-facing URL.
  try {
    const u=new URL(href);
    if(u.protocol!=='https:'||u.hostname!=='emmake.com'||u.port||u.username||u.password)
      return 'wrong_origin';
    if(!u.pathname.startsWith('/nuevaweb/'))return 'outside_staging';
    if(broken(href))return 'unresolved_permalink_marker';
    if(u.hash)return 'fragment';
    if(u.search)return u.searchParams.has('p')?'query_post_permalink':'other_query';
    return 'eligible';
  }catch{return 'malformed_url';}
}
function validRequest(r, id) {
  return r && typeof r === 'object' && Object.keys(r).sort().join(',') === 'audit_id,check,requested_by,site,version'
    && r.version === '1' && r.audit_id === id && ID_PATTERN.test(id)
    && r.check === 'responsive-links' && r.site === 'emmake-nuevaweb' && r.requested_by === 'chatgpt';
}
function selfTest() {
  const id = 'visual-emmake-20261111-01';
  const r = { version: '1', audit_id: id, check: 'responsive-links', site: 'emmake-nuevaweb', requested_by: 'chatgpt' };
  if (!validRequest(r, id) || validRequest({...r,url:'https://attacker.test/'},id)) throw Error('request contract failed');
  if (!broken('https://emmake.com/nuevaweb/{d276abfeceab40cca0e158fc6217176554b8e54a1f85b6eb004941797db52171}category{d276abfeceab40cca0e158fc6217176554b8e54a1f85b6eb004941797db52171}/')) throw Error('permalink pattern missed');
  if (broken('https://emmake.com/nuevaweb/blog/')) throw Error('valid link rejected');
  if (classifyPublicArticleLink('https://emmake.com/nuevaweb/post/')!=='eligible')throw Error('valid post rejected');
  if (classifyPublicArticleLink('https://emmake.com/nuevaweb/?p=43')!=='query_post_permalink')throw Error('query fallback not classified');
  if (classifyPublicArticleLink('https://outside.example.test/post/')!=='wrong_origin')throw Error('external post not rejected');
  if (!validRequest({...r,audit_id:'visual-emmake-20261112-02'},'visual-emmake-20261112-02'))throw Error('New audit date refused');
  if(PAGES.length!==12 || new Set(PAGES.map(p=>p[1])).size!==12)throw Error('Published page inventory mismatch');
  if(PAGES.some(p=>p[1].includes('..')||p[1].startsWith('/')||p[1].includes('?')))throw Error('Unsafe fixed path');
  process.stdout.write('PASS: fixed-domain responsive browser QA contract\n');
}

async function audit(id) {
  const { chromium } = require('playwright');
  fs.mkdirSync(OUT,{recursive:true});
  fs.mkdirSync(ROOT+'/results',{recursive:true});
  const browser = await chromium.launch({headless:true,args:['--no-sandbox']});
  const checks=[], errors=[], screenshots=[];
  try {
    for (const [device,width,height,isMobile] of SIZES) {
      const context = await browser.newContext({viewport:{width,height},isMobile,hasTouch:isMobile,
        reducedMotion:'reduce',locale:'es-ES',deviceScaleFactor:1,serviceWorkers:'block'});
      try {
        for (const [name,route] of PAGES) {
          const pg=await context.newPage();
          let jsErrors=0, status=0;
          pg.on('pageerror',()=>jsErrors++);
          try {
            const response=await pg.goto(ORIGIN+route,{waitUntil:'domcontentloaded',timeout:30000});
            status=response?response.status():0;
            await pg.waitForTimeout(850);
          } catch(e) { errors.push(device+'_'+name+'_navigation'); }
          const actual=pg.url();
          let locationOk=false;
          try {
            const u=new URL(actual);
            locationOk=u.protocol==='https:' && u.hostname==='emmake.com'
              && u.pathname.startsWith('/nuevaweb/') && !u.search;
          } catch {}
          const data=await pg.evaluate((approvedH1)=>{
            const hrefs=[...document.querySelectorAll('a[href]')].map(x=>x.getAttribute('href')||'');
            const h1=[...document.querySelectorAll('h1')].map(x=>(x.textContent||'').replace(/\s+/g,' ').trim());
            const navLinks=[...document.querySelectorAll('nav a,[role="navigation"] a')].some(x=>{
              const b=x.getBoundingClientRect();return b.width>0&&b.height>0;
            });
            const menuButton=[...document.querySelectorAll('button,[role="button"]')].some(x=>{
              const b=x.getBoundingClientRect();
              const label=((x.getAttribute('aria-label')||'')+' '+(x.textContent||'')).toLowerCase();
              return b.width>0&&b.height>0 && (/menu|menú|navigation|navegación/.test(label)||x.hasAttribute('aria-expanded'));
            });
            const imgs=[...document.images];
            return {
              width:document.documentElement.scrollWidth, viewport:innerWidth,
              main:!!document.querySelector('main'), lang:!!document.documentElement.lang,
              h1Okay:h1.length===1 && h1[0]===approvedH1,
              theme:!!document.querySelector('.seo-geo-corporate-v5-home'),
              noindex:!!document.querySelector('meta[name="robots"][content*="noindex"]'),
              headingCount:h1.length,
              editorialWords:(()=>{
                const main=document.querySelector('main');
                if(!main)return 0;
                const full=(main.innerText||'').trim();
                const primary=h1.length>0?h1[0]:'';
                return (full.replace(primary,'').match(/\S+/g)||[]).length;
              })(),
              formCount:document.querySelectorAll('main form').length,
              postCards:document.querySelectorAll('main .wp-block-post-template > li').length,
              internalContact:hrefs.some(h=>h.includes('/nuevaweb/contacto/')),
              linkCount:hrefs.length, hrefs,
              navReady:navLinks||menuButton,
              images:imgs.length,
              brokenImages:imgs.filter(x=>x.complete&&x.naturalWidth===0&&x.currentSrc).length,
              hero_bounds: (() => {
                const r=document.querySelector('.seo-geo-corporate-native-hero')?.getBoundingClientRect();
                return r ? {left:r.left,right:r.right} : null;
              })(),
              method_cards: (() => {
                const cards=[...document.querySelectorAll('.seo-geo-corporate-native-process .seo-geo-corporate-process-grid > .seo-geo-corporate-process-step')];
                return cards.map(card=>{
                  const bounds=card.getBoundingClientRect();
                  const elements=[card.querySelector('.seo-geo-corporate-process-step__number'),card.querySelector('h3'),card.querySelector('p:last-child')];
                  return {
                    inset:Number.parseFloat(getComputedStyle(card).paddingLeft)||0,
                    content_ok:elements.every(el=>{
                      if(!el)return false;
                      const r=el.getBoundingClientRect();
                      return r.left-bounds.left>=24 && bounds.right-r.right>=20;
                    })
                  };
                });
              })(),
              insight_links:[...document.querySelectorAll('.seo-geo-corporate-native-insights .wp-block-post-title a[href]')].slice(0,3).map(a=>a.href),
            };
          }, H1);
          const prefix=device+'_'+name;
          const add=(key,ok,details)=>checks.push({id:prefix+'_'+key,ok:Boolean(ok),...(details===undefined?{}:{details})});
          add('http_200',status===200,status);
          add('staging_https',locationOk);
          add('no_overflow',data.width<=data.viewport+4,{document:data.width,viewport:data.viewport});
          add('main_present',data.main);
          add('lang_present',data.lang);
          add('no_broken_images',data.brokenImages===0,{images:data.images,failed:data.brokenImages});
          const bad=data.hrefs.filter(broken).length;
          add('no_malformed_links',bad===0,{total:data.linkCount,malformed:bad});
          add('no_javascript_exceptions',jsErrors===0,jsErrors);
          add('one_h1',data.headingCount===1,data.headingCount);
          add('staging_noindex_global',data.noindex);
          const minWords={services:60,about:35,brands:35,careers:30,cases:35};
          if(Object.prototype.hasOwnProperty.call(minWords,name)) {
            add('editorial_body_review',data.editorialWords>=minWords[name],
              {word_count:data.editorialWords,minimum_for_initial_review:minWords[name]});
          }
          if(name==='contact')add('contact_form_present',data.formCount>=1,{forms:data.formCount});
          if(name==='blog')add('posts_listed',data.postCards>0,{cards:data.postCards});
          if(name==='home') {
            add('theme_rendered',data.theme);
            add('approved_h1',data.h1Okay);
            add('contact_link',data.internalContact);
            add('navigation_available',data.navReady);
            add('staging_noindex',data.noindex);
            add('hero_background_full_bleed',!!data.hero_bounds&&Math.abs(data.hero_bounds.left)<=2&&Math.abs(data.hero_bounds.right-data.viewport)<=2);
            add('method_three_cards',data.method_cards.length===3,data.method_cards.length);
            add('method_inner_padding',data.method_cards.length===3&&data.method_cards.every(x=>x.inset>=24&&x.content_ok),
              {cards_checked:data.method_cards.length, minimum_inset:Math.round(Math.min(...data.method_cards.map(x=>x.inset)))});
            // Follow only up to three public article links, restricted to the bound
            // staging origin and path. Never persist their raw URLs in Git results.
            const articles=data.insight_links;
            const outcomes=[];
            for(const href of articles){
              const category=classifyPublicArticleLink(href);
              if(category!=='eligible'){
                outcomes.push({ok:false,reason:category});
                continue;
              }
              try{
                const resp=await context.request.get(href,{timeout:12000,maxRedirects:0});
                const status=resp.status();
                outcomes.push({ok:status===200,reason:status===200?'http_200':('http_'+String(status))});
                await resp.dispose();
              }catch{outcomes.push({ok:false,reason:'network_error'});}
            }
            const reasons={};
            for(const outcome of outcomes)reasons[outcome.reason]=(reasons[outcome.reason]||0)+1;
            add('insight_links_http_200',articles.length>0&&articles.length<=3&&outcomes.length===articles.length&&outcomes.every(x=>x.ok),
              {links_checked:articles.length,live_200:outcomes.filter(x=>x.ok).length,reasons});
            // Read-only published WordPress REST inventory, restricted to one
            // fixed provider URL. For numeric public post IDs, compare the safe
            // ?p=<id> fallback without writing content or exporting post URLs.
            let wpRestStatus=0;
            let publicIds=[];
            const restUrl='https://emmake.com/nuevaweb/wp-json/wp/v2/posts?per_page=3&_fields=id,status';
            try {
              const rest=await context.request.get(restUrl,{timeout:12000,maxRedirects:0});
              wpRestStatus=rest.status();
              if(wpRestStatus===200){
                const json=await rest.json();
                if(Array.isArray(json))publicIds=json.slice(0,3)
                  .filter(p=>p&&p.status==='publish'&&Number.isSafeInteger(p.id)&&p.id>0&&p.id<100000000)
                  .map(p=>p.id);
              }
              await rest.dispose();
            }catch{}
            add('published_posts_rest_probe',wpRestStatus===200&&publicIds.length>0,
              {rest_http_status:wpRestStatus,public_posts_found:publicIds.length});
            const fallbackStatuses={};
            for(const id of publicIds){
              const candidate='https://emmake.com/nuevaweb/?p='+String(id);
              try{
                const resp=await context.request.get(candidate,{timeout:12000,maxRedirects:0});
                const status=resp.status();
                const key='http_'+String(status);
                fallbackStatuses[key]=(fallbackStatuses[key]||0)+1;
                await resp.dispose();
              }catch{fallbackStatuses.network_error=(fallbackStatuses.network_error||0)+1;}
            }
            // Standard ?p=ID may legitimately redirect (301/302) to its
            // canonical permalink; report its status, but only gate on the
            // featured canonical links resolving HTTP 200 above.
            add('post_id_fallback_probe_completed',publicIds.length>0&&
              Object.values(fallbackStatuses).reduce((a,b)=>a+b,0)===publicIds.length,
              {posts_checked:publicIds.length,status_counts:fallbackStatuses});

          }
          const png=prefix+'.png';
          try {
            await pg.screenshot({path:path.join(OUT,png),fullPage:true,timeout:25000,animations:'disabled'});
            screenshots.push(png);
          } catch { errors.push(prefix+'_screenshot'); }
          await pg.close();
        }
      } finally { await context.close(); }
    }
  } finally { await browser.close(); }
  const report={
    version:'1',audit_id:id,site:'emmake-nuevaweb',checked_origin:ORIGIN,
    checked_at:new Date().toISOString(),
    status:checks.some(x=>!x.ok)||errors.length?'failed':'passed',
    checks,errors,
    screenshots_taken:screenshots.length,
    visual_human_review_completed:false,
    html_in_results:false,screenshots_in_git_results:false,
  };
  fs.writeFileSync(path.join(OUT,'report.json'),JSON.stringify(report,null,2)+'\n');
  fs.writeFileSync(path.join(ROOT,'results',id+'.json'),JSON.stringify(report,null,2)+'\n');
  process.stdout.write(JSON.stringify({audit:id,status:report.status,
    checked:checks.length,failed:checks.filter(x=>!x.ok).map(x=>x.id),
    screenshots:screenshots.length})+'\n');
}
async function main() {
  if(process.argv.length===3&&process.argv[2]==='--self-test'){ selfTest();return; }
  if(process.argv.length!==3||process.argv[2]!=='--run-pending')throw Error('only fixed --run-pending allowed');
  const dir=ROOT+'/pending';
  const files=fs.existsSync(dir)?fs.readdirSync(dir).filter(x=>x.endsWith('.json')):[];
  if(files.length>5)throw Error('too many audits');
  for(const f of files.sort()){
    const id=f.replace(/\.json$/,'');
    const req=JSON.parse(fs.readFileSync(path.join(dir,f),'utf8'));
    if(!validRequest(req,id))throw Error('invalid fixed-domain request');
    if(!fs.existsSync(path.join(ROOT,'results',f)))await audit(id);
  }
}
main().catch(()=>{process.stderr.write('Fixed-origin browser audit failed safely\n');process.exitCode=1;});
