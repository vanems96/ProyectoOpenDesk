"""Serial HTTPS acceptance/performance run; never writes credentials to artifacts."""
from pathlib import Path
from datetime import datetime
from zoneinfo import ZoneInfo
from http.client import HTTPSConnection
from http.cookies import SimpleCookie
from urllib.parse import quote,urlsplit
from collections import defaultdict
import json,os,time,statistics,ssl,hashlib,argparse

parser=argparse.ArgumentParser(description='Medición HTTPS del flujo con cuentas sintéticas autorizadas.')
parser.add_argument('--base-url',required=True)
parser.add_argument('--output',type=Path,required=True)
parser.add_argument('--runs',type=int,choices=range(3,6),default=5)
parser.add_argument('--allow-write',action='store_true',help='Autoriza crear y cerrar los tickets sintéticos de esta ejecución.')
args=parser.parse_args()
if not args.allow_write:parser.error('La prueba crea datos sintéticos: indique --allow-write en un entorno autorizado.')
url=urlsplit(args.base_url)
if url.scheme!='https' or not url.hostname or url.username or url.password or url.query or url.fragment:parser.error('Use una URL base HTTPS sin credenciales ni parámetros.')
required=['OD_BENCH_ADMIN_EMAIL','OD_BENCH_ADMIN_PASSWORD','OD_BENCH_CLIENT_EMAIL','OD_BENCH_AGENT_EMAIL','OD_BENCH_OTHER_CLIENT_EMAIL','OD_BENCH_DEMO_PASSWORD']
if any(not os.environ.get(k) for k in required):parser.error('Configure todas las variables privadas OD_BENCH_ documentadas, sin publicar sus valores.')
OUT=args.output.resolve();OUT.mkdir(parents=True,exist_ok=True)
HOST=url.hostname;PORT=url.port or 443;BASE=url.path.rstrip('/')+'/' 
samples=[];checks=[];runs=[]
stamp=datetime.now(ZoneInfo('America/Costa_Rica')).strftime('%Y%m%d-%H%M%S')

class Client:
 def __init__(self,role):
  self.role=role;self.conn=HTTPSConnection(HOST,port=PORT,timeout=25,context=ssl.create_default_context());self.cookies={};self.csrf='';self.user={}
 def call(self,path='',method='GET',body=None,label=None,run=0,expected=200,raw=False):
  target=BASE+('index.php?api='+quote(path,safe='') if path else '')
  headers={'Accept':'application/json' if path else 'text/html','User-Agent':'OpenDesk-Acceptance-Measurement/1.0'}
  if self.cookies:headers['Cookie']='; '.join(k+'='+v for k,v in self.cookies.items())
  if self.csrf:headers['X-CSRF-Token']=self.csrf
  data=None if body is None else json.dumps(body,ensure_ascii=False).encode()
  if data is not None:headers['Content-Type']='application/json'
  start=time.perf_counter_ns()
  self.conn.request(method,target,body=data,headers=headers)
  res=self.conn.getresponse();payload=res.read();elapsed=(time.perf_counter_ns()-start)/1e9
  for k,v in res.getheaders():
   if k.lower()=='set-cookie':
    cookie=SimpleCookie();cookie.load(v)
    for name,m in cookie.items():self.cookies[name]=m.value
  record={'run':run,'operation':label or method+' '+path,'role':self.role,'method':method,'path':path or '/','seconds':elapsed,'status':res.status,'bytes':len(payload)}
  samples.append(record)
  if res.status!=expected:
   raise RuntimeError(f'{self.role} {method} {path}: HTTP {res.status}, esperado {expected}: '+payload.decode(errors='replace')[:220])
  return payload if raw or not path else json.loads(payload)
 def login(self,email,password,run=0):
  self.csrf=self.call('session',label='Preparar sesión '+self.role,run=run)['csrf']
  result=self.call('login','POST',{'email':email,'password':password},label='Iniciar sesión '+self.role,run=run)
  self.csrf=result['csrf'];self.user=result['user']
  verify(result['ok'] and self.user['role']==self.role,'Autenticación y rol '+self.role,run)
  return self.user
 def close(self):self.conn.close()

def verify(condition,name,run=0):
 checks.append({'run':run,'check':name,'passed':bool(condition)})
 if not condition:raise AssertionError(name)

def save(error=None):
 grouped=defaultdict(list)
 for row in samples:
  if row['run']>0:grouped[row['operation']].append(row['seconds'])
 summaries={name:{'n':len(values),'median_seconds':statistics.median(values),'min_seconds':min(values),'max_seconds':max(values),'samples_seconds':values} for name,values in grouped.items()}
 record={'date_cr':datetime.now(ZoneInfo('America/Costa_Rica')).isoformat(),'url':args.base_url,'run_id':stamp,'method':'Serial HTTPS from client to published application, full response body, persistent connection per account. Includes network and server; excludes browser rendering and human time. No concurrent load or forced service outage.','run_count':len(runs),'runs':runs,'samples':samples,'summary':summaries,'checks':checks,'error':error}
 (OUT/'resultados.json').write_text(json.dumps(record,ensure_ascii=False,indent=2),encoding='utf-8')
 return record

clients=[]
try:
 admin=Client('administrador');customer=Client('cliente');agent=Client('agente');other=Client('cliente');clients=[admin,customer,agent,other]
 admin.login(os.environ['OD_BENCH_ADMIN_EMAIL'],os.environ['OD_BENCH_ADMIN_PASSWORD'])
 customer.login(os.environ['OD_BENCH_CLIENT_EMAIL'],os.environ['OD_BENCH_DEMO_PASSWORD'])
 agent.login(os.environ['OD_BENCH_AGENT_EMAIL'],os.environ['OD_BENCH_DEMO_PASSWORD'])
 other.login(os.environ['OD_BENCH_OTHER_CLIENT_EMAIL'],os.environ['OD_BENCH_DEMO_PASSWORD'])
 health=admin.call('health');verify(health['mongo'] and health['redis'] and health['profile']=='local','MongoDB local y Redis disponibles')
 original=admin.call('tickets');baseline_ids={x['id'] for x in original};baseline_open=sum(x['status']!='Resuelto' for x in original)
 verify(agent.user.get('departmentId')=='reclamos','Empleado pertenece al departamento de Reclamos')
 print(json.dumps({'phase':'preflight','health':health,'baseline_tickets':len(original),'baseline_open':baseline_open}),flush=True)
 for run in range(1,args.runs+1):
  begin=time.perf_counter_ns();offset=len(samples)
  customer.login(os.environ['OD_BENCH_CLIENT_EMAIL'],os.environ['OD_BENCH_DEMO_PASSWORD'],run)
  admin.login(os.environ['OD_BENCH_ADMIN_EMAIL'],os.environ['OD_BENCH_ADMIN_PASSWORD'],run)
  agent.login(os.environ['OD_BENCH_AGENT_EMAIL'],os.environ['OD_BENCH_DEMO_PASSWORD'],run)
  listing=admin.call('tickets',label='Listar tickets',run=run)
  case_id='qa-completa-'+stamp+'-'+str(run)
  payload={'id':case_id,'clientId':customer.user['clientId'],'requester':customer.user['name'],'subject':'QA MEDICIÓN COMPLETA · '+stamp+' · ejecución '+str(run),'description':'Caso sintético autorizado para medir creación, asignación, atención, propuesta, cierre y persistencia. No corresponde a un reclamo real.','category':'Consulta','orderRef':'QA-SIN-COMPRA-REAL','productId':'','priority':'Baja','departmentId':'reclamos'}
  created=customer.call('tickets','POST',payload,label='Crear ticket',run=run,expected=201)
  verify(created['id']==case_id and created['status']=='Nuevo','Ticket creado y pendiente de atención',run)
  first=admin.call('queue',label='Cola tras invalidación (MongoDB)',run=run)
  second=admin.call('queue',label='Cola repetida (Redis)',run=run)
  verify(first['source']=='MongoDB' and second['source']=='Redis','Caché: MongoDB primero, Redis después',run)
  verify(case_id in [t['id'] for t in second['items']],'Ticket nuevo visible en cola Redis',run)
  other.call('tickets/'+case_id,label='Acceso de otro cliente rechazado',run=run,expected=404)
  customer.call('tickets/'+case_id,'PATCH',{'assigneeId':agent.user['id']},label='Asignación por cliente rechazada',run=run,expected=403)
  admin.call('tickets/'+case_id,'PATCH',{'assigneeId':agent.user['id'],'status':'En atención'},label='Asignar empleado',run=run)
  assigned=agent.call('tickets/'+case_id,label='Consultar caso asignado',run=run)
  verify(assigned['assigneeId']==agent.user['id'] and assigned['status']=='En atención','Asignación persistente y visible para empleado',run)
  secret='NOTA INTERNA QA '+case_id
  agent.call('tickets/'+case_id,'PATCH',{'visibility':'internal','note':secret},label='Guardar nota privada',run=run)
  public=customer.call('tickets/'+case_id,label='Verificar privacidad de nota',run=run)
  verify(secret not in json.dumps(public,ensure_ascii=False),'Cliente no ve la nota interna',run)
  agent.call('tickets/'+case_id,'PATCH',{'note':'DEMO · Primera respuesta de atención para la medición completa.','status':'En atención'},label='Responder al cliente',run=run)
  customer.call('tickets/'+case_id,'PATCH',{'note':'DEMO · Respuesta sintética del cliente para continuar la atención.'},label='Cliente responde',run=run)
  agent.call('tickets/'+case_id,'PATCH',{'note':'DEMO · Solución sintética propuesta para validar el recorrido completo; sin transacción real.','status':'Solución propuesta'},label='Proponer solución',run=run)
  proposed=customer.call('tickets/'+case_id,label='Consultar solución propuesta',run=run)
  verify(proposed['status']=='Solución propuesta' and proposed['waitingFor']=='cliente','Propuesta requiere confirmación del cliente',run)
  customer.call('tickets/'+case_id,'PATCH',{'action':'close','rating':6},label='Calificación inválida rechazada',run=run,expected=422)
  customer.call('tickets/'+case_id,'PATCH',{'action':'close','rating':4,'comment':'DEMO · Confirmación y valoración sintéticas de medición, no opinión de un cliente real.'},label='Confirmar cierre y valorar',run=run)
  closed=admin.call('tickets/'+case_id,label='Verificar cierre persistente',run=run)
  verify(closed['status']=='Resuelto' and closed['rating']==4 and closed.get('closedAt',0)>0,'Cierre y valoración guardados en consulta posterior',run)
  verify(secret in json.dumps(closed,ensure_ascii=False),'Administrador conserva historial privado',run)
  queue=admin.call('queue',label='Verificar invalidación al cerrar',run=run)
  verify(case_id not in [t['id'] for t in queue['items']],'Ticket cerrado retirado de la cola',run)
  agent.call('users',label='Acceso del empleado a usuarios rechazado',run=run,expected=403)
  notices=customer.call('notifications',label='Consultar notificaciones',run=run)
  verify(case_id in json.dumps(notices,ensure_ascii=False),'Notificaciones del caso disponibles al cliente',run)
  report=admin.call('reports',label='Consultar reportes',run=run)
  verify(report['resolved']>=1,'Reporte incluye casos resueltos',run)
  total=(time.perf_counter_ns()-begin)/1e9
  current={'run':run,'ticket_id':case_id,'final_status':closed['status'],'rating':closed['rating'],'http_requests':len(samples)-offset,'sum_http_seconds':sum(x['seconds'] for x in samples[offset:]),'full_cycle_seconds':total,'queue_sources':[first['source'],second['source']]}
  runs.append(current);save();print(json.dumps({'phase':'completed_run',**current}),flush=True)
 final=admin.call('tickets');verify(baseline_ids.issubset({t['id'] for t in final}),'Todos los tickets anteriores se conservan')
 verify(sum(t['status']!='Resuelto' for t in final)==baseline_open,'Sin tickets de prueba abiertos al finalizar')
 record=save();record['baseline']={'tickets':len(original),'open':baseline_open};record['final']={'tickets':len(final),'open':sum(t['status']!='Resuelto' for t in final)}
 (OUT/'resultados.json').write_text(json.dumps(record,ensure_ascii=False,indent=2),encoding='utf-8')
 print(json.dumps({'phase':'complete','runs':len(runs),'checks':len(checks),'passed':sum(c['passed'] for c in checks),'output':str(OUT)}),flush=True)
except Exception as e:
 save(str(e));print(json.dumps({'phase':'failed','error':str(e)},ensure_ascii=False),flush=True);raise
finally:
 for c in clients:c.close()
