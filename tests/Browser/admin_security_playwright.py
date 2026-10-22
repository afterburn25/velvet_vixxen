#!/usr/bin/env python3
"""Packaged authenticator/recovery and keyboard UI on file/MySQL desktop/mobile."""
import argparse, hashlib, importlib.util, json, os, tempfile
from pathlib import Path
from playwright.sync_api import sync_playwright
ROOT=Path(__file__).resolve().parents[2]
spec=importlib.util.spec_from_file_location('admin_security_http',ROOT/'tests/Http/admin_security_http.py');security=importlib.util.module_from_spec(spec);spec.loader.exec_module(security)
http=security.http;check=http.check

def case_browser(browser,case,viewport,evidence):
 start=http.checks;context=browser.new_context(viewport=viewport);page=context.new_page();errors=[];page.on('pageerror',lambda e:errors.append(str(e)));shots=[];layouts=[]
 def shot(name):
  geometry=page.evaluate('''()=>({width:innerWidth,document:document.documentElement.scrollWidth,controls:[...document.querySelectorAll('input:not([type=hidden]),button,summary')].filter(x=>x.getBoundingClientRect().height>0).map(x=>({name:x.name||x.textContent.trim().slice(0,60),height:(x.type==='checkbox'?(x.closest('label')||x):x).getBoundingClientRect().height}))})''')
  check(geometry['document']<=geometry['width']+1,'Security screen overflows '+name)
  check(all(x['height']>=40 for x in geometry['controls']),'Security hit target clipped '+name+repr(geometry))
  payload=page.screenshot();filename='security-'+case['label']+'-'+name+'.png';shots.append({'name':filename,'sha256':hashlib.sha256(payload).hexdigest()});layouts.append(geometry)
  if evidence:(evidence/filename).write_bytes(payload)
 def submit(button):
  with page.expect_response(lambda r:r.request.method=='POST' and '/admin/' in r.url) as result:button.click()
  page.wait_for_load_state('domcontentloaded');check(result.value.status in [200,302,303],'Security form did not succeed.')
 try:
  page.goto(case['base']+'/admin/?page=login');page.get_by_label('Email address',exact=True).fill(http.OWNER);page.get_by_label('Password',exact=True).fill(http.PASSWORD);submit(page.get_by_role('button',name='Sign in',exact=True));check('page=login' not in page.url,'Browser sign-in failed.')
  page.goto(case['base']+'/admin/?page=security');shot('overview')
  begin=page.locator('form').filter(has=page.locator('input[value=security_begin]'));begin.get_by_label('Current password',exact=True).fill(http.PASSWORD)
  begin.get_by_role('button',name='Set up authenticator').focus();check(page.evaluate('document.activeElement.textContent.trim()')=='Set up authenticator','Keyboard setup focus missing.');page.keyboard.press('Enter');page.wait_for_load_state('domcontentloaded')
  page.locator('.security-secret code').wait_for();secret=page.locator('.security-secret code').inner_text().strip();shot('setup')
  page.get_by_label('Authenticator code',exact=True).fill(security.totp(secret));submit(page.get_by_role('button',name='Connect authenticator'))
  codes=page.locator('.security-codes code').all_inner_texts();check(len(codes)==10,'Browser recovery codes missing.');shot('recovery-codes')
  page.goto(case['base']+'/admin/?page=logout');page.get_by_label('Email address',exact=True).fill(http.OWNER);page.get_by_label('Password',exact=True).fill(http.PASSWORD);submit(page.get_by_role('button',name='Sign in',exact=True));shot('challenge')
  page.get_by_label('Authenticator or recovery code',exact=True).fill(codes[0]);submit(page.get_by_role('button',name='Verify code',exact=True));check('page=login' not in page.url,'Recovery sign-in failed.')
  page.goto(case['base']+'/admin/?page=security');shot('enabled')
  details=page.locator('details').filter(has=page.get_by_text('Remove authenticator',exact=True).first);summary=details.locator('summary');summary.focus();page.keyboard.press('Enter');check(details.get_attribute('open') is not None,'Keyboard disclosure failed.')
  remove=details.locator('form');remove.get_by_label('Current password',exact=True).fill(http.PASSWORD);remove.get_by_label('Authenticator or recovery code',exact=True).fill(codes[1]);remove.get_by_role('checkbox').check();shot('remove-confirmation');submit(remove.get_by_role('button',name='Remove authenticator',exact=True))
  check(page.get_by_role('button',name='Set up authenticator').count()==1,'Authenticator removal not reflected.')
  check(not errors,'Browser JS errors: '+repr(errors));check(case['capture'].connections==0,'Browser security flow contacted SMTP.')
  actual=http.fixture(case['site'],'read',case['php'])
  check((case['mode']=='mysql')==bool(actual['mysql_version']),'Actual administrator-security database version is missing.')
  return {'checks':http.checks-start,'mysql_version':actual['mysql_version'],'screenshots':shots,'layouts':layouts,'javascript_errors':errors,'keyboard_setup':True,'keyboard_disclosure':True,'smtp_connections':0}
 finally:context.close()

def run():
 parser=argparse.ArgumentParser();parser.add_argument('--mode',choices=['file','mysql','both'],default='both');opt=parser.parse_args();evidence=http.evidence_directory();report={'cases':{}}
 with tempfile.TemporaryDirectory(prefix='vvx-admin-security-browser-') as tmp,sync_playwright() as playwright:
  work=Path(tmp);archive,identity=security.prepare_package(work);report.update(identity);browser=playwright.chromium.launch(executable_path=os.environ.get('PLAYWRIGHT_CHROMIUM_EXECUTABLE') or os.environ.get('CHROMIUM_EXECUTABLE') or None,headless=True,args=['--no-sandbox'])
  report['chromium']=browser.version
  try:
   for mode in (['file','mysql'] if opt.mode=='both' else [opt.mode]):
    for label,prefix,viewport in [('desktop','',{'width':1440,'height':1000}),('mobile','/nested/velvet',{'width':390,'height':844})]:
     name=mode+'-'+label
     with http.runtime(work,archive,mode,name,prefix,evidence) as case:report['cases'][name]=case_browser(browser,case,viewport,evidence)
     print('Administrator security browser '+name+': PASS',flush=True)
  finally:browser.close()
 if evidence:(evidence/'admin-security-browser-evidence.json').write_text(json.dumps(report,indent=2)+'\n')
 print(json.dumps(report,indent=2))
if __name__=='__main__':run()
