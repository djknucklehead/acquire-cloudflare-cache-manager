#!/usr/bin/env python3
"""Evaluate only the template's expression subset, not Cloudflare's compiler."""
import json,re,pathlib,urllib.parse
root=pathlib.Path(__file__).resolve().parents[1]
template=json.loads((root/'includes/cache-template.php').read_text().split("<<<'ACFCM_JSON'\n")[1].split('\nACFCM_JSON')[0])
class Expr:
 def __init__(self,s,f):
  self.f=f.copy()
  for i,field in enumerate(set(re.findall(r'http\.(?:request|response)\.headers\["[^"]+"\]\[\*\]|http.request.uri.args.names\[\*\]',s))):
   alias='array'+str(i);s=s.replace(field,alias);self.f[alias]=f.get(field,[])
  tokens=re.findall(r'"(?:[^"\\]|\\.)*"|[(){} ,]|[0-9]+|[a-zA-Z_][a-zA-Z0-9_.]*',s)
  assert ''.join(tokens)==s,('unsupported syntax',s)
  self.t=[x for x in tokens if x!=' '];self.i=0
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
  if t.isdigit():return int(t)
  if t in ['lower','starts_with','any','all']:
   assert self.pop()=='('
   if t in ['any','all']:v=self.expr();v=any(v) if t=='any' else all(v)
   else:
    a=self.atom()
    if t=='lower':v=a.lower()
    else:
     assert self.pop()==',';c=self.atom();v=[x.startswith(c) for x in a] if isinstance(a,list) else a.startswith(c)
   assert self.pop()==')';return v
  return self.f[t]
 def pred(self):
  if self.peek()=='not':self.pop();return not self.pred()
  a=self.atom();op=self.peek()
  if op in ['eq','ne','contains','in']:
   self.pop();b=self.atom();fn=lambda x:{'eq':lambda:x==b,'ne':lambda:x!=b,'contains':lambda:b in x,'in':lambda:x in b}[op]()
   return [fn(x) for x in a] if isinstance(a,list) else fn(a)
  return a
 def conjunction(self):
  v=self.pred()
  while self.peek()=='and':self.pop();r=self.pred();v=v and r
  return v
 def expr(self):
  v=self.conjunction()
  while self.peek()=='or':self.pop();r=self.conjunction();v=v or r
  return v
 def run(self):v=self.expr();assert self.i==len(self.t);return bool(v)
def evaluate(path='/',query='',method='GET',cookie='',host='ACFCM_HOST',status=200,headers=None,auth=False):
 f={'http.host':host,'http.request.method':method,'http.request.uri.path':path,'http.request.uri.query':query,'http.request.uri.path.extension':path.rsplit('.',1)[-1] if '.' in path else '', 'http.cookie':cookie,'http.response.code':status,'http.request.headers["authorization"][*]':['Bearer test'] if auth else [],'http.request.uri.args.names[*]':[k for k,v in urllib.parse.parse_qsl(query,keep_blank_values=True)]}
 for k,v in (headers or {}).items():f['http.response.headers["'+k+'"][*]']=v if isinstance(v,list) else [v]
 return [[Expr(r['expression'],f).run() for r in template[p]['rules']] for p in ['request','response']]
cases=[('public',{},[[False,True,False],[False,False,False]]),('tracking',{'query':'utm_source=ad&gclid=1'},[[False,True,False],[False,False,False]]),('unknown query',{'query':'session=1'},[[False,False,True],[False,False,False]]),('mixed query',{'query':'utm_source=ad&preview=true'},[[False,False,True],[False,False,False]]),('HEAD',{'method':'HEAD'},[[False,True,False],[False,False,False]]),('PURGE cache key',{'method':'PURGE','query':'utm_source=ad'},[[False,True,False],[False,False,False]]),('form POST',{'method':'POST'},[[False,False,True],[False,False,False]]),('static version',{'path':'/wp-content/app.js','query':'ver=7'},[[True,False,False],[False,False,False]]),('GF page',{'headers':{'x-acquire-form-page':'gravity-forms'}},[[False,True,False],[True,False,False]]),('GF bot cookie',{'headers':{'x-acquire-form-page':'gravity-forms','set-cookie':'__cf_bm=abc; Secure'}},[[False,True,False],[True,True,False]]),('GF mixed application cookie',{'headers':{'x-acquire-form-page':'gravity-forms','set-cookie':['__cf_bm=abc','session=private']}},[[False,True,False],[False,False,True]]),('non200',{'status':404},[[False,True,False],[False,False,True]]),('other host',{'host':'other.test'},[[False,False,False],[False,False,False]])]
for name,args,want in cases:
 got=evaluate(**args);assert got==want,(name,got,want);print('PASS cache expression',name)
for path in ['/wp-admin/','/wp-json/wp/v2/posts','/api/data','/checkout/','/preview/','/feed/','/index.php','/private.pdf']:
 assert evaluate(path=path)[0]==[False,False,True],path
 print('PASS dynamic path bypass',path)
for cookie in ['wordpress_logged_in_x=a','wordpress_sec_x=a','wp-postpass_x=a','regnum_session=a','wp_woocommerce_session_x=a','woocommerce_items_in_cart=1','PHPSESSID=abc','comment_author_x=a','gform_token=a']:
 assert evaluate(cookie=cookie)[0]==[False,False,True],cookie
 print('PASS session bypass',cookie.split('=')[0])
assert evaluate(auth=True)[0]==[False,False,True];print('PASS authorization bypass')
for header in ['cache-control','cdn-cache-control','cloudflare-cdn-cache-control']:
 for value in ['private','no-store','no-cache','max-age=0']:
  assert evaluate(headers={header:value,'x-acquire-form-page':'gravity-forms'})[1]==[False,False,True],(header,value)
  print('PASS private response',header,value)
assert evaluate(headers={'x-acquire-cache-guard':'password-protected'})[1]==[False,False,True]
assert template['request']['rules'][1]['action_parameters']['browser_ttl']=={'mode':'bypass'}
assert template['request']['rules'][1]['action_parameters']['cache_key']['custom_key']['query_string']=={'exclude':{'all':True}}
assert 'cache_key' not in template['request']['rules'][0]['action_parameters']
assert template['response']['rules'][0]['action_parameters']['s-maxage']['value']==86400
print('PASS guard response, HTML normalized key, static version key, browser bypass and GF TTL')
print('Cache expression tests passed.')
