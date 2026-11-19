<?php
declare(strict_types=1);
require __DIR__.'/../src/database.php';
require __DIR__.'/../src/AppointmentService.php';
session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax',
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
]);
session_start();
$_SESSION['csrf'] ??= bin2hex(random_bytes(32));
function e(mixed $value): string {return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');}
$db=database();$service=new AppointmentService($db);$message='';
if($_SERVER['REQUEST_METHOD']==='POST') {
    if(!hash_equals($_SESSION['csrf'],(string)($_POST['csrf']??''))) {
        http_response_code(403);exit('Недействительный запрос');
    }
    try {
        if(($_POST['action']??'')==='cancel') {
            $service->cancel((int)($_POST['appointment']??0));$message='Запись отменена. История сохранена.';
        } else {
            $start=str_replace('T',' ',(string)($_POST['start']??'')).':00';
            $end=str_replace('T',' ',(string)($_POST['end']??'')).':00';
            $id=$service->book((int)($_POST['patient']??0),(int)($_POST['doctor']??0),$start,$end);
            $message='Создана запись №'.$id;
        }
    } catch(DomainException $ex) {$message=$ex->getMessage();}
      catch(Throwable $ex) {http_response_code(500);$message='Не удалось сохранить изменения.';error_log($ex->getMessage());}
}
$patients=$db->query('SELECT id,patient_code FROM patients WHERE active=1 ORDER BY patient_code')->fetchAll(PDO::FETCH_ASSOC);
$doctors=$db->query('SELECT id,doctor_code,specialty FROM doctors WHERE active=1 ORDER BY doctor_code')->fetchAll(PDO::FETCH_ASSOC);
$rows=$db->query('SELECT a.*,p.patient_code,d.doctor_code FROM appointments a JOIN patients p ON p.id=a.patient_id JOIN doctors d ON d.id=a.doctor_id ORDER BY starts_at DESC LIMIT 100')->fetchAll(PDO::FETCH_ASSOC);
?><!doctype html><html lang="ru"><meta charset="utf-8"><title>Здоровье+ | Запись на приём</title>
<style>body{font:16px system-ui;margin:40px auto;max-width:1100px;background:#f4f7fb;color:#172c43}header{display:flex;justify-content:space-between}section{background:white;padding:24px;border-radius:14px;margin:20px 0}form.fields{display:grid;grid-template-columns:1fr 1fr;gap:16px}label{display:grid;gap:8px}input,select,button{padding:11px;border:1px solid #bccbd9;border-radius:7px;font:inherit}button{background:#216b92;color:white;cursor:pointer}table{width:100%;border-collapse:collapse}td,th{padding:12px;text-align:left;border-bottom:1px solid #dce4ed}small{color:#546a7f}</style>
<header><h1>Здоровье+</h1><p>Рабочее место регистратора</p></header>
<small>Модуль записи на приём. Контроль расписания и история изменений.</small>
<?php if($message):?><section role="status"><?=e($message)?></section><?php endif?>
<section><h2>Новый приём</h2><form method="post" class="fields">
<input type="hidden" name="csrf" value="<?=e($_SESSION['csrf'])?>">
<label>Пациент<select name="patient" required><?php foreach($patients as $p):?><option value="<?=e($p['id'])?>"><?=e($p['patient_code'])?></option><?php endforeach?></select></label>
<label>Врач<select name="doctor" required><?php foreach($doctors as $x):?><option value="<?=e($x['id'])?>"><?=e($x['doctor_code'].' · '.$x['specialty'])?></option><?php endforeach?></select></label>
<label>Начало<input name="start" type="datetime-local" required></label><label>Окончание<input name="end" type="datetime-local" required></label>
<button>Записать на приём</button></form></section>
<section><h2>История приёмов</h2><table><tr><th>№</th><th>Пациент</th><th>Врач</th><th>Начало</th><th>Окончание</th><th>Статус</th><th>Действие</th></tr>
<?php foreach($rows as $r):?><tr><?php foreach(['id','patient_code','doctor_code','starts_at','ends_at','status'] as $k):?><td><?=e($r[$k])?></td><?php endforeach?><td>
<?php if($r['status']==='scheduled'):?><form method="post"><input type="hidden" name="csrf" value="<?=e($_SESSION['csrf'])?>"><input type="hidden" name="action" value="cancel"><input type="hidden" name="appointment" value="<?=e($r['id'])?>"><button>Отменить</button></form><?php endif?></td></tr><?php endforeach?></table></section>
</html>
