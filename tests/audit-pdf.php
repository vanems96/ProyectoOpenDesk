<?php
// Real handlers and PDF serializer, with the isolated fixtures used by roles.php.
require __DIR__.'/roles.php';
$before=$checks;
foreach([$c2,$agent,$supervisor] as $u){useUser(db()->odac_users->findOne(['id'=>$u['id']])) ;callApi('POST','audit/pdf',['ids'=>['sample-audit-0']],403);}
useUser($admin);
foreach([[],['ids'=>[]],['ids'=>array_fill(0,51,'a')],['ids'=>['a','a']],['ids'=>[['$ne'=>'']]],['ids'=>['bad/id']],['ids'=>['missing']],['ids'=>'a']] as $body)callApi('POST','audit/pdf',$body,isset($body['ids'])&&$body['ids']===['missing']?409:422);
callApi('POST','audit/pdf',['ids'=>['sample-audit-0']],403,false);
for($i=0;$i<50;$i++)db()->odac_audit->insertOne(['id'=>'sample-audit-'.$i,'date'=>1793808000+$i,'actorName'=>str_repeat('María Muñoz ',5),'action'=>str_repeat('asignación_',5),'target'=>'ticket-'.$i.'-'.str_repeat('registro',15),'details'=>['private'=>'MUST_NOT_BE_EXPORTED']]);
foreach([10,20,30,50] as $size){
    $ids=array_map(fn($n)=>'sample-audit-'.$n,range(0,$size-1));
    $response=$app->dispatch('POST','audit/pdf',['ids'=>$ids]);
    check($response->status===200,'PDF successful '.$size);
    check($response->headers['Content-Type']==='application/pdf','PDF MIME');
    check($response->headers['Cache-Control']==='no-store','PDF not cached');
    check(str_starts_with($response->body,'%PDF-1.4'),'PDF header');
    check(str_contains($response->body,'Vista seleccionada: '.$size.' registros.'),'PDF selected count');
    check(!str_contains($response->body,'MUST_NOT_BE_EXPORTED'),'Audit details not exported');
    check(str_contains($response->body,'xref')&&str_ends_with($response->body,"%%EOF\n"),'PDF cross reference and end marker');
    if($size===50&&getenv('AUDIT_PDF_SAMPLE'))file_put_contents(getenv('AUDIT_PDF_SAMPLE'),$response->body);
}
echo 'PASS: '.($checks-$before)." audit PDF assertions; roles, CSRF, bounds, invalid IDs, selected count and private details.\n";
