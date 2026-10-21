#!/usr/bin/env python3
"""Real packaged owner backup, schedule, download and recovery controls."""
from __future__ import annotations

import argparse
import hashlib
import importlib.metadata
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
from urllib.parse import parse_qs

from playwright.sync_api import expect, sync_playwright

ROOT=Path(__file__).resolve().parents[2]
sys.dont_write_bytecode=True
spec=importlib.util.spec_from_file_location('vvx_operations_http',ROOT/'tests/Http/operations_http.py')
assert spec and spec.loader
operations=importlib.util.module_from_spec(spec);spec.loader.exec_module(operations)
check,http=operations.check,operations.http


def submit(page,button,label,status=200):
    with page.expect_navigation(wait_until='networkidle') as result:button.click()
    response=result.value
    check(response is not None and response.status==status,label+' returned the wrong HTTP status.')
    if status==200:
        check(response.request.method=='GET' and response.request.redirected_from is not None
              and response.request.redirected_from.method=='POST',label+' bypassed POST/Redirect/GET.')
        expect(page.locator('[data-operations-error]')).to_have_count(0)
    else:
        check(response.request.method=='POST',label+' did not refuse the real stale submission.')
        expect(page.locator('[data-operations-error]')).to_be_visible()


def geometry(page,label):
    result=page.evaluate('''() => ({viewport:innerWidth,content:document.documentElement.scrollWidth,
      controls:[...document.querySelectorAll(`.operations-page input:not([type=hidden]):not([type=checkbox]),
        .operations-page button,.operations-page a.admin-button,.operations-page summary,.operations-check`)]
      .filter(element=>element.getClientRects().length).map(element=>{
        const r=element.getBoundingClientRect();return {name:element.name||element.innerText,left:r.left,right:r.right,height:r.height};
      }),contrast:[...document.querySelectorAll(`.operations-page p,.operations-page label,.operations-page summary,
        .operations-page code,.operations-page input:not([type=hidden]):not([type=checkbox])`)]
      .filter(element=>element.getClientRects().length).map(element=>{
        const style=getComputedStyle(element),numbers=value=>(value.match(/[\\d.]+/g)||[]).map(Number);
        const fg=numbers(style.color),paint=numbers(style.backgroundColor),panel=[27,17,25];
        const bg=paint.length===3||paint[3]===1?paint:panel;
        const lum=rgb=>rgb.slice(0,3).map(v=>{v/=255;return v<=.04045?v/12.92:((v+.055)/1.055)**2.4})
          .reduce((sum,v,index)=>sum+v*[.2126,.7152,.0722][index],0);
        const a=lum(fg),b=lum(bg);return {tag:element.tagName,color:style.color,background:style.backgroundColor,
          contrast:(Math.max(a,b)+.05)/(Math.min(a,b)+.05)};
      })})''')
    check(result['content']<=result['viewport']+1,label+' has document overflow: '+repr(result))
    check(bool(result['controls']) and all(item['left']>=-1 and item['right']<=result['viewport']+1 and item['height']>=43
          for item in result['controls']),label+' clips or undersizes owner controls: '+repr(result))
    check(bool(result['contrast']) and all(item['contrast']>=4.5 for item in result['contrast']),
          label+' has unreadable body/form text against the actual dark admin palette: '+repr(result['contrast']))
    return {'label':label,**result}


def capture(page,evidence,label):
    if evidence is None:return None
    page.evaluate('window.scrollTo({top:0,left:0,behavior:"instant"})')
    name='operations-'+label+'.png';path=evidence/name
    page.screenshot(path=str(path),full_page=True)
    check(path.stat().st_size>1000,'Browser screenshot is empty.')
    return {'file':name,'sha256':hashlib.sha256(path.read_bytes()).hexdigest()}


def browser_case(browser,case,viewport,evidence):
    initial=operations.checks+http.checks
    context=browser.new_context(viewport=viewport,accept_downloads=True)
    page=context.new_page();errors=[];actions=[];screenshots=[];layouts=[]
    page.on('pageerror',lambda error:errors.append(str(error)))
    page.on('request',lambda request:actions.extend(parse_qs(request.post_data or '').get('action',[]))
            if request.method=='POST' and request.resource_type=='document' else None)
    url=case['base']+'/admin/?page=operations';guest=http.session();other=None
    try:
        page.goto(case['base']+'/admin/?page=login',wait_until='networkidle')
        page.get_by_label('Email address').fill(http.OWNER)
        page.get_by_label('Password',exact=True).fill(http.PASSWORD)
        page.get_by_role('button',name='Sign in',exact=True).click()
        page.wait_for_url(case['base']+'/admin/')
        before=operations.fixture(case)
        response=page.goto(url,wait_until='networkidle')
        check(response is not None and response.status==200 and 'no-store' in response.headers.get('cache-control',''),
              'Owner Operations is not served privately.')
        expect(page.locator('[data-operations]')).to_be_visible()
        check(operations.fixture(case)==before,'Opening Operations initialized or changed private state.')
        layouts.append(geometry(page,'Unconfigured Operations'))
        screenshots.append(capture(page,evidence,case['label']+'-unconfigured'))
        with page.expect_download() as guide_result:page.get_by_role('link',name='Download deployment guide',exact=True).click()
        guide=guide_result.value
        check(guide.suggested_filename=='BACKUP_OPERATIONS.md' and Path(guide.path()).read_bytes()==(ROOT/'app/Docs/BACKUP_OPERATIONS.md').read_bytes(),
              'The real browser guide download differs from its shipped resource.')
        setup=page.get_by_role('button',name='Set up Operations',exact=True)
        setup.focus();page.keyboard.press('Tab');page.keyboard.press('Shift+Tab')
        check(setup.evaluate('(element)=>document.activeElement===element'),'Operations setup is not keyboard reachable.')
        submit(page,setup,'Set up Operations')
        expect(page.locator('[data-gate-status="pending"]')).to_be_visible()
        check(guest.get(case['base']+'/',timeout=20).status_code==503,'Initial setup allowed undrained public requests.')
        pending=operations.fixture(case)
        layouts.append(geometry(page,'Pending operator activation'))
        screenshots.append(capture(page,evidence,case['label']+'-pending'))
        # Really end the process which existed before setup before confirming it.
        case['restart']()
        operations.cli(case,'operations','--activate','--quiesced','--gate-revision='+pending['gate_revision'])
        status=operations.cli(case,'operations','--status')
        operations.cli(case,'operations','--configure-key','--key-file='+str(case['key']),'--config-revision='+status['config_revision'])
        page.goto(url,wait_until='networkidle')
        expect(page.locator('[data-gate-status="ready"]')).to_be_visible()
        check(guest.get(case['base']+'/',timeout=20).status_code==200,'Reviewed activation did not resume public requests.')
        policy=page.locator('[data-operations-policy]')
        policy.get_by_label('Schedule daily backups',exact=True).check()
        policy.get_by_label('Daily time (UTC)',exact=True).fill('03:15')
        policy.get_by_label('Keep newest copies',exact=True).fill('3')
        policy.get_by_label('Keep copies for days',exact=True).fill('14')
        submit(page,policy.get_by_role('button',name='Save schedule & retention',exact=True),'Save schedule')
        saved=operations.fixture(case)
        check(saved['journal']['policy']['enabled'] and saved['journal']['policy']['utc_time']=='03:15'
              and saved['journal']['policy']['keep_count']==3 and saved['journal']['policy']['keep_days']==14,
              'Actual browser schedule controls saved different values.')
        layouts.append(geometry(page,'Saved schedule and retention'))
        screenshots.append(capture(page,evidence,case['label']+'-scheduled'))
        page.reload(wait_until='networkidle')
        check(operations.fixture(case)==saved,'Reloading scheduled Operations ran due work or changed private state.')
        # A separate real owner session saves newer settings while this form stays open.
        other=http.owner_login(case['base']);newer=other.get(url,timeout=20)
        operations.post(other,url,http.changed(operations.form(newer,'[data-operations-policy]'),keep_count='4'))
        newest=operations.fixture(case)
        submit(page,page.locator('[data-operations-policy]').get_by_role('button',name='Save schedule & retention',exact=True),'Refuse stale schedule',409)
        check(operations.fixture(case)==newest,'Stale browser submission overwrote newer retention settings.')
        layouts.append(geometry(page,'Stale schedule refusal'))
        screenshots.append(capture(page,evidence,case['label']+'-stale'))
        page.goto(url,wait_until='networkidle')
        expect(page.get_by_label('Keep newest copies',exact=True)).to_have_value('4')
        submit(page,page.get_by_role('button',name='Queue encrypted backup',exact=True),'Queue backup')
        expect(page.locator('[data-open-backup="queued"]')).to_be_visible()
        queued=operations.fixture(case)
        jobs=[job for job in queued['journal']['jobs'].values() if job['status']=='queued']
        check(len(jobs)==1,'The browser queue did not save one durable attempt.')
        result=operations.cli(case,'run_backups')
        check(result['completed']==1 and result['failed']==0,'The real queued browser backup did not complete.')
        page.reload(wait_until='networkidle')
        history=page.locator('[data-backup-id="'+jobs[0]['id']+'"]')
        expect(history).to_have_attribute('data-backup-status','complete')
        history.get_by_text('Archive checksum',exact=True).click()
        completed=operations.fixture(case)['journal']['jobs'][jobs[0]['id']]
        expect(history.locator('code')).to_have_text(completed['archive_sha256'])
        with page.expect_download() as archive_result:history.get_by_role('button',name='Download encrypted archive',exact=True).click()
        download=archive_result.value;literal=Path(download.path()).read_bytes()
        check(download.suggested_filename.endswith('.vvxbak') and hashlib.sha256(literal).hexdigest()==completed['archive_sha256']
              and b'Private independent customer bytes.' not in literal,'The real browser archive download differs or exposes plaintext.')
        layouts.append(geometry(page,'Verified archive history'))
        screenshots.append(capture(page,evidence,case['label']+'-completed'))
        submit(page,page.get_by_role('button',name='Turn on recovery mode',exact=True),'Enable recovery')
        expect(page.locator('[data-gate-status="recovery"]')).to_be_visible()
        check(guest.get(case['base']+'/',timeout=20).status_code==503,'Browser recovery control did not hold public access.')
        layouts.append(geometry(page,'Owner recovery controls'))
        screenshots.append(capture(page,evidence,case['label']+'-recovery'))
        submit(page,page.get_by_role('button',name='Resume normal requests',exact=True),'Resume requests')
        expect(page.locator('[data-gate-status="ready"]')).to_be_visible()
        check(guest.get(case['base']+'/',timeout=20).status_code==200,'Browser recovery control did not resume public requests.')
        after=operations.fixture(case)
        check(after['protected']==before['protected'] and case['capture'].connections==0 and not errors,
              'Operations changed unrelated customer bytes, sent mail or raised JavaScript errors.')
        required=['operations_setup','operations_policy','operations_queue','operations_download','operations_recovery']
        check(all(action in actions for action in required),'A required owner browser action was bypassed.')
        return {'checks':operations.checks+http.checks-initial,'mode':case['mode'],'viewport':viewport,'source_server':after['source_server'],
            'actions':actions,'layouts':layouts,'screenshots':[item for item in screenshots if item],
            'javascript_errors':errors,'smtp_messages':0,'real_key_setup':True,'real_guide_download':True,
            'real_ciphertext_download':True,'stale_refusal_preserves_state':True,'no_customer_sentinel_changes':True}
    finally:
        if other is not None:other.close()
        guest.close();context.close()


def run():
    parser=argparse.ArgumentParser(description=__doc__);parser.add_argument('--mode',choices=('file','mysql','both'),default='both');args=parser.parse_args()
    evidence=operations.evidence_directory()
    report={'test_sha256':hashlib.sha256(Path(__file__).read_bytes()).hexdigest(),
        'http_test_sha256':hashlib.sha256((ROOT/'tests/Http/operations_http.py').read_bytes()).hexdigest(),
        'fixture_sha256':hashlib.sha256(operations.FIXTURE.read_bytes()).hexdigest(),
        'php':subprocess.check_output(['php','-r','echo PHP_VERSION;'],text=True),
        'playwright':importlib.metadata.version('playwright'),'cases':{}}
    with tempfile.TemporaryDirectory(prefix='vvx-operations-browser-') as temporary,sync_playwright() as playwright:
        work=Path(temporary);archive,identity=operations.prepare_package(work);report.update(identity)
        launch={'headless':True,'args':['--no-sandbox']}
        if os.environ.get('VELVET_CHROMIUM_EXECUTABLE'):launch['executable_path']=os.environ['VELVET_CHROMIUM_EXECUTABLE']
        browser=playwright.chromium.launch(**launch);report['chromium']=browser.version
        try:
            for mode in (('file','mysql') if args.mode=='both' else (args.mode,)):
                for layout,prefix,viewport in [('root-desktop','',{'width':1440,'height':1000}),('nested-mobile','/nested/velvet',{'width':390,'height':844})]:
                    label=mode+'-'+layout
                    with operations.runtime(work,archive,mode,label,prefix,evidence) as case:report['cases'][label]=browser_case(browser,case,viewport,evidence)
                    print(label+': '+str(report['cases'][label]['checks'])+' operations browser checks passed.',flush=True)
        finally:browser.close()
    report['checks']=sum(case['checks'] for case in report['cases'].values())
    if evidence:(evidence/'operations-browser.json').write_text(json.dumps(report,indent=2)+'\n')
    print(json.dumps(report,indent=2))


if __name__=='__main__':run()
