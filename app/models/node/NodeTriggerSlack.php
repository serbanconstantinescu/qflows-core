<?php

/*
    CLD: 2026-11-25T14:00:00+02:00: Slack message - a conversation with an agent in Slack: a message to the credential's app starts a run (variables message, conversation, user,
    slack: { channel, ts, thread_ts, kind, user }); Reply to chat answers there as the app (util\agent\Channels). What starts a run:
	- a direct message to the app (one conversation per direct-message channel; a thread in it: its own conversation)
	- a message in a channel that mentions the app (@app): it starts a conversation in that message's thread, answered in the thread; the thread's next messages
	  continue it, with or without a mention
    Not read: the app's own messages, other bots', edits, joins and other events of the channel.
    How messages are noticed (setting Receive):
	events  Slack posts each message to the step's address /slack/<token> (SlackController; the app's Event Subscriptions > Request URL; bot events app_mention,
	        message.im, message.channels, message.groups), signed with the app's signing secret (the credential's): at once. Publish first: Slack checks the address
	poll    the step checks on its schedule (models\wf\WfSchedule; util\Scheduler runs runScheduled; models\TriggerState: how far it has read): the app's direct messages
	        (its newest MAX_DMS) and the channels named (mentions, and the threads of their conversations). The first check only notes where it starts.
	        For an installation Slack cannot reach; Slack limits how often its history is read (tier 3, about 50 a minute)
*/

namespace models\node;

class NodeTriggerSlack extends \models\wf\WfNode {
    public static $type = 'trigger';
    public static $name = 'slack';
    public static $label = 'Slack message';
    public static $icon = 'bi-slack';
    public static $inputs = 0;
    public static $outputs = 1;
    public static $scheduled = true;

    const RECEIVE = [ 'events' => 'Slack sends each message (Events API)', 'poll' => 'Check on a schedule (polling)' ];
    const MAX_CHANNELS = 20;
    const MAX_DMS = 20;
    const MAX_MESSAGES = 100;
    //threads of the trigger's conversations read per check (polling), the newest first, of the last day
    const MAX_THREADS = 20;
    const THREAD_DAYS = 1;
    //the runs started per check; the message ts remembered per channel; the events remembered (a retry, a message that is also a mention)
    const MAX_RUNS = 20;
    const MAX_EVENTS = 300;
    const OVERLAP = 60;
    //the message subtypes read (the others are edits, joins, topic changes, …)
    const SUBTYPES = [ '', 'file_share', 'thread_broadcast' ];

    //its address's token is indexed (models\TriggerToken), with no data of its own
    public static function tokenData(array $node) {
	return [];
    }

    public static function initConfig() {
	$token = \models\TriggerToken::newToken('hex');
	return [ 'token' => $token, 'url' => \V::app()->createAbsoluteUrl('/slack/' . $token) ];
    }

    public static function configSchema() {
	$choices = [];
	if (\V::app()->config('app.mode') == 'web' && !\V::app()->user->isGuest && !empty(\V::app()->user->crtProject))
	    foreach(\models\Credential::choicesFor('slack', \V::app()->config('rt.account')->_id ?? null, \V::app()->user->crtProject) as $c)
		$choices[$c['value']] = $c['label'];
	$polling = [ 'key' => 'receive', 'operator' => 'eq', 'value' => 'poll' ];
	$schedule = array_map(function($f) {
	    $f['ui'] = ($f['ui'] ?? []) + [ 'hideIf' => [ 'key' => 'receive', 'operator' => 'eq', 'value' => 'events' ] ];
	    return $f;
	}, \models\wf\WfSchedule::schema([ 'mode' => 'interval', 'every' => 1, 'every_unit' => 'minutes' ]));
	return array_merge([
	    [
		'key' => 'credential',
		'type' => 'select',
		'label' => 'Slack credential',
		'choices' => $choices,
		'help' => 'The agent reads and answers as this app: use an app of its own',
		'required' => true,
		'ui' => [ 'width' => 6 ],
	    ],
	    [
		'key' => 'receive',
		'type' => 'select',
		'label' => 'Receive',
		'required' => true,
		'default' => 'events',
		'choices' => self::RECEIVE,
		'ui' => [ 'width' => 6 ],
	    ],
	    [
		'key' => 'url',
		'type' => 'text',
		'label' => 'Request URL',
		'perm:update' => 'nobody',
		'help' => 'In the Slack app: Event Subscriptions > Request URL, once the workflow is published (Slack checks it). Keep it private',
		'ui' => [ 'placeholder' => 'Generating…', 'showIf' => [ 'key' => 'receive', 'operator' => 'eq', 'value' => 'events' ] ],
	    ],
	    [
		'key' => 'channels',
		'type' => 'text',
		'label' => 'Channels',
		'default' => '',
		'help' => 'Where mentions of the app are read (ids or #names, separated by commas; at most ' . self::MAX_CHANNELS . '); direct messages always',
		'ui' => [ 'showIf' => $polling ],
	    ],
	], $schedule);
    }

    public static function matches(array $cfg, \DateTimeInterface $t) {
	return ($cfg['receive'] ?? 'events') === 'poll' && \models\wf\WfSchedule::matches($cfg, $t);
    }

    public function validates($options = []) {
	$valid = parent::validates($options);
	$cfg = is_array($this->config) ? $this->config : [];
	if (($cfg['receive'] ?? 'events') === 'poll') {
	    $error = \models\wf\WfSchedule::validate($cfg);
	    if ($error !== null) {
		$this->errors('config', $error);
		$valid = false;
	    }
	    if (count(self::channelNames($cfg)) > self::MAX_CHANNELS) {
		$this->errors('config', 'Slack message: at most ' . self::MAX_CHANNELS . ' channels');
		$valid = false;
	    }
	}
	$cred = (string)$this->cfg('credential', '');
	$found = $cred !== '' && $this->workflow ? \models\Credential::findFor($this->workflow, $cred, 'slack') : null;
	if ($cred !== '' && !$found) {
	    $this->errors('config', 'Slack message: choose a Slack credential of this project');
	    $valid = false;
	} elseif ($found && ($cfg['receive'] ?? 'events') === 'events' && trim((string)$found->signingSecret) === '') {
	    $this->errors('config', 'Slack message: the credential has no signing secret, which receiving events needs (or choose polling)');
	    $valid = false;
	}
	return \models\TriggerToken::checkToken($this, 'hex') && $valid;
    }

    public static function outputVariables($config) {
	return NodeTriggerChat::conversationVariables() + [ 'slack' => [ 'type' => 'object', 'info' => 'where it was written', 'fields' => [ 'channel' => [ 'type' => 'string' ],
	    'ts' => [ 'type' => 'string', 'info' => 'the message (a reaction, a link)' ], 'thread_ts' => [ 'type' => 'string', 'info' => 'the thread answered in (empty: a direct message)' ],
	    'kind' => [ 'type' => 'string', 'info' => 'dm, mention or thread' ], 'user' => [ 'type' => 'string', 'info' => 'the person\'s Slack id' ], 'files' => [ 'type' => 'array', 'info' => 'the files sent: [ { id, name } ] (Slack Files Download)' ] ] ] ];
    }

    //the Channels setting as names / ids
    private static function channelNames(array $cfg) {
	return array_values(array_filter(array_map('trim', preg_split('/[\s,]+/', (string)($cfg['channels'] ?? ''))), fn($v) => $v !== ''));
    }

    // ------------------------------------------------------------------------------------
    //a message

    //the conversation's thread key and where the answer goes: [ key, thread_ts ('' : in the channel), kind ]; null: not a message for the app ($me: its user id; $hasConv($key): a
    //conversation of the trigger has that key)
    public static function placeOf(array $m, $channel, $me, \Closure $hasConv) {
	$text = (string)($m['text'] ?? '');
	$thread = (string)($m['thread_ts'] ?? '');
	$ts = (string)($m['ts'] ?? '');
	if (str_starts_with((string)$channel, 'D') || ($m['channel_type'] ?? '') === 'im')
	    return $thread !== '' && $thread !== $ts ? [ "{$channel}:{$thread}", $thread, 'dm' ] : [ (string)$channel, '', 'dm' ];
	if ($thread !== '' && $thread !== $ts && $hasConv("{$channel}:{$thread}"))
	    return [ "{$channel}:{$thread}", $thread, 'thread' ];
	if ($me !== '' && str_contains($text, "<@{$me}>"))
	    return [ "{$channel}:" . ($thread !== '' ? $thread : $ts), $thread !== '' ? $thread : $ts, 'mention' ];
	return null;
    }

    //whether $m is a person's message (not the app's, not a bot's, not an edit or a join)
    public static function isPersons(array $m, $me) {
	return in_array((string)($m['subtype'] ?? ''), self::SUBTYPES, true) && empty($m['bot_id']) && empty($m['bot_profile']) && (string)($m['user'] ?? '') !== '' && (string)$m['user'] !== (string)$me;
    }

    //a run of Slack message $m of $channel for the trigger step $node of workflow $wf (the published version) with credential $cred, whose app is user $me; $start: what is done with it
    //(default: queued). Null: not for the app, no text
    public static function startMessage($wf, array $node, $cred, $me, array $m, $channel, ?callable $start = null) {
	if (!self::isPersons($m, $me))
	    return null;
	$hasConv = fn($key) => \V::app()->om->count('AgentConversation', [ 'aid' => $wf->aid, 'wf' => $wf->_id, 'thread' => $key ]) > 0;
	$place = self::placeOf($m, $channel, $me, $hasConv);
	if (!$place)
	    return null;
	[ $key, $thread, $kind ] = $place;
	$uid = (string)$m['user'];
	$person = [ 'id' => $uid, 'name' => $uid, 'email' => '' ];
	try {
	    $u = NodeAppSlackUsers::userOf($cred->client(5)->call('users.info', [ 'user' => $uid ])['user'] ?? []);
	    $person = [ 'id' => $uid, 'name' => $u['name'] !== '' ? $u['name'] : $uid, 'email' => $u['email'] ];
	} catch (\RuntimeException $e) {
	    //the name stays the id: the message is not lost for it
	}
	$text = trim(\util\Slack::plain(trim(str_replace("<@{$me}>", '', (string)($m['text'] ?? '')))));
	$files = array_values(array_map(fn($f) => [ 'id' => (string)($f['id'] ?? ''), 'name' => (string)($f['name'] ?? '') ], array_filter((array)($m['files'] ?? []), 'is_array')));
	if ($text === '' && !$files)
	    return null;
	if ($text === '')
	    $text = '(files: ' . implode(', ', array_column($files, 'name')) . ')';
	$conv = \V::app()->om->findOne('AgentConversation', [ 'aid' => $wf->aid, 'wf' => $wf->_id, 'thread' => $key ], [ 'arrays' => true ]);
	//a new conversation made here, through save() (the defaults and last_at: AgentConversation)
	if (!$conv) {
	    $new = \V::app()->om->create('AgentConversation', [ 'aid' => $wf->aid, 'project' => $wf->project, 'wf' => $wf->_id, 'node' => (string)$node['wfid'], 'channel' => 'slack',
		'user_name' => $person['name'], 'user_email' => $person['email'], 'who' => 's:' . $uid, 'thread' => $key, 'credential' => (string)($node['config']['credential'] ?? '') ]);
	    if (!$new->save())
		throw new \ErrorException('the conversation was not saved');
	    $conv = $new->data();
	}
	$exe = \models\agent\AgentConversation::startTurn($wf, $node, $conv, $text, [ 'wait' => false, 'trigger' => 'slack', 'user' => $person, 'input' => [ 'who' => 's:' . $uid ],
	    'vars' => [ 'slack' => [ 'channel' => (string)$channel, 'ts' => (string)($m['ts'] ?? ''), 'thread_ts' => $thread, 'kind' => $kind, 'user' => $uid, 'files' => $files ] ] ]);
	if ($exe)
	    ($start ?? fn($e) => $e->queue())($exe);
	return $exe;
    }

    //whether event key $key (channel:ts) was already taken by the step (Slack's retries; a mention is also a channel message); noted when not
    public static function seenEvent($wf, $nodeId, $key) {
	try {
	    return \V::app()->om->updateOne('TriggerState', [ 'wf' => $wf->_id, 'node' => (string)$nodeId, 'aid' => $wf->aid, 'data.events' => [ '$ne' => (string)$key ] ],
		[ '$push' => [ 'data.events' => [ '$each' => [ (string)$key ], '$slice' => -self::MAX_EVENTS ] ], '$setOnInsert' => [ 'project' => $wf->project, 'locked_until' => null,
		'created_at' => new \MongoDB\BSON\UTCDateTime() ] ], [ 'upsert' => true ]) === 0;
	} catch (\MongoDB\Driver\Exception\ServerException $e) {
	    //the upsert of a step whose state holds the key: it is there
	    if ($e->getCode() == 11000)
		return true;
	    throw $e;
	}
    }

    // ------------------------------------------------------------------------------------
    //polling

    public static function runScheduled($wf, array $node, $at) {
	if (($node['config']['receive'] ?? 'events') !== 'poll')
	    return [];
	$state = \models\TriggerState::acquire($wf, $node['wfid']);
	if (!$state) {
	    \V::app()->log->info("Slack message: workflow {$wf->_id}, step {$node['wfid']}: the previous check is still running, skipped");
	    return;
	}
	try {
	    [ $data, $started ] = self::check($wf, $node, $state->dataArray());
	} catch (\Throwable $e) {
	    $state->release();
	    throw $e;
	}
	$state->finish($data);
	return $started;
    }

    //one check: [ the new state data { me, since (Unix seconds), seen: { channel: [ ts ] } }, the executions started ]. $start: what is done with a run (default: queued; the check suite runs it)
    public static function check($wf, array $node, array $data, ?callable $start = null) {
	$cfg = (array)($node['config'] ?? []);
	$first = empty($data['since']);
	if (!$first && array_filter($wf->validateFlow(), fn($i) => $i['level'] == 'error'))
	    throw new \Exception("workflow `{$wf->name}` has errors and cannot run");
	$cred = \models\Credential::findFor($wf, (string)($cfg['credential'] ?? ''), 'slack');
	if (!$cred)
	    throw new \Exception('Slack message: the credential is no longer available');
	$slack = $cred->client(50);
	$me = (string)($data['me'] ?? '');
	if ($me === '')
	    $me = (string)($slack->call('auth.test')['user_id'] ?? '');
	if ($first)
	    return [ [ 'me' => $me, 'since' => (string)time(), 'seen' => [] ], [] ];
	$since = (string)$data['since'];
	$seen = (array)($data['seen'] ?? []);

	//where to read: the channels named (ids, names looked up), the app's newest direct messages, the threads of the trigger's recent conversations in those channels
	$ids = [];
	$names = [];
	foreach(array_slice(self::channelNames($cfg), 0, self::MAX_CHANNELS) as $c) {
	    $c = \util\Slack::channelId($c);
	    if (\util\Slack::isChannelId($c))
		$ids[] = $c;
	    else
		$names[] = strtolower(ltrim($c, '#'));
	}
	if ($names) {
	    [ $all ] = $slack->pages('conversations.list', [ 'types' => 'public_channel,private_channel', 'exclude_archived' => true ], 'channels', NodeAppSlack::MAX_ITEMS);
	    foreach($all as $c)
		if (in_array(strtolower((string)($c['name'] ?? '')), $names, true))
		    $ids[] = (string)$c['id'];
	}
	[ $dms ] = $slack->pages('conversations.list', [ 'types' => 'im' ], 'channels', self::MAX_DMS);
	foreach($dms as $d)
	    $ids[] = (string)($d['id'] ?? '');
	$ids = array_values(array_unique(array_filter($ids)));
	$threads = [];
	$recent = new \MongoDB\BSON\UTCDateTime((time() - self::THREAD_DAYS * 86400) * 1000);
	foreach(\V::app()->om->find('AgentConversation', [ 'aid' => $wf->aid, 'wf' => $wf->_id, 'channel' => 'slack', 'last_at' => [ '$gte' => $recent ] ], [ 'sort' => [ 'last_at' => -1 ], 'limit' => self::MAX_THREADS, 'arrays' => true ]) as $c) {
	    $parts = explode(':', (string)($c['thread'] ?? ''), 2);
	    if (count($parts) === 2 && in_array($parts[0], $ids, true))
		$threads[] = $parts;
	}

	$started = [];
	$later = false;
	$read = function($channel, array $list) use (&$seen, &$started, &$later, $wf, $node, $cred, $me, $since, $start) {
	    $known = array_map('strval', (array)($seen[$channel] ?? []));
	    usort($list, fn($a, $b) => strcmp((string)($a['ts'] ?? ''), (string)($b['ts'] ?? '')));
	    foreach($list as $m) {
		$ts = (string)($m['ts'] ?? '');
		if ($ts === '' || in_array($ts, $known, true) || (float)$ts < (float)$since)
		    continue;
		//over the limit: the next check takes it (not noted as seen; `since` stays)
		if (count($started) >= self::MAX_RUNS) {
		    $later = true;
		    continue;
		}
		$known[] = $ts;
		$exe = self::startMessage($wf, $node, $cred, $me, $m, $channel, $start);
		if ($exe)
		    $started[] = $exe;
	    }
	    $seen[$channel] = array_slice($known, -(self::MAX_MESSAGES * 2));
	};
	foreach($ids as $channel)
	    $read($channel, (array)($slack->call('conversations.history', [ 'channel' => $channel, 'oldest' => $since, 'limit' => self::MAX_MESSAGES ])['messages'] ?? []));
	foreach($threads as [ $channel, $thread ])
	    $read($channel, array_filter((array)($slack->call('conversations.replies', [ 'channel' => $channel, 'ts' => $thread, 'oldest' => $since, 'limit' => self::MAX_MESSAGES ])['messages'] ?? []),
		fn($m) => (string)($m['ts'] ?? '') !== $thread));
	//a minute back: Slack's clock is not this server's; `seen` keeps a message from starting two runs
	$next = (string)(time() - self::OVERLAP);
	return [ [ 'me' => $me, 'since' => $later || (float)$next < (float)$since ? $since : $next, 'seen' => $seen ], $started ];
    }
}
