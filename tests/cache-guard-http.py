#!/usr/bin/env python3
"""Actual PHP response headers and byte preservation; no external HTTP."""
import pathlib,subprocess,urllib.request,socket,time,os,signal
repo=pathlib.Path(__file__).resolve().parents[1]
with socket.socket() as s:s.bind(('127.0.0.1',0));port=s.getsockname()[1]
p=subprocess.Popen(['php','-S',f'127.0.0.1:{port}',str(repo/'tests/cache-guard-harness.php')],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,start_new_session=True)
try:
 for _ in range(50):
  try:
   with socket.create_connection(('127.0.0.1',port),.1):break
  except OSError:time.sleep(.05)
 for case in ['public','password','unlocked-password','logged-in','preview','form-hook','form-fragment','unscoped','admin','ajax','rest','feed','legacy','legacy-new-site']:
  with urllib.request.urlopen(f'http://127.0.0.1:{port}/?case={case}') as response:
   h=response.headers;b=response.read();expected='<!doctype html>\n<html><body>Literal &amp; bytes 🦉\n'+('<form id="gform_7"><input value="unchanged"></form>' if case=='form-fragment' else '')+'</body></html>'
   assert b==expected.encode(),(case,'HTML bytes changed')
   protected=case in ['password','unlocked-password','logged-in','preview']
   assert (h['X-Test-No-Cache']=='yes')==protected,(case,'cache flag')
   assert ('no-store' in h.get('Cloudflare-CDN-Cache-Control',''))==protected,(case,'edge protection')
   if protected:assert 'private' in h['Cache-Control']
   assert (h.get('X-Acquire-Form-Page')=='gravity-forms')==(case in ['form-hook','form-fragment','legacy','legacy-new-site']),(case,'GF marker')
   expected_buffers=0 if case in ['unscoped','admin','ajax','rest','feed'] else 1
   assert h['X-Test-Buffers']==str(expected_buffers),(case,'duplicate or unwanted buffer')
   print('PASS actual PHP headers, body preservation, buffer scope:',case)
finally:
 os.killpg(p.pid,signal.SIGTERM);p.wait(timeout=5)
