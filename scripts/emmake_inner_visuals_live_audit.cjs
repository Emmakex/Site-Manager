#!/usr/bin/env node
'use strict';

/*
 * Fixed-origin visual acceptance for Emmake's nine interior WebP banners.
 * Public GET-only, no credentials/forms/cookies and no client content mutation.
 */
const fs = require('node:fs');
const path = require('node:path');
const ORIGIN = 'https://emmake.com';
const PREFIX = '/nuevaweb/';
const PAGES = [
  'servicios','sobre-nosotros','contacto','nuestras-marcas','blog',
  'blog-transformacion-digital','politica-de-privacidad',
  'trabaja-con-nosotros','asistentes-virtuales-de-voz'
];
const DEVICES = [['desktop',1440,900,false],['mobile',390,844,true]];
const AUDIT_ID = 'visual-inner-emmake-20261114-01';
const dir = 'audits/emmake/inner-media';
const pending = dir+'/pending/'+AUDIT_ID+'.json';

function validRequest(r) {
  return !!r && typeof r==='object' &&
    Object.keys(r).sort().join(',')==='audit_id,check,requested_by,site,version' &&
    r.version==='1' && r.audit_id===AUDIT_ID &&
    r.check==='interior-webp-9-pages' && r.site==='emmake-nuevaweb' && r.requested_by==='chatgpt';
}
function selfTest() {
  const good={version:'1',audit_id:AUDIT_ID,check:'interior-webp-9-pages',site:'emmake-nuevaweb',requested_by:'chatgpt'};
  if(!validRequest(good) || validRequest({...good,url:'https://another.example/'}) ||
    validRequest({...good,site:'production'}))throw Error('Unsafe or invalid QA command');
  if(new Set(PAGES).size!==9 || PAGES.some(v=>!/^[a-z-]+$/.test(v)))throw Error('Unsafe page allowlist');
  process.stdout.write('PASS: fixed nine-page, two-viewport, GET-only QA contract\n');
}
async function run() {
  const req=JSON.parse(fs.readFileSync(pending,'utf8'));
  if(!validRequest(req))throw Error('Invalid fixed QA request');
  const {chromium}=require('playwright');
  const browser=await chromium.launch({headless:true,args:['--no-sandbox']});
  const checks=[];
  const screenshots='artifacts/emmake-inner-media';
  fs.mkdirSync(screenshots,{recursive:true});
  try {
    for(const [device,width,height,mobile] of DEVICES) {
      const context=await browser.newContext({viewport:{width,height},isMobile:mobile,hasTouch:mobile,
        deviceScaleFactor:1,serviceWorkers:'block',reducedMotion:'reduce',locale:'es-ES'});
      try {
        for(const slug of PAGES) {
          const page=await context.newPage();
          let errors=0;page.on('pageerror',()=>errors++);
          const url=ORIGIN+PREFIX+slug+'/';
          let status=0;
          try{
            const response=await page.goto(url,{waitUntil:'domcontentloaded',timeout:30000});
            status=response?.status()||0;
            await page.waitForTimeout(1000);
          }catch(e){errors++;}
          const state=await page.evaluate((slug)=>{
            const main=document.querySelector('main');
            const computed=main?getComputedStyle(main,'::before'):null;
            const bg=computed?.backgroundImage||'';
            const match=bg.match(/url\(["']?([^"')]+)["']?\)/i);
            const viewport=document.documentElement.clientWidth;
            const width=Math.max(document.body.scrollWidth,document.documentElement.scrollWidth);
            const box=main?.getBoundingClientRect();
            const beforeHeight=Number.parseFloat(computed?.height||'0');
            return {
              slugClass:document.body.classList.contains('seo-geo-emmake-inner-visual-'+slug),
              mainPresent:!!main,photoUrl:match?.[1]||'',
              beforeHeight:Number.isFinite(beforeHeight)?Math.round(beforeHeight):0,
              beforeDisplay:computed?.display||'none',
              noOverflow:width<=viewport+4,
              mainWidth:Math.round(box?.width||0),noindex:!!document.querySelector('meta[name="robots"][content*="noindex"]')
            };
          },slug);
          let imgOk=false;
          if(state.photoUrl) {
            try {
              const target=new URL(state.photoUrl);
              if(target.origin===ORIGIN &&
                target.pathname.endsWith('/assets/images/emmake/inner/'+slug+'.webp') &&
                target.pathname.startsWith('/nuevaweb/wp-content/themes/')) {
                const resp=await context.request.get(target.toString(),{timeout:15000,maxRedirects:0});
                imgOk=resp.status()===200 && /image\/webp/i.test(resp.headers()['content-type']||'');
                await resp.dispose();
              }
            }catch{}
          }
          const ok=status===200&&state.slugClass&&state.mainPresent&&state.beforeDisplay==='block'&&
            state.beforeHeight>=125&&state.noOverflow&&state.noindex&&imgOk&&errors===0;
          checks.push({page:slug,device,ok,status,slug_class:state.slugClass,decorative_banner:state.beforeHeight,
            first_party_webp:imgOk,no_overflow:state.noOverflow,noindex:state.noindex,script_errors:errors});
          await page.screenshot({path:path.join(screenshots,device+'-'+slug+'.png'),fullPage:false});
          await page.close();
        }
      }finally{await context.close();}
    }
  }finally{await browser.close();}
  const bad=checks.filter(x=>!x.ok);
  const report={version:'1',audit_id:AUDIT_ID,checked_site:'emmake-nuevaweb',status:bad.length?'failed':'success',
    checks,failed_checks:bad.length,screenshots:checks.length};
  fs.mkdirSync(dir+'/results',{recursive:true});
  fs.writeFileSync(dir+'/results/'+AUDIT_ID+'.json',JSON.stringify(report,null,2)+'\n');
  process.stdout.write('EMMAKE_INTERIOR_VISUAL_RESULT='+JSON.stringify({status:report.status,checks:checks.length,failed:bad.length})+'\n');
  if(bad.length)process.exitCode=1;
}
(async()=>{
  const mode=process.argv[2];
  if(mode==='--self-test')selfTest();
  else if(mode==='--run-pending')await run();
  else throw Error('Unsupported mode');
})().catch(e=>{console.error('EMMAKE_INTERIOR_QA_ERROR',e.message);process.exitCode=1});
