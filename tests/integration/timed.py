#!/usr/bin/env python3
# Real elapsed-time check: does not rewrite the persisted due time.
exec(open(__file__.replace('timed.py','run.py')).read().split("BEFORE='before-")[0])
req('setup');reset();mock([{'code':503,'retry_after':'61'}]);req('purge',urls=[URL+'/elapsed-time']);j=list(req('state')['jobs'].values())[0]
print('WAITING until persisted due '+str(j['due']),flush=True)
while time.time() <= j['due']:time.sleep(min(10,j['due']-time.time()+.2))
check(len(http()['calls'])==1,'no origin traffic means no spontaneous retry even after due')
cron();check(len(http()['calls'])==2 and not req('state')['jobs'],'explicit cron runs real elapsed-time retry without timestamp manipulation')
print('TIMED INTEGRATION PASSED',flush=True)
