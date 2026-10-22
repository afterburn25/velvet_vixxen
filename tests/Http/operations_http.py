#!/usr/bin/env python3
"""Actual-package encrypted backup, owner controls and isolated restore acceptance.

Only owned temporary installations, loopback HTTP/SMTP and separate initially
empty disposable SQL schemas are permitted. Workers and the drain gate are
real child processes. A killed finish-commit worker must resume its exact bytes.
"""
from __future__ import annotations
import argparse
import concurrent.futures
from contextlib import contextmanager
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import re
import shutil
import socket
import subprocess
import sys
import tempfile
import threading
import time
from zipfile import ZipFile
from bs4 import BeautifulSoup
import requests

ROOT=Path(__file__).resolve().parents[2]
FIXTURE=Path(__file__).with_name('operations_fixture.php')
sys.dont_write_bytecode=True

def load(name,path):
    spec=importlib.util.spec_from_file_location(name,path);assert spec and spec.loader
    module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module);return module

depth=load('vvx_operations_package',ROOT/'tests/Http/composer_depth_http.py')
http=depth._base
checks=0
RUNTIME_FILES=('app/Core/BackupArchive.php','app/Core/BackupDatabase.php','app/Core/BackupJobs.php','app/Core/BackupRestore.php',
    'app/Core/BackupService.php','app/Core/OperationsFiles.php','app/Core/OperationsGate.php','app/Core/PrivateState.php',
    'app/Core/Config.php','app/Core/Auth.php','app/Support/operations_admin.php','app/Views/admin/operations.php',
    'app/Console/run_backups.php','app/Console/operations.php','app/Console/migrate.php','includes/bootstrap.php',
    'app/bootstrap.php','admin/index.php','app/Views/admin/navigation.php','public/assets/css/operations.css',
    'database/migrations/202610210017_private_state.php','app/Docs/BACKUP_OPERATIONS.md')

def check(value,message):
    global checks
    checks+=1
    if not value: raise AssertionError(message)

def fixture(case,action='read',**values):
    result=subprocess.run([case['php'],str(FIXTURE),str(case['site']),action],input=json.dumps(values)+'\n',text=True,capture_output=True,timeout=55,cwd='/tmp')
    check(result.returncode==0,'Owned operations fixture failed ('+action+'): '+result.stderr[:1700])
    return json.loads(result.stdout)

def cli(case,name,*arguments,expected=0):
    result=subprocess.run([case['php'],str(case['site']/('app/Console/'+name+'.php')),*arguments],text=True,capture_output=True,timeout=150,cwd=case['site'])
    check(result.returncode==expected,'Actual operations command failed: '+name+' '+result.stderr[:1800])
    if expected==1: return result
    value=json.loads(result.stdout)
    check(not result.stderr,'Successful worker emitted unexpected diagnostics.')
    return value

def prepare_package(work):
    archive,identity=depth.prepare_package(work)
    with ZipFile(archive) as zipped:
        for path in RUNTIME_FILES:check(zipped.read(path)==(ROOT/path).read_bytes(),'Packaged operations source differs: '+path)
    return archive,identity

@contextmanager
def runtime(work,archive,mode,label,prefix,evidence=None):
    php=shutil.which('php');check(bool(php),'PHP is required.')
    folder=work/label;document_root=folder/'www';site=document_root/prefix.lstrip('/');site.mkdir(parents=True)
    with ZipFile(archive) as zipped:zipped.extractall(site)
    (site/'.vvx-test-runtime').write_text('Owned packaged operations acceptance.\n')
    with socket.socket() as available:available.bind(('127.0.0.1',0));port=available.getsockname()[1]
    base=f'http://127.0.0.1:{port}{prefix}'
    smtp=load('vvx_operations_smtp_'+label.replace('-','_'),ROOT/'tests/Http/mail_settings_http.py')
    capture=smtp.Capture();threading.Thread(target=capture.serve_forever,daemon=True).start()
    log_path=folder/'server.log';log=log_path.open('w+');process=None;target_owned=False
    case={'site':site,'base':base,'php':php,'capture':capture,'mode':mode,'label':label,'folder':folder}
    def stop():
        nonlocal process
        if process is not None:
            process.terminate()
            try:process.wait(timeout=10)
            except subprocess.TimeoutExpired:process.kill();process.wait(timeout=5)
            process=None
    def restart():
        nonlocal process
        stop();environment=os.environ.copy();environment.pop('PHP_CLI_SERVER_WORKERS',None)
        process=subprocess.Popen([php,'-d','opcache.enable=0','-d','opcache.enable_cli=0','-S',f'127.0.0.1:{port}','-t',str(document_root)],cwd=folder,env=environment,stdout=log,stderr=log)
        probe=http.session();deadline=time.monotonic()+15
        try:
            while True:
                check(process.poll() is None,'The owned PHP server exited.')
                try:
                    if probe.get(base+'/admin/?page=login',timeout=1).status_code==200:break
                except requests.RequestException:pass
                if time.monotonic()>deadline:raise AssertionError('The real packaged PHP server never became ready.')
                time.sleep(.05)
        finally:probe.close()
    case['restart']=restart
    try:
        http.fixture(site,'setup-'+mode,php,base=base,smtp_port=capture.server_address[1]);fixture(case,'seed')
        key=folder/'backup.key';key.write_bytes(os.urandom(32));key.chmod(0o600);case['key']=key
        if mode=='mysql':
            check(bool(os.environ.get('VELVET_OPERATIONS_TARGET_DB_FILE')),'An empty independent restore database must be explicitly configured.')
            target=fixture(case,'target-prepare');target_owned=True;case['target_server']=target['target_server']
        else:case['target_server']=None
        restart();yield case
        log.flush();check(capture.connections==0 and not capture.payloads and not capture.errors,'Backup operations contacted SMTP.')
        check(not re.search(r'PHP (?:Fatal error|Parse error|Warning)|Uncaught ',log_path.read_text()),'Packaged Operations raised PHP diagnostics.')
    finally:
        stop();log.close();capture.release.set();capture.shutdown();capture.server_close()
        if target_owned:fixture(case,'target-cleanup')
        if mode=='mysql' and (site/'storage/temp/editorial-access-ownership.json').is_file():http.fixture(site,'cleanup-mysql',php)
        if evidence and log_path.is_file():shutil.copyfile(log_path,evidence/('operations-'+label+'-http.log'))

def form(page,selector):return http.fields(page.text,selector)

def post(owner,url,values,status=200):
    response=owner.post(url,data=values,timeout=40,allow_redirects=status==200)
    check(response.status_code==status,'Owner operation returned '+str(response.status_code)+': '+response.text[:250])
    if status==200:check(bool(response.history) and response.history[0].status_code in (302,303),'Mutation did not use real POST/Redirect/GET.')
    return response

def setup(case,owner):
    url=case['base']+'/admin/?page=operations';page=owner.get(url,timeout=30)
    check(page.status_code==200 and 'no-store' in page.headers.get('Cache-Control',''),'Owner Operations is not private.')
    guide=owner.get(url+'&guide=1',timeout=20)
    check(guide.status_code==200 and guide.content==(ROOT/'app/Docs/BACKUP_OPERATIONS.md').read_bytes(),'Owner guide differs from the shipped instructions.')
    post(owner,url,form(page,'[data-operations-setup]'))
    saved=fixture(case);check(saved['operations_enabled'] and not saved['gate']['ready'] and saved['gate']['recovery'],'Setup skipped its first drain boundary.')
    guest=http.session()
    try:check(guest.get(case['base']+'/',timeout=20).status_code==503,'Public requests ran before first activation.')
    finally:guest.close()
    # End the actual pre-setup server process before attesting quiescence.
    case['restart']()
    cli(case,'operations','--activate','--quiesced','--gate-revision='+saved['gate_revision'])
    status=cli(case,'operations','--status')
    cli(case,'operations','--configure-key','--key-file='+str(case['key']),'--config-revision='+status['config_revision'])
    return owner.get(url,timeout=30)

def queue(case,owner):
    url=case['base']+'/admin/?page=operations';page=owner.get(url,timeout=30)
    post(owner,url,form(page,'[data-operations-queue]'))
    saved=fixture(case);opened=[job for job in saved['journal']['jobs'].values() if job['status'] in ('queued','processing')]
    check(len(opened)==1 and opened[0]['status']=='queued','The real owner queue did not commit exactly one attempt.')
    return opened[0]

def child(case,action,**values):
    process=subprocess.Popen([case['php'],str(FIXTURE),str(case['site']),action],stdin=subprocess.PIPE,stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True)
    process.stdin.write(json.dumps(values)+'\n');process.stdin.flush();return process

def finish_child(process):
    process.stdin.write('finish\n');process.stdin.flush();process.stdin.close()
    output=process.stdout.read();error=process.stderr.read();code=process.wait(timeout=15)
    check(code==0 and not error,'Owned gate holder failed: '+error[:1700]);return output

def wait_file(path,timeout=25):
    deadline=time.monotonic()+timeout
    while not path.is_file():
        if time.monotonic()>deadline:raise AssertionError('The real worker never published '+path.name)
        time.sleep(.01)

def quarantined_http(case,target):
    """Boot the literal restored runtime: neither public nor owner PHP may run."""
    with socket.socket() as available:available.bind(('127.0.0.1',0));port=available.getsockname()[1]
    log_path=case['folder']/'restored-server.log'
    probe=http.session();process=None
    try:
        with log_path.open('w+') as log:
            process=subprocess.Popen([case['php'],'-d','opcache.enable=0','-S',f'127.0.0.1:{port}','-t',str(target)],
                cwd=target,stdout=log,stderr=log)
            deadline=time.monotonic()+10
            while True:
                check(process.poll() is None,'The restored PHP process unexpectedly exited.')
                try:response=probe.get(f'http://127.0.0.1:{port}/',timeout=1,allow_redirects=False);break
                except requests.RequestException:
                    if time.monotonic()>deadline:raise AssertionError('The restored PHP server never answered.')
                    time.sleep(.05)
            for suffix in ('/','/admin/?page=login','/route.php?vvx_route=shop'):
                response=probe.get(f'http://127.0.0.1:{port}'+suffix,timeout=10,allow_redirects=False)
                check(response.status_code==503 and 'quarantined' in response.text and not response.history,
                      'The literal restored runtime escaped quarantine through '+suffix)
            log.flush()
            check(not re.search(r'PHP (?:Fatal error|Parse error|Warning)|Uncaught ',log_path.read_text()),
                  'Restored quarantine raised PHP diagnostics.')
    finally:
        probe.close()
        if process is not None:
            process.terminate()
            try:process.wait(timeout=5)
            except subprocess.TimeoutExpired:process.kill();process.wait(timeout=5)

def exercise(case):
    initial=checks+http.checks;owner=http.owner_login(case['base']);guest=http.session();url=case['base']+'/admin/?page=operations';before=fixture(case)
    actions=[]
    try:
        check(guest.get(url,timeout=20).status_code==403,'Anonymous viewer reached Operations.')
        page=owner.get(url,timeout=30);after=fixture(case)
        check(after==before,'Read-only unconfigured Operations initialized or modified private state.')
        forged=http.changed(form(page,'[data-operations-setup]'),_csrf='forged')
        post(owner,url,forged,419);check(fixture(case)==before,'Invalid CSRF initialized Operations.')
        setup(case,owner);actions.append('setup-and-restart-activate')
        check(guest.get(case['base']+'/',timeout=30).status_code==200,'Reviewed gate did not resume public requests.')
        page=owner.get(url,timeout=30);policy=form(page,'[data-operations-policy]')
        post(owner,url,http.changed(policy,enabled='1',utc_time='25:99'),422)
        snapshot=fixture(case);check(snapshot['journal'] is None,'Invalid policy initialized journal.')
        post(owner,url,http.changed(policy,enabled=None,utc_time='03:00',keep_count='1',keep_days='1'))
        saved=fixture(case);check(saved['journal']['policy']['keep_count']==1 and saved['journal']['policy']['keep_days']==1,'Owner retention fields were not persisted.')
        post(owner,url,policy,409);check(fixture(case)==saved,'Stale policy overwrote newer settings.')
        job=queue(case,owner);holder=child(case,'shared');check(holder.stdout.readline().strip()=='ready','The genuine shared request never acquired its gate.')
        with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
            futures=[pool.submit(cli,case,'run_backups') for _ in range(2)]
            time.sleep(.15);check(fixture(case)['journal']['jobs'][job['id']]['status']=='queued','Worker claimed before the earlier request drained.')
            finish_child(holder);results=[future.result(timeout=150) for future in futures]
        check(sum(item['completed'] for item in results)==1 and sum(item['failed'] for item in results)==0,'Two real CLI workers duplicated or failed the backup.')
        saved=fixture(case);complete=saved['journal']['jobs'][job['id']];check(complete['status']=='complete','Completed archive was not recorded.')
        verified=cli(case,'run_backups','--verify='+job['id']);check(verified['verified'] and verified['archive_sha256']==complete['archive_sha256'],'Full verification lost saved identity.')
        page=owner.get(url,timeout=30);download=owner.post(url,data=form(page,'[data-backup-id="'+job['id']+'"] [data-operations-download]'),timeout=40)
        check(download.status_code==200 and download.headers['Content-Type'].startswith('application/octet-stream'),'Encrypted owner download failed.')
        check(hashlib.sha256(download.content).hexdigest()==complete['archive_sha256'] and b'Private independent customer bytes.' not in download.content,'Downloaded archive differs or exposes plaintext.')
        actions+=['two-real-workers','earlier-request-drain','verify','owner-ciphertext-download']
        archive=case['folder']/'downloaded.vvxbak';archive.write_bytes(download.content);archive.chmod(0o600)
        target=case['folder']/'isolated-restore';arguments=['--archive='+str(archive),'--key-file='+str(case['key']),'--target='+str(target)]
        if case['mode']=='mysql':arguments+=['--target-db-file='+os.environ['VELVET_OPERATIONS_TARGET_DB_FILE']]
        review=cli(case,'operations','--restore-review',*arguments);check(not target.exists() and review['quarantine_required'],'Restore review initialized its target.')
        cli(case,'operations','--restore-apply',*arguments,'--plan='+'0'*64,'--confirm-empty-target',expected=1)
        check(not target.exists(),'Stale restore plan created a target.')
        restored=cli(case,'operations','--restore-apply',*arguments,'--plan='+review['plan'],'--confirm-empty-target')
        check(restored['verified'] and restored['quarantined'] and restored['archive_sha256']==complete['archive_sha256'],'Complete isolated restore was not verified and quarantined.')
        for relative in ('config/app.php','storage/install.lock','uploads/operations-customer.bin','storage/private/operations-customer.bin','uploads/operations-drained.txt'):
            check(hashlib.sha256((target/relative).read_bytes()).hexdigest()==hashlib.sha256((case['site']/relative).read_bytes()).hexdigest(),'Independent restored bytes differ: '+relative)
        blocked=subprocess.run([case['php'],str(target/'app/Console/run_backups.php')],text=True,capture_output=True,timeout=20)
        check(blocked.returncode==1 and 'quarantined' in blocked.stderr,'A restored worker connected to the copied live configuration.')
        quarantined_http(case,target)
        cli(case,'operations','--restore-apply',*arguments,'--plan='+review['plan'],'--confirm-empty-target',expected=1)
        actions.append('real-quarantined-complete-restore')
        # Kill only the owned process after the encrypted final file exists but
        # while the real PrivateState commit is blocked by this guarded fixture.
        large=case['site']/'uploads/operations-crash-window.bin'
        with large.open('wb') as output:
            for _ in range(32):output.write(bytes(range(256))*4096)
        job2=queue(case,owner);lock=child(case,'finish-lock',id=job2['id'])
        worker=subprocess.Popen([case['php'],str(case['site']/'app/Console/run_backups.php')],stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True)
        try:
            check(lock.stdout.readline().strip()=='locked','The genuine finish transaction could not be blocked.')
            final=case['site']/('storage/private/operations/backups/'+job2['id']+'.vvxbak');wait_file(final)
            before_kill=hashlib.sha256(final.read_bytes()).hexdigest();worker.kill();worker.communicate(timeout=10);finish_child(lock)
            observed=fixture(case);check(observed['journal']['jobs'][job2['id']]['status']=='processing','The killed worker somehow committed completion.')
            recovered=cli(case,'run_backups');check(recovered['recovered']==1 and recovered['completed']==1,'Killed complete archive was not recovered once.')
            check(hashlib.sha256(final.read_bytes()).hexdigest()==before_kill and cli(case,'run_backups')['completed']==0,'Recovery recaptured or duplicated finished encrypted bytes.')
        finally:
            if worker.poll() is None:worker.kill();worker.communicate(timeout=10)
            if lock.poll() is None:lock.kill();lock.communicate(timeout=10)
        large.unlink();actions.append('killed-finish-commit-recovered-exact-bytes')
        fixture(case,'age-retention',id=job['id']);pruned=cli(case,'run_backups')
        check(pruned['pruned']==1 and fixture(case)['journal']['jobs'][job['id']]['status']=='deleted' and final.is_file(),'Retention did not preserve the newest verified copy.')
        page=owner.get(url,timeout=30);post(owner,url,http.changed(form(page,'[data-operations-policy]'),enabled='1',utc_time='00:00'))
        check(cli(case,'run_backups')['completed']==0,'A newly saved past daily time ran immediately.')
        fixture(case,'due-schedule');check(cli(case,'run_backups')['completed']==1 and cli(case,'run_backups')['completed']==0,'Actual-clock daily scheduler repeated or missed today.')
        actions+=['retention','daily-once']
        page=owner.get(url,timeout=30);recovery=form(page,'[data-operations-recovery]');post(owner,url,recovery)
        check(guest.get(case['base']+'/',timeout=30).status_code==503 and guest.get(case['base']+'/admin/?page=login',timeout=30).status_code==200,'Recovery did not hold public access while preserving sign-in.')
        blocked=subprocess.run([case['php'],str(case['site']/'app/Console/run_scenes.php')],capture_output=True,text=True,timeout=20)
        check(blocked.returncode==75,'An ordinary worker ran through recovery mode.')
        page=owner.get(url,timeout=30);check(page.status_code==200,'The real owner lost Operations in recovery mode.');post(owner,url,form(page,'[data-operations-recovery]'))
        check(guest.get(case['base']+'/',timeout=30).status_code==200,'Owner could not leave recovery mode.')
        actions.append('recovery-enter-exit')
        fixture(case,'expire-session',session=owner.cookies.get('velvet_vixxen_admin'))
        page=owner.get(url,timeout=30);saved=fixture(case);post(owner,url,form(page,'[data-operations-queue]'),428)
        check(fixture(case)==saved,'Expired recent-owner confirmation mutated backup state.')
        owner.get(case['base']+'/admin/?page=logout',timeout=20);owner.close();owner=http.owner_login(case['base'])
        queued=queue(case,owner);http.fixture(case['site'],'owner-status',case['php'],status='demoted')
        check(owner.get(url,timeout=20).status_code==403,'Demoted owner retained Operations access.')
        held=cli(case,'run_backups',expected=2);check(held['held']==1 and fixture(case)['journal']['jobs'][queued['id']]['status']=='held','Revoked owner attempt was not held.')
        http.fixture(case['site'],'owner-status',case['php'],status='owner');check(cli(case,'run_backups')['completed']==0,'Restored role revived held work.')
        actions+=['recent-owner-refusal','live-owner-revocation']
        after=fixture(case);check(before['protected']==after['protected'],'Operations changed unrelated installation/customer sentinels.')
        check(case['capture'].connections==0,'Operations sent a message.')
        return {'checks':checks+http.checks-initial,'mode':case['mode'],'source_server':after['source_server'],'target_server':case['target_server'],
            'actions':actions,'complete_restore':restored,'restored_files':review['files'],'restored_tables':review['database_tables'],
            'restored_rows':review['database_rows'],'smtp_messages':0,'real_concurrent_workers':2,'real_finish_worker_killed':True,
            'newest_archive_preserved':True,'no_customer_sentinel_changes':True}
    finally:owner.close();guest.close()

def evidence_directory():
    value=os.environ.get('VELVET_OPERATIONS_EVIDENCE_DIR') or os.environ.get('VELVET_SCREENSHOT_DIR')
    path=Path(value).resolve() if value else None
    if path:
        check(not path.is_relative_to(ROOT) or path.is_relative_to(ROOT/'artifacts'),'Evidence must be outside tracked/runtime source.');path.mkdir(parents=True,exist_ok=True)
    return path

def run():
    parser=argparse.ArgumentParser(description=__doc__);parser.add_argument('--mode',choices=('file','mysql','both'),default='both');args=parser.parse_args()
    evidence=evidence_directory();report={'test_sha256':hashlib.sha256(Path(__file__).read_bytes()).hexdigest(),'fixture_sha256':hashlib.sha256(FIXTURE.read_bytes()).hexdigest(),
        'php':subprocess.check_output(['php','-r','echo PHP_VERSION;'],text=True),'cases':{}}
    with tempfile.TemporaryDirectory(prefix='vvx-operations-http-') as temporary:
        work=Path(temporary);archive,identity=prepare_package(work);report.update(identity)
        for mode in (('file','mysql') if args.mode=='both' else (args.mode,)):
            for path,prefix in [('root',''),('nested','/nested/velvet')]:
                label=mode+'-'+path
                with runtime(work,archive,mode,label,prefix,evidence) as case:report['cases'][label]=exercise(case)
                print(label+': '+str(report['cases'][label]['checks'])+' operations checks passed.',flush=True)
    report['checks']=sum(item['checks'] for item in report['cases'].values())
    if evidence:(evidence/'operations-http.json').write_text(json.dumps(report,indent=2)+'\n')
    print(json.dumps(report,indent=2))

if __name__=='__main__':run()
