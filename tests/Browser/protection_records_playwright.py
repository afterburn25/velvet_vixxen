#!/usr/bin/env python3
"""Real Apache owner/report controls and actual desktop/mobile protection captures."""
import argparse, hashlib, importlib.util, json, os, tempfile
from pathlib import Path
from playwright.sync_api import sync_playwright

ROOT=Path(__file__).resolve().parents[2]
spec=importlib.util.spec_from_file_location('protection_http',ROOT/'tests/Http/protection_records_http.py')
protection=importlib.util.module_from_spec(spec);spec.loader.exec_module(protection)
http=protection.http;check=http.check

def browser_case(browser,case,viewport,evidence):
    start=http.checks;context=browser.new_context(viewport=viewport);reporter_context=browser.new_context(viewport=viewport)
    page=context.new_page();reporter=reporter_context.new_page();errors=[];shots=[];layouts=[]
    for current in [page,reporter]:current.on('pageerror',lambda e:errors.append(str(e)))
    records=json.loads((case['site']/'storage/temp/protection-fixture-records.json').read_text())
    def submit(current,button,status=200):
        with current.expect_response(lambda r:r.request.method=='POST') as response:button.click()
        current.wait_for_load_state('domcontentloaded')
        check(response.value.status in ([200,302,303] if status==200 else [status]),'A real protection control returned unexpected status '+str(response.value.status))
    def shot(current,name):
        current.evaluate('''async()=>{scrollTo(0,0);await new Promise(requestAnimationFrame);await new Promise(requestAnimationFrame)}''')
        geometry=current.evaluate('''()=>({width:innerWidth,document:document.documentElement.scrollWidth,controls:[...(document.querySelector('[data-protection-workspace],#reportMain')||document).querySelectorAll('input:not([type=hidden]),button,select,summary')].filter(x=>x.getBoundingClientRect().height>0).map(x=>({name:x.name||x.textContent.trim().slice(0,60),height:(x.type==='checkbox'?(x.closest('label')||x):x).getBoundingClientRect().height}))})''')
        check(geometry['document']<=geometry['width']+1,'Protection horizontal overflow: '+name+repr(geometry))
        check(all(x['height']>=44 for x in geometry['controls']),'Protection touch target below44px: '+name+repr(geometry))
        payload=current.screenshot(full_page=True);filename='protection-'+case['label']+'-'+name+'.png'
        shots.append({'name':filename,'sha256':hashlib.sha256(payload).hexdigest(),'bytes':len(payload)});layouts.append({'name':name,**geometry})
        if evidence:(evidence/filename).write_bytes(payload)
    try:
        page.goto(case['base']+'/admin/?page=login');page.get_by_label('Email address',exact=True).fill(http.OWNER);page.get_by_label('Password',exact=True).fill(http.PASSWORD)
        submit(page,page.get_by_role('button',name='Sign in',exact=True));check('page=login' not in page.url,'Owner browser login failed.')
        page.goto(protection.workspace(case));shot(page,'setup')
        page.locator('[name=confirm_private]').check();submit(page,page.get_by_role('button',name='Enable protection',exact=True))
        check(page.get_by_role('link',name='Add collaborator',exact=True).count()==1,'Actual activation did not open collaborator controls.')
        page.get_by_role('link',name='Add collaborator',exact=True).click();page.get_by_label('Display name',exact=True).fill('Synthetic collaborator')
        page.locator('[name=document_reference]').fill('urn:fixture:document-reference');page.get_by_label('Private notes',exact=True).fill('Private synthetic review note.')
        submit(page,page.get_by_role('button',name='Save collaborator',exact=True));person=page.locator('input[name=id]').input_value()
        stale_page=context.new_page();stale_page.goto(page.url);stale_revision=stale_page.locator('input[name=revision]').input_value()
        page.get_by_label('Display name',exact=True).fill('Current collaborator');submit(page,page.get_by_role('button',name='Save collaborator',exact=True))
        stale_page.get_by_label('Display name',exact=True).fill('Retained stale collaborator');submit(stale_page,stale_page.get_by_role('button',name='Save collaborator',exact=True),409)
        check(stale_page.locator('input[name=revision]').input_value()==stale_revision and stale_page.get_by_label('Display name',exact=True).input_value()=='Retained stale collaborator','Stale browser edit was silently rebased.');stale_page.close()
        page.goto(protection.workspace(case,'releases','new'));page.get_by_label('Release title',exact=True).fill('Synthetic consent release')
        page.get_by_label('Status',exact=True).select_option('active');page.get_by_label('Collaborators',exact=True).select_option(person)
        page.get_by_label('Linked publications and media',exact=True).select_option('album:'+records['album']);page.locator('[name=document_reference]').fill('urn:fixture:saved-release')
        page.locator('[name=consent_reviewed]').check();shot(page,'release');submit(page,page.get_by_role('button',name='Save release',exact=True))
        reporter.goto(protection.report_url(case));reporter.get_by_label('What is your concern?',exact=True).select_option('consent')
        reporter.get_by_label('Report subject',exact=True).fill('Synthetic publishing concern');reporter.get_by_label('Your name',exact=True).fill('Fixture Reporter')
        reporter.get_by_label('Email address',exact=True).fill('reporter@example.test');reporter.get_by_label('Content address on this website',exact=True).fill(case['base']+'/music/')
        reporter.get_by_label('Report details',exact=True).fill('Private synthetic report details to review.');reporter.locator('[name=accurate]').check();shot(reporter,'report')
        submit(reporter,reporter.get_by_role('button',name='Submit report',exact=True));case_id=reporter.get_by_label('Receipt case number',exact=True).input_value();key=reporter.get_by_label('Receipt access key',exact=True).input_value()
        check(len(case_id)==32 and len(key)==48 and key not in reporter.url,'Browser receipt key was missing or exposed in the URL.')
        reporter.reload();check(reporter.get_by_label('Receipt access key',exact=True).count()==0,'Browser receipt key was redisplayed.')
        case_url=protection.workspace(case,'cases',case_id);page.goto(case_url);shot(page,'case')
        page.get_by_label('Case action',exact=True).select_option('request_reply');page.get_by_label('Private decision notes',exact=True).fill('Private owner request context.')
        page.locator('[name=public_message]').fill('Please provide the remaining synthetic details.');submit(page,page.get_by_role('button',name='Save case decision',exact=True))
        reporter.reload();check('Private owner request context.' not in reporter.locator('body').inner_text(),'Private owner notes leaked to the reporter.')
        reporter.get_by_label('Your reply',exact=True).fill('The requested synthetic information.');submit(reporter,reporter.get_by_role('button',name='Send reply to this case',exact=True))
        page.goto(case_url);page.get_by_label('Case action',exact=True).select_option('takedown');page.get_by_label('Linked publications and media',exact=True).select_option(['album:'+records['album'],'video:'+records['video']])
        page.get_by_label('Private decision notes',exact=True).fill('Private withdrawal reason.');page.locator('[name=public_message]').fill('The selected content is withdrawn while we review.');submit(page,page.get_by_role('button',name='Save case decision',exact=True))
        reporter.reload();shot(reporter,'held');check(reporter.get_by_role('button',name='Submit appeal',exact=True).count()==1,'Reporter has no stateful appeal control.')
        check(reporter.request.get(records['signed_url']).status==403,'Previously signed media link survived withdrawal.')
        page.get_by_label('Case action',exact=True).select_option('reinstate');page.get_by_label('Private decision notes',exact=True).fill('Current review is complete.')
        page.locator('[name=public_message]').fill('This hold is lifted. The files still await separate owner release.');submit(page,page.get_by_role('button',name='Save case decision',exact=True))
        page.goto(protection.workspace(case,'media'));shot(page,'offline')
        check(reporter.request.get(records['signed_url']).status==403,'Reinstatement revived an old signed link.')
        check(page.locator('form[data-protection-release-media]').count()==2,'Held media file review did not include both affected files.')
        while page.locator('form[data-protection-release-media]').count():
            form=page.locator('form[data-protection-release-media]').first;form.locator('input[type=checkbox]').check();submit(page,form.get_by_role('button',name='Make media available',exact=True))
        check(reporter.request.get(records['signed_url']).status==200,'Explicit owner file release did not restore protected delivery.')
        current=protection.fixture(case,'snapshot');check(current['album']['status']==current['video']['status']==current['post']['status']=='draft','The browser hold-lift flow automatically published saved content.')
        reporter.reload();reporter.get_by_label('Appeal details',exact=True).fill('Please reconsider these additional synthetic details.');submit(reporter,reporter.get_by_role('button',name='Submit appeal',exact=True));shot(reporter,'appeal')
        # The independent receipt lookup is fully functional without JavaScript.
        no_script=browser.new_context(viewport=viewport,java_script_enabled=False)
        try:
            lookup=no_script.new_page();lookup.goto(protection.report_url(case));scope=lookup.locator('[data-report-lookup]')
            scope.get_by_label('Case number',exact=True).fill(case_id);scope.get_by_label('Access key',exact=True).fill(key);submit(lookup,scope.get_by_role('button',name='Open case',exact=True))
            check('Appeal received' in lookup.locator('body').inner_text(),'No-script receipt lookup failed.')
        finally:no_script.close()
        check(not errors,'Protection browser JS errors: '+repr(errors));check(case['capture'].connections==0,'Browser protection controls contacted SMTP.')
        return {'checks':http.checks-start,'mysql_version':case['mysql_version'],'screenshots':shots,'layouts':layouts,'javascript_errors':errors,'real_apache_activation':True,'collaborator_release_controls':True,'stale_form_preserved':True,'report_reply_appeal_controls':True,'private_receipt_lookup_without_js':True,'no_automatic_publication':True,'old_signed_url_hold':True,'explicit_media_release':True,'smtp_connections':0}
    finally:context.close();reporter_context.close()

def run():
    parser=argparse.ArgumentParser();parser.add_argument('--mode',choices=['file','mysql','both'],default='both');args=parser.parse_args();evidence=http.evidence_directory();report={'server':'real Apache + matching mod_php','cases':{}}
    with tempfile.TemporaryDirectory(prefix='vvx-protection-browser-') as tmp,sync_playwright() as playwright:
        work=Path(tmp);archive,identity=protection.prepare_package(work);report.update(identity)
        browser=playwright.chromium.launch(executable_path=os.environ.get('VELVET_CHROMIUM') or os.environ.get('PLAYWRIGHT_CHROMIUM_EXECUTABLE') or None,headless=True,args=['--no-sandbox'])
        report['chromium']=browser.version
        try:
            for mode in (['file','mysql'] if args.mode=='both' else [args.mode]):
                for label,prefix,viewport in [('root-desktop','',{'width':1440,'height':1000}),('nested-mobile','/nested/velvet',{'width':390,'height':844})]:
                    name=mode+'-'+label
                    with protection.runtime(work,archive,mode,name,prefix,evidence) as case:report['cases'][name]=browser_case(browser,case,viewport,evidence)
                    print('Protection browser '+name+': PASS',flush=True)
        finally:browser.close()
    if evidence:(evidence/'protection-browser-evidence.json').write_text(json.dumps(report,indent=2)+'\n')
    print(json.dumps(report,indent=2))
if __name__=='__main__':run()
