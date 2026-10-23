#!/usr/bin/env python3
"""Exact-package checkout terms, delayed owner recovery and billing, root/nested on file/SQL."""
from __future__ import annotations
import argparse
import hashlib
import json
from pathlib import Path
import subprocess
import tempfile
from urllib.parse import urljoin
from bs4 import BeautifulSoup
import payment_recovery_http as recovery

ROOT = Path(__file__).resolve().parents[2]
http, check = recovery.http, recovery.check
FIXTURE = Path(__file__).with_name('purchased_terms_fixture.php')
RUNTIME = ('app/Core/MembershipPeriod.php','database/migrations/202610230020_membership_day_periods.php','app/Core/PurchasedTerms.php','app/Core/PurchasedTermsStoreTrait.php',
           'app/Core/CommerceService.php','app/Core/CommerceStoreTrait.php','app/Core/MemberStoreTrait.php',
           'app/Core/MemberPrivacyProjection.php','app/Views/public/partials/checkout-terms.php',
           'app/Views/public/partials/purchased-membership.php','public/routes/checkout/index.php','public/routes/account/billing.php')

def fixture(case, action, **values):
    result = subprocess.run([case['php'],str(FIXTURE),str(case['site']),action],input=json.dumps(values),capture_output=True,text=True,timeout=60)
    check(result.returncode == 0,'Owned packaged terms fixture failed: '+result.stderr[:1800])
    return json.loads(result.stdout)

def prepare_package(work):
    archive, identity = recovery.prepare_package(work)
    from zipfile import ZipFile
    with ZipFile(archive) as zipped:
        check(all(zipped.read(name)==(ROOT/name).read_bytes() for name in RUNTIME),'Terms producer is not testing the exact candidate runtime.')
    identity['purchased_terms_runtime_sha256']={name:hashlib.sha256((ROOT/name).read_bytes()).hexdigest() for name in RUNTIME}
    return archive, identity

def scenario(case, browser=None, viewport=None, evidence=None):
    start = http.checks
    if case['mode']=='mysql':
        owner=http.owner_login(case['base'])
        try:http.activate_mysql(owner,case['base'],case['site'],case['php'])
        finally:owner.close()
    records=fixture(case,'seed'); owner=http.owner_login(case['base']); buyer=http.session()
    context=page=None; images=[]
    try:
        login=http.route(case['base'],'account/login.php'); response=buyer.get(login,timeout=20)
        response=buyer.post(login,data=http.changed(http.fields(response.text,'.member-form'),email='purchased-terms-buyer@example.test',password='Purchased-Terms-123!',**{'return':'/membership/'}),timeout=20)
        check(response.status_code==200 and 'Sign in to your account' not in response.text,'Actual purchased-terms member sign-in failed.')
        if browser:
            context=browser.new_context(viewport=viewport,java_script_enabled=False)
            context.add_cookies([{'name':cookie.name,'value':cookie.value,'url':case['base']} for cookie in buyer.cookies])
            page=context.new_page()
        def shot(label):
            if not page:return
            geometry=page.evaluate('() => ({width:innerWidth,document:document.documentElement.scrollWidth})')
            check(geometry['document']<=geometry['width']+1,'Purchased-terms UI overflows: '+label)
            data=page.screenshot(full_page=True)
            from PIL import Image
            import io
            with Image.open(io.BytesIO(data)) as decoded:decoded.load();dimensions=list(decoded.size)
            name='purchased-terms-'+case['label']+'-'+label+'.png'
            if evidence:(evidence/name).write_bytes(data)
            images.append({'file':name,'sha256':hashlib.sha256(data).hexdigest(),'dimensions':dimensions,'decoded':True})
        def start_checkout(kind, identifier):
            source=buyer.get(http.route(case['base'],'membership/'),timeout=20)
            response=buyer.post(http.route(case['base'],'checkout/'),data={'_csrf':dict(http.fields(source.text))['_csrf'],'action':'start','type':kind,'id':identifier,'mode':'subscribe' if kind=='plan' else 'purchase'},timeout=20)
            check(response.status_code==200 and 'data-checkout-purchased-terms' in response.text,'Actual checkout did not disclose the purchased terms.')
            return response
        def pay(response, result='processing'):
            if page:
                page.goto(response.url);page.locator('select[name="sandbox_result"]').select_option(result)
                page.locator('input[name="confirmation"]').check();shot('checkout-'+BeautifulSoup(response.text,'html.parser').select_one('[data-checkout-purchased-terms]')['data-checkout-purchased-terms'])
                with page.expect_navigation():page.locator('form.checkout-card button[type="submit"], form.checkout-card button').last.click()
                return page.content()
            paid=buyer.post(response.url,data=http.changed(http.fields(response.text,'form.checkout-card'),provider='sandbox',sandbox_result=result,confirmation='1'),timeout=25)
            check(paid.status_code==200,'Actual checkout POST failed.');return paid.text
        def recover(order):
            confirmed=fixture(case,'confirm-only',order=order['id']);check(confirmed['state']=='confirmed','Fixture evidence did not preserve a confirmed original operation.')
            response=owner.get(recovery.workspace(case,record=confirmed['record']),timeout=20)
            form=BeautifulSoup(response.text,'html.parser').select_one('.payment-recovery-action form')
            check(form is not None and 'Check payment outcome' in form.get_text(),'Real owner recovery action is unavailable.')
            result=owner.post(urljoin(response.url,form.get('action','')),data=http.fields(str(form)),timeout=25)
            check(result.status_code==200,'Owner recovery form failed.')
        # Real owner form and customer pages disclose a day count, not a monthly alias.
        plan_url=case['base']+'/admin/?page=plans&edit='+records['dayPlan']['id']
        editor=owner.get(plan_url,timeout=20)
        form=BeautifulSoup(editor.text,'html.parser').select_one('form.media-editor-card')
        check(form is not None and form.select_one('select[name="billing_interval"] option[selected]')['value']=='day','Owner editor did not retain the fixed-day interval.')
        rejected=owner.post(plan_url,data=http.changed(http.fields(str(form)),billing_interval='day',interval_count='30.5'),timeout=25)
        check('whole interval count from 1 to 120' in rejected.text,'Actual owner POST silently truncated a fractional day count.')
        editor=owner.get(plan_url,timeout=20);form=BeautifulSoup(editor.text,'html.parser').select_one('form.media-editor-card')
        check(form is not None and form.select_one('input[name="interval_count"]')['value']=='30','Rejected day count changed the saved offer.')
        saved=owner.post(plan_url,data=http.changed(http.fields(str(form)),billing_interval='day',interval_count='30'),timeout=25)
        check(saved.status_code==200 and 'Explicit 30-day fixture offer' in saved.text,'Actual owner form could not save a 30-day offer.')
        for public_path in ('membership/','shop/'):
            public=buyer.get(http.route(case['base'],public_path),timeout=20)
            cards=BeautifulSoup(public.text,'html.parser').select('.plan-card, .membership-store-card')
            day_cards=[card for card in cards if 'Explicit 30-day fixture offer' in card.get_text()]
            check(len(day_cards)==1 and 'every 30 days' in day_cards[0].get_text(),'Public offer hid or substituted its fixed-day count: '+public_path)
        day_review=start_checkout('plan',records['dayPlan']['id'])
        check('every 30 days' in day_review.text and 'USD 11.30' in day_review.text,'Real checkout did not disclose the exact fixed-day offer.')
        if page:
            page.goto(day_review.url)
            check('every 30 days' in page.locator('[data-checkout-purchased-terms="plan"]').inner_text(),'No-script checkout replaced fixed days with a calendar month.')
            shot('checkout-fixed-day')
        response=start_checkout('plan',records['plan']['id']);check('USD 19.25' in response.text,'Displayed original membership price is missing.')
        pay(response); state=fixture(case,'read');check(len(state['orders'])==1 and state['orders'][0]['status']=='processing','Pending real membership checkout was not saved.')
        plan_order=state['orders'][0];snapshot=plan_order['items'][0]['metadata']['purchaseTerms']
        fixture(case,'edit-plan');recover(plan_order)
        state=fixture(case,'read');check(state['orders'][0]['status']=='paid' and state['orders'][0]['items'][0]['metadata']['purchaseTerms']==snapshot,'Owner recovery changed accepted terms.')
        billing=buyer.get(http.route(case['base'],'account/billing.php'),timeout=20)
        accepted=BeautifulSoup(billing.text,'html.parser').select_one('[data-purchased-terms-state="accepted"]')
        check(accepted is not None and 'USD 19.25' in accepted.get_text() and '2 months' in accepted.get_text() and '20.00%' in accepted.get_text(),'Billing substituted the current membership catalogue.')
        check('Legacy membership: original purchase terms were not recorded.' in billing.text,'Legacy membership explanation is missing.')
        if page:page.goto(http.route(case['base'],'account/billing.php'));shot('billing-accepted-and-legacy')
        # Real form replay cannot duplicate the applied original operation.
        before=fixture(case,'read');owner.get(recovery.workspace(case),timeout=20);after=fixture(case,'read')
        check(before==after,'Reading recovery mutated financial or access state.')
        response=start_checkout('bundle',records['bundle']['id']);pay(response)
        state=fixture(case,'read');bundles=[order for order in state['orders'] if order['items'][0]['itemType']=='bundle'];check(len(bundles)==1,'Real bundle checkout was not persisted.')
        bundle_order=bundles[0];fixture(case,'edit-bundle');recover(bundle_order)
        state=fixture(case,'read');grants=[row for row in state['entitlements'] if row['sourceId']==bundle_order['id']]
        check(len(grants)==1 and grants[0]['contentId']==records['a']['id'],'Delayed bundle recovery substituted the edited item set.')
        if page:page.goto(http.route(case['base'],'account/library.php'));shot('library-original-bundle')
        # Stale browser or no-script forms are rejected without an order.
        stale=start_checkout('plan',records['plan']['id']);fixture(case,'edit-plan',price=100)
        old_count=len(state['orders'])
        response=buyer.post(stale.url,data=http.changed(http.fields(stale.text,'form.checkout-card'),provider='sandbox',sandbox_result='success',confirmation='1'),timeout=25)
        check('purchase terms changed' in response.text and len(fixture(case,'read')['orders'])==old_count,'Stale displayed checkout silently accepted new terms.')
        return {'passed':True,'checks':http.checks-start,'images':images,'no_javascript':bool(page),'database':state['database'],'exact_original_membership_and_bundle':True,'stale_review_rejected_before_order':True,'fixed_day_offer_owner_and_public_disclosure':True}
    finally:
        if context:context.close()
        buyer.close();owner.close()

def main():
    parser=argparse.ArgumentParser(description=__doc__);parser.add_argument('--mode',choices=('file','mysql','both'),default='both');args=parser.parse_args()
    evidence=http.evidence_directory();report={'passed':False,'report_kind':'purchased_terms_http','cases':{}}
    try:
        with tempfile.TemporaryDirectory(prefix='vvx-payment-recovery-terms-http-') as temporary:
            work=Path(temporary);archive,identity=prepare_package(work);report.update(identity)
            for mode in ('file','mysql') if args.mode=='both' else (args.mode,):
                for path,prefix in (('root',''),('nested','/nested/velvet')):
                    label=mode+'-'+path
                    with recovery.runtime(work,archive,mode,label,prefix,evidence) as case:report['cases'][label]=scenario(case)
        report['passed']=True;print(json.dumps(report,indent=2))
    finally:
        if evidence:(evidence/'purchased-terms-http-evidence.json').write_text(json.dumps(report,indent=2)+'\n')

if __name__=='__main__':main()
