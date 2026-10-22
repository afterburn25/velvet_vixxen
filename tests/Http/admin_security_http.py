#!/usr/bin/env python3
"""Real package administrator MFA, recovery, revocation and current-owner acceptance."""
import argparse, base64, hashlib, hmac, importlib.util, json, os, struct, subprocess, tempfile, time
from pathlib import Path
from bs4 import BeautifulSoup
ROOT=Path(__file__).resolve().parents[2]
spec=importlib.util.spec_from_file_location('security_depth',ROOT/'tests/Http/composer_depth_http.py'); depth=importlib.util.module_from_spec(spec);spec.loader.exec_module(depth)
http=depth._base;check=http.check
FILES=['app/Core/Auth.php','app/Core/AdminSecurity.php','app/Core/AdminSecurityStoreTrait.php','app/Core/Totp.php','app/Views/admin/login.php','app/Views/admin/security.php','app/Views/admin/security-actions.php','public/assets/css/security-admin.css']

def prepare_package(work):
 from zipfile import ZipFile
 archive,identity=depth.prepare_package(work)
 with ZipFile(archive) as z:check(all(z.read(name)==(ROOT/name).read_bytes() for name in FILES),'Package differs from actual administrator security source.')
 return archive,identity

def fixture(case,action,*args):
 p=subprocess.run([case['php'],str(ROOT/'tests/Http/admin_security_fixture.php'),str(case['site']),action,*args],text=True,capture_output=True,timeout=20)
 check(p.returncode==0,'Security fixture failed: '+p.stderr[:1500]);return json.loads(p.stdout)

def form(response,action):
 soup=BeautifulSoup(response.text,'html.parser');field=soup.select_one('input[name=action][value="'+action+'"]')
 check(field is not None,'Actual security form absent: '+action)
 return dict(http.fields(str(field.find_parent('form'))))

def totp(secret,offset=0):
 raw=base64.b32decode(secret);counter=int(time.time()//30)+offset;digest=hmac.new(raw,struct.pack('>Q',counter),hashlib.sha1).digest();start=digest[-1]&15
 return str((struct.unpack('>I',digest[start:start+4])[0]&0x7fffffff)%1000000).zfill(6)

def password_stage(client,case):
 response=client.get(case['base']+'/admin/?page=login',timeout=20);values=dict(http.fields(response.text))
 return client.post(case['base']+'/admin/?page=login',data={**values,'email':http.OWNER,'password':http.PASSWORD},timeout=20)

def challenge(client,case,code):
 response=client.get(case['base']+'/admin/?page=login',timeout=20);values=dict(http.fields(response.text))
 return client.post(case['base']+'/admin/?page=login',data={**values,'code':code},timeout=20)

def http_case(case):
 start=http.checks;url=case['base']+'/admin/?page=security';owner=http.owner_login(case['base']);other=http.owner_login(case['base']);guest=http.session()
 try:
  response=owner.get(url,timeout=20);http.private(response);check(response.status_code==200 and 'Authenticator app' in response.text,'Security page unavailable.')
  check(guest.get(url,timeout=20).url.endswith('page=login'),'Guest read owner security.')
  values=form(response,'security_begin');before=fixture(case,'snapshot')
  denied=owner.post(url,data={**values,'_csrf':'bad','password':http.PASSWORD},timeout=20)
  check(denied.status_code==419 and fixture(case,'snapshot')==before,'CSRF failure changed security.')
  enrolled=owner.post(url,data={**values,'password':http.PASSWORD},timeout=20)
  secret=BeautifulSoup(enrolled.text,'html.parser').select_one('.security-secret code').text.strip();check(len(secret)==32,'Authenticator setup key missing.')
  enable=form(enrolled,'security_enable');response=owner.post(url,data={**enable,'code':totp(secret)},timeout=20)
  codes=[x.text.strip() for x in BeautifulSoup(response.text,'html.parser').select('.security-codes code')]
  check(response.status_code==200 and len(codes)==10,'Enrollment did not return ten one-use recovery codes.')
  state=fixture(case,'snapshot');encoded=json.dumps(state)
  check(secret not in encoded and all(x not in encoded for x in codes),'Raw factor or recovery secrets persisted.')
  check('page=login' in other.get(url,timeout=20).url,'Enrollment left previous session authorized.')
  check('security-codes' not in owner.get(url,timeout=20).text,'Recovery codes were redisplayed on later GET.')
  stage=password_stage(other,case);check('Verify your sign-in.' in stage.text,'Password bypassed enrolled factor.')
  check('page=login' in other.get(url,timeout=20).url,'Pending second factor reached protected page.')
  bad=challenge(other,case,'wrong');check('not accepted' in bad.text,'Wrong factor accepted.')
  response=challenge(other,case,totp(secret,1));check('page=login' not in response.url,'Valid independent RFC time code refused.')
  replay=http.session()
  try:
   password_stage(replay,case);result=challenge(replay,case,totp(secret,1));check('not accepted' in result.text,'Time code replay accepted.')
   result=challenge(replay,case,codes[0]);check('page=login' not in result.url,'Recovery code refused.')
   owner.post(url,data=form(owner.get(url,timeout=20),'security_revoke'),timeout=20)
   check('page=login' in replay.get(url,timeout=20).url and 'page=login' in other.get(url,timeout=20).url,'Other sessions remained active after revocation.')
   password_stage(replay,case);check('not accepted' in challenge(replay,case,codes[0]).text,'Recovery code replay accepted.')
  finally:replay.close()
  cookie=owner.cookies.get('velvet_vixxen_admin');fixture(case,'age-session',cookie)
  response=owner.post(url,data=form(owner.get(url,timeout=20),'security_revoke'),timeout=20)
  check(response.status_code==428 and 'Confirm your password' in response.text,'Expired privileged auth accepted.')
  values=form(owner.get(url,timeout=20),'security_confirm');response=owner.post(url,data={**values,'password':http.PASSWORD,'code':codes[1]},timeout=20)
  check(response.status_code==200 and response.history,'Password/factor reauthentication failed.')
  http.fixture(case['site'],'owner-status',case['php'],status='demoted')
  check(owner.get(url,timeout=20).status_code==403,'Demoted saved owner read security.')
  http.fixture(case['site'],'owner-status',case['php'],status='owner')
  response=owner.get(url,timeout=20);values=form(response,'security_disable')
  response=owner.post(url,data={**values,'password':http.PASSWORD,'code':codes[2],'confirm_disable':'1'},timeout=20)
  check(response.status_code==200 and response.history and 'Set up authenticator' in response.text,'Protected authenticator removal failed.')
  http.fixture(case['site'],'owner-status',case['php'],status='inactive');check('page=login' in owner.get(url,timeout=20).url,'Disabled owner session survived.')
  http.fixture(case['site'],'owner-status',case['php'],status='owner')
  limited=http.session()
  try:
   for _ in range(9):
    login=limited.get(case['base']+'/admin/?page=login',timeout=20);data=dict(http.fields(login.text));limited.post(login.url,data={**data,'email':'unknown-rate@example.test','password':'WrongPassword12!'},timeout=20)
   state=fixture(case,'snapshot');check(any(x.get('count')==9 for x in state['attempts'].values()),'Persistent login limiter was not enforced.')
  finally:limited.close()
  check(case['capture'].connections==0,'Security actions transmitted email instead of queuing alerts.')
  actual=http.fixture(case['site'],'read',case['php'])
  check((case['mode']=='mysql')==bool(actual['mysql_version']),'Actual administrator-security database version is missing.')
  return {'checks':http.checks-start,'mysql_version':actual['mysql_version'],'factor_enrollment':True,'rfc_totp':True,'replay_refused':True,'recovery_single_use':True,'session_revocation':True,'recent_owner_required':True,'smtp_connections':0}
 finally:owner.close();other.close();guest.close()

def run():
 parser=argparse.ArgumentParser();parser.add_argument('--mode',choices=['file','mysql','both'],default='both');opt=parser.parse_args();evidence=http.evidence_directory();report={'cases':{}}
 with tempfile.TemporaryDirectory(prefix='vvx-admin-security-http-') as tmp:
  work=Path(tmp);archive,identity=prepare_package(work);report.update(identity)
  for mode in (['file','mysql'] if opt.mode=='both' else [opt.mode]):
   for label,prefix in [('root',''),('nested','/nested/velvet')]:
    name=mode+'-'+label
    with http.runtime(work,archive,mode,name,prefix,evidence) as case:report['cases'][name]=http_case(case)
    print('Administrator security HTTP '+name+': PASS',flush=True)
 if evidence:(evidence/'admin-security-http-evidence.json').write_text(json.dumps(report,indent=2)+'\n')
 print(json.dumps(report,indent=2))
if __name__=='__main__':run()
