<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || getenv('TAKT_TEST_DATABASE') !== '1') { exit(2); }
$db = new PDO('mysql:host=127.0.0.1;port=3306;dbname=takt_test_ci;charset=utf8mb4', 'root', 'takt-ci-only', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$results=[];
function checkTest(string $name, callable $fn): void { global $results; try { if (!$fn()) { throw new RuntimeException('Assertion failed'); } $results[]=['name'=>$name,'ok'=>true]; } catch (Throwable $e) { $results[]=['name'=>$name,'ok'=>false,'message'=>$e->getMessage()]; } }
function q(string $sql, array $values=[]): PDOStatement { global $db; $s=$db->prepare($sql);$s->execute($values);return $s; }
checkTest('Unchanged complete schema imported',fn()=>(int)q('SELECT COUNT(*) n FROM information_schema.tables WHERE table_schema=DATABASE()')->fetch()['n']>=50);
checkTest('PHP and MySQL timezone agreement',function(){date_default_timezone_set('Europe/Vienna');q('SET SESSION time_zone = ?',[date('P')]);return q('SELECT @@session.time_zone tz')->fetch()['tz']===date('P');});
q("INSERT INTO betriebe(id,name,slug,status) VALUES(1,'Fiktiv A','test-a','aktiv'),(2,'Fiktiv B','test-b','aktiv')");
q("INSERT INTO kunden(id,betrieb_id,firmenname) VALUES(1,1,'Fiktiv A'),(2,2,'Fiktiv B')");
q("INSERT INTO benutzer(id,betrieb_id,rolle,benutzername,totp_aktiv,totp_secret_enc,totp_backup) VALUES(1,1,'inhaber','test-owner',1,?,'[]')",[random_bytes(60)]);
q("INSERT INTO anbieter_benutzer(id,benutzername,passwort_hash,totp_aktiv,totp_secret_enc,totp_backup) VALUES(1,'test-provider','test-only',1,?,'[]')",[random_bytes(60)]);
checkTest('Tenant SELECT with grouped OR',fn()=>count(q('SELECT * FROM kunden WHERE betrieb_id=? AND (id=? OR id=?)',[1,1,2])->fetchAll())===1);
checkTest('Cross-tenant UPDATE denied',fn()=>q('UPDATE kunden SET firmenname=? WHERE id=? AND betrieb_id=?',['forbidden',2,1])->rowCount()===0);
checkTest('Cross-tenant DELETE denied',fn()=>q('DELETE FROM kunden WHERE id=? AND betrieb_id=?',[2,1])->rowCount()===0);
checkTest('Rollback preserves customer',function()use($db){$db->beginTransaction();q('UPDATE kunden SET firmenname=? WHERE id=? AND betrieb_id=?',['temporary',1,1]);$db->rollBack();return q('SELECT firmenname FROM kunden WHERE id=1')->fetch()['firmenname']==='Fiktiv A';});
foreach(['benutzer','anbieter_benutzer'] as $table) {
    $secret=q("SELECT totp_secret_enc FROM {$table} WHERE id=1")->fetch()['totp_secret_enc'];
    $sql="UPDATE {$table} SET totp_letzter_schritt = ? WHERE id = ? AND aktiv = 1 AND totp_aktiv = 1 AND totp_secret_enc = ? AND (gesperrt_bis IS NULL OR gesperrt_bis <= NOW()) AND (totp_letzter_schritt IS NULL OR totp_letzter_schritt < ?)";
    checkTest($table.': first TOTP claim accepted',fn()=>q($sql,[1000,1,$secret,1000])->rowCount()===1);
    checkTest($table.': stale TOTP claim rejected',fn()=>q($sql,[1000,1,$secret,1000])->rowCount()===0);
    checkTest($table.': earlier TOTP rejected',fn()=>q($sql,[999,1,$secret,999])->rowCount()===0);
    checkTest($table.': changed secret rejected',fn()=>q($sql,[1001,1,random_bytes(60),1001])->rowCount()===0);
    q("UPDATE {$table} SET gesperrt_bis=DATE_ADD(NOW(), INTERVAL 15 MINUTE) WHERE id=1");
    checkTest($table.': locked TOTP rejected',fn()=>q($sql,[1001,1,$secret,1001])->rowCount()===0);
    q("UPDATE {$table} SET gesperrt_bis=NULL,fehlversuche=0 WHERE id=1");
    $previous=json_encode([password_hash('test-backup',PASSWORD_BCRYPT,['cost'=>4])]);
    q("UPDATE {$table} SET totp_backup=? WHERE id=1",[$previous]);
    $backup="UPDATE {$table} SET totp_backup = ? WHERE id = ? AND aktiv = 1 AND totp_aktiv = 1 AND totp_secret_enc = ? AND (gesperrt_bis IS NULL OR gesperrt_bis <= NOW()) AND BINARY totp_backup = ?";
    checkTest($table.': backup claim accepted',fn()=>q($backup,['[]',1,$secret,$previous])->rowCount()===1);
    checkTest($table.': stale backup claim rejected',fn()=>q($backup,['[]',1,$secret,$previous])->rowCount()===0);
    checkTest($table.': lock exactly at fifth failure',function()use($table){for($i=1;$i<=5;$i++){q("UPDATE {$table} SET gesperrt_bis = IF(fehlversuche + 1 >= ?, DATE_ADD(NOW(), INTERVAL ? MINUTE), gesperrt_bis), fehlversuche = fehlversuche + 1 WHERE id = ?",[5,15,1]);$r=q("SELECT fehlversuche,gesperrt_bis FROM {$table} WHERE id=1")->fetch();if((int)$r['fehlversuche']!==$i || ($r['gesperrt_bis']!==null)!==($i===5))return false;}return true;});
    checkTest($table.': lock query enforced using DB time',fn()=>(int)q("SELECT (gesperrt_bis IS NOT NULL AND gesperrt_bis > NOW()) AS gesperrt FROM {$table} WHERE id=? AND aktiv=1",[1])->fetch()['gesperrt']===1);
}
checkTest('Foreign customer unchanged',fn()=>q('SELECT firmenname FROM kunden WHERE id=2')->fetch()['firmenname']==='Fiktiv B');
$report=['php'=>PHP_VERSION,'database'=>q('SELECT VERSION() v')->fetch()['v'],'scope'=>'Native MySQL SQL-contract tests against unchanged full schema; not full application integration','tests'=>count($results),'passed'=>count(array_filter($results,fn($r)=>$r['ok'])),'failed'=>count(array_filter($results,fn($r)=>!$r['ok'])),'results'=>$results];
echo json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n";exit($report['failed']?1:0);
