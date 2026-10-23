#!/usr/bin/env python3
"""Actual recurring member and owner controls, desktop/phone with JavaScript disabled."""
import argparse
import importlib.util
import json
import os
from pathlib import Path
import sys
import tempfile
from playwright.sync_api import sync_playwright

ROOT=Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT/'tests/Http'))
import recurring_http as terms

def main():
    parser=argparse.ArgumentParser(description=__doc__);parser.add_argument('--mode',choices=('file','mysql','both'),default='both');args=parser.parse_args()
    evidence=terms.http.evidence_directory();report={'passed':False,'report_kind':'recurring_browser','cases':{}}
    try:
        with tempfile.TemporaryDirectory(prefix='vvx-payment-recovery-recurring-browser-') as temporary,sync_playwright() as p:
            work=Path(temporary);archive,identity=terms.prepare_package(work);report.update(identity)
            browser=p.chromium.launch(headless=True,executable_path=os.environ.get('VELVET_CHROMIUM') or None,args=['--no-sandbox']);report['chromium']=browser.version
            try:
                for mode in ('file','mysql') if args.mode=='both' else (args.mode,):
                    for path,prefix,viewport in (('desktop','',{'width':1440,'height':1000}),('phone','/nested/velvet',{'width':390,'height':844})):
                        label=mode+'-'+path
                        with terms.recovery.runtime(work,archive,mode,label,prefix,evidence) as case:report['cases'][label]=terms.scenario(case,browser,viewport,evidence)
            finally:browser.close()
        report['passed']=True;print(json.dumps(report,indent=2))
    finally:
        if evidence:(evidence/'recurring-browser-evidence.json').write_text(json.dumps(report,indent=2)+'\n')

if __name__=='__main__':main()
