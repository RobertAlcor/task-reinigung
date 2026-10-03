<?php
/** Ausfuehren: php tests/unit.php [Projektverzeichnis] */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ob_start();
$root = realpath($argv[1] ?? dirname(__DIR__));
if ($root === false) { throw new RuntimeException('Projektverzeichnis fehlt.'); }
require __DIR__ . '/support/test_runtime.php';
foreach (['Database','Crypto','Totp','Auth','Http'] as $class) { require $root . '/public/system/' . $class . '.php'; }
date_default_timezone_set('Europe/Vienna');
$save = sys_get_temp_dir() . '/takt-tests-' . bin2hex(random_bytes(6));
mkdir($save,0700,true);
session_save_path($save);
App\Crypto::init(base64_encode(str_repeat('x',32)));
$results = [];
function check(bool $condition, string $message = 'Assertion failed'): void { if (!$condition) { throw new RuntimeException($message); } }
function throws(callable $fn, ?int $status = null): void {
    try { $fn(); } catch (Throwable $e) {
        if ($status !== null) { check($e instanceof App\AuthException && $e->status() === $status, 'Wrong exception/status: '.get_class($e)); }
        return;
    }
    throw new RuntimeException('Expected exception, but access/action succeeded');
}
function resetTest(): AuthFixture {
    if (session_status() === PHP_SESSION_ACTIVE) { $_SESSION=[]; session_destroy(); }
    session_id('');
    $_SESSION=[];
    $_SERVER=['HTTPS'=>'on','REMOTE_ADDR'=>'127.0.0.1'];
    $_POST=[]; $_GET=[];
    (new ReflectionClass(App\Auth::class))->getProperty('user')->setValue(null,null);
    App\Auth::init(['session_lifetime'=>7200,'lockout_minutes'=>15,'max_login_tries'=>5]);
    App\Crypto::init(base64_encode(str_repeat('x',32)));
    return new AuthFixture();
}
function test(string $name, callable $fn): void {
    global $results;
    try { $fn(resetTest()); $results[]=['name'=>$name,'ok'=>true]; }
    catch (Throwable $e) { $results[]=['name'=>$name,'ok'=>false,'message'=>$e->getMessage()]; }
}
use App\Auth; use App\Crypto; use App\Totp;
test('DB: reject missing tenant',fn($f)=>throws(fn()=>$f->db->select('kunden')));
test('DB: reject invalid tenant id',fn($f)=>throws(fn()=>$f->db->setTenant(0)));
test('DB: tenant prepended to SELECT parameters',function($f){$f->db->setTenant(7);$f->db->select('kunden','id = ? OR id = ?',[1,2]);[$sql,$p]=end($f->pdo->calls);check($p===[7,1,2] && str_contains($sql,'AND (id = ? OR id = ?)'));});
test('DB: INSERT ignores submitted tenant',function($f){$f->db->setTenant(7);$f->db->insert('kunden',['betrieb_id'=>999,'firmenname'=>'Test']);[$sql,$p]=end($f->pdo->calls);check($p===[7,'Test']);});
test('DB: UPDATE protects id and tenant',function($f){$f->db->setTenant(7);$f->db->update('kunden',10,['id'=>12,'betrieb_id'=>999,'firmenname'=>'Test']);[$sql,$p]=end($f->pdo->calls);check($p===['Test',10,7] && str_contains($sql,'WHERE id = ? AND betrieb_id = ?'));});
test('DB: DELETE scoped by tenant',function($f){$f->db->setTenant(7);$f->db->delete('kunden',10);[$sql,$p]=end($f->pdo->calls);check($p===[10,7] && str_contains($sql,'AND betrieb_id = ?'));});
test('DB: reject table injection',function($f){$f->db->setTenant(7);throws(fn()=>$f->db->select('kunden; DROP TABLE benutzer'));});
test('DB: reject column injection',function($f){$f->db->setTenant(7);throws(fn()=>$f->db->insert('kunden',['name` = 1 --'=>'test']));});
test('DB: commit transaction',function($f){check($f->db->transaction(fn()=>42)===42 && $f->pdo->commits===1);});
test('DB: rollback transaction',function($f){throws(fn()=>$f->db->transaction(fn()=>throw new RuntimeException('expected')));check($f->pdo->rollbacks===1);});
test('DB: retain error after DB-side rollback',function($f){try{$f->db->transaction(function()use($f){$f->pdo->transaction=false;throw new RuntimeException('original-error');});}catch(Throwable $e){check($e->getMessage()==='original-error');return;}check(false);});
test('Auth: valid session sets tenant',function($f){Auth::startSession();$_SESSION['benutzer_id']=1;check(Auth::authenticate()['id']===1 && $f->db->tenant()===7);});
test('Auth: customer cannot access owner role',function($f){$f->user['rolle']='kunde';Auth::startSession();$_SESSION['benutzer_id']=1;throws(fn()=>Auth::require(['inhaber']),403);});
test('Auth: malformed Bearer never falls back to cookie',function($f){Auth::startSession();$_SESSION['benutzer_id']=1;$_SERVER['HTTP_AUTHORIZATION']='Bearer invalid';check(Auth::authenticate()===null);});
test('Auth: valid Bearer sets tenant',function($f){$_SERVER['HTTP_AUTHORIZATION']='Bearer '.str_repeat('a',64);check(Auth::authenticate()['id']===1 && $f->db->tenant()===7);});
test('Auth: expired/revoked Bearer rejected',function($f){$f->tokenValid=false;$_SERVER['HTTP_AUTHORIZATION']='Bearer '.str_repeat('a',64);check(Auth::authenticate()===null);});
test('Auth SQL contract: token and user tenant must match',function($f){$f->tokenTenantMismatch=true;$_SERVER['HTTP_AUTHORIZATION']='Bearer '.str_repeat('a',64);check(Auth::authenticate()===null);});
test('Auth SQL contract: cancelled tenant session denied',function($f){$f->cancelled=true;Auth::startSession();$_SESSION['benutzer_id']=1;check(Auth::authenticate()===null);});
test('Auth SQL contract: cancelled tenant Bearer denied',function($f){$f->cancelled=true;$_SERVER['HTTP_AUTHORIZATION']='Bearer '.str_repeat('a',64);check(Auth::authenticate()===null);});
test('Auth SQL contract: deactivated employee Bearer denied',function($f){$f->employeeInactive=true;$f->user['rolle']='mitarbeiter';$_SERVER['HTTP_AUTHORIZATION']='Bearer '.str_repeat('a',64);check(Auth::authenticate()===null);});
test('Auth: logout clears tenant context',function($f){$f->db->setTenant(7);Auth::startSession();Auth::logout();check(!$f->db->hasTenant());});
test('Auth: tenant login removes old provider identity',function($f){$f->user['totp_aktiv']=0;Auth::startSession();$_SESSION['anbieter_id']=20;Auth::loginOhnePasswort($f->user);check(!isset($_SESSION['anbieter_id']) && $_SESSION['benutzer_id']===1);});
test('Auth: new login removes old TOTP setup secret',function($f){$f->user['totp_aktiv']=0;Auth::startSession();$_SESSION['totp_setup_secret']='old';Auth::loginOhnePasswort($f->user);check(!isset($_SESSION['totp_setup_secret']));});
test('Auth: provider login removes tenant context',function($f){$f->provider['totp_aktiv']=0;$f->db->setTenant(7);Auth::loginProviderOhnePasswort($f->provider);check(!$f->db->hasTenant() && $_SESSION['anbieter_id']===20);});
test('Auth: pending MFA has no authenticated tenant session',function($f){$u=Auth::loginWithPassword('test-owner','Test-only-password');check(($u['zweiter_faktor']??false) && !isset($_SESSION['benutzer_id']));});
test('MFA: locked tenant rejects correct code',function($f){$f->locked=true;$f->pending();throws(fn()=>Auth::completeSecondFactor($f->otp()),401);check(!isset($_SESSION['benutzer_id']));});
test('MFA: locked provider rejects correct code',function($f){$f->locked=true;$f->pending(true);throws(fn()=>Auth::completeProviderSecondFactor($f->otp()),401);check(!isset($_SESSION['anbieter_id']));});
test('MFA: valid tenant code completes login',function($f){$f->pending();check(Auth::completeSecondFactor($f->otp())['id']===1 && $_SESSION['benutzer_id']===1);});
test('MFA: valid provider code completes login',function($f){$f->pending(true);check(Auth::completeProviderSecondFactor($f->otp())['id']===20 && $_SESSION['anbieter_id']===20);});
test('MFA: wrong code counts failure',function($f){$f->pending();throws(fn()=>Auth::completeSecondFactor('wrong-code'),401);check($f->failureWrites===1);});
test('MFA: expired challenge rejected',function($f){$f->pending();$_SESSION['zf_seit']=time()-301;throws(fn()=>Auth::completeSecondFactor($f->otp()),401);});
test('MFA: CAS conflict cannot grant tenant login',function($f){$f->pending();$f->cas=0;throws(fn()=>Auth::completeSecondFactor($f->otp()),401);});
test('MFA: CAS conflict cannot grant provider login',function($f){$f->pending(true);$f->cas=0;throws(fn()=>Auth::completeProviderSecondFactor($f->otp()),401);});
test('MFA: backup code CAS conflict denied',function($f){$f->user['totp_backup']=json_encode([password_hash('abcd-efgh',PASSWORD_BCRYPT,['cost'=>4])]);$f->pending();$f->cas=0;throws(fn()=>Auth::completeSecondFactor('abcd-efgh'),401);});
test('MFA: valid backup code accepted',function($f){$f->user['totp_backup']=json_encode([password_hash('abcd-efgh',PASSWORD_BCRYPT,['cost'=>4])]);$f->pending();check(Auth::completeSecondFactor('abcd-efgh')['id']===1);});
test('MFA: disabled MFA challenge rejected',function($f){$f->user['totp_aktiv']=0;$f->pending();throws(fn()=>Auth::completeSecondFactor($f->otp()),401);});
test('Auth: expired session creates persistent anonymous CSRF',function($f){Auth::startSession();$_SESSION['benutzer_id']=1;$_SESSION['last_seen']=time()-7201;session_write_close();$token=Auth::csrfToken();check(session_status()===PHP_SESSION_ACTIVE && !isset($_SESSION['benutzer_id']));session_write_close();Auth::startSession();check(($_SESSION['csrf']??null)===$token);});
test('CSRF: missing token rejected',function($f){Auth::csrfToken();throws(fn()=>Auth::checkCsrf(null),419);});
test('CSRF: wrong token rejected',function($f){Auth::csrfToken();throws(fn()=>Auth::checkCsrf('wrong'),419);});
test('CSRF: correct token accepted',function($f){Auth::checkCsrf(Auth::csrfToken());});
test('Auth: dummy hash is a valid costly bcrypt hash',function($f){$rc=new ReflectionClass(Auth::class);$hash=$rc->getConstant('DUMMY_HASH');check(is_string($hash) && password_get_info($hash)['algoName']==='bcrypt' && password_get_info($hash)['options']['cost']>=12);});
foreach ([59=>'287082',1111111109=>'081804',1111111111=>'050471',1234567890=>'005924',2000000000=>'279037',20000000000=>'353130'] as $time=>$expected) {
    test('TOTP RFC6238 SHA1, six digits at '.$time,fn($f)=>check(Totp::code($f->secret,intdiv($time,30))===$expected));
}
test('TOTP: consumed timestep rejected',function($f){$step=intdiv(time(),30);check(Totp::verify($f->secret,Totp::code($f->secret,$step),$step)===null);});
test('Crypto: Unicode roundtrip',fn($f)=>check(Crypto::decrypt(Crypto::encrypt('Test ÄÖÜ — 123'))==='Test ÄÖÜ — 123'));
test('Crypto: random IV changes ciphertext',fn($f)=>check(Crypto::encrypt('test')!==Crypto::encrypt('test')));
test('Crypto: altered authentication tag rejected',function($f){$s=Crypto::encrypt('test');$s[12]=chr(ord($s[12])^1);throws(fn()=>Crypto::decrypt($s));});
test('Crypto: altered ciphertext rejected',function($f){$s=Crypto::encrypt('test');$s[strlen($s)-1]=chr(ord($s[strlen($s)-1])^1);throws(fn()=>Crypto::decrypt($s));});
test('Crypto: wrong key rejected',function($f){$s=Crypto::encrypt('test');Crypto::init(base64_encode(str_repeat('y',32)));throws(fn()=>Crypto::decrypt($s));});
test('Crypto: invalid key length rejected',fn($f)=>throws(fn()=>Crypto::init(base64_encode('short'))));
test('Crypto: truncated encrypted value rejected',fn($f)=>throws(fn()=>Crypto::decrypt('short')));
if (session_status()===PHP_SESSION_ACTIVE) { $_SESSION=[];session_destroy(); }
foreach (glob($save.'/*') as $file) { unlink($file); } rmdir($save);
$failed=count(array_filter($results,fn($r)=>!$r['ok']));
ob_end_clean();
echo json_encode(['php'=>PHP_VERSION,'root'=>$root,'pdo'=>'test double; no real database','mbstring'=>extension_loaded('mbstring')?'native':'test-only UTF-8 length/substring shim; lowercase tests ASCII only','tests'=>count($results),'passed'=>count($results)-$failed,'failed'=>$failed,'results'=>$results],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE).PHP_EOL;
exit($failed?1:0);
