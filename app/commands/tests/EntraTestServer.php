<?php

/*
    CLD: 2026-11-26T01:00:00+02:00: a fake Microsoft Graph (and its sign-in) for the check suite (CheckCommand::suiteEntra), run by PHP's built-in server:
    ENTRA_STATE=<a folder> php -S 127.0.0.1:<port> EntraTestServer.php
	/<tenant>/oauth2/v2.0/token: client_credentials with client_secret sec-check, or a client assertion signed with the key of <state>/cert.pem (its x5t checked) -> tok-graph.
	/v1.0/…: Bearer tok-graph. Users (ana, dan; by ID or UPN), groups (Sales, Ops; a dynamic one), members / memberOf / owners, the manager, licenses (subscribedSkus,
	licenseDetails, assignLicense), revokeSignInSessions, service principals with app roles, appRoleAssignedTo / appRoleAssignments, authentication methods (list, delete, a
	temporary access pass), subscriptions (create: the notificationUrl validated first as Graph does; renew, delete), delta queries of users and groups (a change log: a
	deltaLink's $deltatoken is how far it read), /organization. Pages: $top, @odata.nextLink. <state>/busy.json: how many 429 next (Retry-After: 1).
	Each request appended to <state>/requests.jsonl (method, path, query, body, consistency)
*/

$state = getenv('ENTRA_STATE') ?: sys_get_temp_dir();
$method = $_SERVER['REQUEST_METHOD'];
$path = rawurldecode((string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
parse_str((string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY), $query);
$raw = file_get_contents('php://input');
$body = json_decode($raw, true);
file_put_contents("{$state}/requests.jsonl", json_encode([ 'method' => $method, 'path' => $path, 'query' => $query, 'body' => $body ?? $_POST, 'consistency' => $_SERVER['HTTP_CONSISTENCYLEVEL'] ?? null ],
    JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);
$load = fn($name, $default = []) => is_file("{$state}/{$name}.json") ? json_decode(file_get_contents("{$state}/{$name}.json"), true) : $default;
$save = fn($name, $v) => file_put_contents("{$state}/{$name}.json", json_encode($v, JSON_UNESCAPED_SLASHES));
$host = 'http://' . $_SERVER['HTTP_HOST'];
$out = function($v, $status = 200, array $headers = []) {
    http_response_code($status);
    foreach($headers as $k => $h)
	header("{$k}: {$h}");
    if ($v !== null) {
	header('Content-Type: application/json');
	echo json_encode($v, JSON_UNESCAPED_SLASHES);
    }
    exit;
};
$fail = fn($status, $code, $message) => $out([ 'error' => [ 'code' => $code, 'message' => $message ] ], $status);

//the sign-in
if (preg_match('~^/[^/]+/oauth2/v2\.0/token$~', $path)) {
    $f = $_POST;
    $ok = ($f['client_secret'] ?? '') === 'sec-check';
    if (!$ok && ($f['client_assertion'] ?? '') !== '' && is_file("{$state}/cert.pem")) {
	$parts = explode('.', $f['client_assertion']);
	$d = fn($s) => base64_decode(strtr($s, '-_', '+/'));
	$h = json_decode($d($parts[0] ?? ''), true);
	$cert = file_get_contents("{$state}/cert.pem");
	$x5t = rtrim(strtr(base64_encode(openssl_x509_fingerprint($cert, 'sha1', true)), '+/', '-_'), '=');
	$ok = ($h['x5t'] ?? '') === $x5t && openssl_verify("{$parts[0]}.{$parts[1]}", $d($parts[2] ?? ''), openssl_pkey_get_public($cert), OPENSSL_ALGO_SHA256) === 1;
    }
    if (!$ok)
	$out([ 'error' => 'invalid_client', 'error_description' => "AADSTS7000215: Invalid client secret provided.\r\nTrace ID: x" ], 401);
    $out([ 'access_token' => 'tok-graph', 'expires_in' => 3599, 'token_type' => 'Bearer' ]);
}
if (($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== 'Bearer tok-graph')
    $fail(401, 'InvalidAuthenticationToken', 'Access token is empty.');
if (($b = (int)$load('busy', 0)) > 0) {
    $save('busy', $b - 1);
    $out([ 'error' => [ 'code' => 'TooManyRequests', 'message' => 'Too many requests' ] ], 429, [ 'Retry-After' => '1' ]);
}
if (!preg_match('~^/(v1\.0|beta)(/.*)$~', $path, $m))
    $fail(404, 'BadRequest', 'Invalid version');
$p = $m[2];

$ANA = '11111111-1111-1111-1111-111111111111';
$DAN = '22222222-2222-2222-2222-222222222222';
$SALES = 'aaaaaaaa-0000-0000-0000-000000000001';
$OPS = 'aaaaaaaa-0000-0000-0000-000000000002';
$DYN = 'aaaaaaaa-0000-0000-0000-000000000003';
$db = $load('db', null) ?? [
    'users' => [
	$ANA => [ 'id' => $ANA, 'displayName' => 'Ana Pop', 'givenName' => 'Ana', 'surname' => 'Pop', 'userPrincipalName' => 'ana@acme.com', 'mail' => 'ana@acme.com', 'department' => 'IT', 'jobTitle' => 'SRE',
	    'accountEnabled' => true, 'userType' => 'Member', 'createdDateTime' => '2026-01-01T10:00:00Z', 'usageLocation' => 'RO' ],
	$DAN => [ 'id' => $DAN, 'displayName' => 'Dan Ionescu', 'givenName' => 'Dan', 'surname' => 'Ionescu', 'userPrincipalName' => 'dan@acme.com', 'mail' => 'dan@acme.com', 'department' => 'Sales',
	    'accountEnabled' => true, 'userType' => 'Member', 'createdDateTime' => '2026-02-01T10:00:00Z', 'usageLocation' => 'RO' ],
    ],
    'groups' => [
	$SALES => [ 'id' => $SALES, 'displayName' => 'Sales', 'securityEnabled' => true, 'mailEnabled' => false, 'groupTypes' => [], 'members' => [ $DAN ], 'owners' => [ $ANA ], 'createdDateTime' => '2026-01-01T00:00:00Z' ],
	$OPS => [ 'id' => $OPS, 'displayName' => 'Ops', 'securityEnabled' => false, 'mailEnabled' => true, 'mail' => 'ops@acme.com', 'groupTypes' => [ 'Unified' ], 'members' => [ $ANA, $DAN ], 'owners' => [], 'createdDateTime' => '2026-01-01T00:00:00Z' ],
	$DYN => [ 'id' => $DYN, 'displayName' => 'All staff', 'securityEnabled' => true, 'mailEnabled' => false, 'groupTypes' => [ 'DynamicMembership' ], 'membershipRule' => 'user.accountEnabled -eq true',
	    'members' => [ $ANA, $DAN ], 'owners' => [], 'createdDateTime' => '2026-01-01T00:00:00Z' ],
    ],
    'managers' => [ $DAN => $ANA ],
    'skus' => [ [ 'skuId' => 'sku-e3-0000-0000-0000-000000000000', 'skuPartNumber' => 'SPE_E3', 'capabilityStatus' => 'Enabled', 'consumedUnits' => 1, 'prepaidUnits' => [ 'enabled' => 10 ] ] ],
    'licenses' => [ $DAN => [ 'sku-e3-0000-0000-0000-000000000000' ] ],
    'sp' => [ [ 'id' => 'sp000000-0000-0000-0000-000000000001', 'appId' => 'app00000-0000-0000-0000-000000000001', 'displayName' => 'Payroll',
	'appRoles' => [ [ 'id' => 'role0000-0000-0000-0000-000000000001', 'value' => 'Viewer', 'displayName' => 'Viewer', 'allowedMemberTypes' => [ 'User' ] ] ] ] ],
    'assignments' => [],
    'methods' => [ $DAN => [ [ 'id' => 'pwd', '@odata.type' => '#microsoft.graph.passwordAuthenticationMethod' ], [ 'id' => 'ph1', '@odata.type' => '#microsoft.graph.phoneAuthenticationMethod', 'phoneNumber' => '+40 722' ],
	[ 'id' => 'ma1', '@odata.type' => '#microsoft.graph.microsoftAuthenticatorAuthenticationMethod', 'displayName' => 'iPhone' ] ] ],
    'revoked' => [],
    'subs' => [],
    'log' => [],
    'seq' => 0,
];
$saveDb = function() use (&$db, $save) {
    $save('db', $db);
};
//a change noted for the delta queries
$change = function($kind, $id, $extra = []) use (&$db) {
    $db['seq']++;
    $db['log'][] = [ 'seq' => $db['seq'], 'kind' => $kind, 'id' => $id ] + $extra;
};
$userById = function($ref) use (&$db) {
    foreach($db['users'] as $u)
	if ($u['id'] === $ref || strcasecmp($u['userPrincipalName'], $ref) === 0)
	    return $u['id'];
    return null;
};
$view = function(array $o, $type = null) use ($query) {
    $sel = isset($query['$select']) ? explode(',', $query['$select']) : null;
    $v = $sel ? array_intersect_key($o, array_flip(array_merge($sel, [ 'id' ]))) : array_diff_key($o, [ 'members' => 1, 'owners' => 1 ]);
    return ($type ? [ '@odata.type' => $type ] : []) + $v;
};
$list = function(array $items) use ($query, $host, $path) {
    $top = max(1, (int)($query['$top'] ?? 100));
    $skip = (int)($query['$skiptoken'] ?? 0);
    $page = array_slice(array_values($items), $skip, $top);
    $r = [ 'value' => $page ];
    if ($skip + $top < count($items)) {
	$q = $query;
	$q['$skiptoken'] = $skip + $top;
	$r['@odata.nextLink'] = $host . '/v1.0' . substr($path, 5 + (str_starts_with($path, '/beta') ? 0 : 0)) . '?' . http_build_query($q);
    }
    return $r;
};

//delta queries
if ($p === '/users/delta' || $p === '/groups/delta') {
    $isUsers = $p === '/users/delta';
    $token = isset($query['$deltatoken']) ? (int)$query['$deltatoken'] : null;
    $items = [];
    if ($token === null)
	$items = $isUsers ? array_values(array_map(fn($u) => $view($u), $db['users'])) : array_values(array_map(fn($g) => [ 'id' => $g['id'], 'displayName' => $g['displayName'],
	    'members@delta' => array_map(fn($id) => [ '@odata.type' => '#microsoft.graph.user', 'id' => $id ], $g['members']) ], $db['groups']));
    else
	foreach($db['log'] as $c) {
	    if ($c['seq'] <= $token)
		continue;
	    if ($isUsers && $c['kind'] === 'user')
		$items[] = isset($db['users'][$c['id']]) ? $view($db['users'][$c['id']]) : [ 'id' => $c['id'], '@removed' => [ 'reason' => 'deleted' ] ];
	    if (!$isUsers && $c['kind'] === 'member')
		$items[] = [ 'id' => $c['group'], 'members@delta' => [ [ '@odata.type' => '#microsoft.graph.user', 'id' => $c['id'] ] + ($c['removed'] ? [ '@removed' => [ 'reason' => 'deleted' ] ] : []) ] ];
	}
    $skip = (int)($query['$skiptoken'] ?? 0);
    $page = array_slice($items, $skip, 2);
    $r = [ 'value' => $page ];
    if ($skip + 2 < count($items))
	$r['@odata.nextLink'] = "{$host}/v1.0{$p}?" . http_build_query([ '$skiptoken' => $skip + 2 ] + ($token !== null ? [ '$deltatoken' => $token ] : []));
    else
	$r['@odata.deltaLink'] = "{$host}/v1.0{$p}?\$deltatoken={$db['seq']}";
    $out($r);
}

if ($p === '/organization')
    $out([ 'value' => [ [ 'displayName' => 'Acme', 'verifiedDomains' => [ [ 'name' => 'acme.com', 'isDefault' => true ] ] ] ] ]);
if ($p === '/subscribedSkus')
    $out([ 'value' => $db['skus'] ]);

//subscriptions
if ($p === '/subscriptions' && $method === 'POST') {
    //Graph validates the notificationUrl first
    $v = 'v' . bin2hex(random_bytes(4));
    $ch = curl_init($body['notificationUrl'] . (str_contains($body['notificationUrl'], '?') ? '&' : '?') . 'validationToken=' . $v);
    curl_setopt_array($ch, [ CURLOPT_POST => true, CURLOPT_POSTFIELDS => '', CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0 ]
	+ (getenv('ENTRA_RESOLVE') ? [ CURLOPT_RESOLVE => [ getenv('ENTRA_RESOLVE') ] ] : []));
    $answer = (string)curl_exec($ch);
    curl_close($ch);
    if ($answer !== $v && !getenv('ENTRA_NO_VALIDATION'))
	$fail(400, 'ValidationError', 'Subscription validation request failed. Response must exactly match validationToken query parameter.');
    $id = 'sub-' . (count($db['subs']) + 1);
    $db['subs'][$id] = $body + [ 'id' => $id ];
    $saveDb();
    $out($db['subs'][$id], 201);
}
if (preg_match('~^/subscriptions/(.+)$~', $p, $mm)) {
    if (!isset($db['subs'][$mm[1]]))
	$fail(404, 'ResourceNotFound', 'The object was not found.');
    if ($method === 'PATCH') {
	$db['subs'][$mm[1]]['expirationDateTime'] = $body['expirationDateTime'];
	$db['subs'][$mm[1]]['renewed'] = true;
	$saveDb();
	$out($db['subs'][$mm[1]]);
    }
    if ($method === 'DELETE') {
	unset($db['subs'][$mm[1]]);
	$saveDb();
	$out(null, 204);
    }
}

//users
if ($p === '/users' && $method === 'GET') {
    $items = array_values($db['users']);
    if (isset($query['$filter']) && preg_match("~accountEnabled eq (true|false)~", $query['$filter'], $fm))
	$items = array_values(array_filter($items, fn($u) => $u['accountEnabled'] === ($fm[1] === 'true')));
    if (isset($query['$search'])) {
	if (($_SERVER['HTTP_CONSISTENCYLEVEL'] ?? '') !== 'eventual')
	    $fail(400, 'Request_UnsupportedQuery', 'Request with $search query parameter only works through MSGraph with a special request header: \'ConsistencyLevel: eventual\'');
	preg_match('~displayName:([^"]+)"~', $query['$search'], $sm);
	$items = array_values(array_filter($items, fn($u) => stripos($u['displayName'] . ' ' . $u['userPrincipalName'], $sm[1] ?? '') !== false));
    }
    $out($list(array_map(fn($u) => $view($u), $items)));
}
if ($p === '/users' && $method === 'POST') {
    foreach($db['users'] as $u)
	if (strcasecmp($u['userPrincipalName'], $body['userPrincipalName']) === 0)
	    $fail(400, 'Request_BadRequest', 'Another object with the same value for property userPrincipalName already exists.');
    $id = sprintf('33333333-3333-3333-3333-%012d', count($db['users']) + 1);
    $db['users'][$id] = array_diff_key($body, [ 'passwordProfile' => 1 ]) + [ 'id' => $id, 'mail' => null, 'userType' => 'Member', 'createdDateTime' => gmdate('Y-m-d\TH:i:s\Z') ];
    $db['passwords'][$id] = $body['passwordProfile'];
    $change('user', $id);
    $saveDb();
    $out($view($db['users'][$id]), 201);
}
if (preg_match('~^/users/([^/]+)(/.*)?$~', $p, $mm)) {
    $id = $userById($mm[1]);
    $rest = $mm[2] ?? '';
    if (!$id)
	$fail(404, 'Request_ResourceNotFound', "Resource '{$mm[1]}' does not exist or one of its queried reference-property objects are not present.");
    if ($rest === '') {
	if ($method === 'PATCH') {
	    if (isset($body['passwordProfile']))
		$db['passwords'][$id] = $body['passwordProfile'];
	    $db['users'][$id] = array_merge($db['users'][$id], array_diff_key($body, [ 'passwordProfile' => 1 ]));
	    $change('user', $id);
	    $saveDb();
	    $out(null, 204);
	}
	if ($method === 'DELETE') {
	    unset($db['users'][$id]);
	    foreach($db['groups'] as &$g)
		$g['members'] = array_values(array_diff($g['members'], [ $id ]));
	    unset($g);
	    $change('user', $id);
	    $saveDb();
	    $out(null, 204);
	}
	$out($view($db['users'][$id]));
    }
    if ($rest === '/revokeSignInSessions') {
	$db['revoked'][] = $id;
	$saveDb();
	$out([ 'value' => true ]);
    }
    if ($rest === '/manager') {
	$mid = $db['managers'][$id] ?? null;
	if (!$mid)
	    $fail(404, 'Request_ResourceNotFound', 'Resource \'manager\' does not exist.');
	$out($view($db['users'][$mid]));
    }
    if ($rest === '/manager/$ref') {
	if ($method === 'PUT') {
	    $db['managers'][$id] = basename($body['@odata.id']);
	    $saveDb();
	    $out(null, 204);
	}
	if (!isset($db['managers'][$id]))
	    $fail(404, 'Request_ResourceNotFound', 'no manager');
	unset($db['managers'][$id]);
	$saveDb();
	$out(null, 204);
    }
    if ($rest === '/memberOf' || $rest === '/memberOf/microsoft.graph.group') {
	$gs = array_values(array_filter($db['groups'], fn($g) => in_array($id, $g['members'], true)));
	$out($list(array_map(fn($g) => $view($g, '#microsoft.graph.group'), $gs)));
    }
    if ($rest === '/licenseDetails') {
	$skus = array_column($db['skus'], 'skuPartNumber', 'skuId');
	$out([ 'value' => array_map(fn($s) => [ 'skuId' => $s, 'skuPartNumber' => $skus[$s] ?? $s ], $db['licenses'][$id] ?? []) ]);
    }
    if ($rest === '/assignLicense') {
	if (empty($db['users'][$id]['usageLocation']) && $body['addLicenses'])
	    $fail(400, 'Request_BadRequest', 'License assignment failed because service plans require a usage location.');
	$cur = $db['licenses'][$id] ?? [];
	$cur = array_values(array_diff(array_unique(array_merge($cur, array_column($body['addLicenses'], 'skuId'))), $body['removeLicenses']));
	$db['licenses'][$id] = $cur;
	$saveDb();
	$out($view($db['users'][$id]));
    }
    if ($rest === '/appRoleAssignments')
	$out([ 'value' => array_values(array_filter($db['assignments'], fn($a) => $a['principalId'] === $id)) ]);
    if (preg_match('~^/appRoleAssignments/(.+)$~', $rest, $am) && $method === 'DELETE') {
	$db['assignments'] = array_values(array_filter($db['assignments'], fn($a) => $a['id'] !== $am[1]));
	$saveDb();
	$out(null, 204);
    }
    if ($rest === '/authentication/methods')
	$out([ 'value' => $db['methods'][$id] ?? [ [ 'id' => 'pwd', '@odata.type' => '#microsoft.graph.passwordAuthenticationMethod' ] ] ]);
    if ($rest === '/authentication/temporaryAccessPassMethods' && $method === 'POST')
	$out([ 'id' => 'tap1', 'temporaryAccessPass' => 'TAP-12345', 'startDateTime' => gmdate('Y-m-d\TH:i:s\Z'), 'lifetimeInMinutes' => $body['lifetimeInMinutes'], 'isUsableOnce' => $body['isUsableOnce'] ], 201);
    if (preg_match('~^/authentication/([A-Za-z]+Methods)/(.+)$~', $rest, $am) && $method === 'DELETE') {
	$db['methods'][$id] = array_values(array_filter($db['methods'][$id] ?? [], fn($x) => $x['id'] !== $am[2]));
	$saveDb();
	$out(null, 204);
    }
}

//groups
if ($p === '/groups' && $method === 'GET') {
    $items = array_values($db['groups']);
    if (isset($query['$filter']) && preg_match("~displayName eq '([^']*)'~", $query['$filter'], $fm))
	$items = array_values(array_filter($items, fn($g) => $g['displayName'] === $fm[1]));
    if (isset($query['$filter']) && str_contains($query['$filter'], "groupTypes/any(t:t eq 'Unified')"))
	$items = array_values(array_filter($items, fn($g) => in_array('Unified', $g['groupTypes'], true)));
    $out($list(array_map(fn($g) => $view($g), $items)));
}
if ($p === '/groups' && $method === 'POST') {
    $id = sprintf('bbbbbbbb-0000-0000-0000-%012d', count($db['groups']) + 1);
    $ids = fn($k) => array_map('basename', (array)($body[$k] ?? []));
    $db['groups'][$id] = [ 'id' => $id, 'displayName' => $body['displayName'], 'description' => $body['description'] ?? null, 'securityEnabled' => $body['securityEnabled'], 'mailEnabled' => $body['mailEnabled'],
	'groupTypes' => $body['groupTypes'], 'mailNickname' => $body['mailNickname'], 'members' => $ids('members@odata.bind'), 'owners' => $ids('owners@odata.bind'), 'createdDateTime' => gmdate('Y-m-d\TH:i:s\Z') ];
    $saveDb();
    $out($view($db['groups'][$id]), 201);
}
if (preg_match('~^/groups/([^/]+)(/.*)?$~', $p, $mm)) {
    $gid = $mm[1];
    $rest = $mm[2] ?? '';
    if (!isset($db['groups'][$gid]))
	$fail(404, 'Request_ResourceNotFound', "Resource '{$gid}' does not exist.");
    $g = &$db['groups'][$gid];
    if ($rest === '' && $method === 'DELETE') {
	unset($db['groups'][$gid]);
	$saveDb();
	$out(null, 204);
    }
    if ($rest === '')
	$out($view($g));
    if (in_array($rest, [ '/members', '/transitiveMembers', '/owners' ], true))
	$out($list(array_map(fn($id) => $view($db['users'][$id] ?? [ 'id' => $id ], '#microsoft.graph.user'), $g[$rest === '/owners' ? 'owners' : 'members'])));
    if (in_array($rest, [ '/members/$ref', '/owners/$ref' ], true) && $method === 'POST') {
	$id = basename($body['@odata.id']);
	$k = $rest === '/owners/$ref' ? 'owners' : 'members';
	if (in_array('DynamicMembership', $g['groupTypes'], true))
	    $fail(400, 'Request_BadRequest', 'Insufficient privileges to complete the operation.');
	if (in_array($id, $g[$k], true))
	    $fail(400, 'Request_BadRequest', 'One or more added object references already exist for the following modified properties: \'members\'.');
	$g[$k][] = $id;
	if ($k === 'members')
	    $change('member', $id, [ 'group' => $gid, 'removed' => false ]);
	$saveDb();
	$out(null, 204);
    }
    if (preg_match('~^/members/([^/]+)/\$ref$~', $rest, $rm) && $method === 'DELETE') {
	if (!in_array($rm[1], $g['members'], true))
	    $fail(404, 'Request_ResourceNotFound', 'not a member');
	$g['members'] = array_values(array_diff($g['members'], [ $rm[1] ]));
	$change('member', $rm[1], [ 'group' => $gid, 'removed' => true ]);
	$saveDb();
	$out(null, 204);
    }
}

//service principals, app role assignments
if ($p === '/servicePrincipals') {
    $f = (string)($query['$filter'] ?? '');
    $items = array_values(array_filter($db['sp'], fn($s) => (preg_match("~displayName eq '([^']*)'~", $f, $x) && $s['displayName'] === $x[1]) || (preg_match("~appId eq '([^']*)'~", $f, $x) && $s['appId'] === $x[1])));
    $out([ 'value' => $items ]);
}
if (preg_match('~^/servicePrincipals/([^/]+)/appRoleAssignedTo$~', $p, $mm) && $method === 'POST') {
    $a = [ 'id' => 'asg-' . (count($db['assignments']) + 1), 'principalId' => $body['principalId'], 'resourceId' => $body['resourceId'], 'appRoleId' => $body['appRoleId'], 'resourceDisplayName' => 'Payroll',
	'createdDateTime' => gmdate('Y-m-d\TH:i:s\Z') ];
    $db['assignments'][] = $a;
    $saveDb();
    $out($a, 201);
}
$fail(404, 'Request_ResourceNotFound', "no fake for {$method} {$p}");
