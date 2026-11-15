#!/usr/bin/env node
'use strict';
// Fixed-domain, public WordPress REST GET inventory parity for 1726 published posts.
// Never exports article content, titles, emails, page HTML, or full URL lists.
const fs=require('node:fs'),path=require('node:path');
const ROOT='https://emmake.com',EXPECTED=1726,PAGE_SIZE=100,MAX_PAGES=20;
const OUT='artifacts/emmake-launch/post-url-parity.json';
const sleep=ms=>new Promise(r=>setTimeout(r,ms));
function selfTest(){
 if(ROOT!=='https://emmake.com'||PAGE_SIZE!==100||MAX_PAGES!==20||EXPECTED!==1726)throw Error('Unbounded request');
 console.log('PASS: fixed-domain public post URL parity audit');
}
async function fetchPage(prefix,p){
 const url=ROOT+prefix+'/wp-json/wp/v2/posts?per_page='+PAGE_SIZE+'&page='+p+'&_fields=id,slug,link';
 for(let attempt=1;attempt<=3;attempt++){
  const ctrl=new AbortController(),timer=setTimeout(()=>ctrl.abort(),20000);
  try{
   const r=await fetch(url,{method:'GET',redirect:'manual',signal:ctrl.signal,
      headers:{accept:'application/json'}});
   if(r.status===429||r.status===503){
    if(attempt===3)throw Error('rate_limited');
    await sleep(4000*attempt);continue;
   }
   if(r.status!==200)throw Error('public_api_'+r.status);
   const data=await r.json();
   if(!Array.isArray(data)||data.length>PAGE_SIZE)throw Error('invalid_page');
   const total=Number(r.headers.get('x-wp-total')||'0');
   const pages=Number(r.headers.get('x-wp-totalpages')||'0');
   if(!Number.isInteger(total)||total>2500||pages<1||pages>MAX_PAGES)throw Error('unexpected_inventory_count');
   return {rows:data,total,pages};
  }finally{clearTimeout(timer);}
 }
 throw Error('public_api_unavailable');
}
function profile(entries,prefix){
 const map=new Map();
 const types={blog:0,root:0,other:0};
 for(const item of entries){
  if(!Number.isInteger(item.id)||typeof item.slug!=='string'||typeof item.link!=='string')
    throw Error('unexpected_post_shape');
  if(map.has(item.slug))throw Error('duplicate_public_slug');
  const u=new URL(item.link);
  if(u.origin!==ROOT)throw Error('external_permalink');
  const route=u.pathname.replace(/\/$/,'');
  const slug='/'+encodeURIComponent(item.slug).replace(/%[0-9A-F]{2}/g,m=>m.toLowerCase());
  if(route.endsWith('/blog'+slug))types.blog++;
  else if(route.endsWith(slug))types.root++;
  else types.other++;
  map.set(item.slug,{id:item.id,route});
 }
 return {map,types};
}
async function main(){
 const all={legacy:[],staging:[]},counts={},errors=[];
 for(const [site,prefix] of [['legacy',''],['staging','/nuevaweb']]){
  let pages=0,total=0;
  for(let n=1;n<=MAX_PAGES;n++){
   try{
    const x=await fetchPage(prefix,n);
    if(n===1){pages=x.pages;total=x.total;}
    if(x.pages!==pages||x.total!==total)throw Error('inventory_changed_during_audit');
    all[site].push(...x.rows);
    await sleep(1100);
    if(n===pages)break;
   }catch(e){errors.push({site,part:n,code:String(e.message||'error').slice(0,80)});break;}
  }
  counts[site]={expected:total,collected:all[site].length};
 }
 let parity=null;
 if(!errors.length&&counts.legacy.collected===counts.legacy.expected&&
    counts.staging.collected===counts.staging.expected){
  const a=profile(all.legacy,''),b=profile(all.staging,'/nuevaweb');
  const missing=[...a.map.keys()].filter(k=>!b.map.has(k)).sort();
  const added=[...b.map.keys()].filter(k=>!a.map.has(k)).sort();
  const idMismatch=[...a.map.entries()].filter(([k,v])=>b.map.has(k)&&b.map.get(k).id!==v.id).length;
  const changedRoutes=[...a.map.keys()].filter(k=>b.map.has(k)&&
    a.map.get(k).route!==b.map.get(k).route.replace(/^\/nuevaweb/,'')).length;
  parity={slug_sets_identical:missing.length===0&&added.length===0,
   exact_slug_matches:a.map.size-missing.length,missing_count:missing.length,
   staging_only_count:added.length,id_mismatch_count:idMismatch,
   relative_permalink_mismatch_count:changedRoutes,
   old_pattern_counts:a.types,new_pattern_counts:b.types,
   missing_slug_examples:missing.slice(0,12),new_slug_examples:added.slice(0,12)};
 }
 const report={site:'emmake-nuevaweb',scope:'fixed-domain public published-post slugs/links',
   observed_at:new Date().toISOString(),status:errors.length?'incomplete':'complete',
   counts,parity,errors,content_exported:false,wordpress_mutations:0};
 fs.mkdirSync(path.dirname(OUT),{recursive:true});
 fs.writeFileSync(OUT,JSON.stringify(report,null,2)+'\n');
 console.log('EMMAKE_POST_PARITY',JSON.stringify({status:report.status,counts,parity}));
 if(report.status!=='complete')process.exitCode=1;
}
if(process.argv[2]==='--self-test')selfTest();
else if(process.argv[2]==='--run')main().catch(e=>{console.error('bounded public inventory audit failed:',e.message);process.exitCode=1});
else process.exitCode=2;
