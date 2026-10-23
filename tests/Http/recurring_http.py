#!/usr/bin/env python3
"""Exact packaged recurring member forms and owner query-only recovery, file/SQL/root/nested."""
from __future__ import annotations
import argparse
import hashlib
import io
import json
from pathlib import Path
import subprocess
import tempfile
from urllib.parse import urljoin
from zipfile import ZipFile
from bs4 import BeautifulSoup
import payment_recovery_http as recovery

ROOT=Path(__file__).resolve().parents[2]
http,check=recovery.http,recovery.check
FIXTURE=Path(__file__).with_name('recurring_fixture.php')
RUNTIME=('app/Core/RecurringCommerceTrait.php','app/Core/RecurringContract.php','app/Core/RecurringRecoveryService.php',
         'app/Core/RecurringStoreTrait.php','app/Payments/RecurringProviderInterface.php','app/Payments/SandboxPaymentProvider.php',
         'app/Views/admin/recurring-recovery.php','app/Views/public/partials/recurring-membership.php',
         'public/routes/account/billing.php','app/Support/payment_recovery_admin.php','app/Views/admin/payment-recovery-actions.php',
         'database/migrations/202610220019_recurring_lifecycle.php')
PRODUCERS=('tests/Http/recurring_fixture.php','tests/Http/recurring_http.py','tests/Browser/recurring_playwright.py')

def fixture(case,action):
    result=subprocess.run([case['php'],str(FIXTURE),str(case['site']),action],input='{}',capture_output=True,text=True,timeout=60)
    check(result.returncode==0,'Owned recurring UI fixture failed: '+result.stderr[:2000])
    return json.loads(result.stdout)

def prepare_package(work):
    archive,identity=recovery.prepare_package(work)
    with ZipFile(archive) as zipped:
        check(all(zipped.read(name)==(ROOT/name).read_bytes() for name in RUNTIME),'Recurring UI producer is not testing the exact candidate runtime.')
    identity['recurring_runtime_sha256']={name:hashlib.sha256((ROOT/name).read_bytes()).hexdigest() for name in RUNTIME}
    identity['recurring_producer_sha256']={name:hashlib.sha256((ROOT/name).read_bytes()).hexdigest() for name in PRODUCERS}
    return archive,identity

def scenario(case,browser=None,viewport=None,evidence=None):
    start=http.checks
    if case['mode']=='mysql':
        owner=http.owner_login(case['base'])
        try:http.activate_mysql(owner,case['base'],case['site'],case['php'])
        finally:owner.close()
    records=fixture(case,'seed');owner=http.owner_login(case['base']);buyer=http.session();guest=http.session()
    context=owner_context=page=None;images=[]
    try:
        login=http.route(case['base'],'account/login.php');response=buyer.get(login,timeout=20)
        response=buyer.post(login,data=http.changed(http.fields(response.text,'.member-form'),email='recurring-ui@example.test',password='Recurring-UI-123!',**{'return':'/membership/'}),timeout=20)
        check(response.status_code==200 and 'Sign in to your account' not in response.text,'Real recurring member login failed.')
        if browser:
            context=browser.new_context(viewport=viewport,java_script_enabled=False)
            context.add_cookies([{'name':cookie.name,'value':cookie.value,'url':case['base']} for cookie in buyer.cookies]);page=context.new_page()
        def shot(label):
            if not page:return
            geometry=page.evaluate('() => ({width:innerWidth,document:document.documentElement.scrollWidth})')
            check(geometry['document']<=geometry['width']+1,'Recurring UI overflows: '+label)
            data=page.screenshot(full_page=True)
            from PIL import Image
            with Image.open(io.BytesIO(data)) as decoded:decoded.load();dimensions=list(decoded.size)
            name='recurring-'+case['label']+'-'+label+'.png'
            if evidence:(evidence/name).write_bytes(data)
            images.append({'file':name,'sha256':hashlib.sha256(data).hexdigest(),'dimensions':dimensions,'decoded':True})
        billing_url=http.route(case['base'],'account/billing.php')
        source=buyer.get(http.route(case['base'],'membership/'),timeout=20)
        checkout=buyer.post(http.route(case['base'],'checkout/'),data={'_csrf':dict(http.fields(source.text))['_csrf'],'action':'start','type':'plan','id':records['plan']['id'],'mode':'subscribe'},timeout=20)
        check(checkout.status_code==200 and 'USD 19.25' in checkout.text,'Real recurring checkout did not disclose the original price.')
        if page:
            page.goto(checkout.url);page.locator('select[name="sandbox_result"]').select_option('success');page.locator('input[name="confirmation"]').check()
            with page.expect_navigation():page.locator('form.checkout-card button').last.click()
        else:
            paid=buyer.post(checkout.url,data=http.changed(http.fields(checkout.text,'form.checkout-card'),provider='sandbox',sandbox_result='success',confirmation='1'),timeout=25)
            check(paid.status_code==200,'Real recurring checkout POST failed.')
        state=fixture(case,'read');check(len(state['agreements'])==1,'Real checkout did not persist exactly one agreement.')
        agreement=state['agreements'][0];period=(agreement['periodStart'],agreement['periodEnd'])
        check(agreement['state']=='active' and not agreement['cancelAtPeriodEnd'],'Real checkout activation was not confirmed.')
        def billing():
            response=buyer.get(billing_url,timeout=20)
            check(response.status_code==200 and 'data-recurring-state' in response.text,'Real member recurring billing did not open.')
            return response
        def member_form(response,action):
            soup=BeautifulSoup(response.text,'html.parser');form=soup.select_one('form:has(input[name=action][value="'+action+'"])')
            check(form is not None,'The current recurring member action is missing: '+action)
            values=dict(http.fields(str(form)))
            check(values.get('agreement_revision','').isdigit() and values.get('request_id','').startswith('member:'),'Member form is not bound to a revision and stable request.')
            return values
        def submit_member(action):
            response=billing();values=member_form(response,action)
            if page:
                page.goto(billing_url);values=member_form(type('BrowserResponse',(),{'text':page.content()})(),action);button=page.locator('form:has(input[name=action][value="'+action+'"]) button');button.focus()
                with page.expect_navigation():page.keyboard.press('Enter')
            else:
                result=buyer.post(billing_url,data=values,timeout=25);check(result.status_code==200,'Recurring member action failed.')
            return values
        response=billing();check('Automatic renewal confirmed.' in response.text,'Confirmed renewal state is missing.')
        if page:page.goto(billing_url);shot('confirmed-renewal')
        cancel=submit_member('cancel_subscription');after=fixture(case,'read');check(after['agreements'][0]['cancelAtPeriodEnd'],'Actual cancel form did not confirm Sandbox cancellation.')
        # The submitted form is replayed, and an altered fresh request with its
        # stale revision is refused before another provider operation exists.
        before=after['financial_sha256'];buyer.post(billing_url,data=cancel,timeout=25)
        check(fixture(case,'read')['financial_sha256']==before,'Identical cancellation form replay changed financial facts.')
        stale=buyer.post(billing_url,data={**cancel,'request_id':'member:stale-extra'},timeout=25)
        check('membership changed' in stale.text.lower() and fixture(case,'read')['financial_sha256']==before,'Stale cancellation form created another request.')
        resume=submit_member('resume_subscription');after=fixture(case,'read');check(not after['agreements'][0]['cancelAtPeriodEnd'],'Actual resume form did not confirm Sandbox resumption.')
        check((after['agreements'][0]['periodStart'],after['agreements'][0]['periodEnd'])==period,'Cancel/resume altered paid access bounds.')
        current=member_form(billing(),'cancel_subscription');before=fixture(case,'read')['financial_sha256']
        rejected=buyer.post(billing_url,data={**current,'_csrf':'invalid'},timeout=20)
        check('Your session expired.' in rejected.text and fixture(case,'read')['financial_sha256']==before,'Invalid member CSRF changed recurring state.')
        fixture(case,'pending');state=fixture(case,'read');agreement=state['agreements'][0];pending=state['records']['pending']
        response=billing();check('Cancellation requested; awaiting confirmation.' in response.text and not BeautifulSoup(response.text,'html.parser').select('form:has(input[name=agreement_revision])'),'An uncertain cancellation was presented as confirmed or allowed another action.')
        if page:page.goto(billing_url);shot('pending-cancellation')
        url=recovery.workspace(case,view='recurring',agreement=agreement['id'])
        target=owner.get(url,timeout=20);check(target.status_code==200 and 'data-recurring-recovery' in target.text,'Real owner recurring recovery did not open.');recovery.private(target)
        for forbidden in (agreement['scope']['installation'],agreement['scope']['account'],agreement['remoteId']):
            check(forbidden not in target.text,'Owner projection exposed private recurring provider identity.')
        values=dict(http.fields(target.text,'[data-recurring-record="'+pending+'"] form'))
        for session in (guest,buyer):
            denied=session.get(url,timeout=20);check(denied.status_code==403 and pending not in denied.text,'Unauthorized user read owner recurring recovery.')
            check(session.post(url,data=values,timeout=20).status_code==403,'Unauthorized user invoked owner recurring recovery.')
        check(owner.post(url,data={**values,'_csrf':'invalid'},timeout=20).status_code==419,'Owner recurring recovery accepted invalid CSRF.')
        before=fixture(case,'read')['financial_sha256'];owner.get(url,timeout=20);buyer.get(billing_url,timeout=20)
        check(fixture(case,'read')['financial_sha256']==before,'Reading recurring billing or recovery changed financial state.')
        if page:
            # Admin sessions bind their real client user agent. Authenticate in
            # Chromium itself instead of reusing a requests-session cookie.
            owner_context=browser.new_context(viewport=viewport,java_script_enabled=False);page=owner_context.new_page()
            page.goto(case['base']+'/admin/?page=login');page.get_by_label('Email address',exact=True).fill(http.OWNER);page.get_by_label('Password',exact=True).fill(http.PASSWORD)
            with page.expect_navigation():page.get_by_role('button',name='Sign in',exact=True).click()
            opened=page.goto(url)
            check(opened.status==200 and page.locator('[data-recurring-recovery]').count()==1,'Real browser owner sign-in did not open recurring recovery.')
            recovery.private(opened);shot('owner-unknown')
            button=page.locator('[data-recurring-record="'+pending+'"] button');button.focus()
            with page.expect_navigation():page.keyboard.press('Enter')
        else:check(owner.post(url,data=values,timeout=25).status_code==200,'Original recurring outcome query failed.')
        state=fixture(case,'read');row=next(row for row in state['journal'] if row['id']==pending)
        check(row['state']=='unknown' and not state['agreements'][0]['cancelAtPeriodEnd'] and len(state['journal'])==4,'Unknown lookup invented confirmation or another recurring operation.')
        fixture(case,'confirm')
        target=owner.get(url,timeout=20);values=dict(http.fields(target.text,'[data-recurring-record="'+pending+'"] form'))
        if page:
            page.goto(url);button=page.locator('[data-recurring-record="'+pending+'"] button');button.focus()
            with page.expect_navigation():page.keyboard.press('Enter')
            shot('owner-confirmed')
        else:check(owner.post(url,data=values,timeout=25).status_code==200,'Confirmed original recurring evidence was not applied.')
        state=fixture(case,'read');agreement=state['agreements'][0]
        check(agreement['cancelAtPeriodEnd'] and (agreement['periodStart'],agreement['periodEnd'])==period and next(row for row in state['journal'] if row['id']==pending)['state']=='applied','Original cancellation evidence changed access bounds or did not apply once.')
        before=state['financial_sha256'];check(owner.post(url,data=values,timeout=25).status_code==409 and fixture(case,'read')['financial_sha256']==before,'Stale owner recovery replay changed recurring state.')
        response=billing();check('Renewal canceled. Paid through' in response.text and 'Resume Renewal' in response.text,'Confirmed cancellation lacks the eligible resume action.')
        return {'passed':True,'checks':http.checks-start,'images':images,'no_javascript':bool(page),'database':state['database'],
                'real_checkout_cancel_resume':True,'synthetic_original_unknown_and_confirmed_fixture':True,
                'query_only_unknown_no_false_confirmation':True,'confirmed_original_apply_once':True,'paid_bounds_unchanged':True,'readonly_financial_hash_unchanged':True}
    finally:
        if owner_context:owner_context.close()
        if context:context.close()
        buyer.close();owner.close();guest.close()

def main():
    parser=argparse.ArgumentParser(description=__doc__);parser.add_argument('--mode',choices=('file','mysql','both'),default='both');args=parser.parse_args()
    evidence=http.evidence_directory();report={'passed':False,'report_kind':'recurring_http','cases':{}}
    try:
        with tempfile.TemporaryDirectory(prefix='vvx-payment-recovery-recurring-http-') as temporary:
            work=Path(temporary);archive,identity=prepare_package(work);report.update(identity)
            for mode in ('file','mysql') if args.mode=='both' else (args.mode,):
                for path,prefix in (('root',''),('nested','/nested/velvet')):
                    label=mode+'-'+path
                    with recovery.runtime(work,archive,mode,label,prefix,evidence) as case:report['cases'][label]=scenario(case)
        report['passed']=True;print(json.dumps(report,indent=2))
    finally:
        if evidence:(evidence/'recurring-http-evidence.json').write_text(json.dumps(report,indent=2)+'\n')

if __name__=='__main__':main()
