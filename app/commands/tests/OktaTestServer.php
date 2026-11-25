<?php

/*
    CLD: 2026-11-26T02:00:00+02:00: a fake Okta org for the check suite (CheckCommand::suiteOkta), run by PHP's built-in server:
    OKTA_STATE=<a folder> php -S 127.0.0.1:<port> OktaTestServer.php
	SSWS ssws-check, or /oauth2/v1/token (client_credentials, a client assertion signed with the key of <state>/app.pub, iss = sub = client-check) -> Bearer tok-okta.
	People (ana, dan, a deactivated one; by ID or login), lifecycle (activate, deactivate, suspend, unsuspend, reset_password, expire_password, reset_factors), sessions,
	groups (Sales, an app group, Everyone) and members, apps (Payroll) and assignments, appLinks, factors, the System Log (/logs: since, filter by eventType / outcome),
	/org, /users/me. Pages: limit, after, two Link headers (self, next). <state>/throttle.json: how many 429 next (x-rate-limit-reset in 1 s).
	Each request appended to <state>/requests.jsonl (method, path, query, body, auth)
*/

$state = getenv('OKTA_STATE') ?: sys_get_temp_dir();
$method = $_SERVER['REQUEST_METHOD'];
$path = rawurldecode((string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
parse_str((string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY), $query);
$raw = file_get_contents('php://input');
$body = json_decode($raw, true);
$auth = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? '');
file_put_contents("{$state}/requests.jsonl", json_encode([ 'method' => $method, 'path' => $path, 'query' => $query, 'body' => $body ?? $_POST, 'auth' => substr($auth, 0, 16) ], JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);
$load = fn($name, $default = []) => is_file("{$state}/{$name}.json") ? json_decode(file_get_contents("{$state}/{$name}.json"), true) : $default;
$save = fn($name, $v) => file_put_contents("{$state}/{$name}.json", json_encode($v, JSON_UNESCAPED_SLASHES));
$host = 'http://' . $_SERVER['HTTP_HOST'];
$out = function($v, $status = 200, array $headers = []) {
    http_response_code($status);
    foreach($headers as $k => $h)
	foreach((array)$h as $one)
	    header("{$k}: {$one}", false);
    if ($v !== null) {
	header('Content-Type: application/json');
	echo json_encode($v, JSON_UNESCAPED_SLASHES);
    }
    exit;
};
$fail = fn($status, $code, $summary, array $causes = []) => $out([ 'errorCode' => $code, 'errorSummary' => $summary, 'errorCauses' => array_map(fn($c) => [ 'errorSummary' => $c ], $causes) ], $status);

if ($path === '/oauth2/v1/token') {
    $a = (string)($_POST['client_assertion'] ?? '');
    $parts = explode('.', $a);
    $d = fn($s) => base64_decode(strtr($s, '-_', '+/'));
    $claims = json_decode($d($parts[1] ?? ''), true);
    $ok = count($parts) === 3 && is_file("{$state}/app.pub") && ($claims['iss'] ?? '') === 'client-check' && ($claims['sub'] ?? '') === 'client-check'
	&& openssl_verify("{$parts[0]}.{$parts[1]}", $d($parts[2]), file_get_contents("{$state}/app.pub"), OPENSSL_ALGO_SHA256) === 1;
    if (!$ok)
	$out([ 'error' => 'invalid_client', 'error_description' => 'The client_assertion signature is invalid.' ], 401);
    $out([ 'access_token' => 'tok-okta', 'token_type' => 'Bearer', 'expires_in' => 3600, 'scope' => (string)($_POST['scope'] ?? '') ]);
}
if ($auth !== 'SSWS ssws-check' && $auth !== 'Bearer tok-okta')
    $fail(401, 'E0000011', 'Invalid token provided');
if (($t = (int)$load('throttle', 0)) > 0) {
    $save('throttle', $t - 1);
    $out([ 'errorCode' => 'E0000047', 'errorSummary' => 'API call exceeded rate limit due to too many requests.' ], 429, [ 'x-rate-limit-reset' => (string)(time() + 1) ]);
}
if (!str_starts_with($path, '/api/v1/'))
    $fail(404, 'E0000022', 'The endpoint does not support the provided HTTP method');
$p = substr($path, 7);

$db = $load('db', null) ?? [
    'users' => [
	'00uana0000000001' => [ 'id' => '00uana0000000001', 'status' => 'ACTIVE', 'created' => '2026-01-01T10:00:00.000Z', 'profile' => [ 'login' => 'ana@acme.com', 'email' => 'ana@acme.com', 'firstName' => 'Ana', 'lastName' => 'Pop', 'department' => 'IT' ] ],
	'00udan0000000002' => [ 'id' => '00udan0000000002', 'status' => 'ACTIVE', 'created' => '2026-02-01T10:00:00.000Z', 'profile' => [ 'login' => 'dan@acme.com', 'email' => 'dan@acme.com', 'firstName' => 'Dan', 'lastName' => 'Ionescu', 'department' => 'Sales' ] ],
	'00uold0000000003' => [ 'id' => '00uold0000000003', 'status' => 'DEPROVISIONED', 'created' => '2025-01-01T10:00:00.000Z', 'profile' => [ 'login' => 'old@acme.com', 'email' => 'old@acme.com', 'firstName' => 'Old', 'lastName' => 'User' ] ],
    ],
    'groups' => [
	'00gsales00000001' => [ 'id' => '00gsales00000001', 'type' => 'OKTA_GROUP', 'profile' => [ 'name' => 'Sales', 'description' => 'Sales team' ], 'members' => [ '00udan0000000002' ] ],
	'00gapp0000000002' => [ 'id' => '00gapp0000000002', 'type' => 'APP_GROUP', 'profile' => [ 'name' => 'AD Staff' ], 'members' => [ '00udan0000000002' ] ],
	'00gevery00000003' => [ 'id' => '00gevery00000003', 'type' => 'BUILT_IN', 'profile' => [ 'name' => 'Everyone' ], 'members' => [ '00uana0000000001', '00udan0000000002' ] ],
    ],
    'apps' => [ '0oapayroll000001' => [ 'id' => '0oapayroll000001', 'label' => 'Payroll', 'name' => 'payroll', 'status' => 'ACTIVE', 'users' => [ '00udan0000000002' ] ] ],
    'factors' => [ '00udan0000000002' => [ [ 'id' => 'opf1abcdefghijklm', 'factorType' => 'push', 'provider' => 'OKTA', 'status' => 'ACTIVE', 'profile' => [ 'name' => 'iPhone' ] ],
	[ 'id' => 'sms1abcdefghijklm', 'factorType' => 'sms', 'provider' => 'OKTA', 'status' => 'ACTIVE', 'profile' => [ 'phoneNumber' => '+40722' ] ] ] ],
    'sessionsCleared' => [],
    'logs' => [],
    'seq' => 0,
];
$saveDb = function() use (&$db, $save) {
    $save('db', $db);
};
$now = gmdate('Y-m-d\TH:i:s.000\Z');
$event = function($type, $userId, $outcome = 'SUCCESS') use (&$db, $now) {
    $u = $db['users'][$userId] ?? null;
    $db['seq']++;
    $db['logs'][] = [ 'uuid' => 'evt-' . $db['seq'], 'eventType' => $type, 'published' => $now, 'severity' => 'INFO', 'displayMessage' => $type, 'outcome' => [ 'result' => $outcome ],
	'actor' => [ 'id' => '00uadmin', 'type' => 'User', 'alternateId' => 'admin@acme.com', 'displayName' => 'Admin' ],
	'target' => [ [ 'id' => $userId, 'type' => 'User', 'alternateId' => $u['profile']['login'] ?? '', 'displayName' => trim(($u['profile']['firstName'] ?? '') . ' ' . ($u['profile']['lastName'] ?? '')) ] ],
	'client' => [ 'ipAddress' => '203.0.113.9', 'geographicalContext' => [ 'city' => 'Bucharest', 'country' => 'Romania' ] ] ];
};
$userRef = function($ref) use (&$db) {
    if (isset($db['users'][$ref]))
	return $ref;
    foreach($db['users'] as $u)
	if (strcasecmp($u['profile']['login'], $ref) === 0)
	    return $u['id'];
    return null;
};
$uview = fn($u) => array_diff_key($u, [ 'password' => 1 ]) + [ '_links' => [] ];
$gview = fn($g) => array_diff_key($g, [ 'members' => 1 ]) + [ 'created' => '2026-01-01T00:00:00.000Z', 'lastMembershipUpdated' => $GLOBALS['now'] ];
$GLOBALS['now'] = $now;
//a page: two Link headers (self, next)
$page = function(array $items) use ($query, $host, $path, $out) {
    $limit = max(1, (int)($query['limit'] ?? 200));
    $after = (int)($query['after'] ?? 0);
    $slice = array_slice(array_values($items), $after, $limit);
    $links = [ '<' . $host . $path . '?' . http_build_query($query) . '>; rel="self"' ];
    if ($after + $limit < count($items)) {
	$q = $query;
	$q['after'] = $after + $limit;
	$links[] = '<' . $host . $path . '?' . http_build_query($q) . '>; rel="next"';
    }
    $out($slice, 200, [ 'Link' => $links ]);
};

if ($p === '/org')
    $out([ 'companyName' => 'Acme', 'subdomain' => 'acme' ]);
if ($p === '/users/me')
    $auth === 'SSWS ssws-check' ? $out($uview($db['users']['00uana0000000001'])) : $fail(404, 'E0000007', 'Not found');
if ($p === '/logs') {
    $since = (string)($query['since'] ?? '');
    $filter = (string)($query['filter'] ?? '');
    preg_match_all('/eventType eq "([^"]+)"/', $filter, $tm);
    $outcome = preg_match('/outcome\.result eq "([A-Z]+)"/', $filter, $om) ? $om[1] : null;
    $items = array_values(array_filter($db['logs'], fn($e) => $e['published'] >= $since && (!$tm[1] || in_array($e['eventType'], $tm[1], true)) && (!$outcome || $e['outcome']['result'] === $outcome)));
    $page($items);
}

//users
if ($p === '/users' && $method === 'GET') {
    $items = array_values($db['users']);
    if (isset($query['q']))
	$items = array_values(array_filter($items, fn($u) => stripos($u['profile']['firstName'] . ' ' . $u['profile']['lastName'] . ' ' . $u['profile']['email'], $query['q']) !== false));
    if (isset($query['search'])) {
	if (preg_match('/status eq "([A-Z_]+)"/', $query['search'], $sm))
	    $items = array_values(array_filter($items, fn($u) => $u['status'] === $sm[1]));
	if (preg_match('/profile\.department eq "([^"]+)"/', $query['search'], $dm))
	    $items = array_values(array_filter($items, fn($u) => ($u['profile']['department'] ?? '') === $dm[1]));
    }
    $page(array_map($uview, $items));
}
if ($p === '/users' && $method === 'POST') {
    foreach($db['users'] as $u)
	if (strcasecmp($u['profile']['login'], $body['profile']['login'] ?? '') === 0)
	    $fail(400, 'E0000001', 'Api validation failed: login', [ 'login: An object with this field already exists in the current organization' ]);
    $id = sprintf('00unew%010d', count($db['users']) + 1);
    $db['users'][$id] = [ 'id' => $id, 'status' => ($query['activate'] ?? 'true') === 'true' ? (isset($body['credentials']['password']) ? 'ACTIVE' : 'PROVISIONED') : 'STAGED', 'created' => $now, 'profile' => $body['profile'] ];
    $event('user.lifecycle.create', $id);
    $saveDb();
    $out($uview($db['users'][$id]));
}
if (preg_match('~^/users/([^/]+)(/.*)?$~', $p, $mm)) {
    $id = $userRef($mm[1]);
    $rest = $mm[2] ?? '';
    if (!$id)
	$fail(404, 'E0000007', "Not found: Resource not found: {$mm[1]} (User)");
    $u = &$db['users'][$id];
    if ($rest === '') {
	if ($method === 'POST') {
	    $u['profile'] = array_merge($u['profile'], (array)($body['profile'] ?? []));
	    $event('user.account.update_profile', $id);
	    $saveDb();
	    $out($uview($u));
	}
	if ($method === 'DELETE') {
	    if ($u['status'] !== 'DEPROVISIONED')
		$fail(403, 'E0000001', 'Api validation failed: the user is not deactivated');
	    unset($db['users'][$id]);
	    $saveDb();
	    $out(null, 204);
	}
	$out($uview($u));
    }
    if (preg_match('~^/lifecycle/([a-z_]+)$~', $rest, $lm) && $method === 'POST') {
	$to = [ 'activate' => 'ACTIVE', 'deactivate' => 'DEPROVISIONED', 'suspend' => 'SUSPENDED', 'unsuspend' => 'ACTIVE', 'expire_password' => 'PASSWORD_EXPIRED', 'reset_password' => 'RECOVERY' ][$lm[1]] ?? null;
	if ($lm[1] === 'reset_factors') {
	    $db['factors'][$id] = [];
	    $saveDb();
	    $out(null, 200);
	}
	if (!$to)
	    $fail(404, 'E0000022', 'no such lifecycle operation');
	if ($lm[1] === 'suspend' && $u['status'] !== 'ACTIVE')
	    $fail(400, 'E0000001', 'Api validation failed: Cannot suspend a user that is not active');
	$u['status'] = $to;
	if ($lm[1] === 'deactivate') {
	    foreach($db['apps'] as &$a)
		$a['users'] = array_values(array_diff($a['users'], [ $id ]));
	    unset($a);
	}
	$event('user.lifecycle.' . $lm[1], $id);
	$saveDb();
	if ($lm[1] === 'reset_password')
	    $out(($query['sendEmail'] ?? 'true') === 'false' ? [ 'resetPasswordUrl' => 'https://acme.okta.com/reset_password/abc' ] : (object)[]);
	$out($lm[1] === 'expire_password' ? $uview($u) : (object)[]);
    }
    if ($rest === '/sessions' && $method === 'DELETE') {
	$db['sessionsCleared'][] = [ $id, $query['oauthTokens'] ?? 'false' ];
	$saveDb();
	$out(null, 204);
    }
    if ($rest === '/groups')
	$page(array_map($gview, array_values(array_filter($db['groups'], fn($g) => in_array($id, $g['members'], true)))));
    if ($rest === '/appLinks')
	$out(array_values(array_map(fn($a) => [ 'appName' => $a['name'], 'appInstanceId' => $a['id'], 'label' => $a['label'], 'linkUrl' => "https://acme.okta.com/home/{$a['name']}" ],
	    array_filter($db['apps'], fn($a) => in_array($id, $a['users'], true)))));
    if ($rest === '/factors')
	$out($db['factors'][$id] ?? []);
    if (preg_match('~^/factors/(.+)$~', $rest, $fm) && $method === 'DELETE') {
	$db['factors'][$id] = array_values(array_filter($db['factors'][$id] ?? [], fn($f) => $f['id'] !== $fm[1]));
	$saveDb();
	$out(null, 204);
    }
}

//groups
if ($p === '/groups' && $method === 'GET') {
    $items = array_values($db['groups']);
    if (isset($query['q']))
	$items = array_values(array_filter($items, fn($g) => stripos($g['profile']['name'], $query['q']) === 0));
    if (isset($query['search']) && preg_match('/type eq "([A-Z_]+)"/', $query['search'], $tm))
	$items = array_values(array_filter($items, fn($g) => $g['type'] === $tm[1]));
    $page(array_map($gview, $items));
}
if ($p === '/groups' && $method === 'POST') {
    $id = sprintf('00gnew%010d', count($db['groups']) + 1);
    $db['groups'][$id] = [ 'id' => $id, 'type' => 'OKTA_GROUP', 'profile' => $body['profile'], 'members' => [] ];
    $saveDb();
    $out($gview($db['groups'][$id]));
}
if (preg_match('~^/groups/([^/]+)(/.*)?$~', $p, $mm)) {
    if (!isset($db['groups'][$mm[1]]))
	$fail(404, 'E0000007', "Not found: Resource not found: {$mm[1]} (UserGroup)");
    $g = &$db['groups'][$mm[1]];
    $rest = $mm[2] ?? '';
    if ($rest === '' && $method === 'DELETE') {
	unset($db['groups'][$mm[1]]);
	$saveDb();
	$out(null, 204);
    }
    if ($rest === '')
	$out($gview($g));
    if ($rest === '/users')
	$page(array_map(fn($id) => $uview($db['users'][$id]), array_values(array_filter($g['members'], fn($id) => isset($db['users'][$id])))));
    if (preg_match('~^/users/([^/]+)$~', $rest, $um)) {
	if ($g['type'] !== 'OKTA_GROUP')
	    $fail(403, 'E0000006', 'You do not have permission to perform the requested action');
	if ($method === 'PUT') {
	    if (!in_array($um[1], $g['members'], true)) {
		$g['members'][] = $um[1];
		$event('group.user_membership.add', $um[1]);
	    }
	} elseif ($method === 'DELETE') {
	    $g['members'] = array_values(array_diff($g['members'], [ $um[1] ]));
	    $event('group.user_membership.remove', $um[1]);
	}
	$saveDb();
	$out(null, 204);
    }
}

//apps
if ($p === '/apps' && $method === 'GET')
    $out(array_values(array_map(fn($a) => array_diff_key($a, [ 'users' => 1 ]), array_filter($db['apps'], fn($a) => !isset($query['q']) || stripos($a['label'], $query['q']) === 0))));
if (preg_match('~^/apps/([^/]+)/users(?:/([^/]+))?$~', $p, $mm)) {
    if (!isset($db['apps'][$mm[1]]))
	$fail(404, 'E0000007', 'Not found: Resource not found (App)');
    $a = &$db['apps'][$mm[1]];
    if ($method === 'POST') {
	$a['users'][] = $body['id'];
	$a['users'] = array_values(array_unique($a['users']));
	$saveDb();
	$out([ 'id' => $body['id'], 'status' => 'PROVISIONED', 'scope' => 'USER', 'profile' => $body['profile'] ?? (object)[] ]);
    }
    if ($method === 'DELETE') {
	if (!in_array($mm[2], $a['users'], true))
	    $fail(404, 'E0000007', 'Not found: Resource not found (AppUser)');
	$a['users'] = array_values(array_diff($a['users'], [ $mm[2] ]));
	$saveDb();
	$out(null, 204);
    }
}
$fail(404, 'E0000022', "no fake for {$method} {$p}");
