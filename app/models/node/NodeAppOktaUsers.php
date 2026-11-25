<?php

/*
    CLD: 2026-11-26T02:00:00+02:00: Okta Users - the org's people (NodeAppOkta). Operations:
	list            people: a search of names and addresses (q), or Okta's search expression (e.g. profile.department eq "Sales"), by status
	get             a person (by login or ID)
	create          first and last name, email, login (empty: the email), more profile attributes (JSON), activated (Okta emails them) or staged, a password optionally
	update          profile attributes given (the others kept)
	deactivate, activate, suspend, unsuspend, delete (a deactivated person only: deactivated first when asked)
	resetPassword   a reset link emailed to them, or given back (sendEmail off)
	expirePassword  changed at the next sign-in
	clearSessions   signed out of Okta (and, optionally, the OAuth tokens it gave revoked)
	groups          the person's groups
	offboard        in one step: deactivated (or suspended), sessions cleared, removed from the groups it may leave (Okta groups, not the app or directory ones), app assignments
	                removed optionally; what was done, and what failed, given back
    A person: { id, login, email, firstName, lastName, displayName, status, department, title, mobile, created, activated, lastLogin, profile }
*/

namespace models\node;

class NodeAppOktaUsers extends NodeAppOkta {
    public static $name = 'okta-users';
    public static $label = 'Okta Users';
    public static $icon = 'bi-person';
    public static $defaultOperation = 'get';
    public static $defaultVariable = 'user';

    const OPERATIONS = [
	'list' => 'List people',
	'get' => 'Get a person',
	'create' => 'Create a person',
	'update' => 'Update a person',
	'deactivate' => 'Deactivate',
	'activate' => 'Activate',
	'suspend' => 'Suspend',
	'unsuspend' => 'Unsuspend',
	'delete' => 'Delete',
	'resetPassword' => 'Reset the password',
	'expirePassword' => 'Expire the password',
	'clearSessions' => 'Sign out everywhere',
	'groups' => 'List the person\'s groups',
	'offboard' => 'Offboard a person',
    ];
    const WRITES = [ 'create', 'update', 'deactivate', 'activate', 'suspend', 'unsuspend', 'delete', 'resetPassword', 'expirePassword', 'clearSessions', 'offboard' ];
    const LISTS = [ 'list', 'groups' ];
    const STATUSES = [ '' => 'All', 'ACTIVE' => 'Active', 'STAGED' => 'Staged', 'PROVISIONED' => 'Pending activation', 'SUSPENDED' => 'Suspended', 'DEPROVISIONED' => 'Deactivated', 'LOCKED_OUT' => 'Locked out',
	'PASSWORD_EXPIRED' => 'Password expired', 'RECOVERY' => 'Password reset' ];
    const BY_USER = [ 'get', 'update', 'deactivate', 'activate', 'suspend', 'unsuspend', 'delete', 'resetPassword', 'expirePassword', 'clearSessions', 'groups', 'offboard' ];
    const MAX_GROUPS = 1000;

    protected static function operationFields() {
	return [
	    self::userField(self::BY_USER),
	    self::field('q', 'Search', [ 'list' ], [ 'help' => 'Optional: the start of a name or address', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('search', 'Expression', [ 'list' ], [ 'help' => 'Optional: Okta\'s search, e.g. profile.department eq "Sales"', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('status', 'Status', [ 'list' ], [ 'type' => 'select', 'default' => '', 'choices' => self::STATUSES, 'ui' => [ 'width' => 6 ] ]),
	    self::field('firstName', 'First name', [ 'create', 'update' ], [ 'ui' => [ 'expression' => 'text', 'width' => 3 ] ]),
	    self::field('lastName', 'Last name', [ 'create', 'update' ], [ 'ui' => [ 'expression' => 'text', 'width' => 3 ] ]),
	    self::field('email', 'Email', [ 'create', 'update' ], [ 'ui' => [ 'expression' => 'text', 'width' => 3 ] ]),
	    self::field('login', 'Login', [ 'create' ], [ 'help' => 'Empty: the email', 'ui' => [ 'expression' => 'text', 'width' => 3 ] ]),
	    self::field('department', 'Department', [ 'create', 'update' ], [ 'ui' => [ 'expression' => 'text', 'width' => 3 ] ]),
	    self::field('title', 'Title', [ 'create', 'update' ], [ 'ui' => [ 'expression' => 'text', 'width' => 3 ] ]),
	    self::field('more', 'More attributes', [ 'create', 'update' ], [ 'type' => 'textarea', 'help' => 'Optional: a JSON object of profile attributes, e.g. {"employeeNumber": "4711", "costCenter": "R&D"}',
		'ui' => [ 'rows' => 2, 'expression' => true, 'code' => true, 'advanced' => true ] ]),
	    self::field('activate', 'Activate', [ 'create' ], [ 'type' => 'switch', 'default' => true, 'help' => 'Okta emails them an activation; off: staged', 'ui' => [ 'width' => 3 ] ]),
	    self::field('password', 'Password', [ 'create' ], [ 'help' => 'Optional: they sign in with it (else they set one when activating)', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('sendEmail', 'Email them', [ 'resetPassword', 'deactivate' ], [ 'type' => 'switch', 'default' => true, 'help' => 'Reset: off gives the link back; deactivate: an email to the admins',
		'ui' => [ 'width' => 6 ] ]),
	    self::field('revokeTokens', 'Revoke the OAuth tokens', [ 'clearSessions', 'offboard' ], [ 'type' => 'switch', 'default' => true, 'ui' => [ 'width' => 3 ] ]),
	    self::field('deactivateFirst', 'Deactivate first', [ 'delete' ], [ 'type' => 'switch', 'default' => false, 'help' => 'Okta deletes deactivated people only', 'ui' => [ 'width' => 6 ] ]),
	    self::field('how', 'Lock with', [ 'offboard' ], [ 'type' => 'select', 'required' => true, 'default' => 'deactivate', 'choices' => [ 'deactivate' => 'Deactivate', 'suspend' => 'Suspend (keeps the app accounts)' ], 'ui' => [ 'width' => 3 ] ]),
	    self::field('removeGroups', 'Remove from groups', [ 'offboard' ], [ 'type' => 'switch', 'default' => true, 'ui' => [ 'width' => 3 ] ]),
	    self::field('removeApps', 'Remove app assignments', [ 'offboard' ], [ 'type' => 'switch', 'default' => false, 'help' => 'Direct assignments (deactivating removes them too)', 'ui' => [ 'width' => 3 ] ]),
	];
    }

    public static function outputVariables($config) {
	$name = is_array($config) ? (string)($config['variable'] ?? static::$defaultVariable) : static::$defaultVariable;
	$op = is_array($config) ? (string)($config['operation'] ?? static::$defaultOperation) : static::$defaultOperation;
	if (!\util\Validator::isIdentifier($name) || in_array($op, self::LISTS, true))
	    return parent::outputVariables($config);
	if ($op === 'offboard')
	    return [ $name => [ 'type' => 'object', 'info' => '{ user, locked, sessionsCleared, groupsRemoved, groupsKept, appsRemoved, errors }' ] ];
	return [ $name => [ 'type' => 'object', 'info' => 'the person: { id, login, email, status, … }', 'fields' => [ 'id' => [ 'type' => 'string' ], 'login' => [ 'type' => 'string' ],
	    'email' => [ 'type' => 'string' ], 'displayName' => [ 'type' => 'string' ], 'status' => [ 'type' => 'string', 'info' => 'ACTIVE, STAGED, SUSPENDED, DEPROVISIONED, …' ],
	    'resetUrl' => [ 'type' => 'string', 'info' => 'reset without an email: the link' ] ] ] ];
    }

    //the profile attributes of the settings
    private function profile(\Closure $text) {
	return array_merge(array_filter([ 'firstName' => $text('firstName'), 'lastName' => $text('lastName'), 'email' => $text('email'), 'department' => $text('department'), 'title' => $text('title') ],
	    fn($v) => $v !== ''), (array)($this->jsonOf('more', 'More attributes') ?? []));
    }

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	if ($operation === 'list') {
	    $search = $text('search');
	    $status = (string)$this->cfg('status', '');
	    if ($status !== '' && isset(self::STATUSES[$status]))
		$search = trim(($search !== '' ? "({$search}) and " : '') . "status eq \"{$status}\"");
	    return $this->listOf('/users', array_filter([ 'q' => $search === '' ? $text('q') : null, 'search' => $search !== '' ? $search : null ]), 'person', fn($u) => self::userOf($u));
	}
	if ($operation === 'create') {
	    $p = $this->profile($text);
	    $p['login'] = $text('login') !== '' ? $text('login') : (string)($p['email'] ?? '');
	    foreach([ 'firstName', 'lastName', 'email' ] as $k)
		if (($p[$k] ?? '') === '')
		    throw new \RuntimeException('a person needs a first name, a last name and an email');
	    $body = [ 'profile' => $p ];
	    if ($text('password') !== '')
		$body['credentials'] = [ 'password' => [ 'value' => $text('password') ] ];
	    $u = self::userOf($this->okta->request('POST', '/users', [ 'activate' => $this->cfg('activate', true) ? 'true' : 'false' ], $body));
	    return [ $u, "{$u['login']} created ({$u['status']})" ];
	}
	$ref = self::userRef($value('user'));
	$path = "/users/{$ref}";
	switch($operation) {
	    case 'get':
		$u = self::userOf($this->okta->request('GET', $path));
		return [ $u, "{$u['login']} ({$u['status']})" ];
	    case 'update':
		$p = $this->profile($text);
		if (!$p)
		    throw new \RuntimeException('nothing to change: give a profile attribute');
		//POST: a partial update (the attributes not given are kept)
		$u = self::userOf($this->okta->request('POST', $path, [], [ 'profile' => $p ]));
		return [ $u, "{$u['login']} updated (" . implode(', ', array_keys($p)) . ')' ];
	    case 'deactivate':
	    case 'activate':
	    case 'suspend':
	    case 'unsuspend':
		$q = $operation === 'deactivate' ? [ 'sendEmail' => $this->cfg('sendEmail', true) ? 'true' : 'false' ] : ($operation === 'activate' ? [ 'sendEmail' => 'true' ] : []);
		$this->okta->request('POST', "{$path}/lifecycle/{$operation}", $q);
		$u = self::userOf($this->okta->request('GET', $path));
		return [ $u, "{$u['login']}: {$u['status']}" ];
	    case 'delete':
		$u = self::userOf($this->okta->request('GET', $path));
		if ($u['status'] !== 'DEPROVISIONED') {
		    if (!$this->cfg('deactivateFirst', false))
			throw new \RuntimeException("{$u['login']} is {$u['status']}: deactivate it first (or turn on Deactivate first)");
		    $this->okta->request('POST', "{$path}/lifecycle/deactivate", [ 'sendEmail' => 'false' ]);
		}
		$this->okta->request('DELETE', "/users/{$u['id']}");
		return [ [ 'id' => $u['id'], 'login' => $u['login'], 'deleted' => true ], "{$u['login']} deleted" ];
	    case 'resetPassword':
		$send = (bool)$this->cfg('sendEmail', true);
		$r = $this->okta->request('POST', "{$path}/lifecycle/reset_password", [ 'sendEmail' => $send ? 'true' : 'false' ]);
		return [ [ 'id' => rawurldecode($ref), 'emailed' => $send ] + (!$send ? [ 'resetUrl' => (string)($r['resetPasswordUrl'] ?? '') ] : []), rawurldecode($ref) . ($send ? ': a reset link emailed' : ': a reset link made') ];
	    case 'expirePassword':
		$u = self::userOf($this->okta->request('POST', "{$path}/lifecycle/expire_password"));
		return [ $u, "{$u['login']}: the password expired" ];
	    case 'clearSessions':
		$this->okta->request('DELETE', "{$path}/sessions", [ 'oauthTokens' => $this->cfg('revokeTokens', true) ? 'true' : 'false' ]);
		return [ [ 'id' => rawurldecode($ref), 'done' => true ], rawurldecode($ref) . ': signed out everywhere' ];
	    case 'groups':
		return $this->listOf("{$path}/groups", [], 'group', fn($g) => self::groupOf($g));
	    case 'offboard':
		return $this->offboard($path);
	}
	throw new \RuntimeException("unknown operation {$operation}");
    }

    //lock, sessions, groups, apps: what was done; an error of one part does not stop the others
    private function offboard($path) {
	$u = self::userOf($this->okta->request('GET', $path));
	$id = $u['id'];
	$r = [ 'user' => $u, 'locked' => '', 'sessionsCleared' => false, 'groupsRemoved' => [], 'groupsKept' => [], 'appsRemoved' => [], 'errors' => [] ];
	$try = function($what, \Closure $f) use (&$r) {
	    try {
		$f();
	    } catch (\RuntimeException $e) {
		$r['errors'][] = "{$what}: " . $e->getMessage();
	    }
	};
	//the groups and apps read before the account is locked (a deactivated person's assignments are gone)
	$groups = [];
	$apps = [];
	if ($this->cfg('removeGroups', true))
	    $try('groups', function() use ($id, &$groups) {
		[ $groups ] = $this->okta->pages("/users/{$id}/groups", [], self::MAX_GROUPS);
	    });
	if ($this->cfg('removeApps', false))
	    $try('apps', function() use ($id, &$apps) {
		[ $apps ] = $this->okta->pages("/users/{$id}/appLinks", [], 500);
	    });
	foreach($groups as $g) {
	    $name = (string)($g['profile']['name'] ?? $g['id']);
	    if (($g['type'] ?? '') !== 'OKTA_GROUP' || $name === 'Everyone') {
		$r['groupsKept'][] = $name;
		continue;
	    }
	    $try("group {$name}", function() use ($g, $id, $name, &$r) {
		$this->okta->request('DELETE', "/groups/{$g['id']}/users/{$id}");
		$r['groupsRemoved'][] = $name;
	    });
	}
	foreach($apps as $a) {
	    $appId = (string)($a['appInstanceId'] ?? '');
	    if ($appId === '')
		continue;
	    $try('app ' . ($a['label'] ?? $appId), function() use ($appId, $id, $a, &$r) {
		[ $status ] = $this->okta->request('DELETE', "/apps/{$appId}/users/{$id}", [], null, [ 'full' => true, 'ok' => [ 404, 403 ] ]);
		if ($status < 400)
		    $r['appsRemoved'][] = (string)($a['label'] ?? $appId);
	    });
	}
	$try('sessions', function() use ($id, &$r) {
	    $this->okta->request('DELETE', "/users/{$id}/sessions", [ 'oauthTokens' => $this->cfg('revokeTokens', true) ? 'true' : 'false' ]);
	    $r['sessionsCleared'] = true;
	});
	$how = (string)$this->cfg('how', 'deactivate') === 'suspend' ? 'suspend' : 'deactivate';
	$try($how, function() use ($id, $how, &$r) {
	    $this->okta->request('POST', "/users/{$id}/lifecycle/{$how}", $how === 'deactivate' ? [ 'sendEmail' => 'false' ] : []);
	    $r['locked'] = $how === 'suspend' ? 'SUSPENDED' : 'DEPROVISIONED';
	});
	return [ $r, "{$u['login']} offboarded: " . implode(', ', array_filter([ $r['locked'] !== '' ? strtolower($r['locked']) : null, $r['sessionsCleared'] ? 'signed out' : null,
	    $r['groupsRemoved'] ? count($r['groupsRemoved']) . ' group(s) left' : null, $r['appsRemoved'] ? count($r['appsRemoved']) . ' app(s) removed' : null ])) . ($r['errors'] ? '; ' . count($r['errors']) . ' error(s)' : '') ];
    }
}
