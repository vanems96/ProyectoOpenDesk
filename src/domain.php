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
