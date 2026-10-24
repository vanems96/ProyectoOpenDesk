<?php
const USER_ROLES=['cliente','agente','administrador'];
function userPublic(array $u):array{return array_intersect_key($u,array_flip(['id','name','email','role','active','clientId','mustChangePassword','createdAt','departmentId','supervisor']))+['cacheVersion'=>$u['version']??1];}
function passwordInput(mixed $p):string{if(!is_string($p)||str_contains($p,"\0")||preg_match_all('/./us',$p)<12||strlen($p)>72)throw new InvalidArgumentException('La contraseña debe tener al menos 12 caracteres y como máximo 72 bytes, sin caracteres nulos.');return $p;}
function loginPassword(mixed $p):string{if(!is_string($p)||strlen($p)>72||str_contains($p,"\0"))throw new InvalidArgumentException('Contraseña inválida.');return $p;}
function emailInput(mixed $email):string{$email=strtolower(cleanText($email,'correo',160));if(!filter_var($email,FILTER_VALIDATE_EMAIL))throw new InvalidArgumentException('Correo inválido.');return $email;}
function userId():string{return bin2hex(random_bytes(16));}
function initSecurity():void{db()->odac_users->createIndex(['id'=>1],['unique'=>true]);db()->odac_users->createIndex(['email'=>1],['unique'=>true]);db()->odac_auth_limits->createIndex(['id'=>1],['unique'=>true]);db()->odac_settings->createIndex(['id'=>1],['unique'=>true]);db()->odac_settings->updateOne(['id'=>'security'],['$setOnInsert'=>['id'=>'security','adminIds'=>[]]],['upsert'=>true]);}
function adminIds():array{return db()->odac_settings->findOne(['id'=>'security'])['adminIds']??[];}
function liveUser():?array{
    if(empty($_SESSION['userId']))return null;
    $u=db()->odac_users->findOne(['id'=>$_SESSION['userId']]);
    $expired=time()-($_SESSION['lastSeen']??0)>1800||time()-($_SESSION['startedAt']??0)>28800;
    if(!$u||empty($u['active'])||$expired||($u['version']??1)!==($_SESSION['userVersion']??0)||($u['role']==='administrador'&&!in_array($u['id'],adminIds(),true))){unset($_SESSION['userId'],$_SESSION['userVersion']);return null;}
    $_SESSION['lastSeen']=time();return $u;
}
function actor():array{return $GLOBALS['currentUser']??throw new RuntimeException('Sin usuario.');}
function isAdmin():bool{return actor()['role']==='administrador';}
function allowedRole(array $roles):bool{return in_array(actor()['role'],$roles,true);}
function deny(Response $res,string $msg='No tiene permiso para esta operación.'):Response{return jsonResponse($res,['error'=>$msg],403);}
function audit(string $action,string $target,array $details=[]):void{db()->odac_audit->insertOne(['id'=>userId(),'date'=>time(),'actorId'=>$GLOBALS['currentUser']['id']??'terminal','actorName'=>$GLOBALS['currentUser']['name']??'Instalación','action'=>$action,'target'=>$target,'details'=>$details]);}
function ticketFilter():array{$u=actor();if($u['role']==='administrador')return [];if($u['role']==='cliente')return ['clientId'=>$u['clientId']];return ['assigneeId'=>$u['id']];}
function visibleTicket(string $id):?array{return db()->odac_tickets->findOne(['id'=>$id]+ticketFilter(),['projection'=>['_id'=>0]]);}
function visibleTickets():array{return array_map('ticketPublic',db()->odac_tickets->find(ticketFilter(),['projection'=>['_id'=>0]])->toArray());}
function policy():array{return db()->odac_settings->findOne(['id'=>'sla'])['hours']??SLA_HOURS;}
function authLimit(string $key,int $max):bool{
    $key=connectionConfig()['namespace'].':auth:'.hash('sha256',$key);$r=redisClient();
    try{if($r){$n=$r->incr($key);if($n===1)$r->expire($key,600);return $n<=$max;}}catch(Throwable $e){}
    // Contingencia duradera: borrar cookies no elimina el contador MongoDB.
    db()->odac_auth_limits->createIndex(['id'=>1],['unique'=>true]);
    db()->odac_auth_limits->updateOne(['id'=>$key],['$setOnInsert'=>['id'=>$key,'attempts'=>0,'expiresAt'=>time()+600]],['upsert'=>true]);
    db()->odac_auth_limits->updateOne(['id'=>$key,'expiresAt'=>['$lte'=>time()]],['$set'=>['attempts'=>0,'expiresAt'=>time()+600]]);
    db()->odac_auth_limits->updateOne(['id'=>$key],['$inc'=>['attempts'=>1]]);
    return (db()->odac_auth_limits->findOne(['id'=>$key])['attempts']??$max+1)<=$max;
}
function createUser(array $b,bool $public=false):array{
    $email=emailInput($b['email']??'');$name=cleanText($b['name']??'','nombre',100);$password=passwordInput($b['password']??'');
    $phone=cleanText($b['phone']??'','teléfono',40,false);
    $role=$public?'cliente':($b['role']??'cliente');if(!in_array($role,USER_ROLES,true))throw new InvalidArgumentException('Rol inválido.');
    initSecurity();if(db()->odac_users->findOne(['email'=>$email]))throw new InvalidArgumentException('No se pudo registrar esa cuenta. Revise los datos o inicie sesión.');
    $team=teamFields($public?[]:$b);$id=userId();$u=['id'=>$id,'name'=>$name,'email'=>$email,'role'=>$role,'active'=>true,'clientId'=>$id,'passwordHash'=>password_hash($password,PASSWORD_DEFAULT),'version'=>1,'mustChangePassword'=>!$public,'createdAt'=>time()]+$team;
    db()->odac_clients->createIndex(['id'=>1],['unique'=>true]);
    db()->odac_users->insertOne($u);
    db()->odac_clients->updateOne(['id'=>$id],['$setOnInsert'=>['id'=>$id,'name'=>$name,'email'=>$email,'phone'=>$phone,'company'=>'','createdAt'=>time()]],['upsert'=>true]);
    if($role==='administrador')db()->odac_settings->updateOne(['id'=>'security'],['$addToSet'=>['adminIds'=>$id]]);
    audit($public?'registro_cliente':'crear_usuario',$id,['role'=>$role]);return userPublic($u);
}
function reserveAdminRemoval(array $u):bool{
    if(!in_array($u['id'],adminIds(),true))return true;
    // Condición y retirada en UN documento: dos bajas simultáneas no eliminan al último administrador.
    $r=db()->odac_settings->updateOne(['id'=>'security','adminIds'=>$u['id'],'adminIds.1'=>['$exists'=>true]],['$pull'=>['adminIds'=>$u['id']]]);
    return $r->getMatchedCount()>0;
}
function restoreAdmin(array $u):void{$now=db()->odac_users->findOne(['id'=>$u['id']]);if($now&&$now['active']&&$now['role']==='administrador')db()->odac_settings->updateOne(['id'=>'security'],['$addToSet'=>['adminIds'=>$u['id']]]);}
function beginUserSession(array $u):void{session_regenerate_id(true);$_SESSION['userId']=$u['id'];$_SESSION['userVersion']=$u['version']??1;$_SESSION['startedAt']=time();$_SESSION['lastSeen']=time();$_SESSION['csrf']=bin2hex(random_bytes(24));}
function bootstrapAdmin(string $email):void{
    initSecurity();if(adminIds())throw new InvalidArgumentException('Ya existe un administrador. Créelo desde la pantalla Usuarios.');
    $email=emailInput($email);$id='initial-administrator';$hash=accessHash();if(!$hash)throw new RuntimeException('Sin contraseña inicial.');
    db()->odac_users->updateOne(['id'=>$id],['$setOnInsert'=>['id'=>$id,'name'=>'Administrador inicial','email'=>$email,'role'=>'administrador','active'=>true,'clientId'=>$id,'passwordHash'=>$hash,'version'=>1,'mustChangePassword'=>true,'createdAt'=>time()]],['upsert'=>true]);
    db()->odac_clients->updateOne(['id'=>$id],['$setOnInsert'=>['id'=>$id,'name'=>'Administrador inicial','email'=>$email,'phone'=>'','company'=>'','createdAt'=>time()]],['upsert'=>true]);
    db()->odac_settings->updateOne(['id'=>'security'],['$addToSet'=>['adminIds'=>$id]]);audit('administrador_inicial',$id);
    echo "Administrador inicial preparado. Use su correo y la contraseña del archivo privado.\n";
}

// Read models are rebuilt only after the current MongoDB authorization check.
function ticketPublic(array $t):array{
    unset($t['_id']);$t['departmentId']=$t['departmentId']??'reclamos';$t['waitingFor']=$t['waitingFor']??($t['status']==='Esperando cliente'?'cliente':'agente');
    $client=actor()['role']==='cliente';$history=[];
    foreach($t['history']??[] as $h){if($client&&($h['visibility']??'public')==='internal')continue;unset($h['recipients']);$history[]=$h;}
    $t['history']=$history;if($client)unset($t['escalationReason']);return $t;
}
function assignmentCandidates(string $department,bool $supervisors=false):array{
    $rows=[];foreach(db()->odac_users->find(['active'=>true,'role'=>'agente'])->toArray() as $u){
        if(($u['departmentId']??'reclamos')!==$department||($supervisors&&empty($u['supervisor'])))continue;
        $load=db()->odac_tickets->countDocuments(['assigneeId'=>$u['id'],'status'=>['$ne'=>'Resuelto']]);
        $rows[]=['id'=>$u['id'],'name'=>$u['name'],'openCases'=>$load,'departmentId'=>$department,'supervisor'=>(bool)($u['supervisor']??false)];
    }usort($rows,fn($a,$b)=>($a['openCases']<=>$b['openCases'])?:strcmp($a['id'],$b['id']));return $rows;
}
function ticketRecipients(array $t):array{
    $ids=[];foreach(db()->odac_users->find(['clientId'=>$t['clientId'],'active'=>true,'role'=>'cliente'])->toArray() as $u)$ids[]=$u['id'];
    if(!empty($t['assigneeId']))$ids[]=$t['assigneeId'];return array_values(array_unique($ids));
}
function workflowEvent(array $t,string $text,string $kind='respuesta',string $visibility='public'):array{
    return ['id'=>userId(),'date'=>time(),'text'=>$text,'kind'=>$kind,'visibility'=>$visibility,'actorId'=>$GLOBALS['currentUser']['id']??'sistema','recipients'=>ticketRecipients($t)];
}
function escalationPolicy():array{return db()->odac_settings->findOne(['id'=>'escalation'])['rules']??['enabled'=>true,'warningMinutes'=>60];}
function processEscalations():array{
    $rules=escalationPolicy();$counts=['warnings'=>0,'escalations'=>0];if(!$rules['enabled'])return $counts;
    foreach(db()->odac_tickets->find(['status'=>['$ne'=>'Resuelto'],'dueAt'=>['$lte'=>time()+$rules['warningMinutes']*60]])->toArray() as $t){
        if($t['status']==='Solución propuesta')continue;$remaining=$t['dueAt']-time();if($remaining>$rules['warningMinutes']*60)continue;
        $stage=$remaining<=0?'overdue':'warning';if(($t['slaStage']??'')===$stage||($t['slaStage']??'')==='overdue')continue;
        $set=['slaStage'=>$stage,'lastActivityAt'=>time()];$department=$t['departmentId']??'reclamos';
        $current=!empty($t['assigneeId'])?db()->odac_users->findOne(['id'=>$t['assigneeId'],'active'=>true]):null;
        $candidates=array_values(array_filter(assignmentCandidates($department,true),fn($a)=>$a['id']!==($t['assigneeId']??'')));
        if($current&&!empty($current['supervisor'])&&($current['departmentId']??'reclamos')===$department)$candidates=[];
        $reason=$stage==='overdue'?'SLA vencido':'SLA próximo a vencer';
        if($candidates){$a=$candidates[0];$set+=['assigneeId'=>$a['id'],'assignee'=>$a['name'],'escalatedAt'=>time(),'escalationReason'=>$reason];}
        $next=array_replace($t,$set);$event=workflowEvent($next,$reason.($candidates?' · Derivado al supervisor '.$a['name']:' · Requiere atención del supervisor o administrador'),'escalamiento','internal');
        $event['actorId']='sistema';$event['recipients']=array_values(array_unique(array_merge($event['recipients'],adminIds())));
        $r=db()->odac_tickets->updateOne(['id'=>$t['id'],'history'=>$t['history']],['$set'=>$set,'$push'=>['history'=>$event]]);
        if($r->getMatchedCount()){$counts['warnings']++;if($candidates)$counts['escalations']++;invalidate();db()->odac_audit->insertOne(['id'=>userId(),'date'=>time(),'actorId'=>'sistema','actorName'=>'Regla automática SLA','action'=>'escalamiento_sla','target'=>$t['id'],'details'=>['stage'=>$stage,'from'=>$t['assigneeId']??'','to'=>$set['assigneeId']??null]]);}
    }return $counts;
}
function notificationsForActor():array{
    $u=actor();$out=[];
    foreach(db()->odac_tickets->find(ticketFilter(),['projection'=>['_id'=>0]])->toArray() as $t){foreach($t['history']??[] as $h){
        if(!in_array($u['id'],$h['recipients']??[],true)||($h['actorId']??'')===$u['id']||($u['role']==='cliente'&&($h['visibility']??'public')==='internal'))continue;
        $key=$u['id'].':'.$h['id'];$out[]=['id'=>$h['id'],'ticketId'=>$t['id'],'subject'=>$t['subject'],'text'=>$h['text'],'date'=>$h['date'],'kind'=>$h['kind'],'read'=>(bool)db()->odac_notification_reads->findOne(['id'=>$key])];
    }}usort($out,fn($a,$b)=>$b['date']<=>$a['date']);return array_slice($out,0,100);
}
