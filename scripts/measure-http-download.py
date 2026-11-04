"""Medición de descarga HTTPS; no mide pantalla lista ni renderizado."""
from http.client import HTTPSConnection
from pathlib import Path
from urllib.parse import urlsplit
import argparse,ssl,time,json,statistics
p=argparse.ArgumentParser(description=__doc__)
p.add_argument('--base-url',required=True);p.add_argument('--output',type=Path,required=True);p.add_argument('--runs',type=int,choices=range(3,6),default=5)
args=p.parse_args();u=urlsplit(args.base_url)
if u.scheme!='https' or not u.hostname or u.username or u.password or u.query or u.fragment:p.error('Use una URL HTTPS sin credenciales ni parámetros.')
out=args.output.resolve();out.mkdir(parents=True,exist_ok=True);base=u.path.rstrip('/')+'/'
samples=[]
for run in range(1,args.runs+1):
 connection=HTTPSConnection(u.hostname,port=u.port or 443,timeout=25,context=ssl.create_default_context())
 begin=time.perf_counter_ns();connection.request('GET',base)
 response=connection.getresponse();html=response.read();html_seconds=(time.perf_counter_ns()-begin)/1e9
 if response.status!=200 or b'assets/opendesk-logo.png' not in html:raise RuntimeError('No se recibió la página OpenDesk esperada.')
 start=time.perf_counter_ns();connection.request('GET',base+'assets/opendesk-logo.png?v=20261029')
 response=connection.getresponse();image=response.read();logo_seconds=(time.perf_counter_ns()-start)/1e9
 if response.status!=200 or not image.startswith(b'\x89PNG'):raise RuntimeError('No se recibió el PNG del logo.')
 samples.append({'run':run,'html_seconds':html_seconds,'logo_seconds':logo_seconds,'total_seconds':(time.perf_counter_ns()-begin)/1e9,'html_bytes':len(html),'logo_bytes':len(image)})
 connection.close()
record={'method':'Nueva conexión HTTPS por ejecución; descarga completa HTML y logo. Incluye DNS/TLS/red/servidor. Excluye JavaScript, API de arranque y renderizado. No mide pantalla lista.','samples':samples,'median_total_seconds':statistics.median(s['total_seconds'] for s in samples),'median_html_seconds':statistics.median(s['html_seconds'] for s in samples)}
(out/'descarga_inicial.json').write_text(json.dumps(record,indent=2),encoding='utf-8')
print(json.dumps(record))
