#!/usr/bin/env node
'use strict';
const fs=require('node:fs'),path=require('node:path');
const ROOT='https://emmake.com/nuevaweb/';
const ROUTES=['','servicios/','sobre-nosotros/','nuestras-marcas/','blog/',
 'blog-transformacion-digital/','contacto/','trabaja-con-nosotros/',
 'calculadora-de-donativos/','asistentes-virtuales-de-voz/','politica-de-privacidad/'];
const OUT='artifacts/emmake-seo/public-pages-seo.json';
function attrs(tag){
 const out={};const re=/([\w:-]+)\s*=\s*(['"])(.*?)\2/g;
 for(const a of tag.matchAll(re))out[a[1].toLowerCase()]=a[3];
 return out;
}
function auditHtml(html,expected){
 const tags=[...html.matchAll(/<meta\s+[^>]*>/gi)].map(x=>attrs(x[0]));
 const links=[...html.matchAll(/<link\s+[^>]*>/gi)].map(x=>attrs(x[0]));
 const find=(key,val)=>tags.find(x=>(x.name||x.property||'').toLowerCase()===val)?.content||'';
 const title=(html.match(/<title[^>]*>([\s\S]*?)<\/title>/i)||[])[1]||'';
 const canonical=links.find(x=>(x.rel||'').toLowerCase()==='canonical')?.href||'';
 const robots=find('name','robots').toLowerCase();
 const desc=find('name','description');
 const ogTitle=find('property','og:title');
 const ogDesc=find('property','og:description');
 let canonicalMatches=false;
 try{const u=new URL(canonical);canonicalMatches=u.protocol==='https:'&&u.hostname==='emmake.com'&&u.pathname===new URL(expected).pathname&&!u.search&&!u.hash;}catch{}
 return {
   title_present:!!title.trim(),title_length:title.trim().length,
   description_present:!!desc.trim(),description_length:desc.trim().length,
   canonical_present:!!canonical,canonical_exact:canonicalMatches,
   robots_noindex:robots.includes('noindex'),
   og_title_present:!!ogTitle,og_description_present:!!ogDesc,
   jsonld_blocks:(html.match(/<script[^>]+type=['"]application\/ld\+json['"][^>]*>/gi)||[]).length,
   h1_count:(html.match(/<h1(?:\s|>)/gi)||[]).length
 };
}
async function audit(){
 const result=[];
 for(const route of ROUTES){
  const url=ROOT+route;
  const ctrl=new AbortController(),timer=setTimeout(()=>ctrl.abort(),18000);
  try{
   const r=await fetch(url,{redirect:'manual',signal:ctrl.signal,headers:{accept:'text/html'}});
   if(r.status!==200) throw Error('public route returned '+r.status+' for '+route);
   const html=await r.text();
   if(html.length>3500000)throw Error('public HTML too large');
   result.push({route:route||'home',http_status:r.status,...auditHtml(html,url)});
  }finally{clearTimeout(timer);}
 }
 const failures=result.flatMap(row=>[
  ...(!row.title_present?['no_title']:[]),...(!row.description_present?['no_description']:[]),
  ...(!row.canonical_exact?['canonical_mismatch']:[]),...(!row.robots_noindex?['staging_noindex_missing']:[]),
  ...(!row.og_title_present?['no_og_title']:[]),...(!row.og_description_present?['no_og_description']:[]),
  ...(row.h1_count!==1?['h1_count_'+row.h1_count]:[])
 ].map(code=>({route:row.route,code})));
 const payload={site:'emmake-nuevaweb',observed_at:new Date().toISOString(),
   scope:'fixed-public-page-seo-metadata',pages_count:result.length,results:result,
   findings:failures,private_content_included:false,values_retained:false};
 fs.mkdirSync(path.dirname(OUT),{recursive:true});
 fs.writeFileSync(OUT,JSON.stringify(payload,null,2)+'\n');
 process.stdout.write('Read '+result.length+' live pages; metadata findings '+failures.length+'\n');
}
function test(){
 if(ROUTES.length!==11||new Set(ROUTES).size!==11)throw Error('wrong bound site pages');
 const html='<title>Test</title><meta name="robots" content="noindex"><meta content="A good summary" name="description"><link rel="canonical" href="https://emmake.com/nuevaweb/servicios/"><h1>Title</h1>';
 const o=auditHtml(html,ROOT+'servicios/');
 if(!o.robots_noindex||!o.canonical_exact||o.h1_count!==1||o.description_length!==14)throw Error('incorrect metadata parser');
 process.stdout.write('PASS fixed 11-page public SEO metadata contract\n');
}
if(process.argv.includes('--self-test'))test();else if(process.argv.includes('--run'))audit().catch(e=>{process.stderr.write('Public SEO audit failed safely: '+e.message+'\n');process.exitCode=1;});else process.exitCode=2;
