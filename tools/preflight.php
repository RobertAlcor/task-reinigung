<?php
/** Read-only: php tools/preflight.php /absoluter/pfad/zum/projekt */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root=realpath($argv[1]??dirname(__DIR__));
if($root===false){fwrite(STDERR,"Projektverzeichnis fehlt.\n");exit(2);}
$checks=[];
function result(string $name, bool $ok, string $note=''):void {global $checks;$checks[]=['pruefung'=>$name,'ok'=>$ok,'hinweis'=>$note];}
result('PHP-Version',PHP_VERSION_ID>=80300,'Pruefziel PHP 8.3 oder neuer; aktueller Wert: '.PHP_VERSION);
foreach(['pdo_mysql','mbstring','openssl','fileinfo','gd','dom','zip'] as $extension)result('Erweiterung '.$extension,extension_loaded($extension));
result('setup.php vom Webserver entfernt',!is_file($root.'/public/setup.php'),'Nur auf der Serverinstallation beurteilen; im Git-Quellprojekt bleibt das Installationsskript erhalten.');
result('system-Verzeichnisschutz vorhanden',is_file($root.'/public/system/.htaccess'),'Die tatsaechliche HTTP-Sperre separat auf dem Webserver testen.');
$configFile=$root.'/public/system/config.php';
result('Produktivkonfiguration vorhanden',is_file($configFile));
if(is_file($configFile)){
 try{
  $c=require $configFile;
  result('Konfigurationsstruktur',is_array($c));
  if(is_array($c)){
   result('Debug ausgeschaltet',($c['app']['debug']??false)===false);
   $url=(string)($c['app']['url']??'');
   result('HTTPS-Basisadresse',filter_var($url,FILTER_VALIDATE_URL)!==false && parse_url($url,PHP_URL_SCHEME)==='https');
   $key=base64_decode((string)($c['crypto']['key']??''),true);
   result('Verschluesselungsschluessel formal gueltig',is_string($key)&&strlen($key)===32,'Bestehenden Schluessel niemals ersetzen. Inhalt wird nicht ausgegeben.');
   $origins=$c['security']['allowed_origins']??[];
   result('CORS ohne Wildcard',is_array($origins)&&$origins!==[]&&!in_array('*',$origins,true));
   result('Sitzungsdauer positiv',(int)($c['security']['session_lifetime']??0)>0);
   result('Login-Sperre konfiguriert',(int)($c['security']['max_login_tries']??0)>0&&(int)($c['security']['lockout_minutes']??0)>0);
  }
 }catch(Throwable){result('Konfiguration ladbar',false,'Fehlerdetails werden wegen moeglicher Zugangsdaten nicht ausgegeben.');}
}
$failed=count(array_filter($checks,fn($c)=>!$c['ok']));
echo json_encode(['nur_lesend'=>true,'keine_sicherheitsfreigabe'=>true,'fehlgeschlagen'=>$failed,'pruefungen'=>$checks],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n";
exit($failed?1:0);
