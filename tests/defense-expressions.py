#!/usr/bin/env python3
"""Evaluate the baseline's small expression subset locally; not a Cloudflare compiler."""
import json,re,pathlib
root=pathlib.Path(__file__).parent/'fixtures/defense'
b=json.loads((root/'baseline.json').read_text())
class Expression:
 def __init__(self,s,fields):
  s=s.replace('any(http.request.headers["authorization"][*] ne "")','has_authorization')
  self.t=re.findall(r'"(?:[^"\\]|\\.)*"|[(){} ,]|[a-zA-Z_][a-zA-Z0-9_.]*',s)
  self.t=[x for x in self.t if x!=' '];self.i=0;self.f=fields
 def pop(self):v=self.t[self.i];self.i+=1;return v
 def peek(self):return self.t[self.i] if self.i<len(self.t) else None
 def atom(self):
  t=self.pop()
  if t=='(':
   v=self.expr();assert self.pop()==')';return v
  if t=='{':
   v=[]
   while self.peek()!='}':v.append(self.atom())
   self.pop();return v
  if t.startswith('"'):return json.loads(t)
  if t in ['lower','starts_with','ends_with']:
   assert self.pop()=='(';a=self.atom()
   if t=='lower':v=a.lower()
   else:
    assert self.pop()==',';c=self.atom();v=a.startswith(c) if t=='starts_with' else a.endswith(c)
   assert self.pop()==')';return v
  return self.f[t]
 def predicate(self):
  if self.peek()=='not':self.pop();return not self.predicate()
  a=self.atom();op=self.peek()
  if op in ['eq','ne','contains','in']:
   self.pop();c=self.atom();return {'eq':lambda:a==c,'ne':lambda:a!=c,'contains':lambda:c in a,'in':lambda:a in c}[op]()
  return a
 def conjunction(self):
  v=self.predicate()
  while self.peek()=='and':self.pop();r=self.predicate();v=v and r
  return v
 def expr(self):
  v=self.conjunction()
  while self.peek()=='or':self.pop();r=self.conjunction();v=v or r
  return v
 def run(self):v=self.expr();assert self.i==len(self.t);return bool(v)
def evaluate(method='GET',path='/',query='',cookie='',auth=False,bot=False):
 f={'http.request.method':method,'http.request.uri.path':path,'http.request.uri.query':query,'http.cookie':cookie,'has_authorization':auth,'cf.client.bot':bot}
 probe=Expression(b['probe']['expression'],f).run();guard=Expression(b['guard']['expression'],f).run();rate=Expression(b['rate']['expression'],f).run()
 return probe,guard,rate and not guard
cases=[('public GET',{},(False,False,True)),('tracking GET',{'query':'utm_source=ad&gclid=123&fbclid=456'},(False,False,True)),('public GF page',{'path':'/contact/'},(False,False,True)),('form POST',{'method':'POST'},(False,True,False)),('AJAX POST',{'method':'POST','path':'/wp-admin/admin-ajax.php'},(False,True,False)),('REST GET',{'path':'/wp-json/wp/v2/posts'},(False,False,False)),('admin GET',{'path':'/wp-admin/edit.php'},(False,False,False)),('preview GET',{'query':'preview=true'},(False,True,False)),('logged-in cookie',{'cookie':'wordpress_logged_in_test=abc'},(False,True,False)),('password cookie',{'cookie':'wp-postpass_test=abc'},(False,True,False)),('Regnum session',{'cookie':'Regnum_session=abc'},(False,True,False)),('Woo session',{'cookie':'wp_woocommerce_session_test=abc'},(False,True,False)),('authorization',{'auth':True},(False,True,False)),('CSS',{'path':'/theme.css'},(False,False,False)),('video',{'path':'/video.MP4'},(False,False,False)),('verified bot',{'bot':True},(False,False,False)),('sensitive file',{'path':'/.env'},(True,False,True)),('sensitive POST still blocked',{'method':'POST','path':'/.env'},(True,True,False))]
for name,args,want in cases:
 assert evaluate(**args)==want,(name,evaluate(**args));print('PASS expression',name)
for query in ['gf_page=1','gform_submit=1','rest_route=/wp/v2','cornerstone=1','cs_preview=1','preview_id=7','customize_changeset_uuid=abc','wc-ajax=1','add-to-cart=1']:
 assert evaluate(query=query)==(False,True,False);print('PASS dynamic query',query)
assert b['guard']['action_parameters']=={'phases':['http_ratelimit']} and b['guard']['logging']=={'enabled':False}
assert b['rate']['ratelimit']=={'characteristics':['cf.colo.id','ip.src'],'period':10,'requests_per_period':30,'mitigation_timeout':10}
traces=json.loads((root/'trace-canary.json').read_text())
for trace in traces:
 # Saved Cloudflare evaluations are independent evidence, not new live requests.
 byname={x['name']:x for x in trace['trace']}
 if trace['name'] in ['public GET','tracking GET']:
  assert byname[b['rate']['description']]['matched']
 if trace['name'] in ['form POST','AJAX POST','preview GET','logged-in cookie','password cookie']:
  assert byname[b['guard']['description']]['matched']
print('PASS saved Cloudflare trace compatibility and narrow skip scope')
fixtures=json.loads((root/'rollout.json').read_text())
for f in fixtures:
 after=f['after']['http_request_firewall_custom']['rules']
 for original in (f['before'].get('http_request_firewall_custom') or {'rules':[]})['rules']:
  if not original.get('enabled',True):continue
  norm=lambda s:re.sub(r'\s+',' ',s).strip()
  assert any(r['action']==original['action'] and r.get('enabled',True) and norm(original['expression']) in norm(r['expression']) for r in after),(f['name'],original['id'])
assert len(fixtures)==153
print('PASS all 153 fixtures retain original active custom-rule conditions, including consolidations')
