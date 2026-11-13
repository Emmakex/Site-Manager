#!/usr/bin/env node
'use strict';
// Public, read-only structural acceptance of the fixed Sobre Nosotros page.
// No form submissions, login, page content export or remote actions.
const fs=require('node:fs'),path=require('node:path');
const ORIGIN='https://emmake.com/nuevaweb/';
const ROUTE='sobre-nosotros/';
const OUT='artifacts/emmake-about-visual';
function assert(v,s){if(!v)throw Error(s)}
async function main(){
 const {chromium}=require('playwright');const browser=await chromium.launch({headless:true,args:['--no-sandbox']});
 const observations=[];
 try {
  for(const [device,width,height,isMobile] of [['desktop',1440,900,false],['mobile',390,844,true]]){
   const context=await browser.newContext({viewport:{width,height},isMobile,hasTouch:isMobile,deviceScaleFactor:1,serviceWorkers:'block',locale:'es-ES'});
   try{
    const page=await context.newPage();
    const response=await page.goto(ORIGIN+ROUTE,{waitUntil:'domcontentloaded',timeout:30000});
    assert(response&&response.status()===200,'non200 '+device);
    await page.waitForTimeout(800);
    const data=await page.evaluate(()=>{
     const root=document.querySelector('main .wp-block-post-content');
     if(!root)return {found:false};
     function summary(n){
      const b=n.getBoundingClientRect(),c=getComputedStyle(n);
      return {tag:n.tagName.toLowerCase(),classes:[...n.classList].filter(x=>/^[a-zA-Z0-9_-]{1,80}$/.test(x)).slice(0,7),
       top:Math.round(b.top+scrollY),height:Math.round(b.height),width:Math.round(b.width),
       left:Math.round(b.left),marginTop:Math.round(parseFloat(c.marginTop)||0),
       marginBottom:Math.round(parseFloat(c.marginBottom)||0),paddingTop:Math.round(parseFloat(c.paddingTop)||0),
       display:c.display,position:c.position,children:n.children.length};
     }
     const direct=[...root.children].slice(0,100).map(summary);
     const images=[...root.querySelectorAll('img')].slice(0,100).map(im=>{
      const a=summary(im),figure=im.closest('figure'),parent=im.parentElement;
      return {...a,naturalWidth:im.naturalWidth,naturalHeight:im.naturalHeight,
       directRootChild:!!im.closest('.wp-block-post-content')?.contains(im)&&im.parentElement===root,
       figure:figure?summary(figure):null,parent:parent?summary(parent):null,
       altPresent:!!im.alt?.trim()};
     });
     const headings=[...root.querySelectorAll('h1,h2,h3,h4')].slice(0,50).map(n=>{
      const q=summary(n);return {...q,publicHeading:(n.textContent||'').replace(/\s+/g,' ').trim().slice(0,90)}
     });
     const major=[...root.querySelectorAll('.wp-block-group,.wp-block-columns,.wp-block-spacer,.wp-block-cover,.wp-block-image,.wp-block-gallery,[style*="height"]')]
      .map(summary).filter(x=>x.height>200).sort((a,b)=>b.height-a.height).slice(0,55);
     const gaps=direct.slice(1).map((n,i)=>({after:i,gap:n.top-(direct[i].top+direct[i].height),before:direct[i].tag,afterTag:n.tag})).filter(x=>x.gap>70).sort((a,b)=>b.gap-a.gap).slice(0,25);
     const forms=[...root.querySelectorAll('form')].map(f=>({classList:[...f.classList].slice(0,8),required:f.querySelectorAll('[required]').length}));
     const styles=getComputedStyle(root);
     return {found:true,docHeight:document.documentElement.scrollHeight,viewport:innerWidth,scrollWidth:document.documentElement.scrollWidth,
      bodyClasses:[...document.body.classList].filter(x=>/^[a-zA-Z0-9_-]{1,80}$/.test(x)).slice(0,55),
      contentHeight:Math.round(root.getBoundingClientRect().height),contentWidth:Math.round(root.getBoundingClientRect().width),
      rootDisplay:styles.display,rootClasses:[...root.classList].slice(0,12),childCount:root.children.length,
      direct,images,headings,major,gaps,forms,
      spacerCount:root.querySelectorAll('.wp-block-spacer').length,logoImagesOver400:images.filter(x=>x.height>400).length};
    });
    assert(data.found,'content not found');
    const imgName=device+'_about.png';fs.mkdirSync(OUT,{recursive:true});
    await page.screenshot({path:path.join(OUT,imgName),fullPage:true,animations:'disabled',timeout:45000});
    observations.push({device,...data});await page.close();
   }finally{await context.close()}
  }
 }finally{await browser.close()}
 const report={site:'emmake-nuevaweb',page:'sobre-nosotros',scope:'published_public_layout_only',
  observed_at:new Date().toISOString(),captures:2,observations,form_submissions:0,private_values_saved:false};
 fs.writeFileSync(path.join(OUT,'report.json'),JSON.stringify(report,null,2)+'\n');
 process.stdout.write('About visual DOM captured on desktop/mobile\n');
}
main().catch(e=>{process.stderr.write('Public About QA failed: '+e.message+'\n');process.exitCode=1});
