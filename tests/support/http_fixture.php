<?php
/** Ausschliesslich fuer lokale HTTP-Regressionstests; nie hochladen. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli-server' || !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1','::1'], true)) { http_response_code(404); exit; }
require __DIR__.'/test_runtime.php';
$root = getenv('TAKT_TEST_ROOT');
if (!$root || !is_dir($root)) { http_response_code(500); exit; }
foreach (['Database','Crypto','Totp','Auth','Http','Push'] as $class) { if (is_file($root.'/public/system/'.$class.'.php')) { require $root.'/public/system/'.$class.'.php'; } }
App\Crypto::init(base64_encode(str_repeat('x',32)));
App\Auth::init(['session_lifetime'=>7200,'max_login_tries'=>5,'lockout_minutes'=>15]);
$f = new AuthFixture();
set_exception_handler(static function(Throwable $e): never { App\Response::error($e instanceof App\AuthException ? $e->getMessage() : 'Internal error', $e instanceof App\AuthException ? $e->status() : 500); });
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/session') {
    App\Auth::startSession(); $_SESSION['benutzer_id']=1;
    App\Response::ok(['csrf'=>App\Auth::csrfToken()]);
}
if ($path === '/utf8') { App\Response::ok(['text'=>"bad\xffvalue"]); }
if ($path === '/infinity') { App\Response::ok(['value'=>INF]); }
if ($path === '/push') { App\Response::ok(['valid'=>App\Push::validEndpoint((string)($_GET['url']??''))]); }
if (isset($_GET['role'])) { $f->user['rolle']=(string)$_GET['role']; }
$r = new App\Router();
$r->open('POST','input',static function(App\Request $r):array {
    return match ($r->text('kind')) {
        'text'=>['value'=>$r->text('value',20,true)],
        'int'=>['value'=>$r->int('value',true,-100,100)],
        'decimal'=>['value'=>$r->decimal('value',true)],
        'date'=>['value'=>$r->date('value',true)],
        default=>$r->all(),
    };
});
$r->open('GET','id/{id}',static fn(App\Request $r):array=>['id'=>$r->paramInt('id')]);
$r->post('write',static fn():array=>['written'=>true],['inhaber']);
$r->get('read',static fn():array=>['read'=>true],['inhaber']);
$r->dispatch($_SERVER['REQUEST_METHOD'],trim($path,'/'));
