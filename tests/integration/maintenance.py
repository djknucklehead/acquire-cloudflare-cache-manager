#!/usr/bin/env python3
"""Disposable local HTTP/SQL integration; simulated providers; no live purges."""
import json,pathlib,sys,time,urllib.request,concurrent.futures
base=pathlib.Path(sys.argv[1]);mode=sys.argv[2];state=base/(mode+'-state');port=18892 if mode=='multi' else 18891
url=f'http://127.0.0.1:{port}'
def req(action,**kw):
 r=urllib.request.Request(url+'/harness.php',data=json.dumps(dict(action=action,**kw)).encode(),headers={'X-ACFCM-Test':'disposable-local-only','Content-Type':'application/json'})
 with urllib.request.urlopen(r,timeout=90) as f:
  raw=f.read().decode()
  try:return json.loads(raw)
  except:raise RuntimeError(raw[:1000])
def log(name):return json.loads((state/(name+'.json')).read_text())
def mock(name,responses=[]): (state/(name+'.json')).write_text(json.dumps(dict(calls=[],responses=responses)))
def check(v,s):
 if not v:raise AssertionError(s)
 print('PASS '+s,flush=True)
def reset():
 req('queue-reset')
 for site in ([1,2] if mode=='multi' else [1]):req('reset',site=site)
 mock('http');mock('origin')
def advance(**kw):req('queue-advance',**kw)
def tick():return req('queue-tick')
def q():return req('queue-state')
(state/'maintenance-enabled').touch()
try:
 for site in ([1,2] if mode=='multi' else [1]):req('setup',site=site)
 reset()
 with concurrent.futures.ThreadPoolExecutor(max_workers=8) as ex:list(ex.map(lambda _:req('queue-enqueue'),range(16)))
 check(len(q()['targets'])==(2 if mode=='multi' else 1) and not log('origin')['calls'],'concurrent update bursts persist one target per site in real SQL')
 advance();mock('origin',[{'sleep':1}])
 with concurrent.futures.ThreadPoolExecutor(max_workers=8) as ex:list(ex.map(lambda _:tick(),range(8)))
 check(len(log('origin')['calls'])==1 and not log('http')['calls'],'overlapping PHP workers dispatch only one origin and no premature edge')
 req('queue-advance',only_due=True);tick()
 check(len(log('http')['calls'])==1 and len(log('origin')['calls'])==1,'edge follows first origin without catch-up origin burst')
 first=log('http')['calls'][0];check(first['payload']=={'hosts':['site1.test']},'maintenance uses hostname purge instead of zone-wide purge')
 check(log('origin')['calls'][0]['time']<=first['time'],'recorded provider order is origin then edge')
 if mode=='multi':
  advance();tick();advance();tick()
  check([x['site'] for x in log('origin')['calls']]==[1,2] and [x['site'] for x in log('http')['calls']]==[1,2],'multisite origin and edge contexts stay separate')
 reset();req('queue-enqueue');advance();mock('origin',[{'sleep':2}])
 with concurrent.futures.ThreadPoolExecutor() as ex:
  f=ex.submit(tick)
  for _ in range(100):
   if log('origin')['calls']:break
   time.sleep(.02)
  req('queue-enqueue',reason='wp_update_theme');f.result()
 check(q()['targets']['1']['stage']=='origin' and q()['targets']['1']['attempt']==0 and not log('http')['calls'],'update during in-flight origin fences old completion without consuming new retry budget')
 tick();check(len(log('origin')['calls'])==1,'renewed quiet period pauses later origin dispatch')
 reset();req('queue-enqueue');advance();tick();req('queue-enqueue',reason='wp_update_core');tick()
 check(q()['targets']['1']['stage']=='origin' and not log('http')['calls'],'update between stages supersedes old edge readiness')
 reset();req('queue-enqueue');advance();mock('origin',[{'crash':True}])
 try:tick()
 except:pass
 check(q()['lease'] is not None,'terminated worker leaves durable lease');tick();check(len(log('origin')['calls'])==1,'restart respects live lease')
 advance(expire_lease=True);tick();check(len(log('origin')['calls'])==2,'expired lease recovers one origin without catch-up burst')
 reset();req('queue-enqueue');advance();mock('origin',[{'fail':True}]);tick()
 check(q()['hold_until']>time.time()+100 and not log('http')['calls'],'origin failure activates durable global backoff and blocks edge')
 reset();req('queue-enqueue');advance();tick();req('queue-advance',only_due=True);mock('http',[{'code':429,'retry_after':'900'}]);tick()
 check(q()['hold_until']>time.time()+890,'Cloudflare failure honors Retry-After across maintenance targets')
 reset();req('queue-enqueue');req('queue-control',control='pause');req('purge',urls=['https://site1.test/edited/']);req('advance');
 # Existing content queue is independent; invoke real persisted WP-Cron event.
 r=urllib.request.Request(url+'/wp-cron.php',headers={'X-ACFCM-Test':'disposable-local-only'})
 with urllib.request.urlopen(r,timeout=90) as f:f.read()
 check(any('files' in c['payload'] for c in log('http')['calls']),'content edit queue completes via real cron while maintenance is paused')
 req('queue-control',control='cancel');req('queue-enqueue');advance();tick();check(q()['cancelled'],'new hooks cannot silently undo cancel')
 reset();req('queue-enqueue');advance()
 with urllib.request.urlopen(urllib.request.Request(url+'/wp-cron.php',headers={'X-ACFCM-Test':'disposable-local-only'}),timeout=90) as f:f.read()
 check(len(log('origin')['calls'])==1,'registered maintenance cron executes with real WordPress scheduling')
 reset();req('queue-control',control='pause')
 for kind in ['plugin','theme','core']:
  req('queue-update',type=kind);advance();tick()
 check(not log('origin')['calls'] and len(q()['targets'])==(2 if mode=='multi' else 1),'separate plugin/theme/core requests coalesce through actual updater hooks while held')
 req('queue-control',control='resume');tick();check(not log('origin')['calls'],'finished control enforces final quiet period')
 advance();tick();check(len(log('origin')['calls'])==1,'finished session starts a single paced origin')
 req('queue-update',type='theme',interrupt=True);tick()
 check(q()['updating'] and not log('http')['calls'],'interrupted updater lease blocks stale edge across requests')
 advance();tick();check(len(log('origin')['calls'])==2,'expired simulated update lease restarts with origin')
 reset();req('queue-enqueue');advance();tick();req('queue-advance',only_due=True);mock('http',[{'code':200,'sleep':2}])
 with concurrent.futures.ThreadPoolExecutor() as ex:
  f=ex.submit(tick)
  for _ in range(100):
   if log('http')['calls']:break
   time.sleep(.02)
  req('queue-update',type='core');f.result()
 check(q()['targets']['1']['stage']=='origin','concurrent core update fences in-flight old edge completion')
 print('Maintenance integration complete: '+mode,flush=True)
finally:(state/'maintenance-enabled').unlink(missing_ok=True)
