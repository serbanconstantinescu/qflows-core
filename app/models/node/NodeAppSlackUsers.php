<?php

/*
    CLD: 2026-11-25T14:00:00+02:00: Slack Users - the workspace's people as the credential's app (NodeAppSlack; emails need the scope users:read.email). Operations:
	list       the members (bots and deactivated people optionally left out)
	info       a person, by id or email: { id, name, realName, email, title, phone, timezone, admin, bot, deleted, guest }
	byEmail    a person by email (users.lookupByEmail)
	presence   whether a person is active or away now
	me         the app's own bot user and workspace (auth.test)
*/

namespace models\node;

class NodeAppSlackUsers extends NodeAppSlack {
    public static $name = 'slack-users';
    public static $label = 'Slack Users';
    public static $icon = 'bi-people';
    public static $defaultOperation = 'byEmail';
    public static $defaultVariable = 'person';

    const OPERATIONS = [
	'byEmail' => 'Find a person by email',
	'info' => 'Get a person',
	'list' => 'List the members',
	'presence' => 'Get a person\'s presence',
	'me' => 'The app\'s bot user',
    ];
    const WRITES = [];
    const LISTS = [ 'list' ];

    protected static function operationFields() {
	return [
	    self::field('email', 'Email', [ 'byEmail' ], [ 'required' => true, 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('user', 'Person', [ 'info', 'presence' ], [ 'required' => true, 'help' => 'An id (U…) or an email', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('people', 'Only people', [ 'list' ], [ 'type' => 'switch', 'default' => true, 'help' => 'Without bots and deactivated members', 'ui' => [ 'width' => 6 ] ]),
	];
    }

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	switch($operation) {
	    case 'byEmail':
	    case 'info':
		$id = $this->userId($operation === 'byEmail' ? $value('email') : $value('user'), $operation === 'byEmail' ? 'Email' : 'Person');
		$u = self::userOf($this->slack->call('users.info', [ 'user' => $id ])['user'] ?? []);
		return [ $u, "{$u['name']} ({$u['id']})" ];
	    case 'list':
		$people = (bool)$this->cfg('people', true);
		[ $all, $truncated ] = $this->slack->pages('users.list', [], 'members', $this->maxItems());
		$items = array_values(array_map(fn($u) => self::userOf($u), array_filter($all, fn($u) => !$people || (empty($u['is_bot']) && empty($u['deleted']) && ($u['id'] ?? '') !== 'USLACKBOT'))));
		return [ [ 'items' => $items, 'count' => count($items), 'truncated' => $truncated ], \util\Format::plural(count($items), 'member') . ($truncated ? ' (more left out)' : '') ];
	    case 'presence':
		$id = $this->userId($value('user'), 'Person');
		$p = (string)($this->slack->call('users.getPresence', [ 'user' => $id ])['presence'] ?? '');
		return [ [ 'id' => $id, 'presence' => $p, 'active' => $p === 'active' ], "{$id}: {$p}" ];
	    case 'me':
		$d = $this->slack->call('auth.test');
		return [ [ 'id' => (string)($d['user_id'] ?? ''), 'name' => (string)($d['user'] ?? ''), 'botId' => (string)($d['bot_id'] ?? ''), 'team' => (string)($d['team'] ?? ''),
		    'teamId' => (string)($d['team_id'] ?? ''), 'url' => (string)($d['url'] ?? '') ], (string)($d['user'] ?? '') . ' in ' . (string)($d['team'] ?? '') ];
	}
	throw new \RuntimeException("unknown operation {$operation}");
    }

    //a person of Slack's answers
    public static function userOf($u) {
	$u = is_array($u) ? $u : [];
	$p = (array)($u['profile'] ?? []);
	return [
	    'id' => (string)($u['id'] ?? ''),
	    'name' => (string)($p['display_name'] ?? '') !== '' ? (string)$p['display_name'] : (string)($u['real_name'] ?? $u['name'] ?? ''),
	    'realName' => (string)($u['real_name'] ?? $p['real_name'] ?? ''),
	    'email' => (string)($p['email'] ?? ''),
	    'title' => (string)($p['title'] ?? ''),
	    'phone' => (string)($p['phone'] ?? ''),
	    'timezone' => (string)($u['tz'] ?? ''),
	    'admin' => (bool)($u['is_admin'] ?? false),
	    'bot' => (bool)($u['is_bot'] ?? false),
	    'deleted' => (bool)($u['deleted'] ?? false),
	    'guest' => !empty($u['is_restricted']) || !empty($u['is_ultra_restricted']),
	];
    }
}
