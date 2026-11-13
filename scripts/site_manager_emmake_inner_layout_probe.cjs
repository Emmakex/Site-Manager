#!/usr/bin/env node
'use strict';
// Scoped public layout measurement. No page text, HTML, URLs or private fields saved.
const fs=require('node:fs');
const path=require('node:path');
const ROUTES=Object.freeze([
  Object.freeze({name:'brands',route:'nuestras-marcas/'}),
  Object.freeze({name:'blog-digital',route:'blog-transformacion-digital/'}),
  Object.freeze({name:'contact',route:'contacto/'}),
]);
const ORIGIN='https://emmake.com/nuevaweb/';
const OUT='artifacts/emmake-inner-layout/structure.json';
function assert(b,msg){if(!b)throw Error(msg)}
async function main(){
  assert(ROUTES.length===3&&ROUTES[0].route==='nuestras-marcas/'&&ROUTES[1].route==='blog-transformacion-digital/'&&ROUTES[2].route==='contacto/','unsafe scope');
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
          const blurbNodes=[...root.children].filter(n=>n.classList.contains('sgmb-divi-blurb'));
          const images=blurbNodes.flatMap(n=>[...n.querySelectorAll('.wp-block-image img')]);
          const imgHeights=images.map(n=>Math.round(n.getBoundingClientRect().height));
          const rootStyle=getComputedStyle(root);
          const columns=rootStyle.gridTemplateColumns.trim().split(/\s+/).filter(Boolean);
          const latestPosts=[...root.querySelectorAll('.wp-block-latest-posts')].map(n=>({
            itemCount:n.querySelectorAll(':scope > li').length,
            columns:getComputedStyle(n).gridTemplateColumns.trim().split(/\s+/).filter(Boolean).length,
            width:Math.round(n.getBoundingClientRect().width)
          })).slice(0,25);
          const formStructure=[...document.querySelectorAll('main form')].map(f=>{
            const u=(()=>{try{return new URL(f.getAttribute('action'),location.href)}catch{return null}})();
            const submit=f.querySelector('button[type="submit"],input[type="submit"]');
            const size=submit?.getBoundingClientRect();
            return {method:f.method.toLowerCase(),action_same_origin:u?.origin===location.origin,
              action_path:u?.pathname?.slice(0,160)||'',
              classes:[...f.classList].filter(x=>/^[a-zA-Z0-9_-]{1,72}$/.test(x)).slice(0,12),
              names:[...f.querySelectorAll('input[type="hidden"]')].map(x=>x.name).filter(x=>/^[a-zA-Z0-9_-]{1,72}$/.test(x)).slice(0,25),
              required_count:f.querySelectorAll('input[required],textarea[required],select[required]').length,
              submit_height:size?Math.round(size.height):0,
              submit_present:!!submit,
              honeypot:!!f.querySelector('input[name*="website"],input[name*="honeypot"],input[name*="_wpcf7"]'),
              has_recaptcha:!!f.querySelector('[class*="recaptcha"]'),
              js_wpcf7:!!window.wpcf7
            };
          }).slice(0,6);
          const overflow=document.documentElement.scrollWidth>innerWidth+4;
          return {formStructure,blurbCount:blurbNodes.length, blurbImageCount:images.length,
            blurbImageMaxHeight:imgHeights.length?Math.max(...imgHeights):0,
            parentDisplay:rootStyle.display, parentGridColumnCount:columns.length,
            latestPosts,overflow,found:true,documentHeight:document.documentElement.scrollHeight,
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
  const brands=results.find(x=>x.page==='brands');
  const blog=results.find(x=>x.page==='blog-digital');
  const checks=[
    {id:'brands_has_migrated_blurbs',ok:brands.blurbCount>=3,count:brands.blurbCount},
    {id:'brands_three_column_card_grid',ok:brands.parentDisplay==='grid'&&brands.parentGridColumnCount===3,
      observed:brands.parentGridColumnCount},
    {id:'brands_logos_contained',ok:brands.blurbImageCount>0&&brands.blurbImageMaxHeight<=190,
      max_image_height:brands.blurbImageMaxHeight},
    {id:'brands_page_height_reduced',ok:brands.documentHeight<9000,height:brands.documentHeight},
    {id:'blog_no_horizontal_overflow',ok:!blog.overflow},
    {id:'blog_latest_posts_present',ok:blog.latestPosts.length>=1,count:blog.latestPosts.length},
    {id:'blog_orphan_cards_reflow',ok:blog.latestPosts.filter(x=>x.itemCount===1).every(x=>x.columns===1)
      &&blog.latestPosts.filter(x=>x.itemCount===2).every(x=>x.columns===2)}
  ];
  const report={site:'emmake-nuevaweb',scope:'public-layout-metrics-no-content',
    observed_at:new Date().toISOString(),viewport_width:1440,status:checks.every(x=>x.ok)?'passed':'failed',
    checks,results,page_text_saved:false,html_saved:false,urls_saved:false};
  fs.mkdirSync(path.dirname(OUT),{recursive:true});
  fs.writeFileSync(OUT,JSON.stringify(report,null,2)+'\n');
  process.stdout.write('Measured '+results.length+' published public page layout structures; '+report.status+'\n');
}
main().catch(e=>{process.stderr.write('Layout probe failed: '+e.message+'\n');process.exitCode=1});
