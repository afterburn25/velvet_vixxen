#!/usr/bin/env python3
"""Actual Apache package protection/report workflows; raw php -S is a negative control."""
import argparse, hashlib, importlib.util, json, os, re, shutil, socket, subprocess, tempfile, threading, time
from contextlib import contextmanager
from pathlib import Path
from zipfile import ZipFile
from bs4 import BeautifulSoup

ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location('protection_access', ROOT / 'tests/Http/editorial_access_http.py')
http = importlib.util.module_from_spec(spec); spec.loader.exec_module(http)
check = http.check
SOURCES = ['tests/Feature/protection_records.php', 'tests/Http/protection_records_fixture.php', 'tests/Http/protection_records_http.py', 'tests/Browser/protection_records_playwright.py']
FILES = ['app/Core/ProtectionRecords.php', 'app/Core/ProtectionResources.php', 'app/Core/ProtectionStoreTrait.php', 'app/Core/ProtectionDelivery.php', 'app/Core/ProtectionUploads.php', 'app/Core/PrivateMediaResponse.php',
         'app/Core/MediaUploadService.php', 'app/Core/UploadService.php', 'app/Core/MediaStoreTrait.php', 'app/Core/EntitlementResolver.php', 'app/Core/MarketingStore.php', 'app/Core/MarketingMetadata.php',
         'app/Support/protection_admin.php', 'app/Views/admin/protection.php', 'app/Views/admin/protection-actions.php', 'public/routes/protected-asset.php', 'public/routes/reports/index.php', '.htaccess']

def prepare_package(work):
    archive, identity = http.prepare_package(work)
    with ZipFile(archive) as z:
        check(all(z.read(name) == (ROOT / name).read_bytes() for name in FILES), 'The package differs from protection runtime source.')
    identity['source_sha256'] = {name: hashlib.sha256((ROOT / name).read_bytes()).hexdigest() for name in SOURCES if (ROOT / name).is_file()}
    return archive, identity

def chown_tree(path):
    if os.geteuid() == 0:
        for base, dirs, files in os.walk(path):
            os.chown(base, 65534, 65534)
            for name in files: os.chown(Path(base) / name, 65534, 65534)

def fixture(case, action, **values):
    result = subprocess.run([case['php'], str(ROOT / 'tests/Http/protection_records_fixture.php'), str(case['site']), action], input=json.dumps(values), text=True, capture_output=True, timeout=40)
    check(result.returncode == 0, 'Protection fixture failed: ' + result.stderr[:2000])
    chown_tree(case['site'])
    return json.loads(result.stdout)

def owner_status(case, status):
    http.fixture(case['site'], 'owner-status', case['php'], status=status); chown_tree(case['site'])

def form(response, action, selector=None):
    soup = BeautifulSoup(response.text, 'html.parser')
    element = soup.select_one(selector) if selector else soup.select_one('input[name=action][value="' + action + '"]')
    check(element is not None, 'Actual protection form missing: ' + action)
    return dict(http.fields(str(element if element.name == 'form' else element.find_parent('form'))))

def workspace(case, tab='performers', record=''):
    return case['base'] + '/admin/?page=protection&tab=' + tab + ('&record=' + record if record else '')

def report_url(case, case_id=''):
    return case['base'] + '/route.php?vvx_route=reports/index.php' + ('&case=' + case_id if case_id else '')

@contextmanager
def runtime(work, archive, mode, label, prefix, evidence):
    apache = os.environ.get('VELVET_APACHE_BINARY') or shutil.which('apache2')
    modules = Path(os.environ.get('VELVET_APACHE_MODULES', '/usr/lib/apache2/modules'))
    php = shutil.which('php'); check(bool(apache and php), 'Real Apache and PHP are required; no synthetic denial router is accepted.')
    version = subprocess.check_output([php, '-r', 'echo PHP_MAJOR_VERSION,".",PHP_MINOR_VERSION;'], text=True)
    php_module = modules / ('libphp' + version + '.so'); check(php_module.is_file(), 'Matching Apache PHP module is required.')
    document_root = work / label / 'www'; site = document_root / prefix.lstrip('/'); site.mkdir(parents=True)
    with ZipFile(archive) as z: z.extractall(site)
    (site / '.vvx-test-runtime').write_text('Disposable protection package acceptance.\n')
    with socket.socket() as sock: sock.bind(('127.0.0.1', 0)); port = sock.getsockname()[1]
    base = f'http://127.0.0.1:{port}{prefix}'
    smtp = http.load('protection_smtp_' + label.replace('-', '_'), ROOT / 'tests/Http/mail_settings_http.py')
    capture = smtp.Capture(); threading.Thread(target=capture.serve_forever, daemon=True).start()
    server = work / label / 'apache'; server.mkdir(); process = None
    case = {'site': site, 'base': base, 'php': php, 'capture': capture, 'mode': mode, 'label': label}
    try:
        http.fixture(site, 'setup-' + mode, php, base=base, smtp_port=capture.server_address[1])
        fixture(case, 'seed')
        # The owned fixture validates the configured connection and target engine
        # before reading SELECT VERSION(); file fixtures report null.
        case['mysql_version'] = http.fixture(site, 'read', php)['mysql_version']
        check((mode == 'file' and case['mysql_version'] is None)
              or (mode == 'mysql' and isinstance(case['mysql_version'], str) and case['mysql_version']),
              'Protection acceptance did not identify its actual storage engine.')
        # Real filesystem ownership, matching an unprivileged web server.
        work.chmod(0o755); (work / label).chmod(0o755); chown_tree(work / label)
        loads = '\n'.join(f'LoadModule {name}_module "{modules}/mod_{name}.so"' for name in ['mpm_prefork', 'authz_core', 'authz_host', 'mime', 'dir', 'rewrite', 'headers'])
        conf = server / 'httpd.conf'
        conf.write_text(f'''ServerRoot "{server}"
Listen 127.0.0.1:{port}
ServerName 127.0.0.1
PidFile "{server}/httpd.pid"
DefaultRuntimeDir "{server}"
ErrorLog "{server}/error.log"
LogLevel warn
{loads}
LoadModule php_module "{php_module}"
PHPIniDir "/etc/php/{version}/cli"
User #{65534 if os.geteuid() == 0 else os.geteuid()}
Group #{65534 if os.geteuid() == 0 else os.getegid()}
StartServers 2
MinSpareServers 2
MaxSpareServers 4
MaxRequestWorkers 6
ServerLimit 6
TypesConfig /etc/mime.types
DocumentRoot "{document_root}"
DirectoryIndex index.php
<Directory "{document_root}">
  AllowOverride All
  Options -Indexes +FollowSymLinks
  Require all granted
</Directory>
<FilesMatch "\\.php$">
  SetHandler application/x-httpd-php
</FilesMatch>
php_admin_flag display_errors Off
php_admin_flag log_errors On
php_admin_flag opcache.enable Off
php_admin_value upload_max_filesize 32M
php_admin_value post_max_size 40M
''')
        # Module startup happens before per-directory php_admin_value overrides.
        # Keep the system extensions and disable JIT in this owned server only.
        supplemental_ini = server / 'php-conf.d'
        supplemental_ini.mkdir()
        (supplemental_ini / '99-vvx-opcache.ini').write_text('opcache.jit=0\nopcache.jit_buffer_size=0\n')
        environment = os.environ.copy()
        environment['PHP_INI_SCAN_DIR'] = '/etc/php/' + version + '/cli/conf.d:' + str(supplemental_ini)
        log = (server / 'console.log').open('w+')
        process = subprocess.Popen([apache, '-f', str(conf), '-DFOREGROUND'], env=environment, cwd=server, stdout=log, stderr=log, start_new_session=True)
        client = http.session(); deadline = time.monotonic() + 15
        try:
            while True:
                if process.poll() is not None: raise AssertionError('Apache failed: ' + (server / 'console.log').read_text() + ((server / 'error.log').read_text() if (server / 'error.log').exists() else ''))
                try:
                    if client.get(base + '/admin/?page=login', timeout=1).status_code == 200: break
                except http.requests.RequestException: pass
                if time.monotonic() > deadline: raise AssertionError('Apache login did not start: ' + (server / 'error.log').read_text())
                time.sleep(.1)
        finally: client.close()
        yield case
        check(capture.connections == 0 and not capture.payloads and not capture.errors, 'Protection contacted SMTP.')
        text = (server / 'error.log').read_text()
        check(not re.search(r'PHP (?:Fatal error|Parse error|Warning|Notice)|Uncaught ', text), 'Actual Apache raised PHP diagnostics: ' + text[-3000:])
    finally:
        if process is not None:
            process.terminate()
            try: process.wait(timeout=10)
            except subprocess.TimeoutExpired: process.kill(); process.wait(timeout=5)
            log.close()
        capture.release.set(); capture.shutdown(); capture.server_close()
        if mode == 'mysql' and (site / 'storage/temp/editorial-access-ownership.json').is_file(): http.fixture(site, 'cleanup-mysql', php)
        if evidence:
            for name in ['error.log', 'console.log']:
                if (server / name).is_file(): shutil.copyfile(server / name, evidence / ('protection-' + label + '-' + name))

def http_case(case):
    start = http.checks; owner = http.owner_login(case['base']); reporter = http.session(); foreign = http.session()
    records = json.loads((case['site'] / 'storage/temp/protection-fixture-records.json').read_text()); url = workspace(case)
    try:
        baseline = fixture(case, 'snapshot'); response = owner.get(url, timeout=20); http.private(response)
        check(response.status_code == 200 and 'Enable protection' in response.text, 'Protection setup is not available.')
        check(fixture(case, 'snapshot') == baseline, 'Ordinary owner GET initialized protection.')
        check(foreign.get(url, timeout=20).status_code == 403, 'Guest saw owner protection data.')
        check(owner.put(url, timeout=20).status_code == 405, 'Unsupported owner method was accepted.')
        check(owner.get(case['base'] + '/reports/', timeout=20).status_code == 200, 'Real Apache pretty report alias is missing.')
        raw = case['base'] + '/uploads/protection-picture.png'; check(hashlib.sha256(reporter.get(raw, timeout=20).content).hexdigest() == records['image_sha256'], 'Pre-activation raw media fixture is not real.')
        values = form(response, 'protection_activate')
        rejected = owner.post(url, data={**values, '_csrf': 'wrong', 'confirm_private': '1'}, timeout=20)
        check(rejected.status_code == 419, 'Owner CSRF status was ' + str(rejected.status_code) + ': ' + BeautifulSoup(rejected.text, 'html.parser').get_text(' ')[-900:])
        check(owner.post(case['base'] + '/admin/?page=dashboard', data=values, timeout=20).status_code == 400, 'Foreign owner dispatch changed protection.')
        response = owner.post(url, data={**values, 'confirm_private': '1'}, timeout=30)
        check(response.status_code == 200 and response.history, 'Real web-server probe failed protection activation: ' + BeautifulSoup(response.text, 'html.parser').get_text(' ')[:800])
        setup = fixture(case, 'snapshot'); check(setup['enabled'] and not setup['pending'] and setup['state']['ready'] and not setup['raw_exists'] and setup['probe_files'] == 0, 'Activation did not preserve and seal media.')
        check(setup['protected_sha256'] == baseline['protected_sha256'], 'Activation changed unrelated marketing records.')
        guarded = case['base'] + '/route.php?vvx_route=protected-asset.php&file=' + hashlib.sha256(b'uploads/protection-picture.png').hexdigest()
        check(reporter.get(raw, timeout=20).status_code in [403, 404], 'Old raw URL remained public.')
        image = reporter.get(guarded, timeout=20); http.private(image)
        check(image.status_code == 200 and hashlib.sha256(image.content).hexdigest() == records['image_sha256'], 'Protected media delivery changed bytes.')
        check(reporter.head(guarded, timeout=20).status_code == 200 and reporter.head(guarded, timeout=20).content == b'', 'Protected HEAD returned bytes or failed.')
        partial = reporter.get(guarded, headers={'Range': 'bytes=0-7'}, timeout=20)
        check(partial.status_code == 206 and partial.content == image.content[:8], 'Protected byte range did not match exact preserved bytes.')
        check(reporter.get(guarded, headers={'Range': 'bytes=999999-'}, timeout=20).status_code == 416, 'Impossible range was not rejected.')
        check(reporter.post(guarded, timeout=20).status_code == 405, 'Protected media accepted mutation method.')
        check(fixture(case, 'stale-public-writer')['exists'] and reporter.get(case['base'] + '/uploads/old-worker-output.png', timeout=20).status_code in [403, 404], 'Server barrier did not block a real stale public-first writer.')
        # Real owner upload paths must stage privately before registration.
        token = BeautifulSoup(owner.get(url, timeout=20).text, 'html.parser').select_one('meta[name=csrf-token]')['content']
        upload = owner.post(case['base'] + '/route.php?vvx_route=api/upload.php', data={'_csrf': token, 'type': 'background'}, files={'image': ('private-first.png', image.content, 'image/png')}, timeout=30)
        check(upload.status_code == 200 and upload.json().get('ok'), 'Actual Composer image upload failed: ' + upload.text[:300])
        uploaded = upload.json()['asset']; check(not (case['site'] / 'uploads' / uploaded['file']).exists(), 'Active upload wrote a public file.')
        check(reporter.get(uploaded['url'], timeout=20).status_code == 200, 'Private-first upload did not resolve through guarded delivery.')
        person_url = workspace(case, 'performers', 'new'); response = owner.get(person_url, timeout=20)
        person_data = {**form(response, 'protection_performer_save'), 'label': 'Synthetic collaborator', 'status': 'active', 'document_reference': 'urn:fixture:private-document', 'notes': 'PRIVATE_COLLABORATOR_NOTE', 'reviewed_on': '2026-10-21'}
        response = owner.post(person_url, data=person_data, timeout=20); check(response.status_code == 200 and response.history, 'Collaborator save failed.')
        person = form(response, 'protection_performer_save')['id']; stale = form(response, 'protection_performer_save')
        owner.post(response.url, data={**stale, 'label': 'Current collaborator', 'status': 'active'}, timeout=20)
        failed = owner.post(workspace(case, 'performers', person), data={**stale, 'label': 'Stale collaborator title'}, timeout=20)
        check(failed.status_code == 409 and form(failed, 'protection_performer_save')['revision'] == stale['revision'] and 'Stale collaborator title' in failed.text, 'Stale record did not preserve the failed form.')
        release_url = workspace(case, 'releases', 'new'); values = form(owner.get(release_url, timeout=20), 'protection_release_save')
        release_data = {**values, 'label': 'Synthetic permission release', 'status': 'active', 'performer_ids[]': person, 'targets[]': 'album:' + records['album'], 'document_reference': 'urn:fixture:release', 'consent_reviewed': '1'}
        invalid = owner.post(release_url, data={**release_data, 'document_reference': ''}, timeout=20); check(invalid.status_code == 422 and 'requires collaborators' in invalid.text, 'Approval accepted missing consent reference.')
        response = owner.post(release_url, data=release_data, timeout=20); check(response.status_code == 200 and response.history, 'Valid release was not saved.')
        # Actual public intake, one-time receipt, private owner review, reply and appeal.
        report = reporter.get(report_url(case), timeout=20); http.private(report)
        values = form(report, 'report_create'); report_data = {**values, 'category': 'consent', 'label': 'Synthetic report for review', 'name': 'Fixture Reporter', 'email': 'reporter@example.test', 'description': 'PRIVATE_REPORT_BODY <script>literal text</script>', 'url': case['base'] + '/music/', 'accurate': '1'}
        check(reporter.post(report_url(case), data={**report_data, '_csrf': 'wrong'}, timeout=20).status_code == 419, 'Public report CSRF was bypassed.')
        response = reporter.post(report_url(case), data=report_data, timeout=20); http.private(response)
        soup = BeautifulSoup(response.text, 'html.parser'); receipt = soup.select_one('[data-report-receipt]'); check(receipt is not None, 'Public report receipt missing.')
        case_id = receipt.select_one('[aria-label="Receipt case number"]')['value']; key = receipt.select_one('[aria-label="Receipt access key"]')['value']
        check(len(case_id) == 32 and len(key) == 48 and key not in response.url, 'Receipt key leaked into the URL or has invalid length.')
        check('Receipt access key' not in reporter.get(report_url(case, case_id), timeout=20).text, 'Receipt key was redisplayed on a later GET.')
        check(foreign.get(report_url(case, case_id), timeout=20).status_code == 403, 'Another browser read a private case.')
        lookup = form(foreign.get(report_url(case), timeout=20), 'report_lookup')
        check(foreign.post(report_url(case), data={**lookup, 'case_number': case_id, 'access_key': '0'*48}, timeout=20).status_code == 403, 'Foreign receipt was accepted.')
        response = foreign.post(report_url(case), data={**lookup, 'case_number': case_id, 'access_key': key}, timeout=20); check(response.status_code == 200 and response.history, 'Saved case receipt could not reopen the case.')
        case_url = workspace(case, 'cases', case_id); response = owner.get(case_url, timeout=20); http.private(response)
        check('PRIVATE_REPORT_BODY &lt;script&gt;' in response.text and '<script>literal text</script>' not in response.text, 'Private case text was not escaped in owner review.')
        values = form(response, 'protection_case_action')
        owner.post(case_url, data={**values, 'decision': 'request_reply', 'message': 'PRIVATE_OWNER_REVIEW', 'public_message': 'Please provide the remaining details.'}, timeout=20)
        response = reporter.get(report_url(case, case_id), timeout=20); check('Please provide the remaining details.' in response.text and 'PRIVATE_OWNER_REVIEW' not in response.text, 'Reporter response leaked internal notes or disappeared.')
        reply = form(response, 'report_reply'); response = reporter.post(report_url(case, case_id), data={**reply, 'message': 'PRIVATE_REPORTER_REPLY'}, timeout=20)
        check(response.status_code == 200 and response.history, 'Stateful reporter reply failed.')
        check(reporter.post(report_url(case, case_id), data={**reply, 'message': 'stale replay'}, timeout=20).status_code == 409, 'A stale reply was replayed.')
        values = form(owner.get(case_url, timeout=20), 'protection_case_action')
        response = owner.post(case_url, data={**values, 'decision': 'takedown', 'targets[]': ['album:' + records['album'], 'video:' + records['video']], 'message': 'PRIVATE_WITHDRAWAL', 'public_message': 'The selected content was withdrawn for review.'}, timeout=20)
        check(response.status_code == 200 and response.history, 'Owner takedown failed.')
        check(reporter.get(guarded, timeout=20).status_code in [403, 404] and reporter.get(records['signed_url'], timeout=20).status_code == 403, 'A previous hashed or signed media URL survived takedown.')
        check(reporter.get(case['base'] + '/route.php?vvx_route=videos/watch.php&slug=protection-fixture-video', timeout=20).status_code == 404, 'Withdrawn video detail exposed title/preview.')
        values = form(owner.get(case_url, timeout=20), 'protection_case_action'); fixture(case, 'post-edit')
        lift = {**values, 'decision': 'reinstate', 'message': 'Reviewed hold lift.', 'public_message': 'This hold is lifted; content awaits a separate release.'}
        failed = owner.post(case_url, data=lift, timeout=20); check(failed.status_code == 409 and form(failed, 'protection_case_action')['revision'] == values['revision'], 'Changed content did not block stale reinstatement.')
        values = form(owner.get(case_url, timeout=20), 'protection_case_action')
        response = owner.post(case_url, data={**lift, **{k:values[k] for k in ['_csrf', 'revision', 'content_revision']}}, timeout=20)
        check(response.status_code == 200 and response.history, 'Fresh hold lift failed.')
        after = fixture(case, 'snapshot'); check(after['album']['status'] == after['video']['status'] == after['post']['status'] == 'draft' and after['post']['body'] == 'Newest saved protection draft.', 'Hold lift published or overwrote current content.')
        check(reporter.get(guarded, timeout=20).status_code in [403, 404] and reporter.get(records['signed_url'], timeout=20).status_code == 403, 'Hold lift revived an old hashed/signed URL.')
        offline = owner.get(workspace(case, 'media'), timeout=20); check('Make media available' in offline.text, 'Deliberate media release control missing.')
        # Different assets have independent review forms and revision tokens.
        for selected in BeautifulSoup(offline.text, 'html.parser').select('form[data-protection-release-media]'):
            current = owner.get(workspace(case, 'media'), timeout=20); asset_id = selected.select_one('[name=id]')['value']
            chosen = BeautifulSoup(current.text, 'html.parser').select_one('form:has(input[name=id][value="' + asset_id + '"])')
            data = dict(http.fields(str(chosen)))
            result = owner.post(workspace(case, 'media'), data={**data, 'confirm_available': '1'}, timeout=20)
            check(result.status_code == 200 and result.history, 'Reviewed media release failed.')
        check(reporter.get(guarded, timeout=20).status_code == 200 and reporter.get(records['signed_url'], timeout=20).status_code == 200, 'Explicit owner file release did not restore protected delivery.')
        check(fixture(case, 'snapshot')['video']['status'] == 'draft', 'Explicit media release republished a video.')
        values = form(reporter.get(report_url(case, case_id), timeout=20), 'report_appeal')
        result = reporter.post(report_url(case, case_id), data={**values, 'message': 'Please reconsider using these synthetic references.'}, timeout=20)
        check(result.status_code == 200 and 'Appeal received' in result.text, 'Reporter appeal failed.')
        after = fixture(case, 'snapshot'); saved = json.dumps(after['state'])
        check(all(secret not in saved for secret in [key, 'PRIVATE_REPORT_BODY', 'PRIVATE_OWNER_REVIEW', 'PRIVATE_REPORTER_REPLY', 'urn:fixture:private-document']), 'Private plaintext or receipt key appeared in stored protection data.')
        check(after['protected_sha256'] == baseline['protected_sha256'] and case['capture'].connections == 0, 'Protection changed unrelated marketing or sent email.')
        owner_status(case, 'demoted'); check(owner.get(case_url, timeout=20).status_code == 403, 'Demoted saved owner read sensitive case details.'); owner_status(case, 'owner')
        expired_form = form(owner.get(case_url, timeout=20), 'protection_case_action')
        fixture(case, 'age-owner-session', cookie=owner.cookies.get('velvet_vixxen_admin'))
        response = owner.get(case_url, timeout=20); check('Confirm your identity' in response.text and 'PRIVATE_REPORT_BODY' not in response.text, 'Old owner authentication exposed case details.')
        check(owner.post(case_url, data={**expired_form, 'decision':'review', 'message':'Expired owner must not save this.'}, timeout=20).status_code == 428, 'Expired privileged owner action succeeded.')
        return {'checks':http.checks-start, 'mysql_version':case['mysql_version'], 'apache_raw_upload_denial':True, 'live_probe_and_cleanup':True, 'private_first_upload':True, 'stale_writer_denied':True, 'immutable_private_reads':True, 'encrypted_references':True, 'report_receipt_reply_appeal':True, 'owner_csrf_and_revisions':True, 'fresh_owner_and_recent_auth':True, 'draft_only_hold_lift':True, 'old_raw_hashed_signed_urls_held':True, 'explicit_media_release':True, 'smtp_connections':0}
    finally: owner.close(); reporter.close(); foreign.close()

def negative_control(work, archive, evidence):
    # Existing php -S serves real static bytes and ignores .htaccess. The feature
    # must refuse activation here, preserving its explicit hosting requirement.
    with http.runtime(work, archive, 'file', 'raw-server-negative', '', evidence) as case:
        owner = http.owner_login(case['base'])
        try:
            url = workspace(case); values = form(owner.get(url, timeout=20), 'protection_activate')
            response = owner.post(url, data={**values, 'confirm_private':'1'}, timeout=20)
            check(response.status_code == 503 and 'Protection was not activated' in response.text, 'A raw no-router server incorrectly passed activation.')
            check(not json.loads(subprocess.check_output([case['php'], '-r', '$c=require $argv[1];echo json_encode($c["protection"]["enabled"]??false);', str(case['site']/'config/app.php')],text=True)), 'Unverified raw server enabled enforcement.')
            return {'activation_refused':True, 'server':'php -S without a router or .htaccess handling'}
        finally: owner.close()

def run():
    parser=argparse.ArgumentParser();parser.add_argument('--mode',choices=['file','mysql','both'],default='both');args=parser.parse_args()
    evidence=http.evidence_directory();report={'server':'real Apache prefork + matching mod_php; no synthetic routing fallback','cases':{}}
    with tempfile.TemporaryDirectory(prefix='vvx-protection-http-') as tmp:
        work=Path(tmp);archive,identity=prepare_package(work);report.update(identity)
        for mode in (['file','mysql'] if args.mode=='both' else [args.mode]):
            for label,prefix in [('root',''),('nested','/nested/velvet')]:
                name=mode+'-'+label
                with runtime(work,archive,mode,name,prefix,evidence) as case:report['cases'][name]=http_case(case)
                print('Protection HTTP '+name+': PASS',flush=True)
        if args.mode in ['file','both']:report['raw_server_negative_control']=negative_control(work,archive,evidence)
    if evidence:(evidence/'protection-http-evidence.json').write_text(json.dumps(report,indent=2)+'\n')
    print(json.dumps(report,indent=2))
if __name__=='__main__':run()
