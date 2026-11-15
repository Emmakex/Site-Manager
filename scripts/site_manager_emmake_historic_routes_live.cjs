#!/usr/bin/env node
'use strict';
// Public, read-only staged migration acceptance: do not touch production root.
const fs=require('node:fs'),path=require('node:path');
const BASE='https://emmake.com/nuevaweb/';
const CATEGORIES={
 50212:'redes-sociales',50234:'marketing-digital',50240:'consultoria',
 50390:'business-intelligence',50406:'analisis-de-datos',
 50428:'investigacion-de-mercado',50504:'uncategorized',50505:'uncategorized',
 50515:'uncategorized',50517:'uncategorized',53293:'uncategorized',
 53392:'uncategorized',53545:'uncategorized',53607:'uncategorized',
 53619:'uncategorized',53632:'uncategorized',53691:'uncategorized',
 53748:'uncategorized',53800:'uncategorized',53816:'uncategorized',
 53828:'uncategorized',53854:'uncategorized',53863:'uncategorized',
 53868:'uncategorized'
};
const BLOG_IDS=[51663,52690,53912,53921,53891];
const OLD_NORMALIZED='/blog/ventajas-y-desventajas-de-los-relojes-de-tiempo-basados-%e2%80%8b%e2%80%8ben-la-web/';
const NEW_NORMALIZED='/blog/ventajas-y-desventajas-de-los-relojes-de-tiempo-basados-en-la-web/';
const OUT='artifacts/emmake-launch/historic-routes-live.json';
const sleep=ms=>new Promise(resolve=>setTimeout(resolve,ms));
function selfTest(){
 if(BASE!=='https://emmake.com/nuevaweb/'||Object.keys(CATEGORIES).length!==24||BLOG_IDS.length!==5)
  throw Error('Invalid fixed-site route contract');
 console.log('PASS: bounded public GET-only Emmake legacy article routes contract');
}
async function get(url){
 const u=new URL(url,BASE);
 if(u.origin!=='https://emmake.com'||!u.pathname.startsWith('/nuevaweb/'))throw Error('URL escapes staging');
 for(let attempt=0;attempt<3;attempt++){
  const ctrl=new AbortController(),timeout=setTimeout(()=>ctrl.abort(),18000);
  try{
   const r=await fetch(u,{method:'GET',redirect:'manual',signal:ctrl.signal,
    headers:{accept:'text/html,application/json;q=0.7'}});
   const loc=r.headers.get('location');
   await r.body?.cancel().catch(()=>{});
   if(r.status===429||r.status===503){
    if(attempt<2){await sleep(3000*(attempt+1));continue;}
   }
   return {status:r.status,location:loc?new URL(loc,u).href:null};
  }finally{clearTimeout(timeout);}
 }
 throw Error('read-only request unavailable');
}
async function run(){
 fs.mkdirSync(path.dirname(OUT),{recursive:true});
 const checks=[];let bad=0;
 for(const [id,category] of Object.entries(CATEGORIES)){
  await sleep(1150);
  try{
   // Limit the REST response to the three public identity fields only.
   const endpoint=new URL('wp-json/wp/v2/posts/'+id+'?_fields=id,slug,link',BASE);
   const controller=new AbortController(), timer=setTimeout(()=>controller.abort(),15000);
   let data;
   try{
    const r=await fetch(endpoint,{redirect:'manual',signal:controller.signal,headers:{accept:'application/json'}});
    data=r.status===200?await r.json():null;
   }finally{clearTimeout(timer)}
   const canonical=data?.link?new URL(data.link):null;
   const expected='/nuevaweb/'+category+'/';
   const pathOk=canonical?.origin==='https://emmake.com'&&canonical.pathname.startsWith(expected)&&
     canonical.pathname.endsWith('/'+data.slug+'/');
   if(!pathOk){
    checks.push({id,kind:'category',ok:false,reason:'historical_permalink_not_preserved'});bad++;continue;
   }
   await sleep(900);
   const page=await get(canonical.href);
   const ok=page.status===200;
   checks.push({id,kind:'category',ok,http:page.status});
   if(!ok)bad++;
  }catch(e){checks.push({id,kind:'category',ok:false,reason:String(e.message).slice(0,95)});bad++;}
 }
 for(const id of BLOG_IDS){
  await sleep(1100);
  try{
   const endpoint=new URL('wp-json/wp/v2/posts/'+id+'?_fields=id,slug,link',BASE);
   const ctrl=new AbortController(),stop=setTimeout(()=>ctrl.abort(),15000);
   let data;
   try{
    const r=await fetch(endpoint,{redirect:'manual',signal:ctrl.signal,headers:{accept:'application/json'}});
    data=r.status===200?await r.json():null;
   }finally{clearTimeout(stop)}
   const uri=data?.link?new URL(data.link):null;
   const pathOk=uri?.origin==='https://emmake.com'&&uri.pathname.startsWith('/nuevaweb/blog/');
   const page=pathOk?await get(uri.href):{status:0};
   const ok=pathOk&&page.status===200;
   checks.push({id,kind:'blog',ok:!!ok,http:page.status});
   if(!ok)bad++;
  }catch(e){checks.push({id,kind:'blog',ok:false,reason:String(e.message).slice(0,95)});bad++;}
 }
 const old=await get(BASE.slice(0,-1)+OLD_NORMALIZED);
 const redirectPath=old.location?new URL(old.location).pathname:null;
 const normalizedOk=old.status===301&&redirectPath==='/nuevaweb'+NEW_NORMALIZED;
 checks.push({kind:'normalized_slug_301',ok:normalizedOk,http:old.status,
  points_to_clean_url:redirectPath==='/nuevaweb'+NEW_NORMALIZED});
 if(!normalizedOk)bad++;
 const blogIndex=await get('blog/');
 const indexOk=blogIndex.status===200;
 checks.push({kind:'blog_index',ok:indexOk,http:blogIndex.status});
 if(!indexOk)bad++;
 const report={version:1,scope:'emmake-nuevaweb GET-only',
   status:bad===0?'passed':'failed',total:checks.length,passed:checks.length-bad,
   findings:checks.filter(x=>!x.ok),checks,wordpress_mutations:0,
   observed_at:new Date().toISOString()};
 fs.writeFileSync(OUT,JSON.stringify(report,null,2)+'\n');
 console.log('EMMAKE_LEGACY_URL_LIVE',JSON.stringify({status:report.status,total:report.total,
  passed:report.passed,findings:report.findings}));
 if(bad)process.exitCode=1;
}
if(process.argv[2]==='--self-test')selfTest();
else if(process.argv[2]==='--run')run().catch(e=>{console.error('Emmake public legacy route QA failed:',e.message);process.exitCode=1});
else process.exitCode=2;
