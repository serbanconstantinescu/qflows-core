<?php

/*
    CLD: 2026-11-25T21:00:00+02:00: a fake GitHub REST API for the check suite (CheckCommand::suiteGitHub), run by PHP's built-in server:
    GITHUB_STATE=<a folder> php -S 127.0.0.1:<port> GitHubTestServer.php
	Bearer ghp_check (user qbot, scopes repo, workflow), or the GitHub App: a JWT signed with the key whose public half is <state>/app.pub (iss 12345) for /app, /app/installations,
	/repos/acme/web/installation (42), /app/installations/42/access_tokens (ghs_inst, an hour), then Bearer ghs_inst. The repository acme/web (default branch main): issues (#1, #2;
	pull request #3 among them), milestones, pull requests (#3 mergeable, #4 not), files and checks, contents (README.md, docs/a.md, data.json), branches, tags, commits, releases,
	asset uploads (/uploads/…), Actions (workflow deploy.yml; a dispatch makes a run, each GET of it moves it on: queued, in_progress, completed success), jobs, a job's log (302 to
	/blob/…, no token), re-runs, cancel, artifacts. Pages: per_page and page, the Link header. <state>/throttle.json: how many 403 rate-limited answers next (reset in 1 s).
	Data in <state>/db.json. Each request appended to <state>/requests.jsonl (method, path, query, body, auth, accept)
*/

$state = getenv('GITHUB_STATE') ?: sys_get_temp_dir();
$method = $_SERVER['REQUEST_METHOD'];
$path = rawurldecode((string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
parse_str((string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY), $query);
$raw = file_get_contents('php://input');
$body = json_decode($raw, true);
$auth = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? '');
$accept = (string)($_SERVER['HTTP_ACCEPT'] ?? '');
file_put_contents("{$state}/requests.jsonl", json_encode([ 'method' => $method, 'path' => $path, 'query' => $query, 'body' => $body, 'auth' => substr($auth, 0, 20), 'accept' => $accept,
    'size' => strlen($raw), 'type' => $_SERVER['CONTENT_TYPE'] ?? null ], JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);
$load = fn($name, $default = []) => is_file("{$state}/{$name}.json") ? json_decode(file_get_contents("{$state}/{$name}.json"), true) : $default;
$save = fn($name, $v) => file_put_contents("{$state}/{$name}.json", json_encode($v, JSON_UNESCAPED_SLASHES));
$host = 'http://' . $_SERVER['HTTP_HOST'];
$out = function($v, $status = 200, array $headers = []) {
    http_response_code($status);
    foreach($headers as $k => $h)
	header("{$k}: {$h}");
    if ($v !== null) {
	header('Content-Type: application/json; charset=utf-8');
	echo json_encode($v, JSON_UNESCAPED_SLASHES);
    }
    exit;
};
$fail = fn($status, $message, array $errors = []) => $out([ 'message' => $message, 'documentation_url' => 'https://docs.github.com/rest' ] + ($errors ? [ 'errors' => $errors ] : []), $status);
$now = gmdate('Y-m-d\TH:i:s\Z');

if (str_starts_with($path, '/blob/')) {
    header('Content-Type: text/plain');
    echo "2026-11-25T10:00:00Z line 1\n2026-11-25T10:00:01Z line 2\n2026-11-25T10:00:02Z Error: boom\n";
    exit;
}

//who: the token, or the app's JWT
$jwtOk = function($jwt) use ($state) {
    $parts = explode('.', $jwt);
    if (count($parts) !== 3 || !is_file("{$state}/app.pub"))
	return false;
    $d = fn($s) => base64_decode(strtr($s, '-_', '+/'));
    $claims = json_decode($d($parts[1]), true);
    if (($claims['iss'] ?? '') !== '12345' || ($claims['exp'] ?? 0) < time() || ($claims['exp'] ?? 0) > time() + 600)
	return false;
    return openssl_verify("{$parts[0]}.{$parts[1]}", $d($parts[2]), file_get_contents("{$state}/app.pub"), OPENSSL_ALGO_SHA256) === 1;
};
$token = str_starts_with($auth, 'Bearer ') ? substr($auth, 7) : '';
$asApp = str_starts_with($path, '/app') || preg_match('~^/repos/[^/]+/[^/]+/installation$~', $path);
if ($asApp) {
    if (!$jwtOk($token))
	$fail(401, 'A JSON web token could not be decoded');
} elseif (!in_array($token, [ 'ghp_check', 'ghs_inst' ], true))
    $fail(401, 'Bad credentials');
if (($t = (int)$load('throttle', 0)) > 0) {
    $save('throttle', $t - 1);
    $out([ 'message' => 'API rate limit exceeded for user ID 1.' ], 403, [ 'x-ratelimit-remaining' => '0', 'x-ratelimit-reset' => (string)(time() + 1) ]);
}

$user = fn($login) => [ 'login' => $login, 'id' => crc32($login) ];
$db = $load('db', null) ?? [
    'issues' => [
	1 => [ 'id' => 1001, 'number' => 1, 'title' => 'Printer down', 'state' => 'open', 'body' => 'Floor 2', 'labels' => [ [ 'name' => 'bug' ] ], 'assignees' => [], 'comments' => [], 'created_at' => '2026-11-01T10:00:00Z', 'updated_at' => '2026-11-01T10:00:00Z', 'user' => 'ana' ],
	2 => [ 'id' => 1002, 'number' => 2, 'title' => 'Add dark mode', 'state' => 'closed', 'body' => '', 'labels' => [ [ 'name' => 'feature' ] ], 'assignees' => [], 'comments' => [], 'created_at' => '2026-11-02T10:00:00Z', 'updated_at' => '2026-11-03T10:00:00Z', 'user' => 'dan' ],
    ],
    'pulls' => [
	3 => [ 'id' => 2003, 'number' => 3, 'title' => 'Fix printer driver', 'state' => 'open', 'body' => 'Fixes #1', 'head' => 'fix/printer', 'base' => 'main', 'sha' => str_repeat('a', 40), 'mergeable' => true,
	    'merged' => false, 'merged_at' => null, 'labels' => [], 'assignees' => [], 'reviewers' => [], 'created_at' => '2026-11-04T10:00:00Z', 'updated_at' => '2026-11-04T10:00:00Z', 'user' => 'dan' ],
	4 => [ 'id' => 2004, 'number' => 4, 'title' => 'WIP refactor', 'state' => 'open', 'body' => '', 'head' => 'wip', 'base' => 'main', 'sha' => str_repeat('b', 40), 'mergeable' => false,
	    'merged' => false, 'merged_at' => null, 'labels' => [], 'assignees' => [], 'reviewers' => [], 'created_at' => '2026-11-05T10:00:00Z', 'updated_at' => '2026-11-05T10:00:00Z', 'user' => 'ana' ],
    ],
    'files' => [ 'README.md' => '# Web', 'docs/a.md' => 'A doc', 'data.json' => '{"n": 1}' ],
    'branches' => [ 'main' => str_repeat('c', 40), 'fix/printer' => str_repeat('a', 40), 'wip' => str_repeat('b', 40) ],
    'releases' => [ [ 'id' => 501, 'tag_name' => 'v1.0.0', 'name' => 'First', 'body' => 'Hello', 'draft' => false, 'prerelease' => false, 'created_at' => '2026-10-01T10:00:00Z', 'published_at' => '2026-10-01T10:00:00Z', 'assets' => [] ] ],
    'runs' => [ 900 => [ 'id' => 900, 'name' => 'CI', 'run_number' => 7, 'event' => 'push', 'status' => 'completed', 'conclusion' => 'failure', 'head_branch' => 'main', 'head_sha' => str_repeat('c', 40),
	'workflow_id' => 77, 'created_at' => '2026-11-06T10:00:00Z', 'updated_at' => '2026-11-06T10:05:00Z', 'gets' => 0 ] ],
    'seq' => 0,
];
$saveDb = function() use (&$db, $save) {
    $save('db', $db);
};
$issueView = fn($i) => [ 'id' => $i['id'], 'number' => $i['number'], 'title' => $i['title'], 'state' => $i['state'], 'state_reason' => $i['state_reason'] ?? null, 'body' => $i['body'],
    'html_url' => "https://github.com/acme/web/issues/{$i['number']}", 'repository_url' => "{$GLOBALS['host']}/repos/acme/web", 'user' => [ 'login' => $i['user'] ], 'labels' => $i['labels'],
    'assignees' => array_map(fn($a) => [ 'login' => $a ], $i['assignees']), 'milestone' => isset($i['milestone']) ? [ 'number' => $i['milestone'], 'title' => 'Sprint 1' ] : null,
    'comments' => count($i['comments']), 'created_at' => $i['created_at'], 'updated_at' => $i['updated_at'], 'closed_at' => $i['state'] === 'closed' ? $i['updated_at'] : null ];
$GLOBALS['host'] = $host;
$pullView = fn($p) => [ 'id' => $p['id'], 'number' => $p['number'], 'title' => $p['title'], 'state' => $p['state'], 'draft' => $p['draft'] ?? false, 'body' => $p['body'], 'html_url' => "https://github.com/acme/web/pull/{$p['number']}",
    'url' => "{$GLOBALS['host']}/repos/acme/web/pulls/{$p['number']}", 'user' => [ 'login' => $p['user'] ], 'head' => [ 'ref' => $p['head'], 'sha' => $p['sha'] ], 'base' => [ 'ref' => $p['base'] ],
    'merged' => $p['merged'], 'merged_at' => $p['merged_at'], 'mergeable' => $p['mergeable'], 'mergeable_state' => $p['mergeable'] ? 'clean' : 'dirty', 'labels' => $p['labels'],
    'assignees' => [], 'requested_reviewers' => array_map(fn($r) => [ 'login' => $r ], $p['reviewers']), 'created_at' => $p['created_at'], 'updated_at' => $p['updated_at'],
    'additions' => 10, 'deletions' => 2, 'changed_files' => 2, 'commits' => 1 ];
$runView = fn($r) => [ 'id' => $r['id'], 'name' => $r['name'], 'display_title' => $r['name'], 'run_number' => $r['run_number'], 'run_attempt' => $r['attempt'] ?? 1, 'event' => $r['event'], 'status' => $r['status'],
    'conclusion' => $r['conclusion'], 'head_branch' => $r['head_branch'], 'head_sha' => $r['head_sha'], 'html_url' => "https://github.com/acme/web/actions/runs/{$r['id']}", 'workflow_id' => $r['workflow_id'],
    'actor' => [ 'login' => 'qbot' ], 'created_at' => $r['created_at'], 'updated_at' => $r['updated_at'] ];
//a page of $items, with the Link header
$page = function(array $items) use ($query, $path, $host) {
    $per = max(1, min(100, (int)($query['per_page'] ?? 30)));
    $p = max(1, (int)($query['page'] ?? 1));
    $slice = array_slice(array_values($items), ($p - 1) * $per, $per);
    $h = [];
    if ($p * $per < count($items)) {
	$q = $query;
	$q['page'] = $p + 1;
	$h['Link'] = '<' . $host . $path . '?' . http_build_query($q) . '>; rel="next"';
    }
    return [ $slice, $h ];
};

if ($path === '/user')
    $out([ 'login' => 'qbot', 'id' => 1 ], 200, [ 'x-oauth-scopes' => 'repo, workflow' ]);
if ($path === '/app')
    $out([ 'id' => 12345, 'name' => 'qflows-app' ]);
if ($path === '/app/installations')
    $out([ [ 'id' => 42, 'account' => [ 'login' => 'acme' ] ] ]);
if ($path === '/repos/acme/web/installation')
    $out([ 'id' => 42 ]);
if ($path === '/app/installations/42/access_tokens' && $method === 'POST')
    $out([ 'token' => 'ghs_inst', 'expires_at' => gmdate('Y-m-d\TH:i:s\Z', time() + 3600) ], 201);
if ($path === '/installation/repositories')
    $out([ 'total_count' => 1, 'repositories' => [ [ 'full_name' => 'acme/web' ] ] ]);
if ($path === '/search/issues') {
    $q = (string)($query['q'] ?? '');
    $items = array_values(array_filter($db['issues'], fn($i) => str_contains($q, 'repo:acme/web') && (!str_contains($q, 'is:open') || $i['state'] === 'open')
	&& (!preg_match('/label:(\S+)/', $q, $m) || in_array($m[1], array_column($i['labels'], 'name'), true))));
    [ $slice, $h ] = $page(array_map($issueView, $items));
    $out([ 'total_count' => count($items), 'items' => $slice ], 200, $h);
}
if (preg_match('~^/uploads/repos/acme/web/releases/(\d+)/assets$~', $path, $m)) {
    foreach($db['releases'] as &$r)
	if ($r['id'] == $m[1]) {
	    $a = [ 'id' => 700 + count($r['assets']), 'name' => (string)($query['name'] ?? ''), 'size' => strlen($raw), 'browser_download_url' => "https://github.com/acme/web/releases/download/{$r['tag_name']}/" . ($query['name'] ?? '') ];
	    $r['assets'][] = $a;
	    $saveDb();
	    $out($a, 201);
	}
    $fail(404, 'Not Found');
}
if (!preg_match('~^/repos/acme/web(/.*)?$~', $path, $m)) {
    if (preg_match('~^/repos/~', $path))
	$fail(404, 'Not Found');
    $fail(404, 'Not Found');
}
$p = $m[1] ?? '';

if ($p === '' && $method === 'GET')
    $out([ 'full_name' => 'acme/web', 'default_branch' => 'main' ]);

//issues
if ($p === '/issues' && $method === 'GET') {
    $all = array_merge(array_values($db['issues']), array_map(fn($pr) => [ 'id' => $pr['id'], 'number' => $pr['number'], 'title' => $pr['title'], 'state' => $pr['state'], 'body' => $pr['body'], 'labels' => [],
	'assignees' => [], 'comments' => [], 'created_at' => $pr['created_at'], 'updated_at' => $pr['updated_at'], 'user' => $pr['user'], 'pr' => true ], array_values($db['pulls'])));
    $state = (string)($query['state'] ?? 'open');
    $all = array_values(array_filter($all, fn($i) => ($state === 'all' || $i['state'] === $state) && (!isset($query['labels']) || !array_diff(explode(',', $query['labels']), array_column($i['labels'], 'name')))
	&& (!isset($query['since']) || $i['updated_at'] >= $query['since'])));
    usort($all, fn($a, $b) => strcmp($b['created_at'], $a['created_at']));
    [ $slice, $h ] = $page(array_map(fn($i) => $issueView($i) + (!empty($i['pr']) ? [ 'pull_request' => [ 'url' => 'x' ] ] : []), $all));
    $out($slice, 200, $h);
}
if ($p === '/issues' && $method === 'POST') {
    if (trim((string)($body['title'] ?? '')) === '')
	$fail(422, 'Validation Failed', [ [ 'resource' => 'Issue', 'field' => 'title', 'code' => 'missing_field' ] ]);
    $n = max(array_merge(array_keys($db['issues']), array_keys($db['pulls']))) + 1;
    $db['issues'][$n] = [ 'id' => 1000 + $n, 'number' => $n, 'title' => $body['title'], 'state' => 'open', 'body' => (string)($body['body'] ?? ''), 'labels' => array_map(fn($l) => [ 'name' => $l ], (array)($body['labels'] ?? [])),
	'assignees' => (array)($body['assignees'] ?? []), 'comments' => [], 'created_at' => $now, 'updated_at' => $now, 'user' => 'qbot' ] + (isset($body['milestone']) ? [ 'milestone' => $body['milestone'] ] : []);
    $saveDb();
    $out($issueView($db['issues'][$n]), 201);
}
if ($p === '/milestones')
    $out([ [ 'number' => 5, 'title' => 'Sprint 1' ] ]);
if (preg_match('~^/issues/(\d+)(/.*)?$~', $p, $mm)) {
    $n = (int)$mm[1];
    $rest = $mm[2] ?? '';
    $isPr = isset($db['pulls'][$n]);
    if (!isset($db['issues'][$n]) && !$isPr)
	$fail(404, 'Not Found');
    if ($rest === '/comments' && $method === 'POST') {
	$c = [ 'id' => 3000 + (++$db['seq']), 'body' => (string)$body['body'], 'user' => [ 'login' => 'qbot' ], 'html_url' => "https://github.com/acme/web/issues/{$n}#issuecomment-1", 'created_at' => $now, 'updated_at' => $now ];
	if (!$isPr)
	    $db['issues'][$n]['comments'][] = $c;
	$saveDb();
	$out($c, 201);
    }
    if ($rest === '/comments')
	$out($isPr ? [] : $db['issues'][$n]['comments']);
    if ($rest === '/labels' && $method === 'POST') {
	foreach((array)$body['labels'] as $l)
	    if (!in_array($l, array_column($db['issues'][$n]['labels'], 'name'), true))
		$db['issues'][$n]['labels'][] = [ 'name' => $l ];
	$saveDb();
	$out($db['issues'][$n]['labels']);
    }
    if (preg_match('~^/labels/(.+)$~', $rest, $lm) && $method === 'DELETE') {
	$before = count($db['issues'][$n]['labels']);
	$db['issues'][$n]['labels'] = array_values(array_filter($db['issues'][$n]['labels'], fn($l) => $l['name'] !== $lm[1]));
	if ($before === count($db['issues'][$n]['labels']))
	    $fail(404, 'Label does not exist');
	$saveDb();
	$out($db['issues'][$n]['labels']);
    }
    if ($rest === '' && $method === 'PATCH') {
	foreach([ 'title', 'body', 'state', 'state_reason', 'milestone' ] as $k)
	    if (array_key_exists($k, (array)$body))
		$db['issues'][$n][$k] = $body[$k];
	if (isset($body['labels']))
	    $db['issues'][$n]['labels'] = array_map(fn($l) => [ 'name' => $l ], $body['labels']);
	if (isset($body['assignees']))
	    $db['issues'][$n]['assignees'] = $body['assignees'];
	$db['issues'][$n]['updated_at'] = $now;
	$saveDb();
	$out($issueView($db['issues'][$n]));
    }
    if ($rest === '')
	$out($issueView($db['issues'][$n]));
}

//pull requests
if ($p === '/pulls' && $method === 'GET') {
    $state = (string)($query['state'] ?? 'open');
    $all = array_values(array_filter($db['pulls'], fn($x) => ($state === 'all' || $x['state'] === $state) && (!isset($query['base']) || $x['base'] === $query['base'])
	&& (!isset($query['head']) || 'acme:' . $x['head'] === $query['head'])));
    usort($all, fn($a, $b) => strcmp($b[($query['sort'] ?? 'created') === 'updated' ? 'updated_at' : 'created_at'], $a[($query['sort'] ?? 'created') === 'updated' ? 'updated_at' : 'created_at']));
    [ $slice, $h ] = $page(array_map($pullView, $all));
    $out($slice, 200, $h);
}
if ($p === '/pulls' && $method === 'POST') {
    if (!isset($db['branches'][$body['head'] ?? '']))
	$fail(422, 'Validation Failed', [ [ 'resource' => 'PullRequest', 'field' => 'head', 'code' => 'invalid' ] ]);
    $n = max(array_merge(array_keys($db['issues']), array_keys($db['pulls']))) + 1;
    $db['pulls'][$n] = [ 'id' => 2000 + $n, 'number' => $n, 'title' => $body['title'], 'state' => 'open', 'body' => (string)($body['body'] ?? ''), 'head' => $body['head'], 'base' => $body['base'],
	'sha' => $db['branches'][$body['head']], 'mergeable' => true, 'merged' => false, 'merged_at' => null, 'labels' => [], 'assignees' => [], 'reviewers' => [], 'created_at' => $now, 'updated_at' => $now,
	'user' => 'qbot', 'draft' => (bool)($body['draft'] ?? false) ];
    $saveDb();
    $out($pullView($db['pulls'][$n]), 201);
}
if (preg_match('~^/pulls/(\d+)(/.*)?$~', $p, $mm)) {
    $n = (int)$mm[1];
    $rest = $mm[2] ?? '';
    if (!isset($db['pulls'][$n]))
	$fail(404, 'Not Found');
    $pr = &$db['pulls'][$n];
    if ($rest === '/files')
	$out([ [ 'filename' => 'driver.c', 'status' => 'modified', 'additions' => 8, 'deletions' => 2, 'patch' => '@@ -1 +1 @@' ], [ 'filename' => 'README.md', 'status' => 'modified', 'additions' => 2, 'deletions' => 0 ] ]);
    if ($rest === '/reviews' && $method === 'POST')
	$out([ 'id' => 4001, 'state' => $body['event'] === 'APPROVE' ? 'APPROVED' : ($body['event'] === 'REQUEST_CHANGES' ? 'CHANGES_REQUESTED' : 'COMMENTED'), 'body' => (string)($body['body'] ?? ''),
	    'user' => [ 'login' => 'qbot' ], 'html_url' => "https://github.com/acme/web/pull/{$n}#pullrequestreview-4001" ]);
    if ($rest === '/requested_reviewers' && $method === 'POST') {
	$pr['reviewers'] = array_values(array_unique(array_merge($pr['reviewers'], (array)($body['reviewers'] ?? []))));
	$saveDb();
	$out($pullView($pr), 201);
    }
    if ($rest === '/merge' && $method === 'PUT') {
	if (!$pr['mergeable'])
	    $fail(405, 'Pull Request is not mergeable');
	$pr['merged'] = true;
	$pr['merged_at'] = $now;
	$pr['state'] = 'closed';
	$pr['updated_at'] = $now;
	$saveDb();
	$out([ 'merged' => true, 'sha' => str_repeat('d', 40), 'message' => 'Pull Request successfully merged', 'method' => $body['merge_method'] ?? 'merge' ]);
    }
    if ($rest === '' && $method === 'PATCH') {
	foreach([ 'title', 'body', 'state', 'base' ] as $k)
	    if (isset($body[$k]))
		$pr[$k] = $body[$k];
	$pr['updated_at'] = $now;
	$saveDb();
	$out($pullView($pr));
    }
    if ($rest === '')
	$out($pullView($pr));
}
if (preg_match('~^/commits/([0-9a-f]{40})/check-runs$~', $p, $mm))
    $out([ 'total_count' => 2, 'check_runs' => [ [ 'name' => 'build', 'status' => 'completed', 'conclusion' => 'success', 'html_url' => 'https://github.com/x/1' ],
	[ 'name' => 'test', 'status' => 'completed', 'conclusion' => $mm[1] === str_repeat('b', 40) ? 'failure' : 'success', 'html_url' => 'https://github.com/x/2' ] ] ]);
if (preg_match('~^/commits/([0-9a-f]{40})/status$~', $p))
    $out([ 'state' => 'success', 'statuses' => [ [ 'context' => 'ci/legacy', 'state' => 'success', 'target_url' => 'https://ci.example/1' ] ] ]);

//contents
if (preg_match('~^/contents(?:/(.*))?$~', $p, $mm)) {
    $fp = (string)($mm[1] ?? '');
    if ($method === 'GET') {
	if (isset($db['files'][$fp])) {
	    if (str_contains($accept, 'raw')) {
		header('Content-Type: application/vnd.github.raw');
		echo $db['files'][$fp];
		exit;
	    }
	    $out([ 'type' => 'file', 'name' => basename($fp), 'path' => $fp, 'size' => strlen($db['files'][$fp]), 'sha' => sha1($db['files'][$fp]), 'html_url' => "https://github.com/acme/web/blob/main/{$fp}",
		'content' => base64_encode($db['files'][$fp]), 'encoding' => 'base64' ]);
	}
	$prefix = $fp === '' ? '' : "{$fp}/";
	$items = [];
	foreach(array_keys($db['files']) as $f) {
	    if ($prefix !== '' && !str_starts_with($f, $prefix))
		continue;
	    $rest = substr($f, strlen($prefix));
	    $first = explode('/', $rest)[0];
	    $isDir = str_contains($rest, '/');
	    $items[$first] = [ 'type' => $isDir ? 'dir' : 'file', 'name' => $first, 'path' => $prefix . $first, 'size' => $isDir ? 0 : strlen($db['files'][$f]), 'sha' => sha1($f), 'html_url' => "https://github.com/acme/web/tree/main/{$prefix}{$first}" ];
	}
	if (!$items)
	    $fail(404, 'Not Found');
	$out(array_values($items));
    }
    if ($method === 'PUT') {
	$exists = isset($db['files'][$fp]);
	if ($exists && ($body['sha'] ?? null) !== sha1($db['files'][$fp]))
	    $fail(409, "{$fp} does not match " . ($body['sha'] ?? 'nothing'));
	if (!$exists && isset($body['sha']))
	    $fail(422, 'Invalid request.', [ 'sha wasn\'t supplied.' ]);
	$db['files'][$fp] = base64_decode((string)$body['content']);
	$db['last_commit'] = [ 'message' => $body['message'], 'branch' => $body['branch'] ?? 'main' ];
	$saveDb();
	$out([ 'content' => [ 'path' => $fp, 'sha' => sha1($db['files'][$fp]), 'html_url' => "https://github.com/acme/web/blob/main/{$fp}" ], 'commit' => [ 'sha' => str_repeat('e', 40), 'message' => $body['message'] ] ], $exists ? 200 : 201);
    }
    if ($method === 'DELETE') {
	if (!isset($db['files'][$fp]) || ($body['sha'] ?? '') !== sha1($db['files'][$fp]))
	    $fail(409, 'sha does not match');
	unset($db['files'][$fp]);
	$saveDb();
	$out([ 'content' => null, 'commit' => [ 'sha' => str_repeat('f', 40), 'message' => $body['message'] ] ]);
    }
}
if ($p === '/branches') {
    [ $slice, $h ] = $page(array_map(fn($k, $v) => [ 'name' => $k, 'commit' => [ 'sha' => $v ], 'protected' => $k === 'main' ], array_keys($db['branches']), array_values($db['branches'])));
    $out($slice, 200, $h);
}
if ($p === '/git/refs' && $method === 'POST') {
    $name = substr((string)$body['ref'], 11);
    if (isset($db['branches'][$name]))
	$fail(422, 'Reference already exists');
    $db['branches'][$name] = $body['sha'];
    $saveDb();
    $out([ 'ref' => $body['ref'], 'object' => [ 'sha' => $body['sha'] ] ], 201);
}
if (preg_match('~^/commits/(.+)$~', $p, $mm) && isset($db['branches'][$mm[1]]))
    $out([ 'sha' => $db['branches'][$mm[1]] ]);
if ($p === '/commits')
    $out([ [ 'sha' => str_repeat('c', 40), 'commit' => [ 'message' => 'Initial', 'author' => [ 'name' => 'Ana', 'email' => 'ana@acme.com', 'date' => '2026-11-01T10:00:00Z' ] ], 'author' => [ 'login' => 'ana' ],
	'html_url' => 'https://github.com/acme/web/commit/c' ] ]);
if ($p === '/tags')
    $out([ [ 'name' => 'v1.0.0', 'commit' => [ 'sha' => str_repeat('c', 40) ] ] ]);

//releases
if ($p === '/releases' && $method === 'GET')
    $out(array_reverse($db['releases']));
if ($p === '/releases/latest')
    $out(end($db['releases']));
if (preg_match('~^/releases/tags/(.+)$~', $p, $mm)) {
    foreach($db['releases'] as $r)
	if ($r['tag_name'] === $mm[1] && !$r['draft'])
	    $out($r);
    $fail(404, 'Not Found');
}
if ($p === '/releases' && $method === 'POST') {
    foreach($db['releases'] as $r)
	if ($r['tag_name'] === $body['tag_name'])
	    $fail(422, 'Validation Failed', [ [ 'resource' => 'Release', 'code' => 'already_exists', 'field' => 'tag_name' ] ]);
    $r = [ 'id' => 500 + count($db['releases']) + 1, 'tag_name' => $body['tag_name'], 'name' => $body['name'] ?? $body['tag_name'], 'body' => trim(($body['body'] ?? '') . (!empty($body['generate_release_notes']) ? "\n\n## What's Changed\n* Fix printer driver by @dan in #3" : '')),
	'draft' => (bool)($body['draft'] ?? false), 'prerelease' => (bool)($body['prerelease'] ?? false), 'target' => $body['target_commitish'] ?? 'main', 'created_at' => $now, 'published_at' => !empty($body['draft']) ? null : $now,
	'assets' => [], 'html_url' => "https://github.com/acme/web/releases/tag/{$body['tag_name']}", 'author' => [ 'login' => 'qbot' ] ];
    $db['releases'][] = $r;
    $saveDb();
    $out($r, 201);
}

//actions
if ($p === '/actions/workflows')
    $out([ 'total_count' => 1, 'workflows' => [ [ 'id' => 77, 'name' => 'Deploy', 'path' => '.github/workflows/deploy.yml', 'state' => 'active', 'html_url' => 'https://github.com/acme/web/actions/workflows/deploy.yml' ] ] ]);
if ($p === '/actions/workflows/deploy.yml/dispatches' && $method === 'POST') {
    if (!isset($db['branches'][$body['ref'] ?? '']))
	$fail(422, "No ref found for: " . ($body['ref'] ?? ''));
    $id = 901 + count($db['runs']);
    $db['runs'][$id] = [ 'id' => $id, 'name' => 'Deploy', 'run_number' => 8 + count($db['runs']), 'event' => 'workflow_dispatch', 'status' => 'queued', 'conclusion' => null, 'head_branch' => $body['ref'],
	'head_sha' => $db['branches'][$body['ref']], 'workflow_id' => 77, 'created_at' => $now, 'updated_at' => $now, 'gets' => 0, 'inputs' => $body['inputs'] ?? null ];
    $saveDb();
    $out(null, 204);
}
if (preg_match('~^/actions/(?:workflows/deploy\.yml/)?runs$~', $p)) {
    $wfOnly = str_contains($p, 'deploy.yml');
    $all = array_values(array_filter($db['runs'], fn($r) => (!$wfOnly || $r['workflow_id'] === 77 && $r['event'] === 'workflow_dispatch') && (!isset($query['event']) || $r['event'] === $query['event'])
	&& (!isset($query['branch']) || $r['head_branch'] === $query['branch']) && (!isset($query['status']) || $r['status'] === $query['status'] || $r['conclusion'] === $query['status'])
	&& (!isset($query['created']) || $r['created_at'] >= substr($query['created'], 2))));
    usort($all, fn($a, $b) => $b['id'] <=> $a['id']);
    [ $slice, $h ] = $page(array_map($runView, $all));
    $out([ 'total_count' => count($all), 'workflow_runs' => $slice ], 200, $h);
}
if (preg_match('~^/actions/runs/(\d+)(/.*)?$~', $p, $mm)) {
    $id = (int)$mm[1];
    $rest = $mm[2] ?? '';
    if (!isset($db['runs'][$id]))
	$fail(404, 'Not Found');
    $r = &$db['runs'][$id];
    if ($rest === '') {
	$r['gets']++;
	if ($r['status'] === 'queued' && $r['gets'] >= 1)
	    $r['status'] = 'in_progress';
	elseif ($r['status'] === 'in_progress' && $r['gets'] >= 3) {
	    $r['status'] = 'completed';
	    $r['conclusion'] = 'success';
	    $r['updated_at'] = $now;
	}
	$saveDb();
	$out($runView($r));
    }
    if ($rest === '/jobs')
	$out([ 'total_count' => 1, 'jobs' => [ [ 'id' => 6001, 'name' => 'build', 'status' => 'completed', 'conclusion' => $r['conclusion'] ?? 'failure', 'html_url' => 'https://github.com/acme/web/actions/runs/' . $id . '/job/6001',
	    'steps' => [ [ 'name' => 'Checkout', 'status' => 'completed', 'conclusion' => 'success' ], [ 'name' => 'Test', 'status' => 'completed', 'conclusion' => 'failure' ] ] ] ] ]);
    if (in_array($rest, [ '/rerun', '/rerun-failed-jobs', '/cancel' ], true) && $method === 'POST') {
	$r['status'] = $rest === '/cancel' ? 'completed' : 'queued';
	$r['conclusion'] = $rest === '/cancel' ? 'cancelled' : null;
	$r['attempt'] = ($r['attempt'] ?? 1) + ($rest === '/cancel' ? 0 : 1);
	$r['gets'] = 0;
	$r['last'] = $rest;
	$saveDb();
	$out((object)[], $rest === '/cancel' ? 202 : 201);
    }
    if ($rest === '/artifacts')
	$out([ 'total_count' => 1, 'artifacts' => [ [ 'id' => 8001, 'name' => 'dist', 'size_in_bytes' => 2048, 'expired' => false, 'archive_download_url' => "{$host}/repos/acme/web/actions/artifacts/8001/zip" ] ] ]);
}
if (preg_match('~^/actions/jobs/(\d+)/logs$~', $p))
    $out(null, 302, [ 'Location' => "{$host}/blob/job-log.txt?sig=x" ]);
$fail(404, 'Not Found');
