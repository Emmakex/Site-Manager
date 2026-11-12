#!/usr/bin/env node
'use strict';

/**
 * Independent fixed-origin PUBLIC WordPress REST snapshot.
 * Purpose: prove real post totals without changing the installed Site Manager
 * plugin, and avoid ever treating its 40-item first page as the catalog total.
 * No raw article text, links, IDs or private data leave this process.
 */
const fs = require('node:fs');
const path = require('node:path');

const BASE = 'https://emmake.com/nuevaweb/wp-json/wp/v2/posts';
const MAX_PAGES = 50;
const PAGE_SIZE = 100;
const OUT = 'artifacts/emmake-post-inventory/public-post-counts.json';
const MAX_RESPONSE_BYTES = 2_000_000;

function safeInteger(s) {
  if (!/^\d+$/.test(String(s))) throw new Error('invalid public count header');
  const n = Number(s);
  if (!Number.isSafeInteger(n) || n < 0 || n > MAX_PAGES * PAGE_SIZE) {
    throw new Error('public count out of bounded range');
  }
  return n;
}
function requestUrl(page) {
  if (!Number.isInteger(page) || page < 1 || page > MAX_PAGES) {
    throw new Error('unbounded WordPress post page');
  }
  return `${BASE}?per_page=${PAGE_SIZE}&page=${page}&_fields=id%2Cstatus%2Cdate_gmt`;
}
function classify(item) {
  if (!item || typeof item !== 'object' || !Number.isSafeInteger(item.id) ||
      item.id <= 0 || item.status !== 'publish' ||
      typeof item.date_gmt !== 'string' ||
      !/^\d{4}-\d{2}-\d{2}T/.test(item.date_gmt)) {
    throw new Error('unexpected or private WordPress record');
  }
  const year = Number(item.date_gmt.slice(0,4));
  if (!Number.isInteger(year) || year < 2000 || year > 2030) {
    throw new Error('published year outside conservative range');
  }
  return year;
}
async function fetchBounded(page) {
  const url = requestUrl(page);
  const ctrl = new AbortController();
  const stop = setTimeout(() => ctrl.abort(), 15000);
  try {
    const response = await fetch(url, {
      redirect:'manual', signal:ctrl.signal,
      headers:{accept:'application/json','user-agent':'SiteManager-PublicCountAudit/1.0'}
    });
    if (response.status !== 200) throw new Error('public WP REST non-200 on page '+page+': '+response.status);
    if (!(response.headers.get('content-type')||'').includes('application/json')) {
      throw new Error('invalid public REST response type');
    }
    const txt = await response.text();
    if (txt.length > MAX_RESPONSE_BYTES) throw new Error('public JSON response too large');
    const records = JSON.parse(txt);
    if (!Array.isArray(records) || records.length > PAGE_SIZE) throw new Error('non-bounded public post list');
    return {records, total:safeInteger(response.headers.get('x-wp-total')), pages:safeInteger(response.headers.get('x-wp-totalpages'))};
  } finally { clearTimeout(stop); }
}
async function run() {
  let meta = null;
  const unique = new Set();
  const years = {};
  let count = 0;
  const first = await fetchBounded(1);
  if (first.pages < 1 || first.pages > MAX_PAGES ||
      first.pages !== Math.max(1,Math.ceil(first.total/PAGE_SIZE))) {
    throw new Error('inconsistent public REST pagination');
  }
  for(let page=1;page<=first.pages;page++){
    const data = page===1 ? first : await fetchBounded(page);
    if(data.total!==first.total || data.pages!==first.pages) {
      throw new Error('public catalog changed while counting; repeat a new snapshot');
    }
    for(const item of data.records) {
      const year=classify(item);
      if (unique.has(item.id)) throw new Error('duplicate ID across public post pages');
      unique.add(item.id); count++;
      years[String(year)] = (years[String(year)]||0)+1;
    }
  }
  const output = {
    source:'wordpress-public-rest',
    site:'emmake-nuevaweb',
    scope:'published-post-aggregate-only',
    observed_at:new Date().toISOString(),
    published_total_header:first.total,
    published_count_scanned:count,
    pages_scanned:first.pages,
    page_size:PAGE_SIZE,
    counts_match:count===first.total,
    published_by_year:years,
    private_or_draft_counts_included:false,
    post_ids_or_titles_retained:false
  };
  fs.mkdirSync(path.dirname(OUT),{recursive:true});
  fs.writeFileSync(OUT,JSON.stringify(output,null,2)+'\n');
  process.stdout.write('Public published posts: '+count+' / '+first.total+'; pages: '+first.pages+'; matching: '+output.counts_match+'\n');
  if(!output.counts_match)process.exitCode=1;
}
function selfTest() {
  if(!requestUrl(1).startsWith(BASE) || requestUrl(3).includes('attacker'))throw Error('invalid request origin');
  for(const x of [0,-1,51,1.5,'?url=https://bad.example']) {
    try {requestUrl(x);throw new Error('accepted unsafe page');} catch(e){if(e.message==='accepted unsafe page')throw e;}
  }
  if(classify({id:9,status:'publish',date_gmt:'2025-03-14T10:10:00'})!==2025)
    throw Error('wrong year classification');
  for(const item of [{id:5,status:'draft',date_gmt:'2025-01-01T00:00:00'},
                      {id:5,status:'private',date_gmt:'2025-01-01T00:00:00'}]){
    try {classify(item);throw new Error('private accepted');} catch(e){if(e.message==='private accepted')throw e;}
  }
  if(safeInteger('1726')!==1726)throw Error('invalid public total');
  process.stdout.write('PASS: bounded fixed WordPress public count contract\n');
}
if(process.argv.includes('--self-test'))selfTest();
else if(process.argv.includes('--run'))run().catch(e=>{
  process.stderr.write('Bounded public inventory failed: '+e.message+'\n');
  process.exitCode=1;
});
else {process.stderr.write('Usage: --self-test or --run\n');process.exitCode=2;}
