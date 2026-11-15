#!/usr/bin/env node
'use strict';
// Public GET-only acceptance of two fixed Emmake interior routes.
// Check expanded mobile menu geometry, contrast and native close; never write WP.
const fs=require('node:fs'),path=require('node:path');
const ORIGIN='https://emmake.com/nuevaweb/';
const PAGES=['servicios/','sobre-nosotros/'];
const OUT='artifacts/emmake-inner-mobile-menu';
function selfTest(){
  if(PAGES.length!==2||PAGES.some(p=>!(/^[a-z-]+\/$/).test(p))||ORIGIN!=='https://emmake.com/nuevaweb/')
    throw Error('Non-allowlisted route');
  console.log('PASS: bounded read-only interior mobile navigation QA');
}
async function main(){
  if(process.argv[2]==='--self-test'){selfTest();return;}
  if(process.argv[2]!=='--run'||process.argv.length!==3)throw Error('Invalid invocation');
  fs.mkdirSync(OUT,{recursive:true});
  const {chromium}=require('playwright');
  const browser=await chromium.launch({headless:true,args:['--no-sandbox']});
  const checks=[];let jsErrors=0;
  try{
    const ctx=await browser.newContext({viewport:{width:390,height:844},isMobile:true,
      hasTouch:true,deviceScaleFactor:1,locale:'es-ES',serviceWorkers:'block',
      reducedMotion:'reduce'});
    try{
      for(const slug of PAGES){
        const pg=await ctx.newPage();
        pg.on('pageerror',()=>jsErrors++);
        const response=await pg.goto(ORIGIN+slug,{waitUntil:'domcontentloaded',timeout:30000});
        const status=response?.status()||0;
        if(status!==200){checks.push({slug,http:status,ok:false,reason:'http_not_200'});await pg.close();continue;}
        const toggle=pg.locator('.seo-geo-site-header .seo-geo-preset-navigation__mobile > summary').first();
        if(!await toggle.isVisible()){checks.push({slug,http:status,ok:false,reason:'toggle_not_visible'});await pg.close();continue;}
        await toggle.click();
        await pg.waitForTimeout(200);
        for(const width of [390,430]){
          await pg.setViewportSize({width,height:844});
          await pg.waitForTimeout(100);
          const m=await pg.evaluate(()=>{
            const el=s=>document.querySelector(s);
            const header=el('.seo-geo-site-header');
            const brand=el('.seo-geo-site-header .seo-geo-site-brand');
            const details=el('.seo-geo-site-header .seo-geo-preset-navigation__mobile');
            const toggle=details?.querySelector(':scope > summary');
            const panel=details?.querySelector(':scope > .seo-geo-preset-navigation__list');
            const main=el('main');
            const r=x=>{const b=x?.getBoundingClientRect();return b?{
              left:Math.round(b.left),right:Math.round(b.right),top:Math.round(b.top),
              bottom:Math.round(b.bottom),width:Math.round(b.width),height:Math.round(b.height)
            }:null;};
            const luminance=c=>{
              const n=String(c).match(/^rgba?\(([^)]+)\)/);
              if(!n)return null;
              const v=n[1].split(/[\s,\/]+/).slice(0,3).map(Number);
              if(v.length!==3||v.some(x=>!Number.isFinite(x)))return null;
              return v.reduce((sum,x,i)=>{
                const s=x/255;const linear=s<=.04045?s/12.92:((s+.055)/1.055)**2.4;
                return sum+linear*[.2126,.7152,.0722][i];
              },0);
            };
            const links=[...(panel?.querySelectorAll('a')||[])];
            const first=panel?.querySelector(':scope > li > a');
            const nested=panel?.querySelector('ul');
            const fg=luminance(first?getComputedStyle(first).color:'');
            const bg=luminance(panel?getComputedStyle(panel).backgroundColor:'');
            return {
              width:innerWidth,scroll:document.documentElement.scrollWidth,
              open:!!details?.open,header:r(header),brand:r(brand),toggle:r(toggle),
              panel:r(panel),main:r(main),firstLink:r(first),
              position:panel?getComputedStyle(panel).position:null,
              nestedPosition:nested?getComputedStyle(nested).position:null,
              links:links.length,nested:!!nested,
              minimumLinkHeight:Math.min(...links.map(x=>r(x)?.height||0)),
              contrast:fg===null||bg===null?0:Math.round((Math.max(fg,bg)+.05)/(Math.min(fg,bg)+.05)*100)/100
            };
          });
          const ok=m.open&&m.header&&m.brand&&m.toggle&&m.panel&&m.main&&m.firstLink&&
            m.toggle.width>=44&&m.toggle.width<=160&&m.toggle.right<=width-8&&
            m.toggle.left>=m.brand.right-2&&m.brand.height>=44&&
            m.panel.left>=-2&&m.panel.right<=width+2&&m.panel.width>=width*.75&&
            m.panel.top>=m.toggle.bottom+8&&m.panel.bottom<=m.header.bottom+2&&m.main.top>=m.header.bottom-3&&
            m.position==='static'&&m.nested&&m.nestedPosition==='static'&&
            m.firstLink.left<=m.panel.left+34&&m.firstLink.right>=m.panel.right-34&&
            m.minimumLinkHeight>=44&&m.links>=8&&m.contrast>=4.5&&m.scroll<=width+4;
          checks.push({slug,width,http:status,ok:!!ok,geometry:m});
          await pg.screenshot({path:path.join(OUT,slug.replace('/','')+'-'+width+'.png'),
            animations:'disabled',fullPage:false});
        }
        await toggle.click();
        const closed=await pg.evaluate(()=>!document.querySelector(
          '.seo-geo-site-header .seo-geo-preset-navigation__mobile')?.open);
        checks.push({slug,check:'native_close',ok:closed});
        await pg.close();
      }
    }finally{await ctx.close();}
  }finally{await browser.close();}
  const report={scope:'fixed-public-GET-only',site:'emmake-nuevaweb',
    status:checks.length===6&&checks.every(x=>x.ok)&&jsErrors===0?'passed':'failed',
    checks,script_errors:jsErrors,wordpress_mutations:0,form_submissions:0};
  fs.writeFileSync(path.join(OUT,'report.json'),JSON.stringify(report,null,2)+'\n');
  console.log('EMMAKE_INNER_MOBILE_NAV',JSON.stringify({status:report.status,
    checks:checks.map(x=>({slug:x.slug,width:x.width,ok:x.ok,http:x.http}))}));
  if(report.status!=='passed')process.exitCode=1;
}
main().catch(e=>{console.error('Safe public nav QA failed:',e.message);process.exitCode=1});
