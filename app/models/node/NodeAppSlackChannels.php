<?php

/*
    CLD: 2026-11-25T14:00:00+02:00: Slack Channels - the workspace's channels as the credential's app (NodeAppSlack). Operations:
	list       the channels the app can see: public ones, the private ones it is in (and, chosen, direct messages); archived ones optionally
	info       a channel: { id, name, private, archived, topic, purpose, members, created, link }
	members    a channel's members (ids, with names and emails optionally)
	create     a public or private channel (its name: lower case, no spaces; Slack's rules), the app in it
	rename, topic, purpose, archive, unarchive
	invite     people into a channel (ids or emails), remove a person
	join       the app joins a public channel (a private one: someone invites it)
*/

namespace models\node;

class NodeAppSlackChannels extends NodeAppSlack {
    public static $name = 'slack-channels';
    public static $label = 'Slack Channels';
    public static $icon = 'bi-hash';
    public static $defaultOperation = 'list';
    public static $defaultVariable = 'channels';

    const OPERATIONS = [
	'list' => 'List channels',
	'info' => 'Get a channel',
	'members' => 'List a channel\'s members',
	'create' => 'Create a channel',
	'rename' => 'Rename a channel',
	'topic' => 'Set the topic',
	'purpose' => 'Set the description',
	'invite' => 'Invite people',
	'remove' => 'Remove a person',
	'join' => 'Join a channel',
	'archive' => 'Archive a channel',
	'unarchive' => 'Unarchive a channel',
    ];
    const WRITES = [ 'create', 'rename', 'topic', 'purpose', 'invite', 'remove', 'join', 'archive', 'unarchive' ];
    const LISTS = [ 'list', 'members' ];
    const KINDS = [ 'public_channel' => 'Public channels', 'private_channel' => 'Private channels', 'im' => 'Direct messages', 'mpim' => 'Group direct messages' ];
    const BY_CHANNEL = [ 'info', 'members', 'rename', 'topic', 'purpose', 'invite', 'remove', 'join', 'archive', 'unarchive' ];

    protected static function operationFields() {
	return [
	    self::channelField(self::BY_CHANNEL),
	    self::field('kinds', 'Kinds', [ 'list' ], [ 'type' => 'select', 'multiple' => true, 'required' => true, 'default' => [ 'public_channel', 'private_channel' ], 'choices' => self::KINDS, 'ui' => [ 'width' => 6 ] ]),
	    self::field('archived', 'With archived ones', [ 'list' ], [ 'type' => 'switch', 'default' => false, 'ui' => [ 'width' => 6 ] ]),
	    self::field('name', 'Name', [ 'create', 'rename' ], [ 'required' => true, 'help' => 'Lower case letters, digits, - and _, at most 80, e.g. incident-4711', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('private', 'Private', [ 'create' ], [ 'type' => 'switch', 'default' => false, 'ui' => [ 'width' => 6 ] ]),
	    self::field('value', 'Text', [ 'topic', 'purpose' ], [ 'help' => 'Empty: cleared', 'ui' => [ 'expression' => 'text' ] ]),
	    self::field('users', 'People', [ 'invite' ], [ 'required' => true, 'help' => 'Ids (U…) or emails, a list or separated by commas, e.g. {{ team.emails }}', 'ui' => [ 'expression' => true ] ]),
	    self::field('user', 'Person', [ 'remove' ], [ 'required' => true, 'help' => 'An id (U…) or an email', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('withNames', 'With names and emails', [ 'members' ], [ 'type' => 'switch', 'default' => false, 'help' => 'One more request per member', 'ui' => [ 'width' => 6 ] ]),
	];
    }

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	if ($operation === 'list') {
	    $kinds = array_values(array_intersect(array_map('strval', (array)$this->cfg('kinds', [])), array_keys(self::KINDS))) ?: [ 'public_channel' ];
	    return $this->listOf('conversations.list', [ 'types' => implode(',', $kinds), 'exclude_archived' => !$this->cfg('archived', false) ], 'channels', 'channel', fn($c) => $this->channelOf($c));
	}
	if ($operation === 'create') {
	    $name = $this->channelName($text('name'));
	    $c = $this->slack->call('conversations.create', [ 'name' => $name, 'is_private' => (bool)$this->cfg('private', false) ])['channel'] ?? [];
	    $c = $this->channelOf($c);
	    return [ $c, "#{$c['name']} created ({$c['id']})" ];
	}
	$channel = $this->channel($value('channel'));
	switch($operation) {
	    case 'info':
		$c = $this->channelOf($this->slack->call('conversations.info', [ 'channel' => $channel, 'include_num_members' => true ])['channel'] ?? []);
		return [ $c, "#{$c['name']}" ];
	    case 'members':
		$withNames = (bool)$this->cfg('withNames', false);
		return $this->listOf('conversations.members', [ 'channel' => $channel ], 'members', 'member', fn($id) => $withNames ? NodeAppSlackUsers::userOf($this->slack->call('users.info', [ 'user' => (string)$id ])['user'] ?? [])
		    : [ 'id' => (string)$id ]);
	    case 'rename':
		$c = $this->channelOf($this->slack->call('conversations.rename', [ 'channel' => $channel, 'name' => $this->channelName($text('name')) ])['channel'] ?? []);
		return [ $c, "renamed #{$c['name']}" ];
	    case 'topic':
	    case 'purpose':
		$t = mb_substr($text('value'), 0, 250);
		$this->slack->call($operation === 'topic' ? 'conversations.setTopic' : 'conversations.setPurpose', [ 'channel' => $channel, $operation => $t ]);
		return [ [ 'id' => $channel, $operation => $t ], ($operation === 'topic' ? 'topic' : 'description') . ($t === '' ? ' cleared' : ' set') ];
	    case 'invite':
		$ids = $this->userIds($value('users'), 'People');
		try {
		    $this->slack->call('conversations.invite', [ 'channel' => $channel, 'users' => implode(',', $ids) ]);
		} catch (\RuntimeException $e) {
		    //everyone already there: nothing to do
		    if (!str_starts_with($e->getMessage(), 'already_in_channel'))
			throw $e;
		}
		return [ [ 'id' => $channel, 'invited' => $ids ], \util\Format::plural(count($ids), 'person', 'people') . ' invited' ];
	    case 'remove':
		$id = $this->userId($value('user'), 'Person');
		$this->slack->call('conversations.kick', [ 'channel' => $channel, 'user' => $id ]);
		return [ [ 'id' => $channel, 'removed' => $id ], "{$id} removed" ];
	    case 'join':
		$c = $this->channelOf($this->slack->call('conversations.join', [ 'channel' => $channel ])['channel'] ?? []);
		return [ $c, "joined #{$c['name']}" ];
	    case 'archive':
	    case 'unarchive':
		$this->slack->call('conversations.' . $operation, [ 'channel' => $channel ]);
		return [ [ 'id' => $channel, 'archived' => $operation === 'archive' ], "{$channel} {$operation}d" ];
	}
	throw new \RuntimeException("unknown operation {$operation}");
    }

    //a channel name as Slack takes it
    private function channelName($name) {
	$name = strtolower(ltrim(trim($name), '#'));
	if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,79}$/u', $name))
	    throw new \RuntimeException('Name: lower case letters, digits, - and _, at most 80 (no spaces): `' . mb_substr($name, 0, 90) . '`');
	return $name;
    }

    //a channel of Slack's answers
    public static function channelOf($c) {
	$c = is_array($c) ? $c : [];
	return [
	    'id' => (string)($c['id'] ?? ''),
	    'name' => (string)($c['name'] ?? $c['user'] ?? ''),
	    'private' => (bool)($c['is_private'] ?? false),
	    'archived' => (bool)($c['is_archived'] ?? false),
	    'member' => (bool)($c['is_member'] ?? false),
	    'kind' => !empty($c['is_im']) ? 'im' : (!empty($c['is_mpim']) ? 'mpim' : (!empty($c['is_private']) ? 'private_channel' : 'public_channel')),
	    'topic' => (string)($c['topic']['value'] ?? ''),
	    'purpose' => (string)($c['purpose']['value'] ?? ''),
	    'members' => isset($c['num_members']) ? (int)$c['num_members'] : null,
	    'created' => isset($c['created']) ? gmdate('Y-m-d\TH:i:s\Z', (int)$c['created']) : '',
	];
    }
}
