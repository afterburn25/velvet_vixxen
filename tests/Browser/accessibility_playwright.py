#!/usr/bin/env python3
"""Real packaged accessibility/keyboard checks and bounded loopback page-load budgets.

Install the pinned development-only engine with npm ci --prefix tests/Browser.
Automated axe results do not certify full WCAG conformance. Unresolved image,
gradient and other manual-review findings remain in the evidence report.
"""
from __future__ import annotations
import argparse, hashlib, importlib.util, json, os, statistics, subprocess, tempfile
from pathlib import Path
from playwright.sync_api import sync_playwright

ROOT=Path(__file__).resolve().parents[2]
spec=importlib.util.spec_from_file_location('accessibility_package',ROOT/'tests/Http/composer_depth_http.py')
depth=importlib.util.module_from_spec(spec);spec.loader.exec_module(depth)
http=depth._base;check=http.check
PUBLIC=['','about','music','photos','videos','membership','shop','collections','booking','account/login.php',
        'account/register.php','cart','blog','newsletter','links','privacy/choices.php']
ADMIN=['dashboard','composer','security','mail-settings','menus','scene-schedules','marketing&tab=overview',
       'marketing&tab=posts','marketing&tab=campaigns','marketing&tab=deleted','marketing&tab=analytics',
       'marketing&tab=notifications','marketing&tab=provider','members','payments','booking-form','media']
MEMBER=['account','account/library.php','account/billing.php','account/security.php','messages','requests']
RUNTIME=['public/assets/js/site.js','public/assets/js/admin.js','public/assets/js/admin-ux.js','public/assets/js/navigation.js','public/home.php',
         'public/routes/messages/index.php','public/routes/account/security.php',
         'public/assets/css/site-ux.css','public/assets/css/admin-ux.css','admin/index.php','app/Views/admin/login.php','app/Views/admin/security.php',
         'app/Views/admin/marketing-publication-editor.php','app/Views/admin/marketing-analytics.php']
TAGS=['wcag2a','wcag2aa','wcag21a','wcag21aa','wcag22aa']


def run_case(browser,case,viewport,engine,evidence):
    seeded=subprocess.run([case['php'],str(ROOT/'tests/Http/accessibility_fixture.php'),str(case['site'])],text=True,capture_output=True,timeout=20)
    check(seeded.returncode==0 and json.loads(seeded.stdout)['seeded'] is True,'Accessible media fixture failed: '+seeded.stderr[:1000])
    start=http.checks;context=browser.new_context(viewport=viewport,reduced_motion='reduce');page=context.new_page()
    errors=[];page.on('pageerror',lambda error:errors.append(str(error)))
    rows=[];shots=[];keyboard=[]
    def screenshot(label):
        data=page.screenshot(full_page=False);name='accessibility-'+case['label']+'-'+label+'.png'
        if evidence:(evidence/name).write_bytes(data)
        shots.append({'filename':name,'sha256':hashlib.sha256(data).hexdigest()})
    def scan(label):
        page.add_script_tag(path=str(engine))
        result=page.evaluate('''async tags=>await axe.run(document,{runOnly:{type:'tag',values:tags}})''',TAGS)
        check(result['testEngine']['version']=='4.11.4','The accessibility engine differs from the pinned dependency.')
        geometry=page.evaluate('''()=>({width:innerWidth,scrollWidth:document.documentElement.scrollWidth,
            title:document.title,lang:document.documentElement.lang,mains:document.querySelectorAll('main').length})''')
        nav=page.evaluate('''()=>{const n=performance.getEntriesByType('navigation')[0];return {
            responseMs:n.responseStart-n.requestStart,domMs:n.domContentLoadedEventEnd-n.startTime,
            documentBytes:n.decodedBodySize,resources:performance.getEntriesByType('resource').map(r=>({name:r.name,bytes:r.decodedBodySize}))}}''')
        rows.append({'label':label,'geometry':geometry,'performance':nav,
            'violations':result['violations'],'manual_review':result['incomplete'],
            'passed_rules':[r['id'] for r in result['passes']]})
        check(len(result['passes'])>=10,'The engine did not execute a substantive rendered-page ruleset.')
        check(bool(geometry['title']) and geometry['lang']=='en','Missing page title or language.')
        check(geometry['scrollWidth']<=geometry['width']+1,'Page overflows the viewport: '+label)
        check(nav['documentBytes']<2_000_000,'The representative HTML exceeds the 2 MB document budget: '+label)
    def visit(label,url,shot=False):
        response=page.goto(url,wait_until='networkidle',timeout=30000)
        check(response is not None and response.status==200,'Unexpected page response: '+label)
        if page.locator('#ageGate:not([hidden])').count():page.locator('#ageEnter').click()
        scan(label)
        if shot:screenshot(label.replace('/','-').replace('&','-').replace('=','-'))
    def key_check(ok,label):check(ok,label);keyboard.append(label)
    try:
        page.goto(case['base']+'/',wait_until='networkidle')
        page.locator('#ageGate:not([hidden])').wait_for()
        key_check(page.locator('#ageEnter').evaluate('(node)=>node===document.activeElement'),'Age prompt receives initial keyboard focus')
        page.keyboard.press('Shift+Tab');key_check(page.locator('#ageGate a[href]').evaluate('(node)=>node===document.activeElement'),'Age prompt wraps backward to Leave')
        page.keyboard.press('Tab');key_check(page.locator('#ageEnter').evaluate('(node)=>node===document.activeElement'),'Age prompt wraps forward to Enter')
        page.keyboard.press('Escape');key_check(page.locator('#ageGate').is_visible(),'Escape does not accept the age acknowledgement')
        page.keyboard.press('Tab');page.keyboard.press('Enter');key_check(page.locator('#ageGate').is_hidden(),'Age choice works using only the keyboard')
        key_check(page.locator('#mainContent').evaluate('(node)=>node===document.activeElement'),'Closing age prompt restores focus to content')
        page.locator('.skip-link').focus();page.keyboard.press('Enter')
        key_check(page.locator('#mainContent').evaluate('(node)=>node===document.activeElement'),'Public skip link reaches main content')
        opener=page.locator('[data-open-trailer]').first
        key_check(opener.count()==1,'The real fixture contains a trailer control')
        opener.focus();page.keyboard.press('Enter');page.locator('#trailerModal:not([hidden])').wait_for()
        key_check(page.locator('[data-close-trailer]').evaluate('(node)=>node===document.activeElement'),'Trailer opens with focus on Close')
        caption=page.locator('#trailerModal track[kind=captions]')
        key_check(caption.count()==1,'The public trailer exposes its saved public caption track')
        caption_response=page.request.get(caption.get_attribute('src'))
        key_check(caption_response.status==200 and caption_response.text().startswith('WEBVTT'),'The caption control retrieves the actual saved caption bytes')
        scan('public/trailer-dialog');screenshot('trailer-dialog')
        page.keyboard.press('Shift+Tab');key_check(page.locator('#trailerModal').evaluate('(node)=>node.contains(document.activeElement)'),'Trailer contains backward keyboard focus')
        page.keyboard.press('Escape');key_check(page.locator('#trailerModal').is_hidden() and opener.evaluate('(node)=>node===document.activeElement'),'Escape closes trailer and returns focus to its opener')
        for path in PUBLIC:
            visit('public/'+(path or 'home'),http.route(case['base'],path) if path else case['base']+'/',path in ['', 'account/register.php'])
        if viewport['width']<900:
            toggle=page.locator('#mobileMenu');toggle.focus();page.keyboard.press('Enter')
            key_check(toggle.get_attribute('aria-expanded')=='true','Public mobile navigation opens with Enter')
            page.keyboard.press('Escape');key_check(toggle.get_attribute('aria-expanded')=='false','Public mobile navigation closes with Escape')
        # Real member password sign-in exposes account, billing and private forms.
        page.goto(http.route(case['base'],'account/login.php'),wait_until='networkidle')
        page.locator('[name=email]').fill('free@example.test');page.locator('[name=password]').fill(http.MEMBER_PASSWORD)
        page.get_by_role('button',name='Sign In',exact=True).click();page.wait_for_load_state('networkidle')
        check('login.php' not in page.url,'Actual member sign-in failed.')
        for path in MEMBER:visit('member/'+path,http.route(case['base'],path),path=='account/security.php')
        visit('admin/login',case['base']+'/admin/?page=login',True)
        page.get_by_label('Email address',exact=True).fill(http.OWNER);page.get_by_label('Password',exact=True).fill(http.PASSWORD)
        page.get_by_role('button',name='Sign in',exact=True).click();page.wait_for_load_state('networkidle')
        check('page=login' not in page.url,'Actual owner sign-in failed.')
        page.locator('.admin-skip-link').focus();page.keyboard.press('Enter')
        key_check(page.locator('#adminContent').evaluate('(node)=>node===document.activeElement'),'Owner skip link reaches main content')
        if viewport['width']<900:
            key_check(page.locator('#adminSidebar').evaluate('(node)=>node.inert'),'Closed mobile owner menu is absent from keyboard navigation')
            toggle=page.locator('.sidebar-toggle');toggle.focus();page.keyboard.press('Enter')
            key_check(toggle.get_attribute('aria-expanded')=='true' and page.locator('#adminSidebar').evaluate('(node)=>node.contains(document.activeElement)'),'Owner mobile menu opens and receives keyboard focus')
            page.keyboard.press('Escape');key_check(toggle.get_attribute('aria-expanded')=='false' and toggle.evaluate('(node)=>node===document.activeElement'),'Owner mobile menu closes and returns keyboard focus')
        for path in ADMIN:visit('admin/'+path,case['base']+'/admin/?page='+path,path in ['composer','security','marketing&tab=notifications'])
        # An acknowledgement remains usable in browsers denying local storage.
        blocked=browser.new_context(viewport=viewport)
        blocked.add_init_script("Storage.prototype.getItem=function(){throw new DOMException('Storage blocked','SecurityError')};Storage.prototype.setItem=function(){throw new DOMException('Storage blocked','SecurityError')}")
        try:
            denied=blocked.new_page();denied.goto(case['base']+'/',wait_until='networkidle')
            denied.locator('#ageEnter').click();key_check(denied.locator('#ageGate').is_hidden(),'Age choice remains usable when browser storage is blocked')
        finally:blocked.close()
        violations=[{'page':r['label'],'rules':[v['id'] for v in r['violations']]} for r in rows if r['violations']]
        timings=sorted(r['performance']['responseMs'] for r in rows)
        p95=timings[min(len(timings)-1,int(len(timings)*.95))]
        check(p95<5000 and max(timings)<10000,'Representative loopback page responses exceeded the reviewed 5 s p95 / 10 s maximum budget.')
        check(not errors,'Browser JavaScript errors: '+repr(errors))
        check(case['capture'].connections==0,'Read-only accessibility review sent mail.')
        return {'checks':http.checks-start,'pages':rows,'keyboard':keyboard,'screenshots':shots,'violations':violations,
            'performance':{'scope':'isolated PHP server and installed stock/editorial fixture; no production latency claim',
                'samples':len(timings),'median_response_ms':statistics.median(timings),'p95_response_ms':p95,'max_response_ms':max(timings),
                'budgets':{'document_bytes':2_000_000,'p95_response_ms':5000,'maximum_response_ms':10000}},
            'javascript_errors':errors,'smtp_connections':case['capture'].connections}
    finally:context.close()


def run():
    parser=argparse.ArgumentParser();parser.add_argument('--mode',choices=['file','mysql','both'],default='both');options=parser.parse_args()
    engine=Path(os.environ.get('VELVET_AXE_SCRIPT',ROOT/'tests/Browser/node_modules/axe-core/axe.min.js'))
    check(engine.is_file(),'Install the pinned browser test dependency with npm ci --prefix tests/Browser.')
    evidence=http.evidence_directory();report={'engine_sha256':hashlib.sha256(engine.read_bytes()).hexdigest(),'axe_version':'4.11.4','tags':TAGS,'cases':{}}
    with tempfile.TemporaryDirectory(prefix='vvx-accessibility-') as tmp,sync_playwright() as playwright:
        work=Path(tmp);archive,identity=depth.prepare_package(work);report.update(identity)
        from zipfile import ZipFile
        with ZipFile(archive) as zipped:check(all(zipped.read(name)==(ROOT/name).read_bytes() for name in RUNTIME),'Accessibility runtime differs from the actual packaged source.')
        browser=playwright.chromium.launch(executable_path=os.environ.get('PLAYWRIGHT_CHROMIUM_EXECUTABLE') or os.environ.get('VELVET_CHROMIUM') or None,headless=True,args=['--no-sandbox','--disable-dev-shm-usage'])
        report['chromium']=browser.version
        try:
            for mode in (['file','mysql'] if options.mode=='both' else [options.mode]):
                for device,prefix,viewport in [('desktop','',{'width':1440,'height':1000}),('mobile','/nested/velvet',{'width':390,'height':844})]:
                    label=mode+'-'+device
                    with http.runtime(work,archive,mode,label,prefix,evidence) as case:report['cases'][label]=run_case(browser,case,viewport,engine,evidence)
                    if evidence:(evidence/'accessibility-evidence.json').write_text(json.dumps(report,indent=2)+'\n')
                    print('Accessibility '+label+': '+str(report['cases'][label]['checks'])+' checks; '+str(len(report['cases'][label]['violations']))+' pages with violations',flush=True)
        finally:browser.close()
    check(all(not c['violations'] for c in report['cases'].values()),'Automated accessibility violations remain: '+repr({k:v['violations'] for k,v in report['cases'].items()}))
    print(json.dumps({'cases':{k:{'checks':v['checks'],'pages':len(v['pages']),'keyboard_checks':len(v['keyboard']),'screenshots':len(v['screenshots']),'performance':v['performance']} for k,v in report['cases'].items()},'fresh_zip_sha256':report['fresh_zip_sha256']},indent=2))

if __name__=='__main__':run()
