<?php

/*
    CLD: 2026-11-26T01:00:00+02:00: Entra ID Users - the tenant's users (NodeAppEntra; Graph application permissions: User.Read.All to read, User.ReadWrite.All to change; a
    password reset also needs the app in the User Administrator role, or User-PasswordProfile.ReadWrite.All). Operations:
	list            users: a search of names and addresses, or an OData filter (e.g. department eq 'Sales'), only enabled or disabled ones
	get             a user (by user principal name or ID), with its manager optionally
	create          display name, user principal name, a password (empty: one made, given back once), must change it at the next sign-in, job title, department, usage location
	update          the settings given (and any of Graph's user properties as JSON)
	disable, enable (accountEnabled), delete (to the deleted items for 30 days)
	resetPassword   a new password (empty: one made, given back once), changed at the next sign-in
	revokeSessions  the user's refresh tokens and sessions revoked: signed out everywhere within the hour
	groups          the groups (and directory roles) the user is a member of
	setManager      the user's manager
	offboard        in one step: disabled, sessions revoked, removed from the groups it may leave (not dynamic ones, not synced ones), licenses removed, the manager kept or
	                removed; what was done, and what failed, given back
    A user: { id, displayName, givenName, surname, upn, mail, jobTitle, department, office, mobile, enabled, type, employeeId, usageLocation, synced, created }
*/

namespace models\node;

class NodeAppEntraUsers extends NodeAppEntra {
    public static $name = 'entra-users';
    public static $label = 'Entra ID Users';
    public static $icon = 'bi-person-badge';
    public static $defaultOperation = 'get';
    public static $defaultVariable = 'user';

    const OPERATIONS = [
	'list' => 'List users',
	'get' => 'Get a user',
	'create' => 'Create a user',
	'update' => 'Update a user',
	'disable' => 'Disable a user',
	'enable' => 'Enable a user',
	'delete' => 'Delete a user',
	'resetPassword' => 'Reset the password',
	'revokeSessions' => 'Sign out everywhere',
	'groups' => 'List the user\'s groups',
	'setManager' => 'Set the manager',
	'offboard' => 'Offboard a user',
    ];
    const WRITES = [ 'create', 'update', 'disable', 'enable', 'delete', 'resetPassword', 'revokeSessions', 'setManager', 'offboard' ];
    const LISTS = [ 'list', 'groups' ];
    const ENABLED = [ '' => 'All', 'true' => 'Enabled', 'false' => 'Disabled' ];
    const BY_USER = [ 'get', 'update', 'disable', 'enable', 'delete', 'resetPassword', 'revokeSessions', 'groups', 'setManager', 'offboard' ];
    const MAX_GROUPS = 1000;

    protected static function operationFields() {
	return [
	    self::userField(self::BY_USER),
	    self::field('search', 'Search', [ 'list' ], [ 'help' => 'Optional: words of the name, address or user principal name', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('filter', 'Filter', [ 'list' ], [ 'help' => 'Optional: an OData filter, e.g. department eq \'Sales\'', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('enabled', 'Accounts', [ 'list' ], [ 'type' => 'select', 'default' => '', 'choices' => self::ENABLED, 'ui' => [ 'width' => 6 ] ]),
	    self::field('withManager', 'With the manager', [ 'get' ], [ 'type' => 'switch', 'default' => false, 'ui' => [ 'width' => 6 ] ]),
	    self::field('displayName', 'Display name', [ 'create', 'update' ], [ 'help' => 'Update: empty leaves it', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('upn', 'User principal name', [ 'create' ], [ 'help' => 'e.g. ana.pop@acme.com (a verified domain of the tenant)', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('givenName', 'First name', [ 'create', 'update' ], [ 'ui' => [ 'expression' => 'text', 'width' => 3 ] ]),
	    self::field('surname', 'Last name', [ 'create', 'update' ], [ 'ui' => [ 'expression' => 'text', 'width' => 3 ] ]),
	    self::field('jobTitle', 'Job title', [ 'create', 'update' ], [ 'ui' => [ 'expression' => 'text', 'width' => 3 ] ]),
	    self::field('department', 'Department', [ 'create', 'update' ], [ 'ui' => [ 'expression' => 'text', 'width' => 3 ] ]),
	    self::field('usageLocation', 'Usage location', [ 'create', 'update' ], [ 'help' => 'Two letters, e.g. RO (needed for licenses)', 'ui' => [ 'expression' => 'text', 'width' => 3 ] ]),
	    self::field('password', 'Password', [ 'create', 'resetPassword' ], [ 'help' => 'Empty: one is made (given back once in the result)', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('mustChange', 'Change it at the next sign-in', [ 'create', 'resetPassword' ], [ 'type' => 'switch', 'default' => true, 'ui' => [ 'width' => 6 ] ]),
	    self::field('more', 'More properties', [ 'create', 'update' ], [ 'type' => 'textarea', 'help' => 'Optional: a JSON object of Graph\'s user properties, e.g. {"employeeId": "4711", "officeLocation": "B2"}',
		'ui' => [ 'rows' => 2, 'expression' => true, 'code' => true, 'advanced' => true ] ]),
	    self::field('manager', 'Manager', [ 'setManager' ], [ 'required' => true, 'help' => 'The manager\'s user principal name or ID', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('removeGroups', 'Remove from groups', [ 'offboard' ], [ 'type' => 'switch', 'default' => true, 'ui' => [ 'width' => 3 ] ]),
	    self::field('removeLicenses', 'Remove licenses', [ 'offboard' ], [ 'type' => 'switch', 'default' => true, 'ui' => [ 'width' => 3 ] ]),
	    self::field('removeManager', 'Remove the manager', [ 'offboard' ], [ 'type' => 'switch', 'default' => false, 'ui' => [ 'width' => 3 ] ]),
	];
    }

    public static function outputVariables($config) {
	$name = is_array($config) ? (string)($config['variable'] ?? static::$defaultVariable) : static::$defaultVariable;
	$op = is_array($config) ? (string)($config['operation'] ?? static::$defaultOperation) : static::$defaultOperation;
	if (!\util\Validator::isIdentifier($name) || in_array($op, self::LISTS, true))
	    return parent::outputVariables($config);
	if ($op === 'offboard')
	    return [ $name => [ 'type' => 'object', 'info' => '{ user, disabled, sessionsRevoked, groupsRemoved, groupsKept, licensesRemoved, managerRemoved, errors }' ] ];
	if (in_array($op, [ 'delete', 'revokeSessions', 'resetPassword' ], true))
	    return [ $name => [ 'type' => 'object', 'info' => $op === 'resetPassword' ? '{ id, password (when made), mustChange }' : '{ id, done }' ] ];
	return [ $name => [ 'type' => 'object', 'info' => 'the user: { id, displayName, upn, mail, enabled, … }', 'fields' => [ 'id' => [ 'type' => 'string' ], 'displayName' => [ 'type' => 'string' ],
	    'upn' => [ 'type' => 'string', 'info' => 'the user principal name' ], 'mail' => [ 'type' => 'string' ], 'enabled' => [ 'type' => 'boolean' ], 'department' => [ 'type' => 'string' ],
	    'jobTitle' => [ 'type' => 'string' ], 'password' => [ 'type' => 'string', 'info' => 'create: the one made, once' ] ] ] ];
    }

    //a password that meets Entra's default rules: 16 characters of the four kinds
    public static function newPassword() {
	$sets = [ 'ABCDEFGHJKLMNPQRSTUVWXYZ', 'abcdefghijkmnopqrstuvwxyz', '23456789', '!#%+-=?@' ];
	$all = implode('', $sets);
	$p = array_map(fn($s) => $s[random_int(0, strlen($s) - 1)], $sets);
	while (count($p) < 16)
	    $p[] = $all[random_int(0, strlen($all) - 1)];
	for ($i = count($p) - 1; $i > 0; $i--) {
	    $j = random_int(0, $i);
	    [ $p[$i], $p[$j] ] = [ $p[$j], $p[$i] ];
	}
	return implode('', $p);
    }

    //the user properties of the settings (empty ones left out)
    private function properties(\Closure $text) {
	$p = array_filter([ 'displayName' => $text('displayName'), 'givenName' => $text('givenName'), 'surname' => $text('surname'), 'jobTitle' => $text('jobTitle'),
	    'department' => $text('department'), 'usageLocation' => strtoupper($text('usageLocation')) ], fn($v) => $v !== '');
	if (isset($p['usageLocation']) && !preg_match('/^[A-Z]{2}$/', $p['usageLocation']))
	    throw new \RuntimeException('Usage location: two letters, e.g. RO');
	return array_merge($p, (array)($this->jsonOf('more', 'More properties') ?? []));
    }

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	if ($operation === 'list') {
	    $filters = [];
	    if ($text('filter') !== '')
		$filters[] = '(' . $text('filter') . ')';
	    if (in_array((string)$this->cfg('enabled', ''), [ 'true', 'false' ], true))
		$filters[] = 'accountEnabled eq ' . $this->cfg('enabled');
	    $q = [ '$select' => self::USER_FIELDS, '$filter' => $filters ? implode(' and ', $filters) : null, '$count' => 'true' ];
	    $search = str_replace('"', '', $text('search'));
	    if ($search !== '')
		$q['$search'] = "\"displayName:{$search}\" OR \"mail:{$search}\" OR \"userPrincipalName:{$search}\"";
	    return $this->listOf('/users', $q, 'user', fn($u) => self::userOf($u), [ 'eventual' => true ]);
	}
	if ($operation === 'create') {
	    $props = $this->properties($text);
	    $upn = $text('upn');
	    if (($props['displayName'] ?? '') === '' || !preg_match('/^[^\s@]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}$/', $upn))
		throw new \RuntimeException('a user needs a display name and a user principal name (name@domain)');
	    $password = $text('password');
	    $made = $password === '';
	    if ($made)
		$password = self::newPassword();
	    $u = self::userOf($this->graph->request('POST', '/users', [], $props + [ 'userPrincipalName' => $upn, 'mailNickname' => substr(preg_replace('/[^A-Za-z0-9._-]/', '', strstr($upn, '@', true)), 0, 64) ?: 'user',
		'accountEnabled' => true, 'passwordProfile' => [ 'password' => $password, 'forceChangePasswordNextSignIn' => (bool)$this->cfg('mustChange', true) ] ]));
	    return [ $u + ($made ? [ 'password' => $password ] : []), "{$u['upn']} created" . ($made ? ' (a password made)' : '') ];
	}
	$ref = self::userRef($value('user'));
	$path = "/users/{$ref}";
	switch($operation) {
	    case 'get':
		$u = self::userOf($this->graph->request('GET', $path, [ '$select' => self::USER_FIELDS ]));
		if ($this->cfg('withManager', false)) {
		    $m = $this->graph->request('GET', "{$path}/manager", [ '$select' => 'id,displayName,userPrincipalName,mail' ], null, [ 'ok' => [ 404 ] ]);
		    $u['manager'] = isset($m['id']) ? [ 'id' => (string)$m['id'], 'displayName' => (string)($m['displayName'] ?? ''), 'upn' => (string)($m['userPrincipalName'] ?? ''), 'mail' => (string)($m['mail'] ?? '') ] : null;
		}
		return [ $u, "{$u['upn']} (" . ($u['enabled'] ? 'enabled' : 'disabled') . ')' ];
	    case 'update':
		$props = $this->properties($text);
		if (!$props)
		    throw new \RuntimeException('nothing to change: give a property');
		$this->graph->request('PATCH', $path, [], $props);
		$u = self::userOf($this->graph->request('GET', $path, [ '$select' => self::USER_FIELDS ]));
		return [ $u, "{$u['upn']} updated (" . implode(', ', array_keys($props)) . ')' ];
	    case 'disable':
	    case 'enable':
		$this->graph->request('PATCH', $path, [], [ 'accountEnabled' => $operation === 'enable' ]);
		$u = self::userOf($this->graph->request('GET', $path, [ '$select' => self::USER_FIELDS ]));
		return [ $u, "{$u['upn']} {$operation}d" ];
	    case 'delete':
		$this->graph->request('DELETE', $path);
		return [ [ 'id' => rawurldecode($ref), 'done' => true ], rawurldecode($ref) . ' deleted (restorable for 30 days)' ];
	    case 'resetPassword':
		$password = $text('password');
		$made = $password === '';
		if ($made)
		    $password = self::newPassword();
		$this->graph->request('PATCH', $path, [], [ 'passwordProfile' => [ 'password' => $password, 'forceChangePasswordNextSignIn' => (bool)$this->cfg('mustChange', true) ] ]);
		return [ [ 'id' => rawurldecode($ref), 'mustChange' => (bool)$this->cfg('mustChange', true) ] + ($made ? [ 'password' => $password ] : []), rawurldecode($ref) . ': the password reset' ];
	    case 'revokeSessions':
		$this->graph->request('POST', "{$path}/revokeSignInSessions", [], (object)[]);
		return [ [ 'id' => rawurldecode($ref), 'done' => true ], rawurldecode($ref) . ': signed out everywhere' ];
	    case 'groups':
		return $this->listOf("{$path}/memberOf", [ '$select' => 'id,displayName,groupTypes,securityEnabled,mailEnabled,membershipRule,onPremisesSyncEnabled' ], 'group', fn($g) => self::groupOf($g)
		    + [ 'role' => ($g['@odata.type'] ?? '') === '#microsoft.graph.directoryRole' ]);
	    case 'setManager':
		$mid = $this->userId($value('manager'), 'Manager');
		$this->graph->request('PUT', "{$path}/manager/\$ref", [], [ '@odata.id' => $this->graph->graph() . "/v1.0/users/{$mid}" ]);
		$u = self::userOf($this->graph->request('GET', $path, [ '$select' => self::USER_FIELDS ]));
		return [ $u + [ 'manager' => $mid ], "{$u['upn']}: manager set" ];
	    case 'offboard':
		return $this->offboard($ref, $path);
	}
	throw new \RuntimeException("unknown operation {$operation}");
    }

    //disable, revoke, groups, licenses, manager: what was done; an error of one part does not stop the others (given in errors)
    private function offboard($ref, $path) {
	$u = self::userOf($this->graph->request('GET', $path, [ '$select' => self::USER_FIELDS ]));
	$id = $u['id'];
	$r = [ 'user' => $u, 'disabled' => false, 'sessionsRevoked' => false, 'groupsRemoved' => [], 'groupsKept' => [], 'licensesRemoved' => [], 'managerRemoved' => false, 'errors' => [] ];
	$try = function($what, \Closure $f) use (&$r) {
	    try {
		$f();
	    } catch (\RuntimeException $e) {
		$r['errors'][] = "{$what}: " . $e->getMessage();
	    }
	};
	$try('disable', function() use ($path, &$r) {
	    $this->graph->request('PATCH', $path, [], [ 'accountEnabled' => false ]);
	    $r['disabled'] = true;
	});
	$try('sessions', function() use ($path, &$r) {
	    $this->graph->request('POST', "{$path}/revokeSignInSessions", [], (object)[]);
	    $r['sessionsRevoked'] = true;
	});
	if ($this->cfg('removeGroups', true))
	    $try('groups', function() use ($path, $id, &$r, $try) {
		[ $groups ] = $this->graph->pages("{$path}/memberOf/microsoft.graph.group", [ '$select' => 'id,displayName,groupTypes,onPremisesSyncEnabled' ], self::MAX_GROUPS);
		foreach($groups as $g) {
		    $name = (string)($g['displayName'] ?? $g['id']);
		    if (in_array('DynamicMembership', (array)($g['groupTypes'] ?? []), true) || !empty($g['onPremisesSyncEnabled'])) {
			$r['groupsKept'][] = $name;
			continue;
		    }
		    $try("group {$name}", function() use ($g, $id, $name, &$r) {
			$this->graph->request('DELETE', "/groups/{$g['id']}/members/{$id}/\$ref");
			$r['groupsRemoved'][] = $name;
		    });
		}
	    });
	if ($this->cfg('removeLicenses', true))
	    $try('licenses', function() use ($path, &$r) {
		$lic = (array)($this->graph->request('GET', "{$path}/licenseDetails", [ '$select' => 'skuId,skuPartNumber' ])['value'] ?? []);
		if ($lic) {
		    $this->graph->request('POST', "{$path}/assignLicense", [], [ 'addLicenses' => [], 'removeLicenses' => array_values(array_map(fn($l) => (string)$l['skuId'], $lic)) ]);
		    $r['licensesRemoved'] = array_values(array_map(fn($l) => (string)($l['skuPartNumber'] ?? $l['skuId']), $lic));
		}
	    });
	if ($this->cfg('removeManager', false))
	    $try('manager', function() use ($path, &$r) {
		[ $status ] = $this->graph->request('DELETE', "{$path}/manager/\$ref", [], null, [ 'full' => true, 'ok' => [ 404 ] ]);
		$r['managerRemoved'] = $status !== 404;
	    });
	return [ $r, "{$u['upn']} offboarded: " . implode(', ', array_filter([ $r['disabled'] ? 'disabled' : null, $r['sessionsRevoked'] ? 'signed out' : null,
	    $r['groupsRemoved'] ? count($r['groupsRemoved']) . ' group(s) left' : null, $r['licensesRemoved'] ? count($r['licensesRemoved']) . ' license(s) removed' : null,
	    $r['managerRemoved'] ? 'manager removed' : null ])) . ($r['errors'] ? '; ' . count($r['errors']) . ' error(s)' : '') ];
    }
}
