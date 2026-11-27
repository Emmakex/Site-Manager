#!/usr/bin/env node
'use strict';
// Fixed-origin, read-only production SEO/GEO readback. Never retain HTML or credentials.
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const ORIGIN = 'https://emmake.com';
const ROUTES = ['/', '/servicios/', '/sobre-nosotros/', '/nuestras-marcas/', '/blog/', '/blog-transformacion-digital/', '/contacto/', '/trabaja-con-nosotros/', '/asistentes-virtuales-de-voz/', '/politica-de-privacidad/'];
const DISCOVERY = ['/robots.txt', '/wp-sitemap.xml', '/sitemap_index.xml', '/llms.txt'];
const APPROVED = 'Impulsa tu negocio con Emmake: estrategia y transformación digital, desarrollo de soluciones, automatización, marketing y analítica de datos.';
const OUT = 'artifacts/emmake-seo/production-public-seo.json';
function decode(s) {
  return s.replace(/&(#x[\da-f]+|#\d+|amp|quot|apos|lt|gt|nbsp);/gi, (m,v) => {
    const named = {amp:'&',quot:'"',apos:"'",lt:'<',gt:'>',nbsp:' '};
    if (v[0] !== '#') return named[v.toLowerCase()] ?? m;
    const n = v[1].toLowerCase() === 'x' ? parseInt(v.slice(2),16) : Number(v.slice(1));
    return Number.isInteger(n) && n > 0 && n <= 0x10ffff ? String.fromCodePoint(n) : m;
  });
}
function attrs(tag) {
  return Object.fromEntries([...tag.matchAll(/([\w:-]+)\s*=\s*(?:"([^"]*)"|'([^']*)'|([^\s>]+))/g)].map(a=>[a[1].toLowerCase(),decode(a[2]??a[3]??a[4])]));
}
function inspectHtml(html, route, header) {
  const metas=[...html.matchAll(/<meta\b[^>]*>/gi)].map(x=>attrs(x[0]));
  const links=[...html.matchAll(/<link\b[^>]*>/gi)].map(x=>attrs(x[0]));
  const desc=metas.filter(x=>x.name?.toLowerCase()==='description');
  const canonical=links.filter(x=>x.rel?.toLowerCase()==='canonical');
  const robots=metas.filter(x=>['robots','googlebot','bingbot'].includes(x.name?.toLowerCase()));
  return {title_present:!!html.match(/<title\b[^>]*>\s*[^<\s]/i),
    description_count:desc.length,description_length:desc[0]?.content?.length??0,
    ...(route==='/servicios/'?{approved_description_exact:desc.length===1&&desc[0].content===APPROVED}:{}),
    canonical_count:canonical.length,canonical_exact:canonical.length===1&&canonical[0].href===ORIGIN+route,
    noindex:robots.some(x=>/\bnoindex\b/i.test(x.content??''))||/\bnoindex\b/i.test(header),
    h1_count:(html.match(/<h1(?:\s|>)/gi)||[]).length,
    jsonld_count:(html.match(/<script\b[^>]*type\s*=\s*['"]application\/ld\+json['"][^>]*>/gi)||[]).length,
    og_title_present:metas.some(x=>x.property==='og:title'&&!!x.content),
    og_description_present:metas.some(x=>x.property==='og:description'&&!!x.content)};
}
function inspectDiscovery(body,route) {
  if(route==='/robots.txt'){
    let agents=[],rules=false,blocked=false;
    for(const line of body.split(/\r?\n/)){
      const m=line.replace(/#.*/,'').trim().match(/^([^:]+):\s*(.*)$/);
      if(!m)continue;
      const key=m[1].toLowerCase(),v=m[2].trim();
      if(key==='user-agent'){if(rules){agents=[];rules=false;}agents.push(v);}
      if(['allow','disallow'].includes(key)){rules=true;if(key==='disallow'&&v==='/'&&agents.includes('*'))blocked=true;}
    }
    return {wildcard_disallow_root:blocked,sitemap_directive_present:/^sitemap:\s*https:\/\/emmake\.com\//im.test(body)};
  }
  if(route==='/llms.txt')return {nonempty:!!body.trim(),markdown_like:/^#{1,3}\s+/m.test(body),contains_staging_link:/https:\/\/emmake\.com\/nuevaweb\//i.test(body)};
  return {sitemap_like:/<(?:sitemapindex|urlset)\b/i.test(body),contains_staging_link:/https:\/\/emmake\.com\/nuevaweb\//i.test(body)};
}
async function request(route){
  const c=new AbortController(),timer=setTimeout(()=>c.abort(),18000);
  try{
    const r=await fetch(ORIGIN+route,{method:'GET',redirect:'manual',signal:c.signal,headers:{accept:'text/html,application/xml,text/plain;q=0.9'}});
    const result={route,http_status:r.status,content_type:(r.headers.get('content-type')||'').split(';')[0]};
    if(r.status!==200){await r.body?.cancel();return result;}
    const reader=r.body.getReader(),chunks=[];let total=0;
    for(;;){const {done,value}=await reader.read();if(done)break;total+=value.byteLength;if(total>3000000){await reader.cancel();return {...result,error:'body_limit'};}chunks.push(Buffer.from(value));}
    const body=Buffer.concat(chunks).toString('utf8');
    return {...result,...(ROUTES.includes(route)?inspectHtml(body,route,r.headers.get('x-robots-tag')||''):inspectDiscovery(body,route))};
  }catch(e){return {route,http_status:0,error:e.name==='AbortError'?'timeout':'request_failed'};}
  finally{clearTimeout(timer);}
}
function selfTest(){
  assert.equal(ROUTES.length,10);assert.equal(new Set([...ROUTES,...DISCOVERY]).size,14);
  assert([...ROUTES,...DISCOVERY].every(r=>/^\/[a-z0-9/._-]*$/.test(r)));
  const html='<title>Servicios</title><meta name="description" content="'+APPROVED.replace('ó','&#243;')+'"><link rel="canonical" href="'+ORIGIN+'/servicios/"><h1>Servicios</h1>';
  const good=inspectHtml(html,'/servicios/','');assert(good.approved_description_exact&&good.canonical_exact&&!good.noindex&&good.h1_count===1);
  assert(!inspectHtml(html.replace('Impulsa','Otro'),'/servicios/','').approved_description_exact);
  assert(inspectHtml(html,'/servicios/','noindex').noindex);
  assert(inspectDiscovery('User-agent: *\nDisallow: /','/robots.txt').wildcard_disallow_root);
  assert(!inspectDiscovery('User-agent: GPTBot\nDisallow: /\nUser-agent: *\nDisallow: /wp-admin/','/robots.txt').wildcard_disallow_root);
  assert(inspectDiscovery('<urlset></urlset>','/wp-sitemap.xml').sitemap_like);
  console.log('PASS production fixed-origin SEO/GEO readback contract');
}
async function run(){
  const results=[];for(const route of [...ROUTES,...DISCOVERY])results.push(await request(route));
  const report={site:'emmake-produccion',observed_at:new Date().toISOString(),wordpress_mutations:0,results};
  fs.mkdirSync(path.dirname(OUT),{recursive:true});fs.writeFileSync(OUT,JSON.stringify(report,null,2)+'\n');
  console.log(JSON.stringify(report));
}
if(process.argv[2]==='--self-test')selfTest();else if(process.argv[2]==='--run')run().catch(()=>{process.exitCode=1;});else process.exitCode=2;
