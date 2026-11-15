#!/usr/bin/env node
'use strict';
// Non-mutating pre-cutover observation of fixed public endpoints only.
// No credentials, secrets, cookies, POSTs, private URLs, page content or titles.
const fs=require('node:fs');
const path=require('node:path');
const ORIGIN='https://emmake.com';
const CHECKS=[
 {id:'legacy_home',url:'/'},
 {id:'legacy_robots',url:'/robots.txt',kind:'robots'},
 {id:'legacy_wp_sitemap',url:'/wp-sitemap.xml',kind:'xml'},
 {id:'legacy_posts_api_count',url:'/wp-json/wp/v2/posts?per_page=1&_fields=id',kind:'api'},
 {id:'legacy_article_sample',url:'/blog/creacion-de-trafico-anuncio-de-5-poderosos-consejos-para-la-creacion-de-trafico/'},
 {id:'staging_home',url:'/nuevaweb/',kind:'html'},
 {id:'staging_robots',url:'/nuevaweb/robots.txt',kind:'robots'},
 {id:'staging_wp_sitemap',url:'/nuevaweb/wp-sitemap.xml',kind:'xml'},
 {id:'staging_llms',url:'/nuevaweb/llms.txt',kind:'llms'},
 {id:'staging_posts_api_count',url:'/nuevaweb/wp-json/wp/v2/posts?per_page=1&_fields=id',kind:'api'},
 {id:'staging_pages_api_count',url:'/nuevaweb/wp-json/wp/v2/pages?per_page=1&_fields=id',kind:'api'},
 {id:'staging_calculator',url:'/nuevaweb/calculadora-de-donativos/',kind:'html'},
 {id:'legacy_calculator',url:'/calculadora-de-donativos/',kind:'html'}
];
const OUT='artifacts/emmake-launch/discovery.json';
const pause=ms=>new Promise(resolve=>setTimeout(resolve,ms));
function analyzeRobots(body){
  const lines=body.split(/\r?\n/).map(l=>l.trim().toLowerCase());
  return {disallow_root:lines.some(l=>/^disallow:\s*\/$/.test(l)),
    disallow_staging:lines.some(l=>/^disallow:\s*\/nuevaweb\/?$/.test(l)),
    gptbot_mentioned:lines.some(l=>l.includes('gptbot')),
    oai_searchbot_mentioned:lines.some(l=>l.includes('oai-searchbot')),
    sitemap_directive_present:lines.some(l=>l.startsWith('sitemap:'))};
}
function analyzeHtml(body){
  const low=body.toLowerCase();
  const robots=body.match(/<meta\s+[^>]*name=["']robots["'][^>]*>/i)?.[0]||'';
  return {html_robots_noindex:/noindex/i.test(robots),
    jsonld_count:(body.match(/<script[^>]+type=["']application\/ld\+json["']/gi)||[]).length,
    canonical_tags:(body.match(/<link[^>]+rel=["']canonical["']/gi)||[]).length,
    open_graph_tag_count:(body.match(/<meta[^>]+property=["']og:/gi)||[]).length,
    html_lang_present:/<html[^>]+lang=/.test(low)};
}
function selfTest(){
  if(ORIGIN!=='https://emmake.com'||CHECKS.length!==13||new Set(CHECKS.map(c=>c.id)).size!==13||
     CHECKS.some(c=>!/^\/[a-z0-9/?=.&_-]*$/.test(c.url)))throw Error('precutover audit not bounded');
  console.log('PASS: fixed-domain read-only launch preflight');
}
async function run(){
  fs.mkdirSync(path.dirname(OUT),{recursive:true});
  const results=[];
  for(const item of CHECKS){
    await pause(1050);
    const ctrl=new AbortController(); const timer=setTimeout(()=>ctrl.abort(),16000);
    try{
      const r=await fetch(ORIGIN+item.url,{method:'GET',redirect:'manual',
        signal:ctrl.signal,headers:{'accept':'text/html,application/xml,text/plain,application/json;q=0.8'}});
      const result={id:item.id,http_status:r.status,
        redirected:r.status>=300&&r.status<400,
        content_type:(r.headers.get('content-type')||'').split(';')[0],
        x_robots_noindex:/noindex/i.test(r.headers.get('x-robots-tag')||''),
        total_count:/^\d+$/.test(r.headers.get('x-wp-total')||'')?Number(r.headers.get('x-wp-total')):null};
      if(r.status===200 && ['robots','llms','html','xml'].includes(item.kind||'html')){
        const type=item.kind||'html';const body=(await r.text()).slice(0,750000);
        if(type==='robots')result.robots=analyzeRobots(body);
        if(type==='html')result.html=analyzeHtml(body);
        if(type==='llms')result.llms={nonempty:body.trim().length>0,
          markdown_like:/(^# |^## |^- \[)/m.test(body)};
        if(type==='xml')result.xml={sitemap_like:/(<sitemapindex|<urlset)/i.test(body)};
      }else {await r.body?.cancel().catch(()=>{});}
      results.push(result);
    }catch(e){results.push({id:item.id,http_status:0,error:e.name==='AbortError'?'timeout':'request_error'});}
    finally{clearTimeout(timer);}
  }
  const byId=Object.fromEntries(results.map(x=>[x.id,x]));
  const report={version:1,scope:'legacy/staging fixed public endpoints',
    observed_at:new Date().toISOString(),wordpress_mutations:0,
    posts_count_parity_known:byId.legacy_posts_api_count?.total_count!==null&&
      byId.staging_posts_api_count?.total_count!==null,
    root_site_serving:byId.legacy_home?.http_status===200,
    staging_site_serving:byId.staging_home?.http_status===200,
    readiness:'manual-cutover-parity-and-backup-gates-required',
    results};
  fs.writeFileSync(OUT,JSON.stringify(report,null,2)+'\n');
  console.log('EMMAKE_CUTOVER_DISCOVERY',JSON.stringify(results.map(r=>({id:r.id,status:r.http_status,total:r.total_count}))));
}
if(process.argv[2]==='--self-test')selfTest();
else if(process.argv[2]==='--run')run().catch(e=>{console.error('fixed public preflight failed:',e.message);process.exitCode=1;});
else process.exitCode=2;
