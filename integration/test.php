<?php
declare(strict_types=1);
// Native PHP HTTP integration runner: no test framework or Python dependency.
$root = dirname(__DIR__);
$php = getenv('PHP_BINARY') ?: PHP_BINARY;
$phpArgs = [$php];
if (getenv('PHP_INI')) array_push($phpArgs, '-c', getenv('PHP_INI'));
$opaBinary = getenv('OPA_BINARY') ?: 'opa';
$temp = sys_get_temp_dir() . '/privacy-php-check-' . bin2hex(random_bytes(8));
mkdir($temp); mkdir($temp . '/install'); mkdir($temp . '/sessions');
$processes = []; $checks = 0;
function check(string $name, bool $value): void { global $checks; if (!$value) throw new RuntimeException($name); ++$checks; echo "PASS $name\n"; }
function freePort(): int { $socket = stream_socket_server('tcp://127.0.0.1:0'); if (!$socket) throw new RuntimeException('Port allocation failed'); $port = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1); fclose($socket); return $port; }
function launch(array $command, string $cwd, ?array $env = null): mixed { global $processes, $temp; $process = proc_open($command, [0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'], 1 => ['file', $temp . '/server.log', 'a'], 2 => ['file', $temp . '/server.log', 'a']], $pipes, $cwd, $env, ['bypass_shell' => true]); if (!is_resource($process)) throw new RuntimeException('Process startup failed'); $processes[] = $process; return $process; }
function stop(mixed $process): void { if (is_resource($process)) { proc_terminate($process); proc_close($process); } }
function http(string $client, string $path, mixed $body = null, string $method = 'GET', array $headers = []): array {
    global $base, $temp; $handle = curl_init($base . $path);
    if ($body !== null && !is_string($body)) $body = json_encode($body, JSON_THROW_ON_ERROR);
    curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT_MS => 15000, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_COOKIEFILE => $temp . '/' . $client . '.cookies', CURLOPT_COOKIEJAR => $temp . '/' . $client . '.cookies', CURLOPT_HTTPHEADER => $headers]);
    if ($body !== null) curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
    $raw = curl_exec($handle); if ($raw === false) throw new RuntimeException(curl_error($handle)); $status = curl_getinfo($handle, CURLINFO_HTTP_CODE); curl_setopt($handle, CURLOPT_COOKIELIST, 'FLUSH');
    $json = json_decode($raw, true); return [$status, json_last_error() === JSON_ERROR_NONE ? $json : $raw];
}
function csrf(string $client): string { return http($client, '/gdpr/csrf')[1]['token']; }
function api(string $client, string $policy, mixed $body = null, string $method = 'POST', bool|string $token = true): array { $headers = ['Content-Type: application/json', 'Accept: application/json']; if ($token !== false) $headers[] = 'X-CSRF-Token: ' . ($token === true ? csrf($client) : $token); return http($client, '/gdpr/' . $policy, $body ?? new stdClass(), $method, $headers); }
function formToken(string $client, string $path): string { $body=http($client,$path)[1]; if(!preg_match('/name="_token"[^>]*value="([^"]+)"/',$body,$m))throw new RuntimeException('Original CSRF token missing'); return html_entity_decode($m[1]); }
function form(string $client, string $path, array $value): array { return http($client, $path, http_build_query($value), 'POST', ['Content-Type: application/x-www-form-urlencoded']); }
function register(string $client, string $name, string $email): array { return form($client,'/register',['_token'=>formToken($client,'/register'),'name'=>$name,'email'=>$email,'password'=>'Integration123!','password_confirmation'=>'Integration123!']); }
function login(string $client, string $email): array { return form($client,'/login',['_token'=>formToken($client,'/login'),'email'=>$email,'password'=>$email==='admin@admin.com'?'password':'Integration123!']); }
function logout(string $client): array { return form($client,'/logout',['_token'=>csrf($client)]); }
function sourceHashes(): array { global $root; $files = array_merge([$root . '/integration/privacy-lib/GdprRouter.php'], glob($root . '/integration/privacy-lib/gdpr/*.rego')); return array_map(fn($p) => hash_file('sha256', $p), $files); }
$original = sourceHashes(); $exit = 0;
try {
    $port=freePort();$opaPort=freePort();$base='http://127.0.0.1:'.$port;
    touch($temp.'/fixture.sqlite');
    $env=array_merge(getenv(),['APP_ENV'=>'local','APP_DEBUG'=>'false','APP_URL'=>$base,'APP_KEY'=>'base64:'.base64_encode(random_bytes(32)),'DB_CONNECTION'=>'sqlite','DB_DATABASE'=>$temp.'/fixture.sqlite','SESSION_DRIVER'=>'file','SESSION_COOKIE'=>'privacy_tickets_test_'.bin2hex(random_bytes(5)),'MAIL_MAILER'=>'array','OPA_QUERY_URL'=>'http://127.0.0.1:'.$opaPort.'/v1/query']);
    $install=launch(array_merge($phpArgs,[$root.'/artisan','migrate','--seed','--force','--no-interaction']),$root,$env);
    if(proc_close($install)!==0)throw new RuntimeException('Original migrations and seed failed');
    $db=new PDO('sqlite:'.$temp.'/fixture.sqlite');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
    $opa=launch([$opaBinary,'run','--server','--addr','127.0.0.1:'.$opaPort,$root.'/integration/privacy-lib/gdpr'],$root);
    $framework=realpath($root.'/vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php');
    $app=launch(array_merge($phpArgs,['-S','127.0.0.1:'.$port,'-t',$root.'/public',$framework]),$root.'/public',$env);
    $ready = false;
    for ($i=0; $i<100; $i++) { try { if (http('anon', '/login')[0] === 200) { $socket = @stream_socket_client('tcp://127.0.0.1:' . $opaPort); if ($socket) {fclose($socket);$ready=true;break;} } } catch (Throwable) {} usleep(100000); }
    if (!$ready) throw new RuntimeException('Startup timed out');
    check('Original login page renders',str_contains(http('alice','/login')[1],'name="email"'));
    check('Original registration page renders',http('alice','/register')[0]===200);
    check('Anonymous GDPR API denied',http('anon','/gdpr/csrf',null,'GET',['Accept: application/json'])[0]===401);
    check('Anonymous GDPR mutation denied',in_array(api('anon','consent',token:false)[0],[401,419],true));
    check('Original registration creates account',register('alice','AlicePrivate','alice@example.org')[0]===302);
    $aliceId=(string)$db->query("SELECT id FROM users WHERE email='alice@example.org'")->fetchColumn();
    check('Original login session works without email verification',http('alice','/tickets')[0]===200);
    check('Account links privacy page',str_contains(http('alice','/tickets')[1],'Privacy checks'));
    check('Privacy page renders real identity',str_contains(http('alice','/gdpr')[1],'AlicePrivate'));
    $ticket=form('alice','/tickets',['_token'=>csrf('alice'),'title'=>'AlicePrivateTicket','message'=>'AlicePrivateBody','priority'=>'low','status'=>'open']);
    check('Original ticket creation works',$ticket[0]===302);
    $aliceTicket=(int)$db->query("SELECT id FROM tickets WHERE title='AlicePrivateTicket'")->fetchColumn();
    check('Original message creation works',form('alice','/messages/'.$aliceTicket,['_token'=>csrf('alice'),'message'=>'AlicePrivateMessage'])[0]===302);
    check('Missing CSRF rejected', api('alice', 'consent', token:false)[0] === 419);
    check('Invalid CSRF rejected', api('alice', 'consent', token:'invalid')[0] === 419);
    check('Missing consent rejects processing', api('alice', 'consent')[1] === false);
    check('Consent is persisted', api('alice', 'consent', ['value'=>'yes','withdrawn'=>false], 'PATCH')[1] === ['value'=>'yes','withdrawn'=>false]);
    check('Consent belongs to authenticated ID', (string)$db->query('SELECT user_id FROM gdpr_consents')->fetchColumn()===$aliceId);
    foreach (['consent','lawful'] as $policy) check($policy . ' accepts stored consent', api('alice',$policy)[1] === true);
    check('Information report uses stored consent', api('alice','information')[1]['consent_valid'] === true);
    $access = api('alice','access')[1]; $data=json_decode($access['download']['content'],true);$fields=array_column($data['data_collected_from_user'],'field');sort($fields);
    check('Access maps real profile metadata', $data['user_id']===$aliceId && $fields===['email','messages','name','tickets']);
    $rect=api('alice','rectification',['updates'=>['name'=>'UpdatedAlice']])[1];
    check('Rectification returns scoped update plan', $rect['request']['validated'] && count(array_filter($rect['db_update_plan'],fn($x)=>$x['user_id']===$aliceId&&$x['field']==='name'))>0);
    check('Retention exception rejects email erasure', api('alice','erasure',['fields'=>['email']])[1]['decision']==='reject');
    check('Restriction returns acceptance plan', api('alice','restriction')[1]['decision']==='accept');
    $export=api('alice','portability')[1];$exportText=json_encode($export);
    check('Portability exports real values in two formats', $export['decision']==='accept'&&count($export['actions']['downloads'])===2&&str_contains($exportText,'AlicePrivate')&&str_contains($exportText,'alice@example.org'));
    check('Passwords and hashes excluded', !str_contains($exportText,'Integration123')&&!str_contains($exportText,'user_password_hash')&&!str_contains(json_encode($access),'password'));
    check('Objection returns stop plan',api('alice','objection',['reason'=>'marketing'])[1]['decision']==='accept');
    check('Complaint returns forwarding plan',api('alice','complaint',['authority_id'=>'cy-0000','description'=>'Test only'])[1]['decision']==='accept');
    check('Ordinary user cannot assess breach',api('alice','breach',['incident_id'=>'test'])[0]===403);
    login('admin','admin@admin.com');
    check('Existing host admin role may assess breach',api('admin','breach',['incident_id'=>'test'])[1]['decision']==='notify_users');
    check('Admin-authored reply created through original route',form('admin','/messages/'.$aliceTicket,['_token'=>csrf('admin'),'message'=>'AdminPrivateReply'])[0]===302);
    register('bob','BobPrivate','bob@example.org');
    $bobTicket=form('bob','/tickets',['_token'=>csrf('bob'),'title'=>'BobPrivateTicket','message'=>'BobPrivateBody','priority'=>'normal','status'=>'open']);
    check('Second original user creates independent ticket',$bobTicket[0]===302);
    check('Second user has independent consent',api('bob','consent')[1]===false);
    check('Non-admin breach request denied',api('bob','breach',['incident_id'=>'test'])[0]===403);
    api('bob','consent',['value'=>'yes','withdrawn'=>false],'PATCH');$bob=json_encode(api('bob','portability')[1]);
    check('Bob export includes owned ticket',str_contains($bob,'BobPrivateTicket'));
    check('Bob export excludes Alice profile and tickets',!str_contains($bob,'alice@example.org')&&!str_contains($bob,'AlicePrivateTicket'));
    $aliceExport=json_encode(api('alice','portability')[1]);
    check('Alice export includes own ticket and authored message',str_contains($aliceExport,'AlicePrivateTicket')&&str_contains($aliceExport,'AlicePrivateMessage'));
    check('Alice export excludes Bob profile/tickets',!str_contains($aliceExport,'bob@example.org')&&!str_contains($aliceExport,'BobPrivateTicket'));
    check('Exports omit other participants authored replies',!str_contains($aliceExport,'AdminPrivateReply'));
    api('admin','consent',['value'=>'yes','withdrawn'=>false],'PATCH');$adminExport=json_encode(api('admin','portability')[1]);
    check('Administrator export is still owner-scoped',!str_contains($adminExport,'AlicePrivateTicket')&&!str_contains($adminExport,'BobPrivateTicket'));
    check('Client cannot select another subject',api('bob','access',['user_id'=>'1'])[0]===422);
    check('Trusted checks cannot be overridden',api('alice','restriction',['checks'=>['data_correct'=>true]])[0]===422);
    check('Cross-session CSRF rejected',api('bob','consent',token:csrf('alice'))[0]===419);
    check('Strict boolean validation',api('alice','consent',['value'=>'yes','withdrawn'=>'false'],'PATCH')[0]===422);
    check('Unknown operations rejected',api('alice','unknown')[0]===422);
    check('Invalid JSON rejected',api('alice','consent','{')[0]===422);
    check('Array JSON rejected',api('alice','consent','[]')[0]===422);
    check('Unsupported method rejected',http('alice','/gdpr/consent')[0]===405);
    check('Consent withdrawal saved',api('alice','consent',['value'=>'yes','withdrawn'=>true],'PATCH')[0]===200);
    foreach(['consent','lawful'] as $policy)check($policy.' rejects after withdrawal',api('alice',$policy)[1]===false);
    check('Portability rejects after withdrawal',api('alice','portability')[1]['decision']==='reject');
    check('Access remains available after withdrawal',api('alice','access')[1]['request']['validated']===true);
    check('Eligible erasure returns delete plan',api('alice','erasure',['fields'=>['name'],'reason'=>'withdrawn_consent'])[1]['decision']==='accept');
    check('Plans did not modify original account',str_contains(http('alice','/profile')[1],'AlicePrivate'));
    logout('alice');check('Original logout invalidates GDPR session',http('alice','/gdpr/csrf',null,'GET',['Accept: application/json'])[0]===401);
    check('Original email login still works',login('alice','alice@example.org')[0]===302 && http('alice','/tickets')[0]===200);
    stop($opa);check('OPA outage returns 502',api('alice','consent')[0]===502);
    check('Router and policies remain unchanged',sourceHashes()===$original);
    echo "Integration result: $checks/$checks passed\n";
} catch(Throwable $error) {fwrite(STDERR,$error->getMessage()."\n".(@file_get_contents($temp.'/server.log')?:''));$exit=1;}
finally {
    foreach(array_reverse($processes) as $process)stop($process);
    $db=null;
    $files=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temp,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($files as $file){if($file->isDir())rmdir($file->getPathname());else unlink($file->getPathname());}rmdir($temp);
}
exit($exit);
