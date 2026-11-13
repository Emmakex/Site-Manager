#!/usr/bin/env node
'use strict';
/* Read-only published staging editorial structure. No WordPress mutations. */
const fs = require('node:fs');
const path = require('node:path');
const ORIGIN = 'https://emmake.com/nuevaweb/';
const ROUTES = Object.freeze([
  {id:'about', slug:'sobre-nosotros/'},
  {id:'blog-digital', slug:'blog-transformacion-digital/'}
]);
const OUT = 'artifacts/emmake-editorial-layout';
function assert(ok,message){if(!ok)throw Error(message)}
async function main(){
  const {chromium}=require('playwright');
  const browser=await chromium.launch({headless:true,args:['--no-sandbox']});
  const observations=[];
  try{
    const ctx=await browser.newContext({viewport:{width:1440,height:900},locale:'es-ES',serviceWorkers:'block'});
    try{
      for(const {id,slug} of ROUTES){
        const pg=await ctx.newPage();
        const res=await pg.goto(ORIGIN+slug,{waitUntil:'domcontentloaded',timeout:30000});
        assert(res&&res.status()===200&&pg.url()===ORIGIN+slug,'unexpected public route '+id);
        await pg.waitForTimeout(550);
        const data=await pg.evaluate(()=>{
          const root=document.querySelector('main .wp-block-post-content');
          if(!root)return {found:false};
          const txt=n=>(n?.textContent||'').replace(/\s+/g,' ').trim().slice(0,145);
          const box=n=>{const b=n.getBoundingClientRect();return {width:Math.round(b.width),height:Math.round(b.height),top:Math.round(b.top+scrollY)}};
          const safePath=href=>{try{const u=new URL(href,location.href);return u.origin===location.origin&&u.pathname.startsWith('/nuevaweb/')?u.pathname:null}catch{return null}};
          const direct=[...root.children].slice(0,80).map((n,i)=>({
            i,tag:n.tagName.toLowerCase(),
            classes:[...n.classList].filter(s=>/^[\w-]{1,90}$/.test(s)).slice(0,6),
            box:box(n),text:txt(n),
            imageCount:n.querySelectorAll('img').length,
            postListCount:n.querySelectorAll('.wp-block-latest-posts').length,
            linkCount:n.querySelectorAll('a[href]').length
          }));
          const lists=[...root.querySelectorAll('.wp-block-latest-posts')].slice(0,20).map((ul,index)=>{
            const li=[...ul.querySelectorAll(':scope > li')];
            const parent=ul.parentElement,previous=ul.previousElementSibling;
            const items=li.slice(0,20).map((node,i)=>{
              const a=node.querySelector('.wp-block-latest-posts__post-title[href],a[href]');
              return {i,title:txt(a),path:safePath(a?.href||''),box:box(node)};
            });
            return {index,items,box:box(ul),itemCount:li.length,
              parentTag:parent?.tagName?.toLowerCase(),parentClasses:[...(parent?.classList||[])].slice(0,6),
              directChildOfContent:parent===root,
              previousElement:{tag:previous?.tagName?.toLowerCase()||'',text:txt(previous)},
              nearestHeading:(()=>{
                let node=ul.previousElementSibling;
                for(let j=0;node&&j<6;j++,node=node.previousElementSibling){
                  if(node.matches('h2,h3,h4'))return txt(node);
                }
                return '';
              })()
            };
          });
          const imgs=[...root.querySelectorAll('img')].map((n,i)=>({
            i,box:box(n),classes:[...n.classList].slice(0,5),alt:!!n.alt.trim(),
            parentClasses:[...(n.parentElement?.classList||[])].slice(0,4)
          })).filter(x=>x.box.height>200).sort((a,b)=>b.box.height-a.box.height).slice(0,25);
          const headings=[...root.querySelectorAll('h2,h3,h4')].slice(0,35).map((n,i)=>({i,level:n.tagName.toLowerCase(),text:txt(n),box:box(n)}));
          const allPaths=lists.flatMap(list=>list.items.map(item=>item.path).filter(Boolean));
          const duplicates=[...new Set(allPaths.filter((x,i)=>allPaths.indexOf(x)!==i))];
          return {
            found:true,documentHeight:document.documentElement.scrollHeight,
            overflow:document.documentElement.scrollWidth>innerWidth+4,
            contentHeight:Math.round(root.getBoundingClientRect().height),
            contentChildren:root.children.length,
            contentClasses:[...root.classList].slice(0,10),
            premiumAboutEnhanced:root.classList.contains('seo-geo-legacy-about-enhanced'),
            premiumAboutAssetLoaded:[...document.scripts].some(s=>s.src?.includes('corporate-about-v1.js')),
            h1Count:document.querySelectorAll('h1').length,
            noindex:!!document.querySelector('meta[name="robots"][content*="noindex"]'),
            direct,headings,lists,largeImages:imgs,
            postLists:lists.length,postCards:allPaths.length,uniquePostPaths:new Set(allPaths).size,
            duplicatePostPaths:duplicates
          };
        });
        assert(data.found,'page structure missing '+id);
        observations.push({page:id,...data});
        fs.mkdirSync(OUT,{recursive:true});
        await pg.screenshot({path:path.join(OUT,id+'-desktop.png'),fullPage:true,animations:'disabled',timeout:45000});
        await pg.close();
      }
    }finally{await ctx.close()}
  }finally{await browser.close()}
  const report={site:'emmake-nuevaweb',checked_at:new Date().toISOString(),
    scope:'fixed_public_rendered_content_only',wp_writes:0,form_submissions:0,
    observations};
  fs.writeFileSync(path.join(OUT,'structure.json'),JSON.stringify(report,null,2)+'\n');
  console.log('EMMAKE about/blog public DOM structure collected');
}
main().catch(e=>{console.error('Public-only structure check failed: '+e.message);process.exitCode=1});
