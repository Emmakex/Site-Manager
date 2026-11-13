#!/usr/bin/env node
'use strict';
/**
 * One controlled, intentionally INVALID anonymous form POST.
 * All required fields and the honeypot must be empty; the Theme must
 * redirect to invalid BEFORE wp_mail(). No emails can be generated.
 * Never log nonce, submitted values, identities, HTML or full URLs.
 */
const fs=require('node:fs');
const path=require('node:path');
const ORIGIN='https://emmake.com';
const PAGE=ORIGIN+'/nuevaweb/contacto/';
const ACTION=ORIGIN+'/nuevaweb/wp-admin/admin-post.php';
const OUT='artifacts/emmake-contact-negative/validation.json';
function assert(value,message){if(!value)throw Error(message)}
async function main(){
  const {chromium}=require('playwright');
  const browser=await chromium.launch({headless:true,args:['--no-sandbox']});
  let report={site:'emmake-nuevaweb',scope:'invalid-contact-form-negative-only',
    checked_at:new Date().toISOString(),email_or_message_sent:false,
    user_values_logged:false,nonce_logged:false,status:'failed'};
  try{
    const context=await browser.newContext({locale:'es-ES',serviceWorkers:'block'});
    try{
      const pg=await context.newPage();
      const landing=await pg.goto(PAGE,{waitUntil:'domcontentloaded',timeout:30000});
      assert(landing&&landing.status()===200&&pg.url()===PAGE,'contact landing unavailable');
      const form=await pg.evaluate(()=>{
        const f=document.querySelector('main form.seo-geo-contact-form');
        if(!f)return null;
        const required=[...f.querySelectorAll('[required]')].map(x=>({name:x.name,value:x.value}));
        return {method:f.method,action:f.getAttribute('action'),
          data:[...new FormData(f).entries()].filter(([k,v])=>typeof v==='string'),
          required};
      });
      assert(form&&form.method.toLowerCase()==='post','native POST form unavailable');
      assert(new URL(form.action,ORIGIN).href===ACTION,'form action not fixed staging endpoint');
      assert(form.required.length>=3,'expected required fields absent');
      assert(form.required.every(x=>x.name.startsWith('seo_geo_fields[')&&x.value===''),
        'required data must be empty for non-delivery negative test');
      const fields=Object.fromEntries(form.data);
      assert(fields.action==='seo_geo_contact_submit','unexpected action');
      assert(String(fields.post_id)==='50085','unexpected contact post');
      assert(typeof fields.form_id==='string'&&fields.form_id.length>0,'form id missing');
      assert(typeof fields._seo_geo_contact_nonce==='string'&&fields._seo_geo_contact_nonce.length>=5,'nonce missing');
      assert(fields.seo_geo_website==='','honeypot must be blank');
      assert(Object.entries(fields).filter(([k])=>k.startsWith('seo_geo_fields[')).every(([,v])=>v===''),
        'unsafe nonempty form value');
      const response=await context.request.post(ACTION,{
        form:fields,timeout:20000,maxRedirects:0,
        headers:{Referer:PAGE,Origin:ORIGIN}
      });
      const http=response.status(),redirect=response.headers().location||'';
      let redirectIsInvalid=false;
      try {
        const u=new URL(redirect,PAGE);
        redirectIsInvalid=u.origin===ORIGIN&&u.pathname==='/nuevaweb/contacto/'&&
          u.searchParams.get('seo_geo_form')==='invalid'&&
          u.searchParams.get('seo_geo_form_id')===fields.form_id;
      }catch{}
      report={...report,http_status:http,form_required_fields:form.required.length,
        wp_endpoint_checked:true,redirect_is_invalid:redirectIsInvalid,
        status:([301,302,303].includes(http)&&redirectIsInvalid)?'passed':'failed'};
      await response.dispose();
      await pg.close();
    }finally{await context.close()}
  }finally{await browser.close()}
  fs.mkdirSync(path.dirname(OUT),{recursive:true});
  fs.writeFileSync(OUT,JSON.stringify(report,null,2)+'\n');
  process.stdout.write('EMMAKE no-email negative submission: '+report.status+
    ' (HTTP '+report.http_status+', invalid_redirect '+report.redirect_is_invalid+')\n');
  if(report.status!=='passed')process.exitCode=1;
}
main().catch(e=>{process.stderr.write('Safe negative form check failed: '+e.message+'\n');process.exitCode=1});
