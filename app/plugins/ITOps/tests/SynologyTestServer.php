<?php

/*
    CLD: 2026-11-20T12:00:00+02:00: a fake Synology NAS (DSM 7.2 Web API) for the check suite (checks.php: synology), run by PHP's built-in server:
    SYNO_STATE=<a folder> php -S 127.0.0.1:<port> SynologyTestServer.php
	each request appended to <state>/requests.jsonl (api, method, version, params without passwords, sid, synotoken header)
	/webapi/query.cgi SYNO.API.Info: the APIs (entry.cgi), less those listed in <state>/missing.json
	/webapi/entry.cgi (POST form; GET for Download; multipart for Upload, api in the query):
	    SYNO.API.Auth login: admin / pw (sid-admin), user / pw (sid-user: not an administrator, SYNO.Core.* and the others: 105), otp / pw with otp_code (the TOTP of
		JBSWY3DPEHPK3PXP; none: 403, wrong: 404; a code used already: 404); else 400; logout
	    the others: a known _sid, else 119
	    File Station: List (list_share, list, getinfo), Search (start, list: finished at the 2nd read, stop, clean), CreateFolder, Rename, CopyMove / Delete / Compress /
		Extract (start; status finished at the 2nd read), Upload, Download, Sharing create; the files in <state>/fs.json { path: { dir, data (base64), mtime } }
	    SYNO.Core.System info / reboot / shutdown, System.Utilization get, Upgrade.Server check (<state>/update.json), Package list, Package.Control start / stop,
		CurrentConnection list, Share list / create / delete, User list / get / create / set / delete, Group list, Group.Member list / add / remove
	    SYNO.Storage.CGI.Storage load_info: <state>/storage.json
	    SYNO.Backup.Task list / get / status / backup / cancel: <state>/tasks.json (a backup: status backup for 2 reads, then done with a new time)
*/

$state = getenv('SYNO_STATE') ?: sys_get_temp_dir();
$full = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$headers = array_change_key_case(getallheaders(), CASE_LOWER);
$p = $_GET + $_POST;
$load = fn($name, $default = []) => is_file("{$state}/{$name}.json") ? json_decode(file_get_contents("{$state}/{$name}.json"), true) : $default;
$save = fn($name, $data) => file_put_contents("{$state}/{$name}.json", json_encode($data, JSON_UNESCAPED_SLASHES));
$logged = $p;
unset($logged['passwd'], $logged['password']);
file_put_contents("{$state}/requests.jsonl", json_encode([ 'path' => $full, 'api' => $p['api'] ?? null, 'method' => $p['method'] ?? null, 'version' => $p['version'] ?? null,
    'params' => array_diff_key($logged, array_flip([ 'api', 'method', 'version', '_sid' ])), 'sid' => $p['_sid'] ?? null, 'token' => $headers['x-syno-token'] ?? null,
    'password' => $p['password'] ?? null ], JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);

$ok = function($data = null) {
    header('Content-Type: application/json');
    echo json_encode($data === null ? [ 'success' => true ] : [ 'success' => true, 'data' => $data ], JSON_UNESCAPED_SLASHES);
    exit;
};
$fail = function($code, $errors = null) {
    header('Content-Type: application/json');
    echo json_encode([ 'success' => false, 'error' => [ 'code' => $code ] + ($errors ? [ 'errors' => $errors ] : []) ], JSON_UNESCAPED_SLASHES);
    exit;
};
$json = fn($v) => is_string($v) && ($d = json_decode($v, true)) !== null ? $d : $v;
$list = fn($v) => is_array($j = $json($v)) ? $j : array_filter(explode(',', (string)$v), fn($x) => $x !== '');

$APIS = [ 'SYNO.API.Auth' => [ 1, 7 ], 'SYNO.FileStation.List' => [ 1, 2 ], 'SYNO.FileStation.Search' => [ 1, 2 ], 'SYNO.FileStation.CreateFolder' => [ 1, 2 ],
    'SYNO.FileStation.Rename' => [ 1, 2 ], 'SYNO.FileStation.CopyMove' => [ 1, 3 ], 'SYNO.FileStation.Delete' => [ 1, 2 ], 'SYNO.FileStation.Compress' => [ 1, 3 ],
    'SYNO.FileStation.Extract' => [ 1, 2 ], 'SYNO.FileStation.Upload' => [ 1, 3 ], 'SYNO.FileStation.Download' => [ 1, 2 ], 'SYNO.FileStation.Sharing' => [ 1, 3 ],
    'SYNO.Core.System' => [ 1, 3 ], 'SYNO.Core.System.Utilization' => [ 1, 1 ], 'SYNO.Core.Upgrade.Server' => [ 1, 4 ], 'SYNO.Core.Package' => [ 1, 2 ],
    'SYNO.Core.Package.Control' => [ 1, 1 ], 'SYNO.Core.CurrentConnection' => [ 1, 1 ], 'SYNO.Core.Share' => [ 1, 1 ], 'SYNO.Core.User' => [ 1, 1 ],
    'SYNO.Core.Group' => [ 1, 1 ], 'SYNO.Core.Group.Member' => [ 1, 1 ], 'SYNO.Storage.CGI.Storage' => [ 1, 1 ], 'SYNO.Backup.Task' => [ 1, 1 ] ];
foreach($load('missing') as $m)
    unset($APIS[$m]);

if ($full === '/webapi/query.cgi') {
    $out = [];
    foreach($APIS as $name => [ $min, $max ])
	$out[$name] = [ 'path' => 'entry.cgi', 'minVersion' => $min, 'maxVersion' => $max, 'requestFormat' => 'JSON' ];
    $ok($out);
}
if ($full !== '/webapi/entry.cgi') {
    http_response_code(404);
    echo '<html>not found</html>';
    exit;
}
$api = (string)($p['api'] ?? '');
$method = (string)($p['method'] ?? '');
if (!isset($APIS[$api]))
    $fail(102);
if ((int)($p['version'] ?? 0) > $APIS[$api][1])
    $fail(104);

//the TOTP code (RFC 6238, SHA-1, 6 digits) of a base32 secret at time slice $t
$totp = function($secret, $t) {
    $alpha = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach(str_split(rtrim($secret, '=')) as $c)
	$bits .= str_pad(decbin(strpos($alpha, $c)), 5, '0', STR_PAD_LEFT);
    $key = '';
    foreach(str_split($bits, 8) as $b)
	if (strlen($b) === 8)
	    $key .= chr(bindec($b));
    $h = hash_hmac('sha1', pack('N*', 0, $t), $key, true);
    $o = ord($h[19]) & 0xf;
    $v = ((ord($h[$o]) & 0x7f) << 24) | (ord($h[$o + 1]) << 16) | (ord($h[$o + 2]) << 8) | ord($h[$o + 3]);
    return str_pad((string)($v % 1000000), 6, '0', STR_PAD_LEFT);
};

if ($api === 'SYNO.API.Auth') {
    if ($method === 'logout')
	$ok();
    $account = (string)($p['account'] ?? '');
    $pw = (string)($p['passwd'] ?? '');
    if ($pw !== 'pw' || !in_array($account, [ 'admin', 'user', 'otp' ], true))
	$fail(400);
    if ($account === 'otp') {
	$code = (string)($p['otp_code'] ?? '');
	if ($code === '')
	    $fail(403);
	$used = $load('otp-used');
	$slice = intdiv(time(), 30);
	$valid = in_array($code, [ $totp('JBSWY3DPEHPK3PXP', $slice), $totp('JBSWY3DPEHPK3PXP', $slice - 1) ], true);
	if (!$valid || in_array($code, $used, true))
	    $fail(404);
	$used[] = $code;
	$save('otp-used', $used);
    }
    $ok([ 'sid' => "sid-{$account}", 'synotoken' => "tok-{$account}", 'did' => '' ]);
}

$sid = (string)($p['_sid'] ?? '');
if (!in_array($sid, [ 'sid-admin', 'sid-user', 'sid-otp' ], true))
    $fail(119);
$admin = $sid !== 'sid-user';
if (!$admin && !str_starts_with($api, 'SYNO.FileStation.'))
    $fail(105);
if (($headers['x-syno-token'] ?? '') !== 'tok-' . substr($sid, 4))
    $fail(119);

// ------------------------------------------------------------------------------------
//File Station

$fs = $load('fs');
$item = function($path) use (&$fs) {
    $f = $fs[$path];
    return [ 'path' => $path, 'name' => basename($path), 'isdir' => $f['dir'], 'additional' => [ 'real_path' => '/volume1' . $path,
	'size' => $f['dir'] ? 0 : strlen(base64_decode($f['data'] ?? '')), 'owner' => [ 'user' => 'admin', 'group' => 'users' ],
	'time' => [ 'mtime' => $f['mtime'] ?? 1790000000, 'crtime' => $f['mtime'] ?? 1790000000 ], 'type' => $f['dir'] ? '' : strtoupper(pathinfo($path, PATHINFO_EXTENSION)) ] ];
};
$children = function($folder) use (&$fs) {
    $out = [];
    foreach(array_keys($fs) as $path)
	if (dirname($path) === $folder && $path !== $folder)
	    $out[] = $path;
    sort($out);
    return $out;
};
$wildcard = fn($pattern, $name) => $pattern === '' || fnmatch(strtolower($pattern), strtolower($name));
$tasks = $load('fstasks');
$newTask = function(array $t) use (&$tasks, $save) {
    $id = 'FileStation_' . bin2hex(random_bytes(4));
    $tasks[$id] = $t + [ 'reads' => 0 ];
    $save('fstasks', $tasks);
    return $id;
};
$copyTree = function($from, $to) use (&$fs) {
    foreach(array_keys($fs) as $path)
	if ($path === $from || str_starts_with($path, $from . '/'))
	    $fs[$to . substr($path, strlen($from))] = $fs[$path];
};
$deleteTree = function($path) use (&$fs) {
    foreach(array_keys($fs) as $x)
	if ($x === $path || str_starts_with($x, $path . '/'))
	    unset($fs[$x]);
};

switch("{$api}.{$method}") {
    case 'SYNO.FileStation.List.list_share':
	$shares = array_values(array_filter(array_keys($fs), fn($x) => substr_count($x, '/') === 1));
	sort($shares);
	$ok([ 'total' => count($shares), 'offset' => 0, 'shares' => array_map(fn($s) => $item($s) + [ 'additional' => $item($s)['additional']
	    + [ 'volume_status' => [ 'freespace' => 500, 'totalspace' => 1000, 'readonly' => false ] ] ], $shares) ]);
    case 'SYNO.FileStation.List.list':
	$folder = (string)$p['folder_path'];
	if (!isset($fs[$folder]))
	    $fail(408);
	$rows = array_values(array_filter($children($folder), fn($x) => $wildcard((string)($p['pattern'] ?? ''), basename($x))
	    && (($p['filetype'] ?? 'all') === 'all' || ($p['filetype'] === 'dir') === $fs[$x]['dir'])));
	if (($p['sort_direction'] ?? 'asc') === 'desc')
	    $rows = array_reverse($rows);
	$offset = (int)($p['offset'] ?? 0);
	$limit = (int)($p['limit'] ?? 0) ?: count($rows);
	$ok([ 'total' => count($rows), 'offset' => $offset, 'files' => array_map($item, array_slice($rows, $offset, $limit)) ]);
    case 'SYNO.FileStation.List.getinfo':
	$ok([ 'files' => array_map(fn($x) => isset($fs[$x]) ? $item($x) : [ 'code' => 408, 'path' => $x ], $list($p['path'])) ]);
    case 'SYNO.FileStation.Search.start':
	$folder = $list($p['folder_path'])[0] ?? '';
	$exts = array_filter(explode(',', strtolower((string)($p['extension'] ?? ''))));
	$found = array_values(array_filter(array_keys($fs), fn($x) => str_starts_with($x, $folder . '/') && !$fs[$x]['dir'] && $wildcard((string)($p['pattern'] ?? ''), basename($x))
	    && (!$exts || in_array(strtolower(pathinfo($x, PATHINFO_EXTENSION)), $exts, true))));
	$ok([ 'taskid' => $newTask([ 'kind' => 'search', 'found' => $found ]) ]);
    case 'SYNO.FileStation.Search.list':
	$t = $tasks[$p['taskid']] ?? $fail(599);
	$tasks[$p['taskid']]['reads']++;
	$save('fstasks', $tasks);
	$finished = $t['reads'] >= 1;
	$found = $finished ? $t['found'] : array_slice($t['found'], 0, 1);
	$ok([ 'finished' => $finished, 'total' => count($found), 'offset' => 0, 'files' => array_map($item, array_slice($found, 0, (int)($p['limit'] ?? 100))) ]);
    case 'SYNO.FileStation.Search.stop':
    case 'SYNO.FileStation.Search.clean':
	$ok();
    case 'SYNO.FileStation.CreateFolder.create':
	$folder = $list($p['folder_path'])[0];
	$name = $list($p['name'])[0];
	$path = "{$folder}/{$name}";
	if (isset($fs[$path]))
	    $fail(1100, [ [ 'code' => 414, 'path' => $path ] ]);
	$at = '';
	foreach(array_filter(explode('/', $path)) as $part) {
	    $at .= '/' . $part;
	    $fs[$at] ??= [ 'dir' => true, 'mtime' => time() ];
	}
	$save('fs', $fs);
	$ok([ 'folders' => [ $item($path) ] ]);
    case 'SYNO.FileStation.Rename.rename':
	$path = $list($p['path'])[0];
	$to = dirname($path) . '/' . $list($p['name'])[0];
	if (!isset($fs[$path]))
	    $fail(1200, [ [ 'code' => 408, 'path' => $path ] ]);
	$copyTree($path, $to);
	$deleteTree($path);
	$save('fs', $fs);
	$ok([ 'files' => [ $item($to) ] ]);
    case 'SYNO.FileStation.CopyMove.start':
	foreach($list($p['path']) as $x)
	    if (!isset($fs[$x]))
		$fail(1000, [ [ 'code' => 408, 'path' => $x ] ]);
	$ok([ 'taskid' => $newTask([ 'kind' => 'copymove', 'paths' => $list($p['path']), 'dest' => $p['dest_folder_path'], 'move' => ($p['remove_src'] ?? '') === 'true',
	    'overwrite' => ($p['overwrite'] ?? '') === 'true' ]) ]);
    case 'SYNO.FileStation.Delete.start':
	$ok([ 'taskid' => $newTask([ 'kind' => 'delete', 'paths' => $list($p['path']) ]) ]);
    case 'SYNO.FileStation.Compress.start':
	$ok([ 'taskid' => $newTask([ 'kind' => 'compress', 'paths' => $list($p['path']), 'dest' => $p['dest_file_path'] ]) ]);
    case 'SYNO.FileStation.Extract.start':
	$ok([ 'taskid' => $newTask([ 'kind' => 'extract', 'file' => $p['file_path'], 'dest' => $p['dest_folder_path'] ]) ]);
    case 'SYNO.FileStation.CopyMove.status':
    case 'SYNO.FileStation.Delete.status':
    case 'SYNO.FileStation.Compress.status':
    case 'SYNO.FileStation.Extract.status':
	$t = $tasks[$p['taskid']] ?? $fail(599);
	$tasks[$p['taskid']]['reads']++;
	$finished = $t['reads'] >= 1;
	if ($finished && empty($t['done'])) {
	    $tasks[$p['taskid']]['done'] = true;
	    if ($t['kind'] === 'copymove')
		foreach($t['paths'] as $x) {
		    $copyTree($x, $t['dest'] . '/' . basename($x));
		    if ($t['move'])
			$deleteTree($x);
		}
	    elseif ($t['kind'] === 'delete')
		foreach($t['paths'] as $x)
		    $deleteTree($x);
	    elseif ($t['kind'] === 'compress')
		$fs[$t['dest']] = [ 'dir' => false, 'data' => base64_encode('PK-fake'), 'mtime' => time() ];
	    elseif ($t['kind'] === 'extract')
		$fs[$t['dest'] . '/extracted.txt'] = [ 'dir' => false, 'data' => base64_encode('x'), 'mtime' => time() ];
	    $save('fs', $fs);
	}
	$save('fstasks', $tasks);
	$ok([ 'finished' => $finished, 'progress' => $finished ? 1 : 0.5, 'processed_num' => $finished ? 1 : 0, 'path' => $t['paths'][0] ?? $t['file'] ?? '' ]);
    case 'SYNO.FileStation.Upload.upload':
	$folder = (string)($_POST['path'] ?? '');
	$file = $_FILES['file'] ?? null;
	if (!$file || $file['error'] !== UPLOAD_ERR_OK)
	    $fail(1802);
	$path = $folder . '/' . $file['name'];
	if (isset($fs[$path]) && ($_POST['overwrite'] ?? null) === null)
	    $fail(1805);
	if (!isset($fs[$folder]) && ($_POST['create_parents'] ?? '') !== 'true')
	    $fail(408);
	$fs[$folder] ??= [ 'dir' => true, 'mtime' => time() ];
	if (!isset($fs[$path]) || ($_POST['overwrite'] ?? '') === 'true')
	    $fs[$path] = [ 'dir' => false, 'data' => base64_encode(file_get_contents($file['tmp_name'])), 'mtime' => time() ];
	$save('fs', $fs);
	$ok([ 'blSkip' => false, 'file' => $file['name'] ]);
    case 'SYNO.FileStation.Download.download':
	$path = (string)$p['path'];
	if (!isset($fs[$path]) || $fs[$path]['dir'])
	    $fail(408);
	header('Content-Type: application/octet-stream');
	echo base64_decode($fs[$path]['data']);
	exit;
    case 'SYNO.FileStation.Sharing.create':
	if (!isset($fs[(string)$p['path']]))
	    $fail(408);
	$ok([ 'links' => [ [ 'id' => 'L1', 'url' => 'https://gofile.me/abc/L1', 'path' => $p['path'], 'date_expired' => $p['date_expired'] ?? '',
	    'has_password' => isset($p['password']) ] ] ]);
}

// ------------------------------------------------------------------------------------
//the system, storage, users, backup

$users = $load('users', [ [ 'name' => 'admin', 'email' => '', 'description' => 'System default user', 'expired' => 'normal' ] ]);
$groups = $load('groups', [ 'administrators' => [ 'admin' ], 'users' => [ 'admin' ] ]);
$btasks = $load('tasks');
switch("{$api}.{$method}") {
    case 'SYNO.Core.System.info':
	$ok([ 'model' => 'DS923+', 'serial' => '2260ABC', 'firmware_ver' => 'DSM 7.2.2-72806 Update 3', 'sys_temp' => (int)($load('temp', [ 45 ])[0]), 'up_time' => '10:01:02', 'ram_size' => 4096 ]);
    case 'SYNO.Core.System.reboot':
    case 'SYNO.Core.System.shutdown':
	$ok();
    case 'SYNO.Core.System.Utilization.get':
	$ok([ 'cpu' => [ 'user_load' => 7, 'system_load' => 3, 'other_load' => 1 ], 'memory' => [ 'real_usage' => 42 ] ]);
    case 'SYNO.Core.Upgrade.Server.check':
	$ok([ 'update' => $load('update', [ 'available' => false ]) ]);
    case 'SYNO.Core.Package.list':
	$ok([ 'packages' => [ [ 'id' => 'HyperBackup', 'name' => 'Hyper Backup', 'version' => '4.1.0', 'additional' => [ 'status' => 'running' ] ],
	    [ 'id' => 'SynologyDrive', 'name' => 'Synology Drive Server', 'version' => '3.5.0', 'additional' => [ 'status' => 'stop' ] ] ], 'total' => 2 ]);
    case 'SYNO.Core.Package.Control.start':
    case 'SYNO.Core.Package.Control.stop':
	if (!in_array($p['id'] ?? '', [ 'HyperBackup', 'SynologyDrive' ], true))
	    $fail(4501);
	$ok();
    case 'SYNO.Core.CurrentConnection.list':
	$ok([ 'items' => [ [ 'who' => 'admin', 'type' => 'HTTP/HTTPS', 'from' => '10.0.0.5', 'descr' => 'DSM' ] ], 'total' => 1 ]);
    case 'SYNO.Storage.CGI.Storage.load_info':
	$ok($load('storage'));
    case 'SYNO.Core.Share.list':
	$ok([ 'shares' => [ [ 'name' => 'docs', 'vol_path' => '/volume1', 'desc' => 'Documents' ] ], 'total' => 1 ]);
    case 'SYNO.Core.Share.create':
	$info = $json($p['shareinfo'] ?? '');
	if (!is_array($info) || ($info['name'] ?? '') !== ($p['name'] ?? ''))
	    $fail(120);
	$ok([ 'name' => $p['name'] ]);
    case 'SYNO.Core.Share.delete':
	$ok();
    case 'SYNO.Core.User.list':
	$offset = (int)($p['offset'] ?? 0);
	$ok([ 'users' => array_slice($users, $offset, (int)($p['limit'] ?? 100)), 'total' => count($users), 'offset' => $offset ]);
    case 'SYNO.Core.User.get':
	foreach($users as $u)
	    if ($u['name'] === $p['name'])
		$ok([ 'users' => [ $u ] ]);
	$fail(3106);
    case 'SYNO.Core.User.create':
	foreach($users as $u)
	    if ($u['name'] === $p['name'])
		$fail(3104);
	$users[] = [ 'name' => $p['name'], 'email' => $p['email'] ?? '', 'description' => $p['description'] ?? '', 'expired' => $p['expired'] ?? 'normal' ];
	$save('users', $users);
	$ok([ 'name' => $p['name'], 'uid' => 1030 ]);
    case 'SYNO.Core.User.set':
	foreach($users as $i => $u)
	    if ($u['name'] === $p['name']) {
		foreach([ 'email', 'description', 'expired' ] as $k)
		    if (isset($p[$k]))
			$users[$i][$k] = $p[$k];
		$save('users', $users);
		$ok([ 'name' => $p['name'] ]);
	    }
	$fail(3106);
    case 'SYNO.Core.User.delete':
	$names = $list($p['name']);
	$users = array_values(array_filter($users, fn($u) => !in_array($u['name'], $names, true)));
	$save('users', $users);
	$ok();
    case 'SYNO.Core.Group.list':
	$ok([ 'groups' => array_map(fn($g) => [ 'name' => $g, 'description' => '' ], array_keys($groups)), 'total' => count($groups) ]);
    case 'SYNO.Core.Group.Member.list':
	if (!isset($groups[$p['group']]))
	    $fail(3201);
	$ok([ 'users' => array_map(fn($n) => [ 'name' => $n ], $groups[$p['group']]), 'total' => count($groups[$p['group']]) ]);
    case 'SYNO.Core.Group.Member.add':
    case 'SYNO.Core.Group.Member.remove':
	if (!isset($groups[$p['group']]))
	    $fail(3201);
	$names = $list($p['name']);
	$groups[$p['group']] = $method === 'add' ? array_values(array_unique(array_merge($groups[$p['group']], $names))) : array_values(array_diff($groups[$p['group']], $names));
	$save('groups', $groups);
	$ok();
    case 'SYNO.Backup.Task.list':
	$ok([ 'task_list' => array_values($btasks), 'total' => count($btasks) ]);
    case 'SYNO.Backup.Task.get':
	$t = $btasks[(string)$p['task_id']] ?? $fail(4401);
	$ok([ 'task' => $t ]);
    case 'SYNO.Backup.Task.status':
	$id = (string)$p['task_id'];
	$t = $btasks[$id] ?? $fail(4401);
	if (($t['status'] ?? '') === 'backup') {
	    $btasks[$id]['reads'] = ($t['reads'] ?? 0) + 1;
	    if ($btasks[$id]['reads'] >= 2)
		$btasks[$id] = [ 'status' => 'none', 'last_bkp_result' => 'done', 'last_bkp_time' => '2026/11/20 12:00', 'reads' => 0 ] + $btasks[$id];
	    $save('tasks', $btasks);
	}
	$ok([ 'task_id' => (int)$id, 'status' => $btasks[$id]['status'], 'last_bkp_result' => $btasks[$id]['last_bkp_result'] ?? '' ]);
    case 'SYNO.Backup.Task.backup':
	$id = (string)$p['task_id'];
	if (!isset($btasks[$id]))
	    $fail(4401);
	$btasks[$id]['status'] = 'backup';
	$btasks[$id]['reads'] = 0;
	$save('tasks', $btasks);
	$ok();
    case 'SYNO.Backup.Task.cancel':
	$ok();
}
$fail(103);
