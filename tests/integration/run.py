#!/usr/bin/env python3
import json,pathlib,sys,time,urllib.request,concurrent.futures
BASE=pathlib.Path(sys.argv[1]); MODE=sys.argv[2] if len(sys.argv)>2 else 'single';PORT=18892 if MODE=='multi' else 18891
STATE=BASE/(MODE+'-state');URL=f'http://127.0.0.1:{PORT}'
def req(action,**kwargs):
 r=urllib.request.Request(URL+'/harness.php',data=json.dumps(dict(action=action,**kwargs)).encode(),headers={'X-ACFCM-Test':'disposable-local-only','Content-Type':'application/json'})
 with urllib.request.urlopen(r,timeout=90) as f:
  b=f.read().decode()
  try:return json.loads(b)
  except:raise Exception(b[:2000])
def http():return json.loads((STATE/'http.json').read_text())
def mock(responses=[]): (STATE/'http.json').write_text(json.dumps(dict(calls=[],responses=responses)))
def reset(site=1):req('reset',site=site);mock()
def check(v,label):
 if not v:raise AssertionError(label)
 print('PASS '+label,flush=True)
def cron(site=1):
 r=urllib.request.Request(URL+'/wp-cron.php?test_site='+str(site),headers={'X-ACFCM-Test':'disposable-local-only'})
 with urllib.request.urlopen(r,timeout=90) as f:f.read()
def drain(site=1):req('advance',site=site);cron(site)
def urls():return [u for c in http()['calls'] for u in c['payload'].get('files',[])]
def create(**kw):return req('create',post=dict(post_title='Synthetic '+str(time.time_ns()),post_status='publish',**kw))['id']
BEFORE='before-'+str(time.time_ns());AFTER='after-'+str(time.time_ns())
req('setup');reset()
id=create(post_name=BEFORE);check(isinstance(id,int),'real wp_insert_post publish');drain();check(URL+'/'+BEFORE+'/' in urls(),'published permalink through actual shutdown/cron')
a=req('term',name='Old '+str(time.time_ns()))['term_id'];b=req('term',name='New '+str(time.time_ns()))['term_id'];author=req('author')['id']
old_thumb=req('attachment',name='old-thumbnail')['id'];new_thumb=req('attachment',name='new-thumbnail')['id']
req('update',post={'ID':id},late_term=a,late_thumb=old_thumb);drain();reset()
r=req('update',post={'ID':id,'post_name':AFTER,'post_content':'Fresh marker','post_author':author,'categories':[b]},rest=True,late_meta='late committed',late_thumb=new_thumb)
check(r['status']==200,'real REST controller update');drain();u=urls();check(URL+'/'+BEFORE+'/' in u and URL+'/'+AFTER+'/' in u,'REST old/new permalinks')
check(any('/category/old-' in x for x in u) and any('/category/new-' in x for x in u),'REST old/new category archives')
check(URL+'/old-thumbnail.jpg' in u and URL+'/new-thumbnail.jpg' in u,'late thumbnail metadata old/new URLs')
check(any('/author/localadmin/' in x for x in u) and any('/author/author' in x for x in u),'old/new author archives')
check(req('state',id=id)['meta']=='late committed','late metadata persisted before shutdown purge')
for status in ['draft','private','future']:
 req('update',post={'ID':id,'post_status':'publish'});drain();reset();req('update',post={'ID':id,'post_status':status});drain();check(URL+'/'+AFTER+'/' in urls(),'real transition away from publish: '+status)
req('update',post={'ID':id,'post_status':'publish'});drain();reset();req('trash',id=id);drain();check(URL+'/'+AFTER+'/' in urls(),'real trash invalidates old URL')
req('update',post={'ID':id,'post_status':'publish'});drain();reset();old=req('state',id=id)['permalink'];req('delete',id=id);drain();check(old in urls(),'real delete invalidates old URL')
reset();future=create(post_date='2099-01-01 00:00:00');req('schedule-now',id=future);cron();drain();check(bool(urls()),'real scheduled publish cron hook')
reset();mock([{'code':200},{'code':503}]);r=req('purge',urls=[URL+'/batch-'+str(i) for i in range(31)]);check(r[0]['success'] and not r[1]['success'],'partial batch responses');check(len(req('state')['jobs'])==1,'real DB retains failed batch only');drain();check(len(http()['calls'])==3 and len(http()['calls'][-1]['payload']['files'])==1,'actual wp-cron executes persisted retry')
reset();mock([{'code':503}]);req('purge',urls=[URL+'/backoff']);job=list(req('state')['jobs'].values())[0];check(job['due']>=time.time()+58,'persisted backoff');cron();check(len(http()['calls'])==1,'cron does not run retry before due')
reset();mock([{'code':429,'retry_after':'900'}]);req('purge',urls=[URL+'/limited']);req('purge',urls=[URL+'/limited-other']);check(len(http()['calls'])==1,'Retry-After shared cooldown');check(list(req('state')['jobs'].values())[0]['due']>=time.time()+898,'900-second Retry-After persisted')
# Remove synthetic cooldown through disposable database via endpoint reset extension.
reset();mock([{'code':503}]*5);req('purge',urls=[URL+'/exhaust']);
for i in range(5):drain()
check(len(http()['calls'])==5 and not req('state')['jobs'],'five-attempt exhaustion')
reset();(STATE/'schedule-fail').touch();r=req('purge',urls=[URL+'/scheduler']);(STATE/'schedule-fail').unlink();check(not http()['calls'] and not r[0]['success'],'scheduler failure is not success');req('state');time.sleep(1.2);cron();check(len(http()['calls'])==1,'origin request repairs missing cron event')
reset();mock([{'code':200,'crash':True}]);
try:req('purge',urls=[URL+'/crash'])
except Exception:pass
check(len(req('state')['jobs'])==1,'terminated HTTP attempt leaves durable recovery lease');drain();check(len(http()['calls'])==2 and not req('state')['jobs'],'cron recovers terminated attempt')
reset();mock([{'code':200,'sleep':2}]);
with concurrent.futures.ThreadPoolExecutor() as ex:
 f=ex.submit(req,'purge',urls=[URL+'/race']);
 for _ in range(100):
  if http()['calls']:break
  time.sleep(.02)
 second=req('purge',urls=[URL+'/race']);first=f.result()
check(len(req('state')['jobs'])==1,'real concurrent submit survives in-flight completion');check(not first[0]['success'] and first[0].get('queued'),'trailing generation is pending, not complete');drain();check(len(http()['calls'])==2 and not req('state')['jobs'],'real concurrent trailing purge completes')
reset();mock([{'code':503}]*6)
with concurrent.futures.ThreadPoolExecutor(max_workers=6) as ex:
 results=list(ex.map(lambda i:req('purge',urls=[URL+'/parallel-'+str(i)]),range(6)))
check(len(req('state')['jobs'])==6 and len(http()['calls'])==6,'six concurrent distinct jobs retained atomically');drain();check(not req('state')['jobs'] and len(http()['calls'])==12,'all concurrent jobs recover through cron')
reset();mock([{'code':200,'sleep':1}]*10);start=time.monotonic();req('bulk',count=100);elapsed=time.monotonic()-start;check(not http()['calls'],'bulk shutdown queues without HTTP latency');print('METRIC bulk_100_seconds='+str(round(elapsed,3)),flush=True);drain()
if MODE=='multi':
 second=req('newsite')['id'];req('setup',site=second);reset();reset(second)
 same_id=int(time.time()*1000);p1=req('create',post={'import_id':same_id,'post_title':'Same ID one','post_status':'publish'},site=1)['id'];p2=req('create',post={'import_id':same_id,'post_title':'Same ID two','post_status':'publish'},site=second)['id'];check(p1==p2,'multisite identical post IDs');drain(1);drain(second)
 check({c['site'] for c in http()['calls']}=={1,second},'multisite cron isolation');check(all((u == URL+'/second' or u.startswith(URL+'/second/')) for c in http()['calls'] if c['site']==second for u in c['payload'].get('files',[])),'second-site URL paths isolated');check(all(c['token']=='Bearer fake-token-'+str(c['site']) and '/fake-zone-'+str(c['site'])+'/' in c['url'] for c in http()['calls']),'multisite independent tokens and zones')
print('CACHE '+json.dumps({k:v for k,v in req('state').items() if k in ['persistent_cache','cache_connected']}),flush=True)
print('INTEGRATION PASSED '+MODE,flush=True)
