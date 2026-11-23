<?php
declare(strict_types=1);
require __DIR__.'/../../includes/request_logger.php';
$count=0;
$assert=static function(bool $ok,string $msg) use (&$count):void { $count++; if (!$ok) throw new RuntimeException($msg); };
$root=__DIR__.'/.request-log-sandbox'; mkdir($root); mkdir($root.'/logs'); file_put_contents($root.'/logs/.htaccess', "Require all denied\n");
$path=$root.'/logs/requests.jsonl';
$input=['method'=>'POST','page'=>'invoice','action'=>'save_invoice','status'=>302,'duration_ms'=>56,'peak_kb'=>1024,'outcome'=>'success','request_id'=>str_repeat('a',24),'pin'=>'PINSECRET','url'=>'private/customer','customer'=>'SECRET'];
$assert(TadegaRequestLogger::write($root,$input),'Write request');
$row=json_decode(trim((string)file_get_contents($path)),true);
$assert($row['page']==='invoice' && $row['action']==='save_invoice' && $row['http_status']===302 && $row['duration_ms']===56 && $row['outcome']==='success','Technical fields recorded');
$assert(!str_contains((string)file_get_contents($path),'SECRET') && !str_contains((string)file_get_contents($path),'private/customer'),'Private input discarded');
$input['action']='SECRET_CUSTOMER'; $input['page']='SECRET'; $input['method']='secret'; $input['outcome']='PRIVATE'; $input['request_id']='private';
$assert(TadegaRequestLogger::write($root,$input),'Write unknown values');
$rows=file($path,FILE_IGNORE_NEW_LINES); $last=json_decode(end($rows),true);
$assert($last['action']==='other' && $last['page']==='other' && $last['method']==='OTHER' && $last['outcome']==='not_recorded' && $last['request_id']==='', 'Unknown values cannot be logged');
$old=json_encode(['timestamp_utc'=>gmdate('Y-m-d\TH:i:s\Z',time()-31*86400),'event'=>'old'])."\n";
$new=json_encode(['timestamp_utc'=>gmdate('Y-m-d\TH:i:s\Z'),'event'=>'new'])."\n";
file_put_contents($path,$old.$new); unlink($root.'/logs/.requests-pruned');
$assert(TadegaRequestLogger::write($root,$input) && !str_contains((string)file_get_contents($path),'"old"') && str_contains((string)file_get_contents($path),'"new"'),'Old log entries are pruned');
file_put_contents($path,str_repeat('x',1048576));
$assert(TadegaRequestLogger::write($root,$input) && filesize($path.'.1')===1048576 && strlen((string)file_get_contents($path))<1000,'Bounded rotation keeps previous file');
$assert((fileperms($path)&0077)===0,'Log file is private');
unlink($root.'/logs/.htaccess');
$assert(!TadegaRequestLogger::write($root,$input),'Missing web denial blocks writes');
file_put_contents($root.'/logs/.htaccess',"Require all granted\n");
$assert(!TadegaRequestLogger::write($root,$input),'Invalid web denial blocks writes');
if (function_exists('symlink') && @symlink($path,$root.'/logs/requests.jsonl.2')) {
    file_put_contents($root.'/logs/.htaccess',"Require all denied\n");
    $assert(!TadegaRequestLogger::write($root,$input),'Symlink in log path blocks writes'); unlink($root.'/logs/requests.jsonl.2');
}
foreach (glob($root.'/logs/*') ?: [] as $file) unlink($file);
foreach (glob($root.'/logs/.*') ?: [] as $file) if (!in_array(basename($file),['.','..'],true)) unlink($file);
rmdir($root.'/logs'); rmdir($root);
echo "PASS: $count private request logging checks.\n";
