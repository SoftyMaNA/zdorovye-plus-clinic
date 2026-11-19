<?php
declare(strict_types=1);
require __DIR__.'/../src/database.php';
require __DIR__.'/../src/AppointmentService.php';
if (!str_ends_with(getenv('DB_NAME') ?: '', '_test')) {
    fwrite(STDERR,"Tests require a separate database ending in _test\n"); exit(2);
}
$db=database(); $service=new AppointmentService($db);
function ensure(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function scalar(PDO $db,string $sql,array $args=[]): mixed {$q=$db->prepare($sql);$q->execute($args);return $q->fetchColumn();}
function rejected(callable $f,string $message): void {
    try {$f();} catch (DomainException $e) {ensure($e->getMessage()===$message,'Unexpected refusal: '.$e->getMessage());return;}
    throw new RuntimeException('Operation was accepted unexpectedly');
}
function clean(PDO $db): void {
    // Только специально выделенная тестовая БД, проверенная выше.
    foreach(['audit_log','medical_records','lab_results','appointments','patients','doctors'] as $t) $db->exec('DELETE FROM '.$t);
}
function fixtures(PDO $db): array {
    clean($db);
    $db->exec("INSERT INTO patients(patient_code) VALUES('TEST-P001'),('TEST-P002')");
    $p=$db->query('SELECT id FROM patients ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
    $db->exec("INSERT INTO doctors(doctor_code,specialty) VALUES('TEST-D001','Терапия'),('TEST-D002','Кардиология')");
    $d=$db->query('SELECT id FROM doctors ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
    return array_map('intval',[$p[0],$p[1],$d[0],$d[1]]);
}
$tests=[
 '1'=>['Создание записи на свободный интервал',function()use($db,$service){
    [$p,$p2,$d,$d2]=fixtures($db);
    $id=$service->book($p,$d,'2030-01-15 10:00:00','2030-01-15 10:30:00');
    ensure($id>0,'No id');ensure(scalar($db,'SELECT status FROM appointments WHERE id=?',[$id])==='scheduled','Wrong status');
    ensure((int)scalar($db,"SELECT COUNT(*) FROM audit_log WHERE appointment_id=? AND event_type='created'",[$id])===1,'No audit event');
    echo "Запись сохранена; статус scheduled; аудит created: 1\n";
 }],
 '2'=>['Запрет пересечений и разрешение соседнего интервала',function()use($db,$service){
    [$p,$p2,$d,$d2]=fixtures($db);
    $service->book($p,$d,'2030-01-15 10:00:00','2030-01-15 10:30:00');
    foreach([['10:10:00','10:40:00'],['09:50:00','10:10:00'],['09:50:00','10:40:00']] as [$a,$b])
      rejected(fn()=>$service->book($p2,$d,'2030-01-15 '.$a,'2030-01-15 '.$b),'Врач или пациент уже занят в это время');
    rejected(fn()=>$service->book($p,$d2,'2030-01-15 10:00:00','2030-01-15 10:30:00'),'Врач или пациент уже занят в это время');
    ensure((int)scalar($db,'SELECT COUNT(*) FROM appointments')===1,'Refusal wrote data');
    $service->book($p2,$d,'2030-01-15 10:30:00','2030-01-15 11:00:00');
    ensure((int)scalar($db,'SELECT COUNT(*) FROM appointments')===2,'Adjacent slot rejected');
    ensure((int)scalar($db,'SELECT COUNT(*) FROM audit_log')===2,'False audit event');
    echo "Четыре пересечения отклонены; соседний интервал разрешён; записей 2\n";
 }],
 '3'=>['Недоступный врач и некорректное время',function()use($db,$service){
    [$p,$p2,$d,$d2]=fixtures($db);
    $db->prepare('UPDATE doctors SET active=0 WHERE id=?')->execute([$d]);
    rejected(fn()=>$service->book($p,$d,'2030-01-15 10:00:00','2030-01-15 10:30:00'),'Врач недоступен');
    rejected(fn()=>$service->book($p,$d2,'2030-01-15 11:00:00','2030-01-15 10:00:00'),'Некорректный интервал приёма');
    ensure((int)scalar($db,'SELECT COUNT(*) FROM appointments')===0,'Unexpected appointment');
    ensure((int)scalar($db,'SELECT COUNT(*) FROM audit_log')===0,'Unexpected audit');
    echo "Оба запроса отклонены; приёмов 0; событий аудита 0\n";
 }],
 '4'=>['Отмена и повторная запись с сохранением истории',function()use($db,$service){
    [$p,$p2,$d,$d2]=fixtures($db);
    $a=$service->book($p,$d,'2030-01-15 10:00:00','2030-01-15 10:30:00');$service->cancel($a);
    $b=$service->book($p2,$d,'2030-01-15 10:00:00','2030-01-15 10:30:00');
    ensure($a!==$b,'History overwritten');
    ensure(scalar($db,'SELECT status FROM appointments WHERE id=?',[$a])==='cancelled','Cancellation missing');
    ensure((int)scalar($db,"SELECT COUNT(*) FROM appointments WHERE status='scheduled'")===1,'Wrong active count');
    ensure((int)scalar($db,'SELECT COUNT(*) FROM appointments')===2,'History lost');
    ensure((int)scalar($db,'SELECT COUNT(*) FROM audit_log')===3,'Wrong audit count');
    echo "Старая запись cancelled; новая scheduled; записей 2; событий аудита 3\n";
 }],
];
$selected=$argv[1]??'all';if($selected!=='all'&&!isset($tests[$selected])){fwrite(STDERR,"Unknown scenario\n");exit(2);}
$failed=0;$count=0;$cases='';
foreach($tests as $key=>[$name,$test]) {
 if($selected!=='all'&&(string)$key!==$selected)continue;
 $count++;$time=microtime(true);$error='';
 try{$test();echo "PASS [$key] $name\n";}catch(Throwable $e){$failed++;$error=$e->getMessage();echo "FAIL [$key] $name: $error\n";}
 finally{clean($db);}
 $esc=fn($s)=>htmlspecialchars((string)$s,ENT_QUOTES|ENT_XML1,'UTF-8');
 $cases.='<testcase name="'.$esc($name).'" time="'.(microtime(true)-$time).'">'.($error?'<failure message="'.$esc($error).'"/>':'').'</testcase>';
}
if(!is_dir(__DIR__.'/results'))mkdir(__DIR__.'/results',0775,true);
file_put_contents(__DIR__.'/results/junit-'.$selected.'.xml','<?xml version="1.0" encoding="UTF-8"?><testsuite name="Clinic appointments" tests="'.$count.'" failures="'.$failed.'">'.$cases.'</testsuite>');
echo "Итого: $count, ошибок: $failed\n";exit($failed?1:0);
