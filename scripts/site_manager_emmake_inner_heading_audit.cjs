#!/usr/bin/env node
'use strict';
// Fixed-site, public REST editorial structure audit. Zero raw content persistence.
const fs=require('node:fs'),path=require('node:path');
const ENDPOINT='https://emmake.com/nuevaweb/wp-json/wp/v2/pages/';
const PAGES=[
  {key:'blog-transformacion-digital',id:50344},
  {key:'trabaja-con-nosotros',id:50476},
  {key:'asistentes-virtuales-de-voz',id:52879},
];
const OUT='artifacts/emmake-inner-headings/report.json';
function countHeadings(html,tag){
  return (html.match(new RegExp('<'+tag+'(?:\\s|>)','gi'))||[]).length;
}
async function audit(){
  const results=[];
  for(const page of PAGES){
    const url=ENDPOINT+page.id+'?_fields=id%2Cstatus%2Cslug%2Ccontent';
    const ctrl=new AbortController(), stop=setTimeout(()=>ctrl.abort(),14000);
    try{
      const r=await fetch(url,{redirect:'manual',signal:ctrl.signal,headers:{accept:'application/json'}});
      if(r.status!==200) throw new Error('public REST did not return 200 for '+page.key+': '+r.status);
      const body=await r.text();
      if(body.length>500000)throw Error('page body exceeds bounded limit');
      const v=JSON.parse(body);
      if(v.id!==page.id||v.status!=='publish'||v.slug!==page.key||
         !v.content||v.content.protected!==false||typeof v.content.rendered!=='string')
        throw Error('unexpected WordPress page identity or visibility: '+page.key);
      const html=v.content.rendered;
      results.push({
        page:page.key, post_id:page.id, status:'published',
        h1_in_rendered_content:countHeadings(html,'h1'),
        h2_in_rendered_content:countHeadings(html,'h2'),
        h3_in_rendered_content:countHeadings(html,'h3'),
        has_nontrivial_content:html.replace(/<[^>]*>/g,' ').trim().length>=80,
        content_logged:false
      });
    }finally{clearTimeout(stop)}
  }
  fs.mkdirSync(path.dirname(OUT),{recursive:true});
  fs.writeFileSync(OUT,JSON.stringify({site:'emmake-nuevaweb',scope:'public-page-structure-only',timestamp:new Date().toISOString(),results},null,2)+'\n');
  for(const r of results)process.stdout.write(r.page+' H1(content)='+r.h1_in_rendered_content+' H2='+r.h2_in_rendered_content+'\n');
}
function selfTest(){
  if(PAGES.length!==3||new Set(PAGES.map(x=>x.id)).size!==3)throw Error('bad scope');
  if(countHeadings('<h1>One</h1><h1 class="x">Two</h1><h2>Other</h2>','h1')!==2)
    throw Error('bad h1 count');
  if(countHeadings('<h3>Test</h3>','h1')!==0)throw Error('false H1');
  process.stdout.write('PASS: fixed published-page outline audit contract\n');
}
if(process.argv.includes('--self-test'))selfTest();
else if(process.argv.includes('--run'))audit().catch(e=>{process.stderr.write('Editorial outline failed safely: '+e.message+'\n');process.exitCode=1;});
else process.exitCode=2;
