#!/usr/bin/env node
'use strict';
// Final public production QA only. No credentials, submissions or WP writes.
const fs=require('node:fs');
const {chromium}=require('playwright');
const ORIGIN='https://emmake.com';
const ROUTES=['/','/servicios/','/sobre-nosotros/','/nuestras-marcas/','/blog/','/blog-transformacion-digital/','/contacto/','/trabaja-con-nosotros/','/asistentes-virtuales-de-voz/','/politica-de-privacidad/'];
const OUT='artifacts/emmake-final-browser';
const DEVICES=[['desktop',1440,900,false],['mobile',390,844,true]];
(async()=>{
  fs.mkdirSync(OUT,{recursive:true});
  const browser=await chromium.launch({headless:true,args:['--no-sandbox']});
  const results=[];
  try {
    for(const [device,width,height,mobile] of DEVICES){
      const ctx=await browser.newContext({viewport:{width,height},isMobile:mobile,hasTouch:mobile,deviceScaleFactor:1,reducedMotion:'reduce',locale:'es-ES',serviceWorkers:'block'});
      for(let i=0;i<ROUTES.length;i++){
        const route=ROUTES[i],page=await ctx.newPage();
        let http=0,jsErrors=0;
        page.on('pageerror',()=>jsErrors++);
        // Read-only public browser: never submit forms or allow mutation methods.
        await page.route('**/*',r=>['GET','HEAD','OPTIONS'].includes(r.request().method())?r.continue():r.abort());
        try {
          await page.addInitScript(()=>{
            window.__smLab={lcp:null,cls:0};
            try{new PerformanceObserver(list=>{for(const e of list.getEntries())window.__smLab.lcp=Math.round(e.startTime)}).observe({type:'largest-contentful-paint',buffered:true})}catch{}
            try{new PerformanceObserver(list=>{for(const e of list.getEntries())if(!e.hadRecentInput)window.__smLab.cls+=e.value}).observe({type:'layout-shift',buffered:true})}catch{}
          });
          const response=await page.goto(ORIGIN+route,{waitUntil:'load',timeout:30000});http=response?.status()||0;
          await page.waitForTimeout(1000);
          const data=await page.evaluate(()=>{
            const images=[...document.images],nav=performance.getEntriesByType('navigation')[0];
            const validJsonLd=[...document.querySelectorAll('script[type="application/ld+json"]')].map(e=>{try{JSON.parse(e.textContent);return true}catch{return false}});
            const visible=e=>{const b=e.getBoundingClientRect();return b.width>0&&b.height>0&&getComputedStyle(e).visibility!=='hidden'};
            const links=[...document.querySelectorAll('a[href]')];
            const bad=links.filter(a=>/%category%|%postname%|%25category%25|%25postname%25/i.test(a.getAttribute('href')||'')).length;
            const staging=links.filter(a=>{try{let u=new URL(a.href);return u.origin===location.origin&&(u.pathname==='/nuevaweb'||u.pathname.startsWith('/nuevaweb/'))}catch{return false}}).length;
            const buttons=[...document.querySelectorAll('header button,header [role="button"],nav button')].filter(visible);
            const form=[...document.querySelectorAll('main form')].map(f=>({required:f.querySelectorAll('[required]').length,submit:!!f.querySelector('button[type="submit"],input[type="submit"]')}));
            return {viewport:innerWidth,document_width:document.documentElement.scrollWidth,main_present:!!document.querySelector('main'),lang:document.documentElement.lang||null,h1_count:document.querySelectorAll('h1').length,invalid_jsonld:validJsonLd.filter(x=>!x).length,jsonld_count:validJsonLd.length,staging_links:staging,malformed_links:bad,images:images.length,broken_loaded_images:images.filter(x=>x.complete&&x.currentSrc&&x.naturalWidth===0).length,missing_alt:images.filter(x=>!x.hasAttribute('alt')).length,visible_navigation_links:[...document.querySelectorAll('header nav a,header [role="navigation"] a')].filter(visible).length,visible_header_buttons:buttons.length,form_controls:form,lab:{ttfb_ms:nav?Math.round(nav.responseStart):null,load_ms:nav?Math.round(nav.loadEventEnd):null,lcp_ms:window.__smLab?.lcp??null,cls:Math.round((window.__smLab?.cls||0)*1000)/1000,resource_transfer_bytes:Math.round(performance.getEntriesByType('resource').reduce((s,e)=>s+(e.transferSize||0),0))}};
          });
          const actual=new URL(page.url());
          results.push({route,device,http,exact_url:actual.origin===ORIGIN&&actual.pathname===route&&!actual.search,js_errors:jsErrors,...data});
          await page.screenshot({path:OUT+'/'+device+'-'+i+'.png',fullPage:false,timeout:10000});
        }catch(e){results.push({route,device,http,error:e.name==='TimeoutError'?'timeout':'navigation_or_render_failed'});}
        finally{await page.close();}
      }
      await ctx.close();
    }
  }finally{await browser.close();}
  const report={site:'emmake-produccion',observed_at:new Date().toISOString(),wordpress_mutations:0,measurement:'unthrottled Chromium single navigation; lab only, not field Core Web Vitals',results};
  fs.writeFileSync(OUT+'/report.json',JSON.stringify(report,null,2)+'\n');
  console.log(JSON.stringify(report));
})().catch(()=>{process.exitCode=1;});
