"""Comprobaciones de los entregables S4, sin servicios ni dependencias externas."""
from pathlib import Path
import re,zipfile,xml.etree.ElementTree as ET,json
root=Path(__file__).resolve().parents[1]
required=['README.md','CHANGELOG.md','LICENSE','.env.example','.env.docker.example','Dockerfile','compose.yaml','portal.yml','docs/redis-claves.md','scripts/seed-mongosh.js','docs/seguimientos/S4.md','docs/instalacion/validacion-externa.md','docs/presentacion/OpenDesk_presentacion_S4.pptx','docs/presentacion/guion.md','docs/img/tickets.png','docs/img/equipos.png','docs/img/valoracion.png']
for name in required:assert (root/name).is_file(),name
portal=(root/'portal.yml').read_text(encoding='utf-8')
keys={line.split(':',1)[0] for line in portal.splitlines() if line and not line.startswith('#')}
expected={'nombre','resumen','problema','usuarios','motores','lenguaje','licencia','repositorio','demo_video','demo_url','captura','grupo','dia','periodo','autores_publicos'}
assert expected<=keys,expected-keys
assert 'demo_video: ""' in portal,'No anunciar video inexistente'
for name in ['README.md','docs/seguimientos/S4.md','docs/presentacion/guion.md']:
 text=(root/name).read_text(encoding='utf-8')
 for target in re.findall(r'\]\(([^)]+)\)',text):
  if target.startswith(('https:','http:','#','mailto:')):continue
  assert ((root/name).parent/target.split('#')[0]).resolve().exists(),(name,target)
with zipfile.ZipFile(root/'docs/presentacion/OpenDesk_presentacion_S4.pptx') as z:
 slides=[n for n in z.namelist() if re.fullmatch(r'ppt/slides/slide[0-9]+.xml',n)]
 assert len(slides)==12,len(slides)
 for slide in slides:ET.fromstring(z.read(slide))
proof=json.loads((root/'docs/evidencias/s4-instalacion-2026-10-23.json').read_text(encoding='utf-8'))
assert proof['httpValidation']['syntheticTicketsAfterRestart']==8
assert proof['httpValidation']['privateFilesHttp404'] is True
assert proof['httpValidation']['independentHumanValidation'] is False
print('S4: archivos, enlaces, ficha, PowerPoint y evidencia de instalación verificados.')
