<?php

/*
    CLD: 2026-11-20T12:00:00+02:00: Synology Users - DSM's local users and groups (DSM's own web interface's APIs: an administrator; not documented for integrations).
    Operations:
	list          the users (SYNO.Core.User list, by pages): { name, email, description, expired }
	get           a user (SYNO.Core.User get)
	create        a user (SYNO.Core.User create): password, email, description; groups to join
	update        email, description, a new password (SYNO.Core.User set)
	disable       a user disabled / enabled (set expired: now / normal)
	enable
	delete        a user deleted (SYNO.Core.User delete; their home folder stays unless DSM is set to remove it)
	groups        the groups (SYNO.Core.Group list)
	members       a group's members (SYNO.Core.Group.Member list)
	addToGroup    users added to / removed from a group (SYNO.Core.Group.Member add / remove)
	removeFromGroup
    Users: a name, a list or one per line or comma (e.g. {{ users.items }})
*/

namespace plugins\ITOps\models\node;

class NodeAppSynoUsers extends NodeAppSynology {
    public static $name = 'syno-users';
    public static $label = 'Synology Users';
    public static $icon = 'bi-people';
    public static $defaultOperation = 'list';
    public static $defaultVariable = 'users';

    const OPERATIONS = [
	'list' => 'List users',
	'get' => 'Get a user',
	'create' => 'Create a user',
	'update' => 'Update a user',
	'disable' => 'Disable a user',
	'enable' => 'Enable a user',
	'delete' => 'Delete a user',
	'groups' => 'List groups',
	'members' => 'List a group\'s members',
	'addToGroup' => 'Add users to a group',
	'removeFromGroup' => 'Remove users from a group',
    ];
    //the operations that change something; the others only read (an AI step's tools: the read ones ticked by default, util\agent\Tools)
    const WRITES = [ 'create', 'update', 'disable', 'enable', 'delete', 'addToGroup', 'removeFromGroup' ];
    const LISTS = [ 'list', 'groups', 'members' ];
    const ADDITIONAL = [ 'email', 'description', 'expired' ];

    protected static function operationFields() {
	return [
	    self::field('user', 'User', [ 'get', 'create', 'update', 'disable', 'enable', 'delete' ], [ 'required' => true, 'help' => 'The account name',
		'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('password', 'Password', [ 'create', 'update' ], [ 'requiredIf' => [ 'if' => 'operation', 'in' => [ 'create' ] ],
		'help' => 'e.g. from a variable ({{ }}); Update: empty keeps it. It must meet DSM\'s password rules', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('email', 'Email', [ 'create', 'update' ], [ 'help' => 'Update: empty keeps it', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('description', 'Description', [ 'create', 'update' ], [ 'help' => 'Update: empty keeps it', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('cannotChange', 'The user may not change the password', [ 'create' ], [ 'type' => 'switch', 'default' => false, 'ui' => [ 'width' => 6 ] ]),
	    self::field('group', 'Group', [ 'members', 'addToGroup', 'removeFromGroup' ], [ 'required' => true, 'help' => 'Its name, e.g. administrators',
		'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('users', 'Users', [ 'addToGroup', 'removeFromGroup' ], [ 'type' => 'textarea', 'required' => true, 'help' => 'Names, one per line or separated by commas, or a list',
		'ui' => [ 'rows' => 2, 'expression' => true ] ]),
	    self::field('joinGroups', 'Groups to join', [ 'create' ], [ 'help' => 'Names, separated by commas; empty: users only', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	];
    }

    protected static function itemInfo($op) {
	return $op === 'groups' ? '{ name, description, gid }' : '{ name, email, description, expired }';
    }

    public static function account($name, $label = 'User') {
	$name = trim(\util\Expression::toText(is_array($name) ? ($name['name'] ?? '') : $name));
	if (!preg_match('/^[^\s\/\\\\:*?"<>|\[\]=+;,@`]{1,64}$/u', $name))
	    throw new \RuntimeException("{$label}: not a DSM account name: `" . mb_substr($name, 0, 64) . '`');
	return $name;
    }

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	switch($operation) {
	    case 'list':
		[ $rows, $total ] = $this->pages('SYNO.Core.User', 'list', [ 'type' => 'local', 'additional' => self::ADDITIONAL ], 'users', 1);
		return $this->listResult($rows, 'user', $total);
	    case 'get':
		$user = self::account($value('user'));
		$d = $this->call('SYNO.Core.User', 'get', [ 'name' => $user, 'additional' => self::ADDITIONAL ], 1);
		$u = (array)($d['users'][0] ?? $d);
		return [ $u, "user {$user}" ];
	    case 'create':
		$user = self::account($value('user'));
		$password = \util\Expression::toText($value('password'));
		if ($password === '')
		    throw new \RuntimeException('Password: empty');
		$d = $this->call('SYNO.Core.User', 'create', [ 'name' => $user, 'password' => $password, 'email' => $text('email'), 'description' => $text('description'),
		    'cannot_chg_passwd' => (bool)$this->cfg('cannotChange', false), 'expired' => 'normal', 'notify_by_email' => false, 'send_password' => false ], 1);
		$joined = [];
		foreach(self::names($text('joinGroups')) as $g) {
		    $this->nas->call('SYNO.Core.Group.Member', 'add', [ 'group' => self::account($g, 'Groups to join'), 'name' => [ $user ] ], 1);
		    $joined[] = $g;
		}
		return [ [ 'name' => $user, 'groups' => $joined ] + $d, "user {$user} created" . ($joined ? ', in ' . implode(', ', $joined) : '') ];
	    case 'update':
		$user = self::account($value('user'));
		$set = [ 'name' => $user ];
		$password = \util\Expression::toText($value('password'));
		if ($password !== '')
		    $set['password'] = $password;
		foreach([ 'email', 'description' ] as $k)
		    if ($text($k) !== '')
			$set[$k] = $text($k);
		if (count($set) === 1 && !$this->extraParams())
		    throw new \RuntimeException('nothing to change: give a password, an email or a description');
		$d = $this->call('SYNO.Core.User', 'set', $set, 1);
		return [ [ 'name' => $user, 'changed' => array_keys(array_diff_key($set, [ 'name' => 1 ])) ] + $d, "user {$user} updated" ];
	    case 'disable':
	    case 'enable':
		$user = self::account($value('user'));
		$d = $this->call('SYNO.Core.User', 'set', [ 'name' => $user, 'expired' => $operation === 'disable' ? 'now' : 'normal' ], 1);
		return [ [ 'name' => $user, 'disabled' => $operation === 'disable' ] + $d, "user {$user} " . ($operation === 'disable' ? 'disabled' : 'enabled') ];
	    case 'delete':
		$user = self::account($value('user'));
		if (strcasecmp($user, trim((string)$this->nas->accountName())) === 0)
		    throw new \RuntimeException("{$user} is the credential's own account");
		$d = $this->call('SYNO.Core.User', 'delete', [ 'name' => [ $user ] ], 1);
		return [ [ 'name' => $user, 'deleted' => true ] + $d, "user {$user} deleted" ];
	    case 'groups':
		[ $rows, $total ] = $this->pages('SYNO.Core.Group', 'list', [ 'type' => 'local' ], 'groups', 1);
		return $this->listResult($rows, 'group', $total);
	    case 'members':
		$group = self::account($value('group'), 'Group');
		[ $rows, $total ] = $this->pages('SYNO.Core.Group.Member', 'list', [ 'group' => $group, 'ingroup' => true ], 'users', 1);
		[ $r, $summary ] = $this->listResult($rows, 'member', $total);
		return [ $r, "{$group}: {$summary}" ];
	    case 'addToGroup':
	    case 'removeFromGroup':
		$group = self::account($value('group'), 'Group');
		$users = array_map(fn($u) => self::account($u, 'Users'), self::names($value('users')));
		if (!$users)
		    throw new \RuntimeException('Users: none given');
		$d = $this->call('SYNO.Core.Group.Member', $operation === 'addToGroup' ? 'add' : 'remove', [ 'group' => $group, 'name' => $users ], 1);
		return [ [ 'group' => $group, 'users' => $users ] + $d, \util\Format::plural(count($users), 'user') . ($operation === 'addToGroup' ? ' added to ' : ' removed from ') . $group ];
	}
	throw new \RuntimeException("unknown operation `{$operation}`");
    }
}
