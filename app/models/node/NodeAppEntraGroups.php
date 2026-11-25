<?php

/*
    CLD: 2026-11-26T01:00:00+02:00: Entra ID Groups - the tenant's groups (NodeAppEntra; Graph application permissions: Group.Read.All / GroupMember.ReadWrite.All, Group.ReadWrite.All
    to create and delete). Operations:
	list           groups: a search of names, or an OData filter; security, Microsoft 365 or all
	get            a group (by ID or display name)
	create         a security group or a Microsoft 365 group (its mail nickname from the name), a description, owners and members
	delete         a group (Microsoft 365 groups: restorable for 30 days)
	members        its members (users, and nested groups and devices: kind), transitive optionally
	addMembers     users (UPNs or IDs, a list) added; those already in it are fine
	removeMember   a user taken out
	owners, addOwner
    A group: { id, displayName, description, mail, kind (security, microsoft365, distribution), dynamic, rule, synced, visibility, created }
*/

namespace models\node;

class NodeAppEntraGroups extends NodeAppEntra {
    public static $name = 'entra-groups';
    public static $label = 'Entra ID Groups';
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
	'owners' => 'List the owners',
	'addOwner' => 'Add an owner',
    ];
    const WRITES = [ 'create', 'delete', 'addMembers', 'removeMember', 'addOwner' ];
    const LISTS = [ 'list', 'members', 'owners' ];
    const KINDS = [ '' => 'All', 'security' => 'Security', 'microsoft365' => 'Microsoft 365' ];
    const NEW_KINDS = [ 'security' => 'Security', 'microsoft365' => 'Microsoft 365' ];
    const BY_GROUP = [ 'get', 'delete', 'members', 'addMembers', 'removeMember', 'owners', 'addOwner' ];

    protected static function operationFields() {
	return [
	    self::groupField(self::BY_GROUP),
	    self::field('search', 'Search', [ 'list' ], [ 'help' => 'Optional: words of the name', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('filter', 'Filter', [ 'list' ], [ 'help' => 'Optional: an OData filter, e.g. startswith(displayName, \'Team\')', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('kind', 'Kind', [ 'list' ], [ 'type' => 'select', 'default' => '', 'choices' => self::KINDS, 'ui' => [ 'width' => 6 ] ]),
	    self::field('displayName', 'Name', [ 'create' ], [ 'required' => true, 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('newKind', 'Kind', [ 'create' ], [ 'type' => 'select', 'required' => true, 'default' => 'security', 'choices' => self::NEW_KINDS, 'ui' => [ 'width' => 6 ] ]),
	    self::field('description', 'Description', [ 'create' ], [ 'ui' => [ 'expression' => 'text' ] ]),
	    self::field('users', 'Members', [ 'create', 'addMembers' ], [ 'help' => 'User principal names or IDs, separated by commas, or a list (create: optional)', 'ui' => [ 'expression' => true ] ]),
	    self::field('ownersOf', 'Owners', [ 'create' ], [ 'help' => 'Optional: user principal names or IDs', 'ui' => [ 'expression' => true ] ]),
	    self::userField([ 'removeMember', 'addOwner' ]),
	    self::field('transitive', 'Nested members too', [ 'members' ], [ 'type' => 'switch', 'default' => false, 'ui' => [ 'width' => 6 ] ]),
	];
    }

    public static function outputVariables($config) {
	$name = is_array($config) ? (string)($config['variable'] ?? static::$defaultVariable) : static::$defaultVariable;
	$op = is_array($config) ? (string)($config['operation'] ?? static::$defaultOperation) : static::$defaultOperation;
	if (!\util\Validator::isIdentifier($name) || in_array($op, self::LISTS, true))
	    return parent::outputVariables($config);
	if (in_array($op, [ 'addMembers', 'removeMember', 'addOwner', 'delete' ], true))
	    return [ $name => [ 'type' => 'object', 'info' => '{ id, added, already, removed, … }' ] ];
	return [ $name => [ 'type' => 'object', 'info' => 'the group: { id, displayName, mail, kind, dynamic, … }', 'fields' => [ 'id' => [ 'type' => 'string' ], 'displayName' => [ 'type' => 'string' ],
	    'mail' => [ 'type' => 'string' ], 'kind' => [ 'type' => 'string', 'info' => 'security, microsoft365 or distribution' ] ] ] ];
    }

    //a member of Graph's answers: a user, a group, a device
    private static function memberOf($m) {
	$type = substr((string)($m['@odata.type'] ?? '#microsoft.graph.user'), 17);
	return [ 'id' => (string)($m['id'] ?? ''), 'kind' => $type, 'displayName' => (string)($m['displayName'] ?? ''), 'upn' => (string)($m['userPrincipalName'] ?? ''), 'mail' => (string)($m['mail'] ?? ''),
	    'enabled' => isset($m['accountEnabled']) ? (bool)$m['accountEnabled'] : null ];
    }

    //users' object IDs of a setting (UPNs looked up)
    private function userIds($value, $label) {
	$ids = [];
	foreach(self::userRefs($value, $label) as $ref)
	    $ids[] = \util\Entra::isGuid($ref) ? $ref : (string)($this->graph->request('GET', "/users/{$ref}", [ '$select' => 'id' ])['id'] ?? '');
	return $ids;
    }

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	if ($operation === 'list') {
	    $filters = [];
	    if ($text('filter') !== '')
		$filters[] = '(' . $text('filter') . ')';
	    if ((string)$this->cfg('kind', '') === 'microsoft365')
		$filters[] = "groupTypes/any(t:t eq 'Unified')";
	    elseif ((string)$this->cfg('kind', '') === 'security')
		$filters[] = 'securityEnabled eq true';
	    $q = [ '$select' => self::GROUP_FIELDS, '$filter' => $filters ? implode(' and ', $filters) : null, '$count' => 'true' ];
	    $search = str_replace('"', '', $text('search'));
	    if ($search !== '')
		$q['$search'] = "\"displayName:{$search}\"";
	    return $this->listOf('/groups', $q, 'group', fn($g) => self::groupOf($g), [ 'eventual' => true ]);
	}
	if ($operation === 'create') {
	    $name = $text('displayName');
	    if ($name === '')
		throw new \RuntimeException('Name: empty');
	    $m365 = (string)$this->cfg('newKind', 'security') === 'microsoft365';
	    $body = [ 'displayName' => mb_substr($name, 0, 256), 'mailNickname' => substr(preg_replace('/[^A-Za-z0-9._-]/', '', str_replace(' ', '-', $name)), 0, 64) ?: 'group' . time(),
		'mailEnabled' => $m365, 'securityEnabled' => !$m365, 'groupTypes' => $m365 ? [ 'Unified' ] : [] ];
	    if ($text('description') !== '')
		$body['description'] = $text('description');
	    $given = fn($v) => is_array($v) ? (bool)$v : trim(\util\Expression::toText($v)) !== '';
	    $members = $given($value('users')) ? $this->userIds($value('users'), 'Members') : [];
	    $owners = $given($value('ownersOf')) ? $this->userIds($value('ownersOf'), 'Owners') : [];
	    $bind = fn($ids) => array_map(fn($id) => $this->graph->graph() . "/v1.0/users/{$id}", array_slice($ids, 0, 20));
	    if ($members)
		$body['members@odata.bind'] = $bind($members);
	    if ($owners)
		$body['owners@odata.bind'] = $bind($owners);
	    $g = self::groupOf($this->graph->request('POST', '/groups', [], $body));
	    return [ $g, "{$g['displayName']} created ({$g['kind']})" ];
	}
	$gid = $this->groupId($value('group'));
	$path = "/groups/{$gid}";
	switch($operation) {
	    case 'get':
		$g = self::groupOf($this->graph->request('GET', $path, [ '$select' => self::GROUP_FIELDS ]));
		return [ $g, "{$g['displayName']} ({$g['kind']})" ];
	    case 'delete':
		$this->graph->request('DELETE', $path);
		return [ [ 'id' => $gid, 'deleted' => true ], "group {$gid} deleted" ];
	    case 'members':
	    case 'owners':
		$sub = $operation === 'owners' ? 'owners' : ($this->cfg('transitive', false) ? 'transitiveMembers' : 'members');
		return $this->listOf("{$path}/{$sub}", [ '$select' => 'id,displayName,userPrincipalName,mail,accountEnabled' ], $operation === 'owners' ? 'owner' : 'member', fn($m) => self::memberOf($m));
	    case 'addMembers':
		$ids = $this->userIds($value('users'), 'Members');
		$added = [];
		$already = [];
		foreach($ids as $id) {
		    try {
			$this->graph->request('POST', "{$path}/members/\$ref", [], [ '@odata.id' => $this->graph->graph() . "/v1.0/directoryObjects/{$id}" ]);
			$added[] = $id;
		    } catch (\RuntimeException $e) {
			//"One or more added object references already exist"
			if (!str_contains($e->getMessage(), 'already exist'))
			    throw $e;
			$already[] = $id;
		    }
		}
		return [ [ 'id' => $gid, 'added' => $added, 'already' => $already ], count($added) . ' added' . ($already ? ', ' . count($already) . ' already in it' : '') ];
	    case 'removeMember':
		$uid = $this->userId($value('user'));
		[ $status ] = $this->graph->request('DELETE', "{$path}/members/{$uid}/\$ref", [], null, [ 'full' => true, 'ok' => [ 404 ] ]);
		return [ [ 'id' => $gid, 'removed' => $status !== 404 ? $uid : null ], $status !== 404 ? "{$uid} removed" : "{$uid} was not a member" ];
	    case 'addOwner':
		$uid = $this->userId($value('user'));
		$this->graph->request('POST', "{$path}/owners/\$ref", [], [ '@odata.id' => $this->graph->graph() . "/v1.0/users/{$uid}" ]);
		return [ [ 'id' => $gid, 'owner' => $uid ], "{$uid} is an owner" ];
	}
	throw new \RuntimeException("unknown operation {$operation}");
    }
}
