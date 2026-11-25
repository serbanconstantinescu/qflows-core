<?php

/*
    CLD: 2026-11-26T02:00:00+02:00: Okta Groups - the org's groups (NodeAppOkta). Operations:
	list           groups: a search of names (q), or Okta's search expression (e.g. type eq "OKTA_GROUP")
	get            a group (by ID or name)
	create, delete an Okta group (a name, a description); app and directory groups are not deleted here
	members        its people
	addMembers     people (logins or IDs, a list); removeMember
    A group: { id, name, description, type (OKTA_GROUP, APP_GROUP, BUILT_IN), created, membershipUpdated }
*/

namespace models\node;

class NodeAppOktaGroups extends NodeAppOkta {
    public static $name = 'okta-groups';
    public static $label = 'Okta Groups';
    public static $icon = 'bi-people';
    public static $defaultOperation = 'addMembers';
    public static $defaultVariable = 'group';

    const OPERATIONS = [
	'list' => 'List groups',
	'get' => 'Get a group',
	'create' => 'Create a group',
	'delete' => 'Delete a group',
	'members' => 'List the members',
	'addMembers' => 'Add members',
	'removeMember' => 'Remove a member',
    ];
    const WRITES = [ 'create', 'delete', 'addMembers', 'removeMember' ];
    const LISTS = [ 'list', 'members' ];
    const BY_GROUP = [ 'get', 'delete', 'members', 'addMembers', 'removeMember' ];

    protected static function operationFields() {
	return [
	    self::groupField(self::BY_GROUP),
	    self::field('q', 'Search', [ 'list' ], [ 'help' => 'Optional: the start of a name', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('search', 'Expression', [ 'list' ], [ 'help' => 'Optional: Okta\'s search, e.g. type eq "OKTA_GROUP"', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('name', 'Name', [ 'create' ], [ 'required' => true, 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('description', 'Description', [ 'create' ], [ 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('users', 'People', [ 'addMembers' ], [ 'required' => true, 'help' => 'Logins or IDs, separated by commas, or a list', 'ui' => [ 'expression' => true ] ]),
	    self::userField([ 'removeMember' ]),
	];
    }

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	if ($operation === 'list')
	    return $this->listOf('/groups', array_filter([ 'q' => $text('search') === '' ? $text('q') : null, 'search' => $text('search') ]), 'group', fn($g) => self::groupOf($g));
	if ($operation === 'create') {
	    $g = self::groupOf($this->okta->request('POST', '/groups', [], [ 'profile' => array_filter([ 'name' => mb_substr($text('name'), 0, 255), 'description' => $text('description') ], fn($v) => $v !== '') ]));
	    return [ $g, "{$g['name']} created" ];
	}
	$gid = $this->groupId($value('group'));
	switch($operation) {
	    case 'get':
		$g = self::groupOf($this->okta->request('GET', "/groups/{$gid}"));
		return [ $g, "{$g['name']} ({$g['type']})" ];
	    case 'delete':
		$g = self::groupOf($this->okta->request('GET', "/groups/{$gid}"));
		if ($g['type'] !== 'OKTA_GROUP')
		    throw new \RuntimeException("{$g['name']} is a {$g['type']}, not an Okta group: it is managed where it comes from");
		$this->okta->request('DELETE', "/groups/{$gid}");
		return [ [ 'id' => $gid, 'name' => $g['name'], 'deleted' => true ], "{$g['name']} deleted" ];
	    case 'members':
		return $this->listOf("/groups/{$gid}/users", [], 'member', fn($u) => self::userOf($u));
	    case 'addMembers':
		$added = [];
		foreach(NodeAppZendesk::names($value('users'), [ 'id', 'login' ]) as $u) {
		    $uid = $this->userId($u, 'People');
		    $this->okta->request('PUT', "/groups/{$gid}/users/{$uid}");
		    $added[] = $uid;
		}
		if (!$added)
		    throw new \RuntimeException('People: none given');
		return [ [ 'id' => $gid, 'added' => $added ], \util\Format::plural(count($added), 'person', 'people') . ' added' ];
	    case 'removeMember':
		$uid = $this->userId($value('user'));
		$this->okta->request('DELETE', "/groups/{$gid}/users/{$uid}");
		return [ [ 'id' => $gid, 'removed' => $uid ], "{$uid} removed" ];
	}
	throw new \RuntimeException("unknown operation {$operation}");
    }
}
