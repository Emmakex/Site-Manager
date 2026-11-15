#!/usr/bin/env node
'use strict';
// Final post-deployment, read-only mobile header regression on the fixed staging URL.
// No credentials, admin endpoints, POSTs, forms or WordPress mutations.
const fs = require('node:fs');
const path = require('node:path');
// Playwright is loaded only for --run after the disposable CI install.
const URL = 'https://emmake.com/nuevaweb/';
const OUT = 'artifacts/emmake-mobile-menu';
const reportPath = 'audits/emmake/mobile-menu/results/visual-mobile-emmake-20261115-01.json';

const rect = (element) => {
  const r=element?.getBoundingClientRect();
  return r?{left:Math.round(r.left),right:Math.round(r.right),
    top:Math.round(r.top),bottom:Math.round(r.bottom),
    width:Math.round(r.width),height:Math.round(r.height)}:null;
};
async function main() {
  if (process.argv[2]==='--self-test') {
    if(URL!=='https://emmake.com/nuevaweb/' || !reportPath.startsWith('audits/emmake/mobile-menu/results/'))
      throw Error('Unexpected origin or output');
    console.log('PASS: fixed-origin mobile menu read-only QA contract');
    return;
  }
  if(process.argv[2]!=='--run' || process.argv.length!==3)throw Error('Unsupported invocation');
  fs.mkdirSync(OUT,{recursive:true});
  fs.mkdirSync(path.dirname(reportPath),{recursive:true});
  const {chromium}=require('playwright');
  const browser=await chromium.launch({headless:true,args:['--no-sandbox']});
  const checks=[];
  let status=0,errors=0;
  try {
    const context=await browser.newContext({viewport:{width:390,height:844},
      isMobile:true,hasTouch:true,deviceScaleFactor:1,locale:'es-ES',
      serviceWorkers:'block',reducedMotion:'reduce'});
    const page=await context.newPage();
    page.on('pageerror',()=>errors++);
    const response=await page.goto(URL,{waitUntil:'domcontentloaded',timeout:30000});
    status=response?.status()||0;
    if(status===200){
      const summary=page.locator('.seo-geo-strategic-header .seo-geo-preset-navigation__mobile > summary');
      await summary.click();
      await page.waitForTimeout(200);
      for(const width of [390,430]){
        await page.setViewportSize({width,height:844});
        await page.waitForTimeout(130);
        const a=await page.evaluate(()=>{
          const header=document.querySelector('.seo-geo-strategic-header');
          const brand=header?.querySelector('.seo-geo-strategic-brand__name');
          const details=header?.querySelector('.seo-geo-preset-navigation__mobile');
          const toggle=details?.querySelector(':scope > summary');
          const panel=details?.querySelector(':scope > .seo-geo-preset-navigation__list');
          const main=document.querySelector('main');
          const links=[...(panel?.querySelectorAll('a')||[])];
          const rootLink=panel?.querySelector(':scope > li > a');
          const submenu=panel?.querySelector('.seo-geo-preset-navigation__sublist');
          const r=e=>{const b=e?.getBoundingClientRect();return b?{
            left:Math.round(b.left),right:Math.round(b.right),top:Math.round(b.top),
            bottom:Math.round(b.bottom),width:Math.round(b.width),height:Math.round(b.height)
          }:null;};
          const color=s=>{const t=String(s||'').match(/^rgba?\(([^)]+)\)/);if(!t)return null;
            const c=t[1].trim().split(/[\s,\/]+/).slice(0,3).map(Number);
            if(c.length!==3||c.some(n=>!Number.isFinite(n)))return null;
            return c.reduce((sum,x,i)=>{const v=x/255;return sum+
              (v<=.04045?v/12.92:((v+.055)/1.055)**2.4)*[.2126,.7152,.0722][i]},0);
          };
          const bg=panel?color(getComputedStyle(panel).backgroundColor):null;
          const fg=links.length?color(getComputedStyle(links[1]||links[0]).color):null;
          return {viewport:innerWidth,scroll:document.documentElement.scrollWidth,
            open:!!details?.open,header:r(header),brand:r(brand),summary:r(toggle),
            panel:r(panel),hero:r(main),position:panel?getComputedStyle(panel).position:null,
            textContrast:bg===null||fg===null?0:Math.round(((Math.max(bg,fg)+.05)/(Math.min(bg,fg)+.05))*100)/100,
            links:links.length,firstRootLink:r(rootLink),minimumLinkHeight:Math.min(...links.map(el=>r(el)?.height||0)),
            nested:!!submenu,submenuPosition:submenu?getComputedStyle(submenu).position:null};
        });
        const passed=status===200 && a.open && a.header && a.summary && a.brand && a.panel && a.hero &&
          a.panel.left>=0 && a.panel.right<=width+2 &&
          a.panel.width>=width*.75 && a.panel.bottom<=a.header.bottom+2 &&
          a.hero.top>=a.header.bottom-2 && a.summary.right<=width-8 &&
          a.summary.left>=a.brand.right+4 &&
          a.scroll<=width+4 && a.textContrast>=4.5 &&
          a.links>=8 && a.minimumLinkHeight>=44 &&
          a.firstRootLink && a.firstRootLink.left<=a.panel.left+34 &&
          a.firstRootLink.right>=a.panel.right-34 &&
          a.position==='static' && a.nested && a.submenuPosition==='static';
        checks.push({width,passed:!!passed,metrics:a});
        await page.screenshot({path:path.join(OUT,'home-menu-open-'+width+'.png'),
          animations:'disabled',fullPage:false});
      }
      await summary.click();
      const closed=await page.evaluate(()=>{
        const details=document.querySelector('.seo-geo-strategic-header .seo-geo-preset-navigation__mobile');
        return !!details && !details.open;
      });
      checks.push({width:430,check:'native_close',passed:closed});
    }
    await context.close();
  } finally {await browser.close()}
  const report={version:'1',site:'emmake-nuevaweb',url_scope:'fixed-public-staging',status:
    status===200&&checks.length===3&&checks.every(x=>x.passed)&&errors===0?'passed':'failed',
    http_status:status,script_errors:errors,checks,wordpress_mutations:0,form_submissions:0};
  fs.writeFileSync(reportPath,JSON.stringify(report,null,2)+'\n');
  console.log('MOBILE_MENU_QA',JSON.stringify({status:report.status,http:status,
    checks:checks.map(x=>({width:x.width,passed:x.passed}))}));
  if(report.status!=='passed')process.exitCode=1;
}
main().catch(e=>{console.error('Read-only mobile menu QA failed:',e.message);process.exitCode=1});
