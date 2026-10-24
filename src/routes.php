<?php
$app->add(function($req,$handler){
    $path=$req->getUri()->getPath();$public=in_array($path,['/api/login','/api/register','/api/session','/api/logout'],true);
    if(!in_array($req->getMethod(),['GET','HEAD','OPTIONS'],true)&&!hash_equals($_SESSION['csrf'],$req->getHeaderLine('X-CSRF-Token')))return jsonResponse(new Response(),['error'=>'Sesión vencida. Actualice la página.'],403);
    if(!$public){$u=liveUser();if(!$u)return jsonResponse(new Response(),['error'=>'Inicie sesión para continuar.'],401);$GLOBALS['currentUser']=$u;if($u['mustChangePassword']&&$path!=='/api/password')return deny(new Response(),'Cambie la contraseña temporal para continuar.');}
    return $handler->handle($req);
});
$app->get('/api/session',function($req,$res){$u=liveUser();return jsonResponse($res,['authenticated'=>(bool)$u,'user'=>$u?userPublic($u):null,'csrf'=>$_SESSION['csrf'],'profile'=>appProfile()]);});
$app->post('/api/register',function($req,$res){if(!authLimit('registration-ip:'.($_SERVER['REMOTE_ADDR']??''),10))return jsonResponse($res,['error'=>'Demasiados registros. Reintente en diez minutos.'],429);return jsonResponse($res,['ok'=>true,'user'=>createUser($req->getParsedBody(),true)],201);});
$app->post('/api/login',function($req,$res){
    $b=$req->getParsedBody();$email=emailInput($b['email']??'');
    if(!authLimit('login-ip:'.($_SERVER['REMOTE_ADDR']??''),30)||!authLimit('login-email:'.$email,10))return jsonResponse($res,['error'=>'Demasiados intentos. Espere diez minutos.'],429);
    $u=db()->odac_users->findOne(['email'=>$email]);$hash=$u['passwordHash']??accessHash();
    $password=loginPassword($b['password']??'');$ok=password_verify($password,$hash);
    if(!$ok||!$u||!$u['active']||($u['role']==='administrador'&&!in_array($u['id'],adminIds(),true)))return jsonResponse($res,['error'=>'Correo o contraseña incorrectos, o cuenta desactivada.'],401);
    if(password_needs_rehash($hash,PASSWORD_DEFAULT))db()->odac_users->updateOne(['id'=>$u['id'],'version'=>$u['version']],['$set'=>['passwordHash'=>password_hash($password,PASSWORD_DEFAULT)]]);
    beginUserSession($u);$GLOBALS['currentUser']=$u;audit('inicio_sesion',$u['id']);return jsonResponse($res,['ok'=>true,'user'=>userPublic($u),'csrf'=>$_SESSION['csrf']]);
});
$app->post('/api/logout',function($req,$res){$_SESSION=[];if(ini_get('session.use_cookies')){$c=session_get_cookie_params();setcookie(session_name(),'', ['expires'=>time()-3600,'path'=>$c['path'],'domain'=>$c['domain'],'secure'=>$c['secure'],'httponly'=>true,'samesite'=>'Strict']);}session_destroy();return jsonResponse($res,['ok'=>true]);});
$app->post('/api/password',function($req,$res){$u=actor();$b=$req->getParsedBody();if(!authLimit('password-change:'.$u['id'],10))return jsonResponse($res,['error'=>'Demasiados intentos. Espere diez minutos.'],429);if(!password_verify(loginPassword($b['currentPassword']??''),$u['passwordHash']))return deny($res,'La contraseña actual no coincide.');$p=passwordInput($b['password']??'');if(password_verify($p,$u['passwordHash']))throw new InvalidArgumentException('Use una contraseña diferente.');$r=db()->odac_users->updateOne(['id'=>$u['id'],'version'=>$u['version']],['$set'=>['passwordHash'=>password_hash($p,PASSWORD_DEFAULT),'mustChangePassword'=>false],'$inc'=>['version'=>1]]);if(!$r->getMatchedCount())return jsonResponse($res,['error'=>'La cuenta cambió. Inicie sesión nuevamente.'],409);$u['version']++;$u['mustChangePassword']=false;beginUserSession($u);audit('cambio_contraseña',$u['id']);return jsonResponse($res,['ok'=>true,'user'=>userPublic($u),'csrf'=>$_SESSION['csrf']]);});
$app->get('/api/health',function($req,$res){return jsonResponse($res,['mongo'=>true,'redis'=>(bool)redisClient(),'profile'=>appProfile()]);});
$app->get('/api/tickets',function($req,$res){processEscalations();return jsonResponse($res,visibleTickets());});
$app->get('/api/tickets/{id}',function($req,$res,$args){processEscalations();$t=visibleTicket($args['id']);return $t?jsonResponse($res,ticketPublic($t)):jsonResponse($res,['error'=>'Ticket no encontrado.'],404);});
$app->get('/api/departments',fn($req,$res)=>jsonResponse($res,DEPARTMENTS));
$app->get('/api/assignment/{id}',function($req,$res,$args){if(!isAdmin())return deny($res);$t=visibleTicket($args['id']);if(!$t)return jsonResponse($res,['error'=>'Ticket no encontrado.'],404);$list=assignmentCandidates($t['departmentId']??'reclamos');return jsonResponse($res,['suggestion'=>$list[0]??null,'candidates'=>$list]);});
$app->get('/api/notifications',function($req,$res){processEscalations();return jsonResponse($res,notificationsForActor());});
$app->post('/api/notifications/{id}',function($req,$res,$args){$list=notificationsForActor();if(!array_filter($list,fn($n)=>$n['id']===$args['id']))return jsonResponse($res,['error'=>'Aviso no encontrado.'],404);$id=actor()['id'].':'.$args['id'];db()->odac_notification_reads->createIndex(['id'=>1],['unique'=>true]);db()->odac_notification_reads->updateOne(['id'=>$id],['$setOnInsert'=>['id'=>$id,'readAt'=>time()]],['upsert'=>true]);return jsonResponse($res,['ok'=>true]);});
$app->get('/api/escalation',fn($req,$res)=>isAdmin()?jsonResponse($res,escalationPolicy()):deny($res));
$app->post('/api/escalation',function($req,$res){if(!isAdmin())return deny($res);$b=$req->getParsedBody();if(!is_bool($b['enabled']??null)||!is_int($b['warningMinutes']??null)||$b['warningMinutes']<1||$b['warningMinutes']>1440)throw new InvalidArgumentException('Regla inválida: entre 1 y 1440 minutos.');$rules=array_intersect_key($b,['enabled'=>1,'warningMinutes'=>1]);db()->odac_settings->updateOne(['id'=>'escalation'],['$set'=>['rules'=>$rules]],['upsert'=>true]);audit('reglas_escalamiento','escalation',$rules);return jsonResponse($res,['ok'=>true]);});
$app->get('/api/queue',function($req,$res){
    $u=actor();processEscalations();$key=connectionConfig()['namespace'].':queue-workflow-v1:'.$u['id'].':'.$u['version'].':';$r=redisClient();
    try{if($r){$key.=($r->get(connectionConfig()['namespace'].':version')?:'0');$cache=$r->get($key);if($cache!==false){$items=json_decode($cache,true,512,JSON_THROW_ON_ERROR);if(!is_array($items))throw new RuntimeException('Caché inválida.');$authorized=[];foreach($items as $cached){$fresh=visibleTicket($cached['id']);if($fresh&&$fresh['status']!=='Resuelto')$authorized[]=ticketPublic($fresh);}return jsonResponse($res,['items'=>$authorized,'source'=>'Redis','cachedAt'=>(int)$r->hGet($key.':meta','builtAt')]);}}}catch(Throwable $e){$r=null;}
    $items=array_values(array_filter(visibleTickets(),fn($t)=>$t['status']!=='Resuelto'));usort($items,fn($a,$b)=>queueScore($a)<=>queueScore($b));
    try{if($r)writeQueueCache($r,$key,$items);}catch(Throwable $e){}
    return jsonResponse($res,['items'=>$items,'source'=>'MongoDB']);
});
$app->get('/api/reports',fn($req,$res)=>isAdmin()?jsonResponse($res,reportData(visibleTickets())):deny($res));
$app->get('/api/agents',function($req,$res){if(actor()['role']==='cliente')return jsonResponse($res,[]);$filter=isAdmin()?['active'=>true,'role'=>['$in'=>['agente','administrador']]]:['id'=>actor()['id']];return jsonResponse($res,array_map(fn($u)=>['id'=>$u['id'],'name'=>$u['name'],'departmentId'=>$u['departmentId']??'reclamos','supervisor'=>$u['supervisor']??false],db()->odac_users->find($filter)->toArray()));});
$app->post('/api/tickets',function($req,$res){
    $u=actor();if($u['role']==='agente')return deny($res,'Los empleados trabajan únicamente los tickets asignados.');$body=$req->getParsedBody();if($u['role']!=='administrador')$body['clientId']=$u['clientId'];$body['requester']=$u['name'];$b=ticketInput($body);
    $existing=db()->odac_tickets->findOne(['id'=>$b['id']],['projection'=>['_id'=>0]]);if($existing){if(!visibleTicket($b['id']))return deny($res);return jsonResponse($res,ticketPublic($existing));}
    $client=db()->odac_clients->findOne(['id'=>$b['clientId']]);if(!$client)throw new InvalidArgumentException('Cliente no encontrado.');
    if($b['productId']&&!db()->odac_products->findOne(['id'=>$b['productId']]))throw new InvalidArgumentException('Producto o servicio no encontrado.');
    $b['requester']=$client['name'];$h=policy();$t=$b+['status'=>'Nuevo','assignee'=>'','assigneeId'=>'','createdBy'=>$u['id'],'waitingFor'=>'agente','lastActivityAt'=>time(),'createdAt'=>time(),'dueAt'=>time()+$h[$b['priority']]*3600,'history'=>[['date'=>time(),'text'=>'Solicitud registrada por '.$u['name']]]];
    db()->odac_tickets->createIndex(['id'=>1],['unique'=>true]);db()->odac_tickets->updateOne(['id'=>$b['id']],['$setOnInsert'=>$t],['upsert'=>true]);invalidate();audit('crear_ticket',$b['id']);return jsonResponse($res,ticketPublic(db()->odac_tickets->findOne(['id'=>$b['id']],['projection'=>['_id'=>0]])),201);
});
$app->patch('/api/tickets/{id}',function($req,$res,$args){
    $t=visibleTicket($args['id']);if(!$t)return jsonResponse($res,['error'=>'Ticket no encontrado.'],404);
    $b=$req->getParsedBody();$u=actor();if(!$b)throw new InvalidArgumentException('No hay cambios.');
    $note=cleanText($b['note']??'','nota',2000,false);$set=['lastActivityAt'=>time()];$visibility=$b['visibility']??'public';
    if(!in_array($visibility,['public','internal'],true))throw new InvalidArgumentException('Visibilidad inválida.');$kind='respuesta';
    if($u['role']==='cliente'){
        if(array_diff(array_keys($b),['note','action','rating','comment']))return deny($res);
        $action=$b['action']??'reply';
        if($action==='close'){
            if($t['status']!=='Solución propuesta')throw new InvalidArgumentException('Confirme primero una solución propuesta.');
            $rating=$b['rating']??null;if(!is_int($rating)||$rating<1||$rating>5)throw new InvalidArgumentException('Califique de 1 a 5.');
            $set+=['status'=>'Resuelto','closedAt'=>time(),'resolvedAt'=>$t['resolvedAt']??time(),'rating'=>$rating,'ratingComment'=>cleanText($b['comment']??'','comentario',1000,false),'waitingFor'=>'ninguno'];$kind='cierre';$note='Atención confirmada · '.$rating.'/5'.($set['ratingComment']?' · '.$set['ratingComment']:'');
        }elseif($action==='reopen'){
            if(!in_array($t['status'],['Solución propuesta','Resuelto'],true)||!$note)throw new InvalidArgumentException('Indique por qué necesita continuar.');
            $set+=['status'=>empty($t['assigneeId'])?'Nuevo':'En atención','waitingFor'=>'agente'];$kind='reapertura';
        }elseif($action==='reply'){
            if($t['status']==='Resuelto')throw new InvalidArgumentException('Reabra el ticket para responder.');if($t['status']==='Solución propuesta')$set['status']='En atención';if(!$note)throw new InvalidArgumentException('Escriba un comentario.');$set['waitingFor']='agente';
        }else throw new InvalidArgumentException('Acción inválida.');
    }else{
        if(array_diff(array_keys($b),['note','visibility','status','assigneeId','departmentId','waitingFor','reason']))throw new InvalidArgumentException('Campo no permitido.');
        $status=$b['status']??$t['status'];if(!is_string($status)||!in_array($status,STATUSES,true))throw new InvalidArgumentException('Estado inválido.');
        if($t['status']==='Resuelto'&&!isAdmin())throw new InvalidArgumentException('El ticket está cerrado.');
        if(!isAdmin()&&(isset($b['departmentId'])||(isset($b['assigneeId'])&&$b['assigneeId']!==$u['id'])))return deny($res,'Solo el administrador asigna o cambia de área.');
        // Legacy resolution submitted by an agent means a proposal, never customer closure.
        if(!isAdmin()&&$status==='Resuelto')$status='Solución propuesta';if(!isAdmin()&&!in_array($status,['En atención','Esperando cliente','Solución propuesta'],true)&&$status!==$t['status'])throw new InvalidArgumentException('El empleado puede atender o proponer una solución.');
        $department=departmentInput($b['departmentId']??$t['departmentId']??'reclamos');$aid=cleanText($b['assigneeId']??$t['assigneeId']??'','responsable',64,false);
        $a=$aid?db()->odac_users->findOne(['id'=>$aid,'active'=>true,'role'=>['$in'=>['agente','administrador']]]):null;
        if($aid&&(!$a||($a['role']==='agente'&&($a['departmentId']??'reclamos')!==$department)))throw new InvalidArgumentException('El responsable debe estar activo y pertenecer al área.');
        if($status!=='Nuevo'&&!$a)throw new InvalidArgumentException('Asigne un responsable.');
        $assignmentChanged=$aid!==($t['assigneeId']??'')||$department!==($t['departmentId']??'reclamos');
        $reason=cleanText($b['reason']??'','justificación',500,false);
        if($assignmentChanged&&!empty($t['assigneeId'])&&!$reason)throw new InvalidArgumentException('Justifique la reasignación.');
        if($status!==$t['status']&&in_array($status,['Solución propuesta','Resuelto'],true)&&(!$note||$visibility!=='public'))throw new InvalidArgumentException('Escriba una solución pública.');
        if($status==='Resuelto'&&$t['status']!=='Resuelto'&&!$reason)throw new InvalidArgumentException('Justifique el cierre administrativo.');
        $wait=$b['waitingFor']??($visibility==='internal'?($t['waitingFor']??'agente'):($status==='Solución propuesta'||$status==='Esperando cliente'?'cliente':'agente'));if(!in_array($wait,['cliente','agente'],true))throw new InvalidArgumentException('Turno inválido.');
        if(!$note&&!$assignmentChanged&&$status===$t['status']&&$wait===($t['waitingFor']??'agente'))throw new InvalidArgumentException('No hay cambios.');
        $set+=['status'=>$status,'departmentId'=>$department,'assigneeId'=>$aid,'assignee'=>$a['name']??'','waitingFor'=>$status==='Resuelto'?'ninguno':$wait];
        if(!isset($t['firstResponseAt'])&&$note&&$visibility==='public')$set['firstResponseAt']=time();
        if(in_array($status,['Solución propuesta','Resuelto'],true)){$set['resolvedAt']=$t['resolvedAt']??time();$kind='resolucion';}
        if($status==='Resuelto')$set['closedAt']=time();
        if($assignmentChanged){$kind='asignacion';$note=($note?$note.' · ':'').'Responsable: '.($a['name']??'Sin asignar').' · Área: '.DEPARTMENTS[$department].($reason?' · Motivo: '.$reason:'');}
        if($visibility==='internal'&&($status!==$t['status']||$assignmentChanged))throw new InvalidArgumentException('Guarde las notas internas por separado de los cambios de estado o responsable.');
    }
    $next=array_replace($t,$set);$event=workflowEvent($next,$u['name'].': '.($note?:'Actualización').' · '.$next['status'],$kind,$visibility);
    $update=['$set'=>$set,'$push'=>['history'=>$event]];
    if(isset($set['status'])&&!in_array($set['status'],['Solución propuesta','Resuelto'],true))$update['$unset']=['resolvedAt'=>'','closedAt'=>'','rating'=>'','ratingComment'=>''];
    $r=db()->odac_tickets->updateOne(['id'=>$t['id'],'history'=>$t['history']],$update);
    if(!$r->getMatchedCount())return jsonResponse($res,['error'=>'El ticket cambió. Actualice y reintente.'],409);
    invalidate();audit('actualizar_ticket',$t['id'],['kind'=>$kind,'from'=>$t['assigneeId']??'','to'=>$next['assigneeId']??'','visibility'=>$visibility]);return jsonResponse($res,['ok'=>true]);
});
$app->delete('/api/tickets/{id}',function($req,$res,$args){if(!isAdmin())return deny($res);db()->odac_tickets->deleteOne(['id'=>$args['id']]);invalidate();audit('eliminar_ticket',$args['id']);return jsonResponse($res,['ok'=>true]);});
function clientFields(array $b):array{$c=['name'=>cleanText($b['name']??'','nombre',100),'email'=>cleanText($b['email']??'','correo',160,false),'phone'=>cleanText($b['phone']??'','teléfono',40,false),'company'=>cleanText($b['company']??'','empresa',120,false)];if($c['email']&&!filter_var($c['email'],FILTER_VALIDATE_EMAIL))throw new InvalidArgumentException('Correo inválido.');if(!$c['email']&&!$c['phone'])throw new InvalidArgumentException('Indique correo o teléfono.');return $c;}
$app->get('/api/clients',fn($req,$res)=>jsonResponse($res,db()->odac_clients->find(isAdmin()?[]:(actor()['role']==='cliente'?['id'=>actor()['clientId']]:['id'=>['$in'=>array_column(visibleTickets(),'clientId')]]),['projection'=>['_id'=>0]])->toArray()));
$app->get('/api/products',fn($req,$res)=>jsonResponse($res,documents('odac_products')));
$app->post('/api/clients',function($req,$res){if(!isAdmin())return deny($res);$b=$req->getParsedBody();$c=clientFields($b);$c['id']=cleanText($b['id']??'','identificador',64);$c['createdAt']=time();db()->odac_clients->createIndex(['id'=>1],['unique'=>true]);if(db()->odac_clients->findOne(['id'=>$c['id']]))throw new InvalidArgumentException('Cliente existente.');db()->odac_clients->insertOne($c);audit('crear_cliente',$c['id']);unset($c['_id']);return jsonResponse($res,$c,201);});
$app->patch('/api/clients/{id}',function($req,$res,$args){if(!isAdmin())return deny($res);$c=clientFields($req->getParsedBody());$result=db()->odac_clients->updateOne(['id'=>$args['id']],['$set'=>$c]);if(!$result->getMatchedCount())return jsonResponse($res,['error'=>'Cliente no encontrado.'],404);audit('editar_cliente',$args['id']);return jsonResponse($res,['ok'=>true]);});
$app->delete('/api/clients/{id}',function($req,$res,$args){if(!isAdmin())return deny($res);if(db()->odac_users->countDocuments(['clientId'=>$args['id']])||db()->odac_tickets->countDocuments(['clientId'=>$args['id']]))throw new InvalidArgumentException('El cliente tiene una cuenta o tickets vinculados.');db()->odac_clients->deleteOne(['id'=>$args['id']]);audit('eliminar_cliente',$args['id']);return jsonResponse($res,['ok'=>true]);});
foreach(['POST','PATCH','DELETE'] as $method){$register=strtolower($method);$path='/api/products'.($method==='POST'?'':'/{id}');$app->$register($path,function($req,$res,$args=[])use($method){if(!isAdmin())return deny($res);$b=$method==='DELETE'?[]:$req->getParsedBody();$id=$method==='POST'?cleanText($b['id']??'','código',64):$args['id'];if($method==='DELETE'){if(db()->odac_tickets->countDocuments(['productId'=>$id]))throw new InvalidArgumentException('Hay tickets vinculados. Marque el producto como retirado.');db()->odac_products->deleteOne(['id'=>$id]);}else{$p=[];foreach(['name','type','category','reference','status'] as $k)$p[$k]=cleanText($b[$k]??'',$k,100);if(!in_array($p['status'],['Disponible','Suspendido','Retirado'],true))throw new InvalidArgumentException('Estado inválido.');if($method==='POST'){db()->odac_products->createIndex(['id'=>1],['unique'=>true]);if(db()->odac_products->findOne(['id'=>$id]))throw new InvalidArgumentException('Código existente.');db()->odac_products->insertOne(['id'=>$id,'createdAt'=>time()]+$p);}else{$result=db()->odac_products->updateOne(['id'=>$id],['$set'=>$p]);if(!$result->getMatchedCount())return jsonResponse($res,['error'=>'Producto no encontrado.'],404);}}audit('catalogo_'.$method,$id);return jsonResponse($res,['ok'=>true]);});}
$app->get('/api/users',fn($req,$res)=>isAdmin()?jsonResponse($res,array_map('userPublic',documents('odac_users'))):deny($res));
$app->post('/api/users',fn($req,$res)=>isAdmin()?jsonResponse($res,createUser($req->getParsedBody()),201):deny($res));
$app->patch('/api/users/{id}',function($req,$res,$args){
    if(!isAdmin())return deny($res);$u=db()->odac_users->findOne(['id'=>$args['id']]);if(!$u)return jsonResponse($res,['error'=>'Usuario no encontrado.'],404);$b=$req->getParsedBody();$role=$b['role']??$u['role'];$active=$b['active']??$u['active'];if(!in_array($role,USER_ROLES,true)||!is_bool($active))throw new InvalidArgumentException('Rol o estado inválido.');
    $name=isset($b['name'])?cleanText($b['name'],'nombre',100):$u['name'];$email=isset($b['email'])?emailInput($b['email']):$u['email'];$other=db()->odac_users->findOne(['email'=>$email]);if($other&&$other['id']!==$u['id'])throw new InvalidArgumentException('Ese correo ya está registrado.');
    $team=teamFields($b,$u);if((!$active||$role!==$u['role']||$team['departmentId']!==($u['departmentId']??'reclamos'))&&db()->odac_tickets->countDocuments(['assigneeId'=>$u['id'],'status'=>['$ne'=>'Resuelto']]))throw new InvalidArgumentException('Reasigne sus tickets abiertos antes de cambiar el área, rol o desactivar.');$resetHash=isset($b['password'])?password_hash(passwordInput($b['password']),PASSWORD_DEFAULT):null;
    $removed=$u['role']==='administrador'&&(!$active||$role!=='administrador');if($removed&&!reserveAdminRemoval($u))return deny($res,'Debe conservar al menos un administrador activo.');
    try{$set=['role'=>$role,'active'=>$active,'name'=>$name,'email'=>$email]+$team;if(isset($b['password'])){$set['passwordHash']=$resetHash;$set['mustChangePassword']=true;}$r=db()->odac_users->updateOne(['id'=>$u['id'],'version'=>$u['version']],['$set'=>$set,'$inc'=>['version'=>1]]);if(!$r->getMatchedCount()){if($removed)restoreAdmin($u);return jsonResponse($res,['error'=>'La cuenta cambió. Actualice y reintente.'],409);}if($active&&$role==='administrador')db()->odac_settings->updateOne(['id'=>'security'],['$addToSet'=>['adminIds'=>$u['id']]]);}catch(Throwable $e){if($removed)restoreAdmin($u);throw $e;}
    audit(isset($b['password'])?'restablecer_contraseña':'editar_usuario',$u['id'],['role'=>$role,'active'=>$active]);return jsonResponse($res,['ok'=>true]);
});
$app->get('/api/settings',fn($req,$res)=>jsonResponse($res,['hours'=>policy()]));
$app->post('/api/settings',function($req,$res){if(!isAdmin())return deny($res);$hours=$req->getParsedBody()['hours']??[];foreach(SLA_HOURS as $p=>$default)if(!isset($hours[$p])||!is_int($hours[$p])||$hours[$p]<1||$hours[$p]>720)throw new InvalidArgumentException('Cada SLA debe tener entre 1 y 720 horas.');$hours=array_intersect_key($hours,SLA_HOURS);db()->odac_settings->updateOne(['id'=>'sla'],['$set'=>['hours'=>$hours]],['upsert'=>true]);audit('configurar_sla','sla',$hours);return jsonResponse($res,['ok'=>true]);});
$app->get('/api/audit',fn($req,$res)=>isAdmin()?jsonResponse($res,db()->odac_audit->find([],['sort'=>['date'=>-1],'limit'=>200,'projection'=>['_id'=>0]])->toArray()):deny($res));
