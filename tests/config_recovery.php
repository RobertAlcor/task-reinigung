<?php
declare(strict_types=1);
require __DIR__ . '/../tools/config-recovery/recovery.template.php';
use TaktConfigRecovery\SafeError;
use function TaktConfigRecovery\{credentials,chooseKey,inspectDatabase,configSource,writeConfig,assertAuthorized};

$passed = 0; $failed = 0;
function test(string $name, callable $fn): void {
    global $passed, $failed;
    try { $fn(); ++$passed; echo "PASS $name\n"; }
    catch (Throwable $e) { ++$failed; echo "FAIL $name: " . get_class($e) . "\n"; }
}
function ok(bool $condition): void { if (!$condition) { throw new RuntimeException('assertion failed'); } }
function rejects(callable $fn): void {
    try { $fn(); } catch (SafeError) { return; }
    throw new RuntimeException('did not reject');
}
function cipher(string $text, string $key): string {
    $iv=random_bytes(12); $tag=''; $c=openssl_encrypt($text,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$iv,$tag,'',16);
    return $iv.$tag.$c;
}
final class Rows extends PDOStatement {
    private int $i = 0;
    public function __construct(private array $rows) {}
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT, mixed ...$args): array { return $this->rows; }
    public function fetch(int $mode=PDO::FETCH_DEFAULT,int $orientation=PDO::FETCH_ORI_NEXT,int $offset=0): mixed { return $this->rows[$this->i++] ?? false; }
    public function fetchColumn(int $column=0): mixed { return $this->rows[0][$column] ?? false; }
    public function closeCursor(): bool { return true; }
}
final class Reader extends PDO {
    public array $columns=[]; public array $values=[]; public array $queries=[];
    public int $providerCount=1; public int $badTwoFactor=0;
    public function __construct() {
        foreach (TaktConfigRecovery\FIELDS as $table=>$cols) {
            foreach ($cols as $col) { $this->columns[]=['TABLE_NAME'=>$table,'COLUMN_NAME'=>$col,'DATA_TYPE'=>'varbinary']; }
        }
        foreach (['benutzer','anbieter_benutzer'] as $table) { $this->columns[]=['TABLE_NAME'=>$table,'COLUMN_NAME'=>'totp_aktiv','DATA_TYPE'=>'tinyint']; }
    }
    public function query(string $query, ?int $fetchMode=null, mixed ...$fetchModeArgs): PDOStatement|false {
        $this->queries[]=$query;
        if (!str_starts_with($query,'SELECT ')) { throw new RuntimeException('write query forbidden'); }
        if (str_contains($query,'information_schema.COLUMNS')) { return new Rows($this->columns); }
        if (str_contains($query,'totp_aktiv = 1')) { return new Rows([[$this->badTwoFactor]]); }
        if (str_contains($query,'WHERE aktiv = 1')) { return new Rows([[$this->providerCount]]); }
        if (preg_match('/SELECT `([a-z_]+)` FROM `([a-z_]+)`/', $query, $m)) { return new Rows($this->values[$m[2].'.'.$m[1]] ?? []); }
        throw new RuntimeException('unexpected SELECT');
    }
}
$db=['host'=>'localhost','name'=>'takt_test','user'=>'test','password'=>"pass'\\\$\n",'charset'=>'utf8mb4'];
$raw=random_bytes(32); $key=base64_encode($raw);
$blob=cipher('test private data',$raw);

// Key handling.
test('new key only when empty and explicitly allowed', fn()=>ok(strlen(base64_decode(chooseKey([], '', true), true))===32));
test('new key requires consent', fn()=>rejects(fn()=>chooseKey([], '', false)));
test('existing key retained unchanged', fn()=>ok(chooseKey([], $key, false)===$key));
test('encrypted data with missing key blocks even with consent', fn()=>rejects(fn()=>chooseKey([$blob], '', true)));
test('encrypted data with correct key accepted', fn()=>ok(chooseKey([$blob], $key, false)===$key));
test('wrong key rejected', fn()=>rejects(fn()=>chooseKey([$blob], base64_encode(random_bytes(32)), true)));
test('tampered authentication tag rejected', function()use($blob,$key){$bad=$blob;$bad[12]=chr(ord($bad[12])^1);rejects(fn()=>chooseKey([$bad],$key,false));});
test('truncated encrypted value rejected', fn()=>rejects(fn()=>chooseKey(['abc'], $key, false)));
test('malformed base64 rejected', fn()=>rejects(fn()=>chooseKey([], 'wrong', false)));
test('mixed-key database rejected', fn()=>rejects(fn()=>chooseKey([$blob,cipher('other',random_bytes(32))],$key,false)));
test('large data scan bounded', fn()=>rejects(fn()=>chooseKey(array_fill(0,10001,$blob),$key,false)));
// Schema checks.
test('empty known fields with existing provider accepted', function(){ $p=new Reader();ok(strlen(inspectDatabase($p,'',true))===44); foreach($p->queries as $q){ok(str_starts_with($q,'SELECT '));} });
test('missing schema field rejected', function(){ $p=new Reader();array_pop($p->columns);rejects(fn()=>inspectDatabase($p,'',true)); });
test('unknown encrypted field rejected', function(){ $p=new Reader();$p->columns[]=['TABLE_NAME'=>'extra','COLUMN_NAME'=>'secret_enc','DATA_TYPE'=>'varchar'];rejects(fn()=>inspectDatabase($p,'',true)); });
test('unknown binary field rejected', function(){ $p=new Reader();$p->columns[]=['TABLE_NAME'=>'extra','COLUMN_NAME'=>'mystery','DATA_TYPE'=>'blob'];rejects(fn()=>inspectDatabase($p,'',true)); });
test('active 2FA missing secret blocks repair', function(){ $p=new Reader();$p->badTwoFactor=1;rejects(fn()=>inspectDatabase($p,'',true)); });
test('empty provider table blocks repair', function(){ $p=new Reader();$p->providerCount=0;rejects(fn()=>inspectDatabase($p,'',true)); });
test('all six ciphertext fields inspected', function()use($blob,$key){ $p=new Reader();foreach(TaktConfigRecovery\FIELDS as $t=>$cols){foreach($cols as $c){$p->values["$t.$c"]=[[$blob]];}}ok(inspectDatabase($p,$key,false)===$key);ok(count($p->queries)===10); });
// Form input and PHP encoding.
test('password special characters preserved', fn()=>ok(credentials(['db_name'=>'takt_test','db_user'=>'test','db_pass'=>$db['password']])['password']===$db['password']));
test('DSN injection rejected', fn()=>rejects(fn()=>credentials(['db_name'=>'test;host=evil','db_user'=>'u','db_pass'=>'p'])));
test('array inputs rejected', fn()=>rejects(fn()=>credentials(['db_name'=>['array'],'db_user'=>'u','db_pass'=>'p'])));
test('password required', fn()=>rejects(fn()=>credentials(['db_name'=>'db','db_user'=>'u'])));
// Authorization before database access.
$server=['HTTPS'=>'on','HTTP_HOST'=>'reinigung.webdesign-alcor.at','REQUEST_METHOD'=>'POST'];
$code=bin2hex(random_bytes(32));$hash=hash('sha256',$code);$until=time()+3600;
test('correct unlock code accepted', fn()=>assertAuthorized($server,['code'=>$code],$hash,$until));
test('wrong unlock code rejected', fn()=>rejects(fn()=>assertAuthorized($server,['code'=>'bad'],$hash,$until)));
test('HTTP rejected', fn()=>rejects(fn()=>assertAuthorized(array_merge($server,['HTTPS'=>'off']),['code'=>$code],$hash,$until)));
test('foreign host rejected', fn()=>rejects(fn()=>assertAuthorized(array_merge($server,['HTTP_HOST'=>'other.example']),['code'=>$code],$hash,$until)));
test('foreign Origin rejected', fn()=>rejects(fn()=>assertAuthorized(array_merge($server,['HTTP_ORIGIN'=>'https://other.example']),['code'=>$code],$hash,$until)));
test('GET rejected', fn()=>rejects(fn()=>assertAuthorized(array_merge($server,['REQUEST_METHOD'=>'GET']),['code'=>$code],$hash,$until)));
test('expired tool rejected', fn()=>rejects(fn()=>assertAuthorized($server,['code'=>$code],$hash,time()-1)));
test('disabled template rejected', fn()=>rejects(fn()=>assertAuthorized($server,['code'=>$code],'__ACCESS_HASH__',$until)));
test('excessive expiry rejected', fn()=>rejects(fn()=>assertAuthorized($server,['code'=>$code],$hash,time()+90000)));
// Filesystem: exclusively temporary test directory.
$dir=sys_get_temp_dir().'/takt-config-test-'.bin2hex(random_bytes(8));mkdir($dir,0700);
file_put_contents($dir.'/bootstrap.php','<?php');file_put_contents($dir.'/.htaccess',"Require all denied\n");
try {
    test('config created with exact credentials and private key', function()use($dir,$db,$key){writeConfig($dir,configSource($db,$key));$c=require $dir.'/config.php';ok($c['db']===$db);ok($c['crypto']['key']===$key);ok($c['app']['debug']===false);ok($c['files']['upload_dir']===$dir.'/../uploads');});
    test('config file private permissions', fn()=>ok((fileperms($dir.'/config.php')&0777)===0640));
    test('existing config never overwritten', function()use($dir){$before=file_get_contents($dir.'/config.php');rejects(fn()=>writeConfig($dir,'<?php bad'));ok(file_get_contents($dir.'/config.php')===$before);});
    unlink($dir.'/config.php');
    test('missing system access guard blocks write', function()use($dir,$db,$key){unlink($dir.'/.htaccess');rejects(fn()=>writeConfig($dir,configSource($db,$key)));ok(!file_exists($dir.'/config.php'));file_put_contents($dir.'/.htaccess','Require all denied');});
    test('dangling symlink config never overwritten', function()use($dir,$db,$key){symlink($dir.'/missing',$dir.'/config.php');rejects(fn()=>writeConfig($dir,configSource($db,$key)));ok(is_link($dir.'/config.php'));unlink($dir.'/config.php');});
    test('temporary secret files cleaned', fn()=>ok(glob($dir.'/.config-recovery-*')===[]));
} finally { foreach(glob($dir.'/*') as $f){unlink($f);}foreach(glob($dir.'/.config*') as $f){unlink($f);}@unlink($dir.'/.htaccess');rmdir($dir); }
echo json_encode(['passed'=>$passed,'failed'=>$failed,'tests'=>$passed+$failed],JSON_PRETTY_PRINT)."\n";
exit($failed===0?0:1);
