#!/usr/bin/env node
'use strict';

/* Post-Theme-install acceptance: the 10 WordPress-published EMMAKE pages. No arbitrary URLs. */
const fs = require('node:fs');
const path = require('node:path');
const ORIGIN = 'https://emmake.com/nuevaweb/';
const PAGES = [
  ['home',''], ['blog','blog/'], ['services','servicios/'],
  ['about','sobre-nosotros/'], ['contact','contacto/'],
  ['brands','nuestras-marcas/'], ['blog-digital','blog-transformacion-digital/'],
  ['privacy','politica-de-privacidad/'],
  ['careers','trabaja-con-nosotros/'], ['voice','asistentes-virtuales-de-voz/'],
];
const SIZES = [['desktop', 1440, 900, false], ['mobile', 390, 844, true]];
const OUT = 'artifacts/emmake-browser';
const ROOT = 'audits/emmake/browser';
const H1 = 'Conectamos estrategia, datos y tecnología para impulsar lo que viene.';
const ID_PATTERN = /^visual-emmake-202611(?:11|12|13)-[0-9]{2}$/;

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
  if(!validRequest({...r,audit_id:'visual-emmake-20261113-01'},'visual-emmake-20261113-01'))throw Error('Nov 13 QA request refused');
  if(PAGES.length!==10 || new Set(PAGES.map(p=>p[1])).size!==10)throw Error('Published page inventory mismatch');
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
              contentH1Count:document.querySelectorAll('main .wp-block-post-content h1').length,
              templateH1Count:document.querySelectorAll('main h1.seo-geo-page-title,main h1.wp-block-post-title').length,
              navLinksToUnavailablePages:[...document.querySelectorAll('header a[href],nav a[href]')].filter(a=>{
                try{
                  const p=new URL(a.href).pathname.replace(/\/$/,'');
                  return p==='/nuevaweb/casos-de-exito'||p==='/nuevaweb/calculadora-de-donativos';
                }catch{return false;}
              }).length,
              editorialWords:(()=>{
                const main=document.querySelector('main');
                if(!main)return 0;
                const full=(main.innerText||'').trim();
                const primary=h1.length>0?h1[0]:'';
                return (full.replace(primary,'').match(/\S+/g)||[]).length;
              })(),
              formCount:document.querySelectorAll('main form').length,
              postCards:document.querySelectorAll('main .wp-block-post-template > li, main .wp-block-latest-posts > li').length,
              blogPostLinks:(()=>{
                const known=new Set(['','blog/','servicios/','sobre-nosotros/','contacto/','nuestras-marcas/',
                  'blog-transformacion-digital/','politica-de-privacidad/','calculadora-de-donativos/',
                  'trabaja-con-nosotros/','asistentes-virtuales-de-voz/','casos-de-exito/']);
                return [...document.querySelectorAll('main a[href]')].filter(a=>{
                  try{
                    const u=new URL(a.href);
                    return u.protocol==='https:'&&u.hostname==='emmake.com'&&
                      u.pathname.startsWith('/nuevaweb/')&&!known.has(u.pathname.slice('/nuevaweb/'.length));
                  }catch{return false;}
                }).length;
              })(),
              internalContact:hrefs.some(h=>h.includes('/nuevaweb/contacto/')),
              linkCount:hrefs.length, hrefs,
              navReady:navLinks||menuButton,

              brandCtas:(()=>{
                const anchors=[...new Set(document.querySelectorAll('main .wp-block-post-content .wp-block-button > a,main .wp-block-post-content a.wp-block-button__link'))];
                const brightness=rgb=>{
                  const nums=(rgb.match(/[0-9.]+/g)||[]).slice(0,3).map(Number);
                  if(nums.length!==3||nums.some(n=>!Number.isFinite(n)))return null;
                  return nums.reduce((sum,n,i)=>{
                    const v=n/255;
                    const lin=v<=0.04045?v/12.92:((v+0.055)/1.055)**2.4;
                    return sum+lin*[0.2126,0.7152,0.0722][i];
                  },0);
                };
                return anchors.map(a=>{
                  const cs=getComputedStyle(a),bg=brightness(cs.backgroundColor),fg=brightness(cs.color);
                  const contrast=bg===null||fg===null?0:
                    (Math.max(bg,fg)+.05)/(Math.min(bg,fg)+.05);
                  return {contrast:Math.round(contrast*100)/100,
                    height:Math.round(a.getBoundingClientRect().height),
                    visible:a.getBoundingClientRect().width>0&&a.getBoundingClientRect().height>0};
                }).slice(0,15);
              })(),
              contactFormState:(()=>{
                const forms=[...document.querySelectorAll('main form')];
                return forms.map(f=>({
                  native:f.classList.contains('seo-geo-contact-form'),
                  post:f.method.toLowerCase()==='post',
                  wpEndpoint:(()=>{try{
                    const u=new URL(f.getAttribute('action'),location.href);return u.origin===location.origin&&u.pathname.endsWith('/wp-admin/admin-post.php');
                  }catch{return false;}})(),
                  nonce:!!f.querySelector('input[name="_seo_geo_contact_nonce"][value]'),
                  hiddenAction:!!f.querySelector('input[name="action"][value]'),
                  requiredFields:f.querySelectorAll('input[required],textarea[required],select[required]').length,
                  submit:!!f.querySelector('button[type="submit"],input[type="submit"]'),
                })).slice(0,5);
              })(),
              blogDigitalLists:(()=>{
                const lists=[...document.querySelectorAll('main .wp-block-latest-posts')];
                const posts=lists.flatMap(ul=>[...ul.querySelectorAll(':scope > li')]);
                const keys=posts.map(li=>{
                  const a=li.querySelector('.wp-block-latest-posts__post-title[href],a[href]');
                  if(!a)return null;
                  try{const u=new URL(a.href);return u.origin===location.origin?u.pathname:null}
                  catch{return null}
                }).filter(Boolean);
                return {lists:lists.length,entries:posts.length,unique:new Set(keys).size,
                  repeated:keys.length-new Set(keys).size,unlinked:posts.length-keys.length};
              })(),
              aboutInner:(()=>{
                const root=document.querySelector('main .wp-block-post-content');
                const gallery=root?.querySelector(':scope > .seo-geo-legacy-about-brand-grid');
                const cards=[...(root?.querySelectorAll(':scope > .seo-geo-legacy-about-card-grid')||[])];
                const logos=[...(gallery?.querySelectorAll(':scope > figure.wp-block-image')||[])];
                const blurbs=cards.flatMap(card=>[...card.querySelectorAll(':scope > .sgmb-divi-blurb')]);
                const columns=el=>el?getComputedStyle(el).gridTemplateColumns.trim().split(/\s+/).filter(Boolean).length:0;
                return {
                  enhanced:!!root?.classList.contains('seo-geo-legacy-about-enhanced'),
                  assetLoaded:[...document.scripts].some(el=>el.src?.includes('corporate-about-v1.js')),
                  brandGroups:gallery?1:0,cardGroups:cards.length,
                  logos:logos.length,blurbs:blurbs.length,
                  maxLogoHeight:Math.round(Math.max(0,...logos.map(el=>el.getBoundingClientRect().height))),
                  maxLogoImageHeight:Math.round(Math.max(0,...logos.map(el=>el.querySelector('img')?.getBoundingClientRect().height||0))),
                  brandColumns:columns(gallery),cardColumns:cards.map(columns)
                };
              })(),
              blogDigitalInner:(()=>{
                const root=document.querySelector('main .wp-block-post-content');
                const feature=root?.querySelector('.seo-geo-blog-digital-feature');
                const archive=root?.querySelector(':scope > .seo-geo-blog-digital-archive');
                const topic=root?.querySelector('.seo-geo-blog-digital-topic-grid');
                const categories=root?.querySelector('.seo-geo-blog-digital-category-grid');
                return {
                  enhanced:!!root?.classList.contains('seo-geo-blog-digital-enhanced'),
                  assetLoaded:[...document.scripts].some(el=>el.src?.includes('corporate-blog-digital-v1.js')),
                  featured:feature?.querySelectorAll('ul.wp-block-latest-posts > li').length||0,
                  archive:archive?.querySelectorAll(':scope > li').length||0,
                  topics:topic?.children.length||0,categories:categories?.children.length||0,
                  newsletterDisabled:root?.querySelectorAll('.seo-geo-blog-digital-newsletter-unavailable').length||0,
                  archiveColumns:archive?getComputedStyle(archive).gridTemplateColumns.trim().split(/\s+/).filter(Boolean).length:0
                };
              })(),
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
          add('one_h1',data.headingCount===1,{
            total:data.headingCount,content_h1:data.contentH1Count,template_h1:data.templateH1Count
          });
          add('staging_noindex_global',data.noindex);
          add('no_header_links_to_unpublished_legacy',data.navLinksToUnavailablePages===0,
            {links:data.navLinksToUnavailablePages});
          const minWords={services:60,about:35,brands:35,careers:30};
          if(Object.prototype.hasOwnProperty.call(minWords,name)) {
            add('editorial_body_review',data.editorialWords>=minWords[name],
              {word_count:data.editorialWords,minimum_for_initial_review:minWords[name]});
          }
          if(name==='contact'){
            add('contact_form_present',data.formCount>=1,{forms:data.formCount});
            add('contact_native_form_ready',data.contactFormState.some(f=>f.native&&f.post&&
              f.wpEndpoint&&f.nonce&&f.hiddenAction&&f.requiredFields>=2&&f.submit),
              {forms_checked:data.contactFormState.length,
                configured_native:data.contactFormState.filter(f=>f.native&&f.post&&f.wpEndpoint&&f.nonce).length,
                required_fields:data.contactFormState.map(f=>f.requiredFields)});
          }
          if(name==='brands'){
            add('cta_accessible_contrast',data.brandCtas.length>0&&
              data.brandCtas.every(c=>c.contrast>=4.5&&c.height>=44&&c.visible),
              {buttons:data.brandCtas.length,min_contrast:Math.min(...data.brandCtas.map(c=>c.contrast)),
                min_height:Math.min(...data.brandCtas.map(c=>c.height))});
          }
          if(name==='about'){
            const a=data.aboutInner;
            add('installed_about_asset_and_enhancer',a.assetLoaded&&a.enhanced,
              {asset_loaded:a.assetLoaded,enhanced:a.enhanced});
            add('installed_about_brand_gallery',a.brandGroups===1&&a.logos===8&&
              a.brandColumns===(device==='desktop'?4:2)&&
              a.maxLogoHeight<=(device==='desktop'?180:150)&&
              a.maxLogoImageHeight<=(device==='desktop'?140:115),a);
            add('installed_about_services_cards',a.cardGroups===2&&a.blurbs===8&&
              a.cardColumns.every(cols=>cols===(device==='desktop'?2:1)),
              {card_groups:a.cardGroups,blurbs:a.blurbs,columns:a.cardColumns});
          }
          if(name==='blog-digital'){
            const b=data.blogDigitalInner;
            add('editorial_latest_posts_inventory',true,data.blogDigitalLists);
            add('installed_blog_asset_and_enhancer',b.assetLoaded&&b.enhanced,
              {asset_loaded:b.assetLoaded,enhanced:b.enhanced});
            add('installed_blog_unique_published_cards',
              data.blogDigitalLists.entries===5&&data.blogDigitalLists.unique===5&&
              data.blogDigitalLists.repeated===0&&data.blogDigitalLists.unlinked===0,
              data.blogDigitalLists);
            add('installed_blog_featured_archive',b.featured===1&&b.archive===4&&
              b.archiveColumns===(device==='desktop'?2:1),
              {featured:b.featured,archive:b.archive,archive_columns:b.archiveColumns});
            add('installed_blog_topics_preserved',b.topics===6&&b.categories===12,
              {topics:b.topics,categories:b.categories});
            add('installed_blog_inert_newsletter_hidden',b.newsletterDisabled===1,
              {disabled_placeholder_cards:b.newsletterDisabled});
          }
          if(name==='blog')add('posts_listed',data.postCards>0||data.blogPostLinks>=3,
            {native_or_latest_post_cards:data.postCards,public_post_links:data.blogPostLinks});
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
  // Old immutable requests remain in Git history. Only incomplete audits count
  // against the concurrency bound, so validated reruns do not deadlock on history.
  const files=fs.existsSync(dir)?fs.readdirSync(dir).filter(x=>x.endsWith('.json') &&
    !fs.existsSync(path.join(ROOT,'results',x))):[];
  if(files.length>5)throw Error('too many unresolved audits');
  for(const f of files.sort()){
    const id=f.replace(/\.json$/,'');
    const req=JSON.parse(fs.readFileSync(path.join(dir,f),'utf8'));
    if(!validRequest(req,id))throw Error('invalid fixed-domain request');
    if(!fs.existsSync(path.join(ROOT,'results',f)))await audit(id);
  }
}
main().catch(()=>{process.stderr.write('Fixed-origin browser audit failed safely\n');process.exitCode=1;});
