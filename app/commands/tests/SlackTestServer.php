<?php

/*
    CLD: 2026-11-25T14:00:00+02:00: a fake Slack Web API for the check suite (CheckCommand::suiteSlack), run by PHP's built-in server:
    SLACK_STATE=<a folder> php -S 127.0.0.1:<port> SlackTestServer.php
	/api/<method>: Bearer xoxb-test (else { ok: false, error: invalid_auth }), arguments as a form (lists as JSON text). Data in <state>/db.json, seeded: the workspace Acme, the
	bot (UBOT00001), Ana (UANA00001, ana@acme.com), Dan (UDAN00001), a bot user, a deactivated one; channels #general (CGEN00001, the bot in it), #random (CRND00001, the bot not in
	it: not_in_channel), private #ops (GOPS00001); Ana's direct messages with the bot (DANA00001). Messages get ts <state>/clock.txt (Unix seconds, else the clock) + a counter.
	Cursor pages (limit, response_metadata.next_cursor). Files: files.getUploadURLExternal gives /upload/<id> (POST the bytes), files.completeUploadExternal shares it;
	/download/<id> (Bearer) gives the bytes. <state>/throttle.json: how many 429 next (Retry-After: 1). Each request appended to <state>/requests.jsonl (method, path, args, auth)
*/

$state = getenv('SLACK_STATE') ?: sys_get_temp_dir();
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
$raw = file_get_contents('php://input');
$args = $_POST;
if (!$args && $raw !== '' && str_starts_with((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'application/x-www-form-urlencoded'))
    parse_str($raw, $args);
parse_str((string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY), $query);
$args += $query;
$auth = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? '');
file_put_contents("{$state}/requests.jsonl", json_encode([ 'method' => $_SERVER['REQUEST_METHOD'], 'path' => $path, 'args' => $args, 'auth' => $auth, 'size' => strlen($raw) ], JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);
$load = fn($name, $default = []) => is_file("{$state}/{$name}.json") ? json_decode(file_get_contents("{$state}/{$name}.json"), true) : $default;
$save = fn($name, $v) => file_put_contents("{$state}/{$name}.json", json_encode($v, JSON_UNESCAPED_SLASHES));
$out = function($v, $status = 200, array $headers = []) {
    http_response_code($status);
    foreach($headers as $k => $h)
	header("{$k}: {$h}");
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($v, JSON_UNESCAPED_SLASHES);
    exit;
};
$fail = fn($error, array $more = []) => $out([ 'ok' => false, 'error' => $error ] + $more);
$host = 'http://' . $_SERVER['HTTP_HOST'];
$now = (int)(trim((string)@file_get_contents("{$state}/clock.txt")) ?: time());

$db = $load('db', null) ?? [
    'users' => [
	'UBOT00001' => [ 'id' => 'UBOT00001', 'name' => 'qflows', 'real_name' => 'qflows', 'is_bot' => true, 'profile' => [ 'display_name' => 'qflows' ] ],
	'UANA00001' => [ 'id' => 'UANA00001', 'name' => 'ana', 'real_name' => 'Ana Pop', 'tz' => 'Europe/Bucharest', 'is_admin' => true, 'profile' => [ 'display_name' => 'Ana', 'email' => 'ana@acme.com', 'title' => 'SRE' ] ],
	'UDAN00001' => [ 'id' => 'UDAN00001', 'name' => 'dan', 'real_name' => 'Dan Ionescu', 'profile' => [ 'display_name' => '', 'email' => 'dan@acme.com' ] ],
	'UOTH00001' => [ 'id' => 'UOTH00001', 'name' => 'otherbot', 'is_bot' => true, 'profile' => [] ],
	'UOLD00001' => [ 'id' => 'UOLD00001', 'name' => 'old', 'deleted' => true, 'profile' => [ 'email' => 'old@acme.com' ] ],
    ],
    'channels' => [
	'CGEN00001' => [ 'id' => 'CGEN00001', 'name' => 'general', 'is_private' => false, 'is_archived' => false, 'members' => [ 'UBOT00001', 'UANA00001', 'UDAN00001' ], 'topic' => '', 'purpose' => '', 'created' => 1700000000 ],
	'CRND00001' => [ 'id' => 'CRND00001', 'name' => 'random', 'is_private' => false, 'is_archived' => false, 'members' => [ 'UANA00001' ], 'topic' => '', 'purpose' => '', 'created' => 1700000000 ],
	'GOPS00001' => [ 'id' => 'GOPS00001', 'name' => 'ops', 'is_private' => true, 'is_archived' => false, 'members' => [ 'UBOT00001', 'UANA00001' ], 'topic' => '', 'purpose' => '', 'created' => 1700000000 ],
	'DANA00001' => [ 'id' => 'DANA00001', 'is_im' => true, 'user' => 'UANA00001', 'members' => [ 'UBOT00001', 'UANA00001' ], 'created' => 1700000000 ],
    ],
    'messages' => [],
    'files' => [],
    'scheduled' => [],
    'seq' => 0,
];
$saveDb = function() use (&$db, $save) {
    $save('db', $db);
};
$newTs = function() use (&$db, $now) {
    $db['seq']++;
    return sprintf('%d.%06d', $now, $db['seq']);
};

//a file's bytes: the upload address (no token), the download (Bearer)
if (preg_match('~^/upload/(F[A-Z0-9]+)$~', $path, $m)) {
    $files = $load('uploads', []);
    $files[$m[1]] = base64_encode($raw);
    $save('uploads', $files);
    http_response_code(200);
    echo 'OK - ' . strlen($raw);
    exit;
}
if (preg_match('~^/download/(F[A-Z0-9]+)$~', $path, $m)) {
    if ($auth !== 'Bearer xoxb-test') {
	header('Content-Type: text/html');
	echo '<html>sign in</html>';
	exit;
    }
    $files = $load('uploads', []);
    header('Content-Type: ' . ($db['files'][$m[1]]['mimetype'] ?? 'application/octet-stream'));
    echo base64_decode($files[$m[1]] ?? '');
    exit;
}
if (!preg_match('~^/api/([A-Za-z.]+)$~', $path, $m))
    $out([ 'ok' => false, 'error' => 'unknown_method' ], 404);
$method = $m[1];
if ($auth !== 'Bearer xoxb-test')
    $fail('invalid_auth');
if (($t = (int)$load('throttle', 0)) > 0) {
    $save('throttle', $t - 1);
    $out([ 'ok' => false, 'error' => 'ratelimited' ], 429, [ 'Retry-After' => '1' ]);
}

$chan = function($id, $mustBeIn = false) use (&$db, $fail) {
    $c = $db['channels'][(string)$id] ?? null;
    if (!$c)
	$fail('channel_not_found');
    if ($mustBeIn && !in_array('UBOT00001', $c['members'], true))
	$fail('not_in_channel');
    return $c;
};
$view = fn($c) => [ 'id' => $c['id'], 'name' => $c['name'] ?? null, 'is_private' => $c['is_private'] ?? false, 'is_archived' => $c['is_archived'] ?? false, 'is_im' => $c['is_im'] ?? false,
    'user' => $c['user'] ?? null, 'is_member' => in_array('UBOT00001', $c['members'], true), 'topic' => [ 'value' => $c['topic'] ?? '' ], 'purpose' => [ 'value' => $c['purpose'] ?? '' ],
    'num_members' => count($c['members']), 'created' => $c['created'] ];
//a page of $items: [ items, next cursor ]
$page = function(array $items) use ($args) {
    $limit = max(1, (int)($args['limit'] ?? 100));
    $from = (int)($args['cursor'] ?? 0);
    $slice = array_slice(array_values($items), $from, $limit);
    $next = $from + $limit < count($items) ? (string)($from + $limit) : '';
    return [ $slice, [ 'next_cursor' => $next ] ];
};
$userOf = function($id) use (&$db, $fail) {
    $u = $db['users'][(string)$id] ?? null;
    if (!$u)
	$fail('user_not_found');
    return $u;
};
$msgOf = function($channel, $ts) use (&$db, $fail) {
    foreach($db['messages'] as $i => $msg)
	if ($msg['channel'] === $channel && $msg['ts'] === $ts)
	    return $i;
    $fail('message_not_found');
};

switch($method) {
    case 'auth.test':
	$out([ 'ok' => true, 'url' => 'https://acme.slack.com/', 'team' => 'Acme', 'user' => 'qflows', 'team_id' => 'TACME0001', 'user_id' => 'UBOT00001', 'bot_id' => 'BBOT00001' ]);

    case 'conversations.list':
	$types = explode(',', (string)($args['types'] ?? 'public_channel'));
	$list = array_filter($db['channels'], function($c) use ($types, $args) {
	    $kind = !empty($c['is_im']) ? 'im' : (!empty($c['is_private']) ? 'private_channel' : 'public_channel');
	    if (!in_array($kind, $types, true))
		return false;
	    if ($kind !== 'public_channel' && !in_array('UBOT00001', $c['members'], true))
		return false;
	    return !(($args['exclude_archived'] ?? 'false') === 'true' && !empty($c['is_archived']));
	});
	[ $items, $meta ] = $page(array_map($view, $list));
	$out([ 'ok' => true, 'channels' => $items, 'response_metadata' => $meta ]);

    case 'conversations.info':
	$out([ 'ok' => true, 'channel' => $view($chan($args['channel'] ?? '')) ]);

    case 'conversations.open':
	$u = (string)($args['users'] ?? '');
	$userOf($u);
	foreach($db['channels'] as $c)
	    if (!empty($c['is_im']) && $c['user'] === $u)
		$out([ 'ok' => true, 'channel' => [ 'id' => $c['id'] ] ]);
	$id = 'D' . strtoupper(substr(md5($u), 0, 8));
	$db['channels'][$id] = [ 'id' => $id, 'is_im' => true, 'user' => $u, 'members' => [ 'UBOT00001', $u ], 'created' => $now ];
	$saveDb();
	$out([ 'ok' => true, 'channel' => [ 'id' => $id ] ]);

    case 'conversations.create':
	$name = (string)($args['name'] ?? '');
	foreach($db['channels'] as $c)
	    if (($c['name'] ?? null) === $name)
		$fail('name_taken');
	$id = (($args['is_private'] ?? 'false') === 'true' ? 'G' : 'C') . strtoupper(substr(md5($name), 0, 8));
	$db['channels'][$id] = [ 'id' => $id, 'name' => $name, 'is_private' => ($args['is_private'] ?? 'false') === 'true', 'is_archived' => false, 'members' => [ 'UBOT00001' ], 'topic' => '', 'purpose' => '', 'created' => $now ];
	$saveDb();
	$out([ 'ok' => true, 'channel' => $view($db['channels'][$id]) ]);

    case 'conversations.rename':
	$c = $chan($args['channel'] ?? '', true);
	$db['channels'][$c['id']]['name'] = (string)$args['name'];
	$saveDb();
	$out([ 'ok' => true, 'channel' => $view($db['channels'][$c['id']]) ]);

    case 'conversations.setTopic':
    case 'conversations.setPurpose':
	$c = $chan($args['channel'] ?? '', true);
	$k = $method === 'conversations.setTopic' ? 'topic' : 'purpose';
	$db['channels'][$c['id']][$k] = (string)($args[$k] ?? '');
	$saveDb();
	$out([ 'ok' => true ]);

    case 'conversations.invite':
	$c = $chan($args['channel'] ?? '', true);
	$new = 0;
	foreach(explode(',', (string)($args['users'] ?? '')) as $u) {
	    $userOf($u);
	    if (!in_array($u, $db['channels'][$c['id']]['members'], true)) {
		$db['channels'][$c['id']]['members'][] = $u;
		$new++;
	    }
	}
	if (!$new)
	    $fail('already_in_channel');
	$saveDb();
	$out([ 'ok' => true, 'channel' => $view($db['channels'][$c['id']]) ]);

    case 'conversations.kick':
	$c = $chan($args['channel'] ?? '', true);
	$db['channels'][$c['id']]['members'] = array_values(array_diff($c['members'], [ (string)$args['user'] ]));
	$saveDb();
	$out([ 'ok' => true ]);

    case 'conversations.join':
	$c = $chan($args['channel'] ?? '');
	if (!empty($c['is_private']))
	    $fail('method_not_supported_for_channel_type');
	if (!in_array('UBOT00001', $c['members'], true))
	    $db['channels'][$c['id']]['members'][] = 'UBOT00001';
	$saveDb();
	$out([ 'ok' => true, 'channel' => $view($db['channels'][$c['id']]) ]);

    case 'conversations.archive':
    case 'conversations.unarchive':
	$c = $chan($args['channel'] ?? '', true);
	$db['channels'][$c['id']]['is_archived'] = $method === 'conversations.archive';
	$saveDb();
	$out([ 'ok' => true ]);

    case 'conversations.members':
	$c = $chan($args['channel'] ?? '');
	[ $items, $meta ] = $page($c['members']);
	$out([ 'ok' => true, 'members' => $items, 'response_metadata' => $meta ]);

    case 'conversations.history':
    case 'conversations.replies':
	$c = $chan($args['channel'] ?? '', true);
	$thread = (string)($args['ts'] ?? '');
	$list = array_filter($db['messages'], function($msg) use ($c, $method, $thread, $args) {
	    if ($msg['channel'] !== $c['id'])
		return false;
	    if (isset($args['oldest']) && (float)$msg['ts'] < (float)$args['oldest'])
		return false;
	    if (isset($args['latest']) && (float)$msg['ts'] > (float)$args['latest'])
		return false;
	    if ($method === 'conversations.history')
		return empty($msg['thread_ts']) || $msg['thread_ts'] === $msg['ts'] || !empty($msg['reply_broadcast']);
	    return $msg['ts'] === $thread || ($msg['thread_ts'] ?? '') === $thread;
	});
	usort($list, fn($a, $b) => $method === 'conversations.history' ? strcmp($b['ts'], $a['ts']) : strcmp($a['ts'], $b['ts']));
	[ $items, $meta ] = $page($list);
	$out([ 'ok' => true, 'messages' => $items, 'has_more' => $meta['next_cursor'] !== '', 'response_metadata' => $meta ]);

    case 'chat.postMessage':
    case 'chat.scheduleMessage':
	$c = $chan($args['channel'] ?? '', true);
	if (trim((string)($args['text'] ?? '')) === '' && empty($args['blocks']))
	    $fail('no_text');
	if (isset($args['blocks']) && !is_array(json_decode((string)$args['blocks'], true)))
	    $fail('invalid_blocks');
	if (isset($args['thread_ts']))
	    $msgOf($c['id'], (string)$args['thread_ts']);
	if ($method === 'chat.scheduleMessage') {
	    $id = 'Q' . (count($db['scheduled']) + 1);
	    $db['scheduled'][$id] = $args;
	    $saveDb();
	    $out([ 'ok' => true, 'scheduled_message_id' => $id, 'channel' => $c['id'], 'post_at' => (int)$args['post_at'] ]);
	}
	$msg = [ 'type' => 'message', 'channel' => $c['id'], 'ts' => $newTs(), 'user' => 'UBOT00001', 'bot_id' => 'BBOT00001', 'text' => (string)($args['text'] ?? '') ]
	    + (isset($args['thread_ts']) ? [ 'thread_ts' => (string)$args['thread_ts'] ] : []) + (($args['reply_broadcast'] ?? '') === 'true' ? [ 'reply_broadcast' => true ] : [])
	    + (isset($args['blocks']) ? [ 'blocks' => json_decode($args['blocks'], true) ] : []) + (($args['mrkdwn'] ?? 'true') === 'false' ? [ 'mrkdwn' => false ] : []);
	$db['messages'][] = $msg;
	$saveDb();
	$out([ 'ok' => true, 'channel' => $c['id'], 'ts' => $msg['ts'], 'message' => $msg ]);

    case 'chat.update':
	$c = $chan($args['channel'] ?? '', true);
	$i = $msgOf($c['id'], (string)($args['ts'] ?? ''));
	if ($db['messages'][$i]['user'] !== 'UBOT00001')
	    $fail('cant_update_message');
	$db['messages'][$i]['text'] = (string)($args['text'] ?? '');
	$saveDb();
	$out([ 'ok' => true, 'channel' => $c['id'], 'ts' => $db['messages'][$i]['ts'], 'text' => $db['messages'][$i]['text'], 'message' => $db['messages'][$i] ]);

    case 'chat.delete':
	$c = $chan($args['channel'] ?? '', true);
	$i = $msgOf($c['id'], (string)($args['ts'] ?? ''));
	if ($db['messages'][$i]['user'] !== 'UBOT00001')
	    $fail('cant_delete_message');
	array_splice($db['messages'], $i, 1);
	$saveDb();
	$out([ 'ok' => true, 'channel' => $c['id'], 'ts' => $args['ts'] ]);

    case 'chat.getPermalink':
	$c = $chan($args['channel'] ?? '');
	$msgOf($c['id'], (string)($args['message_ts'] ?? ''));
	$out([ 'ok' => true, 'channel' => $c['id'], 'permalink' => 'https://acme.slack.com/archives/' . $c['id'] . '/p' . str_replace('.', '', (string)$args['message_ts']) ]);

    case 'reactions.add':
	$c = $chan($args['channel'] ?? '', true);
	$i = $msgOf($c['id'], (string)($args['timestamp'] ?? ''));
	foreach($db['messages'][$i]['reactions'] ?? [] as $r)
	    if ($r['name'] === $args['name'])
		$fail('already_reacted');
	$db['messages'][$i]['reactions'][] = [ 'name' => (string)$args['name'], 'count' => 1, 'users' => [ 'UBOT00001' ] ];
	$saveDb();
	$out([ 'ok' => true ]);

    case 'users.info':
	$out([ 'ok' => true, 'user' => $userOf($args['user'] ?? '') ]);

    case 'users.lookupByEmail':
	foreach($db['users'] as $u)
	    if (strcasecmp((string)($u['profile']['email'] ?? ''), (string)($args['email'] ?? '')) === 0 && empty($u['deleted']))
		$out([ 'ok' => true, 'user' => $u ]);
	$fail('users_not_found');

    case 'users.list':
	[ $items, $meta ] = $page($db['users']);
	$out([ 'ok' => true, 'members' => $items, 'response_metadata' => $meta ]);

    case 'users.getPresence':
	$userOf($args['user'] ?? '');
	$out([ 'ok' => true, 'presence' => $args['user'] === 'UANA00001' ? 'active' : 'away' ]);

    case 'files.getUploadURLExternal':
	if ((int)($args['length'] ?? 0) < 1 || trim((string)($args['filename'] ?? '')) === '')
	    $fail('invalid_arguments');
	$id = 'F' . strtoupper(substr(md5($args['filename'] . $now . count($db['files'])), 0, 9));
	$db['files'][$id] = [ 'id' => $id, 'name' => (string)$args['filename'], 'size' => (int)$args['length'], 'mimetype' => str_ends_with($args['filename'], '.txt') ? 'text/plain' : 'application/octet-stream',
	    'user' => 'UBOT00001', 'created' => $now, 'shared' => false ];
	$saveDb();
	$out([ 'ok' => true, 'upload_url' => "{$host}/upload/{$id}", 'file_id' => $id ]);

    case 'files.completeUploadExternal':
	$files = json_decode((string)($args['files'] ?? '[]'), true) ?: [];
	$c = $chan($args['channel_id'] ?? '', true);
	$uploads = $load('uploads', []);
	$done = [];
	foreach($files as $f) {
	    $id = (string)($f['id'] ?? '');
	    if (!isset($db['files'][$id]) || !isset($uploads[$id]))
		$fail('file_not_found');
	    $db['files'][$id] += [ 'title' => (string)($f['title'] ?? '') ];
	    $db['files'][$id]['channels'] = [ $c['id'] ];
	    $db['files'][$id]['url_private_download'] = "{$host}/download/{$id}";
	    $db['files'][$id]['permalink'] = "https://acme.slack.com/files/UBOT00001/{$id}";
	    $done[] = $db['files'][$id];
	    $db['messages'][] = [ 'type' => 'message', 'subtype' => 'file_share', 'channel' => $c['id'], 'ts' => $newTs(), 'user' => 'UBOT00001', 'bot_id' => 'BBOT00001',
		'text' => (string)($args['initial_comment'] ?? ''), 'files' => [ [ 'id' => $id, 'name' => $db['files'][$id]['name'] ] ] ] + (isset($args['thread_ts']) ? [ 'thread_ts' => $args['thread_ts'] ] : []);
	}
	$saveDb();
	$out([ 'ok' => true, 'files' => $done ]);

    case 'files.info':
	$f = $db['files'][(string)($args['file'] ?? '')] ?? null;
	if (!$f)
	    $fail('file_not_found');
	$out([ 'ok' => true, 'file' => $f ]);

    case 'files.list':
	$list = array_values(array_filter($db['files'], fn($f) => !empty($f['channels']) && (!isset($args['channel']) || in_array($args['channel'], $f['channels'], true))
	    && (!isset($args['user']) || $f['user'] === $args['user'])));
	$count = max(1, (int)($args['count'] ?? 100));
	$pageNo = max(1, (int)($args['page'] ?? 1));
	$out([ 'ok' => true, 'files' => array_slice($list, ($pageNo - 1) * $count, $count), 'paging' => [ 'count' => $count, 'total' => count($list), 'page' => $pageNo, 'pages' => max(1, (int)ceil(count($list) / $count)) ] ]);

    case 'files.delete':
	if (!isset($db['files'][(string)($args['file'] ?? '')]))
	    $fail('file_not_found');
	unset($db['files'][(string)$args['file']]);
	$saveDb();
	$out([ 'ok' => true ]);

    case 'pins.add':
	$c = $chan($args['channel'] ?? '', true);
	$msgOf($c['id'], (string)($args['timestamp'] ?? ''));
	$out([ 'ok' => true ]);
}
$fail('unknown_method');
