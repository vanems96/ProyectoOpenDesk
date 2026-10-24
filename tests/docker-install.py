"""Prueba HTTP de la instalacion Docker recien creada. Solo datos sinteticos."""
import os, subprocess, urllib.request, urllib.error, http.cookiejar, json
from pathlib import Path
if os.environ.get('OPENDESK_TEST_DATABASE')!='yes':raise SystemExit('Solo entorno de prueba aislado')
def dc(*args):return subprocess.check_output(['docker','compose',*args],text=True).strip()
initial='InstallationOnlySynthetic123!';final='InstallationChangedSynthetic123!'
hash_value=dc('exec','-T','app','php','-r',f"echo password_hash('{initial}',PASSWORD_DEFAULT);")
dc('exec','-T','-e','APP_PASSWORD_HASH='+hash_value,'app','php','index.php','--init-admin','install@example.test')
jar=http.cookiejar.CookieJar();opener=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar));csrf=''
def api(route,body=None):
 global csrf
 data=json.dumps(body).encode() if body is not None else None
 request=urllib.request.Request('http://127.0.0.1:8080/index.php?api='+route,data=data,headers={'Content-Type':'application/json','X-CSRF-Token':csrf})
 with opener.open(request,timeout=15) as response:result=json.load(response)
 if isinstance(result,dict) and 'csrf' in result:csrf=result['csrf']
 return result
api('session');logged=api('login',{'email':'install@example.test','password':initial})
assert logged['user']['mustChangePassword'] is True
changed=api('password',{'currentPassword':initial,'password':final});assert changed['user']['mustChangePassword'] is False
health=api('health');assert health['mongo'] and health['redis']
tickets=api('tickets');assert len(tickets)==8,len(tickets)
for private in ['.env','.git/config']:
 try:opener.open('http://127.0.0.1:8080/'+private,timeout=10);raise AssertionError('Archivo privado accesible: '+private)
 except urllib.error.HTTPError as error:assert error.code==404,error.code
api('logout',{})
Path('test-results').mkdir(exist_ok=True)
result={'environment':'Docker limpio CI','firstAdministrator':True,'forcedPasswordChange':True,'httpLogin':True,'mongo':True,'redis':True,'syntheticTicketsAfterRestart':len(tickets),'privateFilesHttp404':True,'independentHumanValidation':False}
Path('test-results/instalacion-http.json').write_text(json.dumps(result,indent=2),encoding='utf-8')
print(json.dumps(result))
