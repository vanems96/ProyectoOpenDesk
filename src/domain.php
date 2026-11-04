<?php
declare(strict_types=1);
const SLA_HOURS = ['Alta'=>4, 'Media'=>24, 'Baja'=>72];
const STATUSES = ['Nuevo', 'En atención', 'Esperando cliente', 'Solución propuesta', 'Resuelto'];
function cleanText(mixed $value, string $name, int $max = 200, bool $required = true): string {
    if (!is_string($value)) throw new InvalidArgumentException("Campo inválido: $name");
    $value = trim($value);
    if (($required && $value === '') || strlen($value) > $max) throw new InvalidArgumentException("Revise el campo $name (máximo $max caracteres).");
    return $value;
}
function ticketInput(array $b): array {
    $priority = $b['priority'] ?? 'Media';
    if (!is_string($priority)||!isset(SLA_HOURS[$priority])) throw new InvalidArgumentException('Prioridad inválida.');
    $id = cleanText($b['id'] ?? '', 'identificador', 64);
    if (!preg_match('/^[a-zA-Z0-9-]{8,64}$/', $id)) throw new InvalidArgumentException('Identificador inválido.');
    $category=$b['category']??'Consulta';
    if(!in_array($category,['Consulta','Reclamo','Devolución','Garantía','Problema con pedido'],true))throw new InvalidArgumentException('Categoría inválida.');
    return ['clientId'=>cleanText($b['clientId']??'', 'cliente',64),'category'=>$category,'orderRef'=>cleanText($b['orderRef']??'', 'pedido',100,false),'id'=>$id, 'subject'=>cleanText($b['subject'] ?? '', 'asunto', 160), 'description'=>cleanText($b['description'] ?? '', 'descripción', 4000), 'requester'=>cleanText($b['requester'] ?? '', 'solicitante', 100), 'productId'=>cleanText($b['productId'] ?? '', 'producto o servicio', 64, false), 'priority'=>$priority,'departmentId'=>departmentInput($b['departmentId']??'reclamos')];
}
function queueScore(array $t): float {
    return (array_search($t['priority'], ['Alta','Media','Baja'], true) * 100000000000) + $t['dueAt'];
}
function reportData(array $tickets): array {
    $resolved = array_values(array_filter($tickets, fn($t)=>$t['status']==='Resuelto'));
    $ok = count(array_filter($resolved, fn($t)=>$t['resolvedAt'] <= $t['dueAt']));
    $responses = array_values(array_filter($tickets, fn($t)=>isset($t['firstResponseAt'])));
    return ['total'=>count($tickets), 'open'=>count($tickets)-count($resolved), 'resolved'=>count($resolved), 'compliance'=>count($resolved) ? round(100*$ok/count($resolved),1) : null, 'averageResponseMinutes'=>count($responses) ? round(array_sum(array_map(fn($t)=>($t['firstResponseAt']-$t['createdAt'])/60,$responses))/count($responses),1) : null, 'overdue'=>count(array_filter($tickets,fn($t)=>!in_array($t['status'],['Resuelto','Solución propuesta'],true) && $t['dueAt']<time()))];
}

const DEPARTMENTS=['ventas'=>'Ventas','garantias'=>'Garantías','entregas'=>'Entregas','reclamos'=>'Reclamos'];
function departmentInput(mixed $id):string{if(!is_string($id)||!isset(DEPARTMENTS[$id]))throw new InvalidArgumentException('Departamento inválido.');return $id;}
function teamFields(array $b,array $old=[]):array{$id=departmentInput($b['departmentId']??$old['departmentId']??'reclamos');$supervisor=$b['supervisor']??$old['supervisor']??false;if(!is_bool($supervisor))throw new InvalidArgumentException('Supervisor inválido.');return ['departmentId'=>$id,'supervisor'=>$supervisor];}


// Standalone PDF writer: fixed fonts, escaped text, no HTML or remote resources.
function auditPdfDocument(array $rows):string{
    $escape=static function(string $s):string{
        $s=preg_replace('/[\x00-\x1f\x7f]/u',' ',$s)??'';
        $s=iconv('UTF-8','Windows-1252//TRANSLIT',$s);
        return str_replace(['\\','(',')'],['\\\\','\\(','\\)'],$s===false?'':$s);
    };
    $text=static fn(float $x,float $y,string $s,int $size=9):string=>'BT /F1 '.$size.' Tf 1 0 0 1 '.$x.' '.$y.' Tm ('.$escape($s).") Tj ET\n";
    $zone=new DateTimeZone('America/Costa_Rica');
    $format=static fn(int $t):string=>(new DateTimeImmutable('@'.$t))->setTimezone($zone)->format('d/m/Y H:i');
    $pages=[];$content='';$y=0;
    $begin=static function()use(&$content,&$y,$text,$format,$rows){
        $content="0.09 0.21 0.18 rg\n".$text(32,560,'OpenDesk - Auditoría',18).$text(32,540,'Vista seleccionada: '.count($rows).' registros. Generado: '.$format(time()).' (Costa Rica)',10);
        $content.="0.93 0.95 0.92 rg 32 503 778 24 re f 0.09 0.21 0.18 rg\n";
        foreach([[38,'Fecha'],[143,'Usuario'],[288,'Acción'],[468,'Registro']] as [$x,$s])$content.=$text($x,512,$s,10);
        $y=491;
    };
    $finish=static function()use(&$pages,&$content,$text){$content.=$text(32,22,'OpenDesk - Auditoría | Página '.(count($pages)+1),8);$pages[]=$content;};
    $begin();
    foreach($rows as $row){
        $values=[$format((int)$row['date']),(string)$row['actorName'],(string)$row['action'],(string)$row['target']];
        $limits=[18,25,31,61];$lines=[];
        foreach($values as $i=>$value){$value=iconv('UTF-8','Windows-1252//TRANSLIT',preg_replace('/[\x00-\x1f\x7f]/u',' ',$value)??'');$parts=explode("\n",wordwrap($value===false?'':$value,$limits[$i],"\n",true));$lines[]=array_map(static fn($s)=>iconv('Windows-1252','UTF-8',$s),$parts);}
        $height=max(array_map('count',$lines))*12+12;
        if($y-$height<40){$finish();$begin();}
        foreach($lines as $i=>$parts)foreach($parts as $j=>$part)$content.=$text([38,143,288,468][$i],$y-$j*12,(string)$part);
        $y-=$height;$content.="0.8 0.85 0.82 RG 32 ".($y+12).' m 810 '.($y+12)." l S\n";
    }
    $finish();$objects=[1=>'<< /Type /Catalog /Pages 2 0 R >>',3=>'<< /Type /Font /Subtype /Type1 /BaseFont /Courier /Encoding /WinAnsiEncoding >>'];$kids=[];
    foreach($pages as $i=>$stream){$page=4+$i*2;$obj=$page+1;$kids[]="$page 0 R";$objects[$page]="<< /Type /Page /Parent 2 0 R /MediaBox [0 0 842 595] /Resources << /Font << /F1 3 0 R >> >> /Contents $obj 0 R >>";$objects[$obj]='<< /Length '.strlen($stream).">>\nstream\n".$stream."endstream";}
    $objects[2]='<< /Type /Pages /Count '.count($pages).' /Kids ['.implode(' ',$kids).'] >>';ksort($objects);$pdf="%PDF-1.4\n%\xe2\xe3\xcf\xd3\n";$offsets=[0];
    foreach($objects as $n=>$object){$offsets[$n]=strlen($pdf);$pdf.="$n 0 obj\n$object\nendobj\n";}
    $xref=strlen($pdf);$pdf.="xref\n0 ".(count($objects)+1)."\n0000000000 65535 f \n";foreach($objects as $n=>$_)$pdf.=sprintf("%010d 00000 n \n",$offsets[$n]);
    return $pdf.'trailer << /Size '.(count($objects)+1)." /Root 1 0 R >>\nstartxref\n$xref\n%%EOF\n";
}
