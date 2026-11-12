#!/usr/bin/env node
'use strict';
// Scoped public layout measurement. No page text, HTML, URLs or private fields saved.
const fs=require('node:fs');
const path=require('node:path');
const ROUTES=Object.freeze([
  Object.freeze({name:'brands',route:'nuestras-marcas/'}),
  Object.freeze({name:'blog-digital',route:'blog-transformacion-digital/'}),
]);
const ORIGIN='https://emmake.com/nuevaweb/';
const OUT='artifacts/emmake-inner-layout/structure.json';
function assert(b,msg){if(!b)throw Error(msg)}
async function main(){
  assert(ROUTES.length===2&&ROUTES[0].route==='nuestras-marcas/'&&ROUTES[1].route==='blog-transformacion-digital/','unsafe scope');
  const {chromium}=require('playwright');
  const browser=await chromium.launch({headless:true,args:['--no-sandbox']});
  const results=[];
  try{
    const context=await browser.newContext({viewport:{width:1440,height:900},locale:'es-ES',serviceWorkers:'block'});
    try{
      for(const row of ROUTES){
        const page=await context.newPage();
        const response=await page.goto(ORIGIN+row.route,{waitUntil:'domcontentloaded',timeout:30000});
        assert(response&&response.status()===200,'unexpected HTTP status '+row.name);
        assert(page.url()===ORIGIN+row.route,'unexpected redirect '+row.name);
        await page.waitForTimeout(700);
        const data=await page.evaluate(()=>{
          const root=document.querySelector('main .wp-block-post-content');
          if(!root)return {found:false};
          function summary(n){
            const r=n.getBoundingClientRect(),cs=getComputedStyle(n);
            return {
              tag:n.tagName.toLowerCase(),classes:[...n.classList].filter(x=>/^[a-zA-Z0-9_-]{1,72}$/.test(x)).slice(0,7),
              top:Math.round(r.top+scrollY),height:Math.round(r.height),width:Math.round(r.width),
              marginTop:Math.round(parseFloat(cs.marginTop)||0),marginBottom:Math.round(parseFloat(cs.marginBottom)||0),
              paddingTop:Math.round(parseFloat(cs.paddingTop)||0),paddingBottom:Math.round(parseFloat(cs.paddingBottom)||0),
              display:cs.display,position:cs.position,inlineHeight:n.style.height.slice(0,36),
              children:n.children.length
            };
          }
          const kids=[...root.children].slice(0,100);
          const sorted=[...root.querySelectorAll('.wp-block-spacer,.wp-block-columns,.wp-block-column,.wp-block-group,.wp-block-image,.wp-block-latest-posts,[style*="height"]')]
            .map(summary).sort((a,b)=>b.height-a.height).slice(0,90);
          const direct=kids.map(summary);
          const gaps=direct.slice(1).map((x,i)=>({
            after:i,gap:Math.round(x.top-(direct[i].top+direct[i].height)),
            before_tag:direct[i].tag,after_tag:x.tag
          })).filter(x=>x.gap>60).sort((a,b)=>b.gap-a.gap).slice(0,35);
          return {found:true,documentHeight:document.documentElement.scrollHeight,
            mainHeight:Math.round(root.getBoundingClientRect().height),
            directChildCount:root.children.length,direct,
            largestStructuralNodes:sorted,largestGaps:gaps,
            spacerCount:root.querySelectorAll('.wp-block-spacer').length,
            latestPostsBlocks:root.querySelectorAll('.wp-block-latest-posts').length,
            columnsCount:root.querySelectorAll('.wp-block-columns').length,
            imageCount:root.querySelectorAll('img').length};
        });
        assert(data.found,'post content missing '+row.name);
        results.push({page:row.name,...data});
        await page.close();
      }
    }finally{await context.close()}
  }finally{await browser.close()}
  const report={site:'emmake-nuevaweb',scope:'public-layout-metrics-no-content',
    observed_at:new Date().toISOString(),viewport_width:1440,results,
    page_text_saved:false,html_saved:false,urls_saved:false};
  fs.mkdirSync(path.dirname(OUT),{recursive:true});
  fs.writeFileSync(OUT,JSON.stringify(report,null,2)+'\n');
  process.stdout.write('Measured '+results.length+' published public page layout structures\n');
}
main().catch(e=>{process.stderr.write('Layout probe failed: '+e.message+'\n');process.exitCode=1});
