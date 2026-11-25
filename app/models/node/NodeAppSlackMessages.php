<?php

/*
    CLD: 2026-11-25T14:00:00+02:00: Slack Messages - messages as the credential's app (NodeAppSlack). Operations:
	send       a message to a channel (the app must be in it; a public one: Channels Join), or to a person (their direct messages with the app): Markdown, Slack mrkdwn or plain
	           text, optional Block Kit blocks (the text then is the notification's), in a thread (a message's ts or link) and also in the channel
	schedule   a message sent later (chat.scheduleMessage: at most 120 days ahead)
	update     the app's own message, new text (and blocks)
	delete     the app's own message
	react      an emoji reaction on a message (its name, e.g. white_check_mark or :eyes:)
	history    the channel's messages, the newest first, optionally since / until a time
	replies    a thread's messages (the first is the thread's start)
	permalink  a message's link
    A message: { ts, channel, thread_ts, user, text, replies, reactions, files, time, link (send, schedule: its id instead) }
*/

namespace models\node;

class NodeAppSlackMessages extends NodeAppSlack {
    public static $name = 'slack-messages';
    public static $label = 'Slack Messages';
    public static $defaultOperation = 'send';
    public static $defaultVariable = 'slack';

    const OPERATIONS = [
	'send' => 'Send a message',
	'schedule' => 'Schedule a message',
	'update' => 'Update a message',
	'delete' => 'Delete a message',
	'react' => 'Add a reaction',
	'history' => 'Read a channel\'s messages',
	'replies' => 'Read a thread',
	'permalink' => 'Get a message\'s link',
    ];
    const WRITES = [ 'send', 'schedule', 'update', 'delete', 'react' ];
    const LISTS = [ 'history', 'replies' ];
    const TEXT_OPS = [ 'send', 'schedule', 'update' ];
    //Slack: a message scheduled at most this far ahead
    const MAX_AHEAD = 120 * 86400;

    protected static function operationFields() {
	return [
	    self::field('to', 'To', [ 'send', 'schedule' ], [ 'required' => true, 'help' => 'A channel (C…, #name, its link) or a person (U…, an email): their direct messages with the app',
		'ui' => [ 'expression' => 'text', 'width' => 6, 'placeholder' => '#general' ] ]),
	    self::field('thread', 'In thread', [ 'send', 'schedule' ], [ 'help' => 'Optional: the ts or link of the thread\'s first message, e.g. {{ slack.ts }}', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::channelField([ 'update', 'delete', 'react', 'history', 'replies', 'permalink' ]),
	    self::field('ts', 'Message', [ 'update', 'delete', 'react', 'replies', 'permalink' ], [ 'required' => true, 'help' => 'Its ts or link, e.g. {{ slack.ts }}', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('at', 'Send at', [ 'schedule' ], [ 'required' => true, 'help' => 'A date and time (ISO 8601, e.g. 2026-12-01T09:00:00+02:00), at most 120 days ahead', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('emoji', 'Emoji', [ 'react' ], [ 'required' => true, 'help' => 'Its name, e.g. white_check_mark or :eyes:', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('since', 'Since', [ 'history' ], [ 'help' => 'Optional: a date and time (ISO 8601) or a ts', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('until', 'Until', [ 'history' ], [ 'help' => 'Optional: a date and time (ISO 8601) or a ts', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('format', 'Message as', self::TEXT_OPS, [ 'type' => 'select', 'required' => true, 'default' => 'markdown', 'choices' => self::FORMATS, 'ui' => [ 'width' => 3, 'newRow' => true ] ]),
	    self::field('broadcast', 'Also in the channel', [ 'send' ], [ 'type' => 'switch', 'default' => false, 'help' => 'A reply in a thread shown in the channel too', 'ui' => [ 'width' => 3 ] ]),
	    self::field('message', 'Message', self::TEXT_OPS, [ 'type' => 'textarea', 'required' => true, 'help' => '{{ ... }} for expressions; with blocks: the notification\'s text',
		'ui' => [ 'rows' => 5, 'expression' => true ] ]),
	    self::field('blocks', 'Blocks', self::TEXT_OPS, [ 'type' => 'textarea', 'help' => 'Optional: Block Kit blocks, a JSON list (app.slack.com/block-kit-builder)',
		'ui' => [ 'rows' => 3, 'expression' => true, 'code' => true, 'advanced' => true ] ]),
	];
    }

    public static function outputVariables($config) {
	$name = is_array($config) ? (string)($config['variable'] ?? static::$defaultVariable) : static::$defaultVariable;
	$op = is_array($config) ? (string)($config['operation'] ?? static::$defaultOperation) : static::$defaultOperation;
	if (!\util\Validator::isIdentifier($name) || in_array($op, self::LISTS, true))
	    return parent::outputVariables($config);
	return [ $name => match($op) {
	    'delete' => [ 'type' => 'object', 'info' => '{ channel, ts, deleted }' ],
	    'react' => [ 'type' => 'object', 'info' => '{ channel, ts, emoji }' ],
	    'permalink' => [ 'type' => 'object', 'info' => '{ channel, ts, link }', 'fields' => [ 'link' => [ 'type' => 'string' ] ] ],
	    'schedule' => [ 'type' => 'object', 'info' => '{ id, channel, at }', 'fields' => [ 'id' => [ 'type' => 'string', 'info' => 'the scheduled message' ], 'channel' => [ 'type' => 'string' ], 'at' => [ 'type' => 'string' ] ] ],
	    default => self::messageType(),
	} ];
    }

    public static function messageType() {
	return [ 'type' => 'object', 'info' => 'the message: { ts, channel, thread_ts, user, text, link, … }', 'fields' => [ 'ts' => [ 'type' => 'string', 'info' => 'its id in the channel (a reply, an update)' ],
	    'channel' => [ 'type' => 'string' ], 'thread_ts' => [ 'type' => 'string' ], 'user' => [ 'type' => 'string' ], 'text' => [ 'type' => 'string' ], 'link' => [ 'type' => 'string', 'info' => 'opens it in Slack' ],
	    'time' => [ 'type' => 'string' ] ] ];
    }

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	return match($operation) {
	    'send', 'schedule' => $this->opSend($operation, $text, $value),
	    'update' => $this->opUpdate($text, $value),
	    'delete' => $this->opDelete($text, $value),
	    'react' => $this->opReact($text, $value),
	    'history' => $this->opHistory($text, $value),
	    'replies' => $this->opReplies($text, $value),
	    'permalink' => $this->opPermalink($text, $value),
	};
    }

    //the text, mrkdwn and blocks of a message being sent or changed
    private function content() {
	[ $msg, $mrkdwn ] = $this->messageText('message');
	$blocks = $this->jsonOf('blocks', 'Blocks', true);
	if ($msg === '' && !$blocks)
	    throw new \RuntimeException('the message is empty');
	return [ 'text' => $msg, 'mrkdwn' => $mrkdwn ] + ($blocks ? [ 'blocks' => $blocks ] : []);
    }

    //a time setting (ISO 8601, or a ts) as Unix seconds; null when empty
    private static function timeOf($v, $label) {
	$v = trim(\util\Expression::toText($v));
	if ($v === '')
	    return null;
	if (preg_match('/^\d{9,11}(\.\d{1,6})?$/', $v))
	    return $v;
	$t = strtotime($v);
	if ($t === false)
	    throw new \RuntimeException("{$label}: not a date and time: `" . mb_substr($v, 0, 60) . '`');
	return (string)$t;
    }

    private function opSend($operation, \Closure $text, \Closure $value) {
	$channel = $this->destination($value('to'), 'To');
	$args = [ 'channel' => $channel ] + $this->content();
	$thread = $text('thread');
	if ($thread !== '')
	    $args['thread_ts'] = \util\Slack::ts($thread, 'In thread');
	if ($operation === 'schedule') {
	    $at = (int)self::timeOf($value('at'), 'Send at');
	    if ($at <= time() || $at > time() + self::MAX_AHEAD)
		throw new \RuntimeException('Send at: in the future, at most 120 days ahead');
	    $d = $this->slack->call('chat.scheduleMessage', $args + [ 'post_at' => $at ]);
	    return [ [ 'id' => (string)($d['scheduled_message_id'] ?? ''), 'channel' => (string)($d['channel'] ?? $channel), 'at' => gmdate('Y-m-d\TH:i:s\Z', $at) ], 'scheduled for ' . gmdate('Y-m-d H:i', $at) . ' UTC' ];
	}
	if (isset($args['thread_ts']) && $this->cfg('broadcast', false))
	    $args['reply_broadcast'] = true;
	$d = $this->slack->call('chat.postMessage', $args);
	$m = self::messageOf((array)($d['message'] ?? []) + [ 'ts' => $d['ts'] ?? '' ], (string)($d['channel'] ?? $channel));
	$m['link'] = $this->link($m['channel'], $m['ts']);
	return [ $m, 'sent to ' . $m['channel'] . (isset($args['thread_ts']) ? ' (in a thread)' : '') ];
    }

    //a message's link; '' when Slack does not give it
    private function link($channel, $ts) {
	try {
	    return (string)($this->slack->call('chat.getPermalink', [ 'channel' => $channel, 'message_ts' => $ts ])['permalink'] ?? '');
	} catch (\RuntimeException $e) {
	    return '';
	}
    }

    private function opUpdate(\Closure $text, \Closure $value) {
	$channel = $this->channel($value('channel'));
	$d = $this->slack->call('chat.update', [ 'channel' => $channel, 'ts' => \util\Slack::ts($text('ts')) ] + $this->content());
	$m = self::messageOf((array)($d['message'] ?? []) + [ 'ts' => $d['ts'] ?? '' ], (string)($d['channel'] ?? $channel));
	$m['link'] = $this->link($m['channel'], $m['ts']);
	return [ $m, "message {$m['ts']} updated" ];
    }

    private function opDelete(\Closure $text, \Closure $value) {
	$channel = $this->channel($value('channel'));
	$ts = \util\Slack::ts($text('ts'));
	$this->slack->call('chat.delete', [ 'channel' => $channel, 'ts' => $ts ]);
	return [ [ 'channel' => $channel, 'ts' => $ts, 'deleted' => true ], "message {$ts} deleted" ];
    }

    private function opReact(\Closure $text, \Closure $value) {
	$channel = $this->channel($value('channel'));
	$ts = \util\Slack::ts($text('ts'));
	$emoji = strtolower(trim($text('emoji'), ": \t"));
	if (!preg_match('/^[a-z0-9_+\'-]+(::skin-tone-[2-6])?$/', $emoji))
	    throw new \RuntimeException('Emoji: a name, e.g. white_check_mark');
	$this->slack->call('reactions.add', [ 'channel' => $channel, 'timestamp' => $ts, 'name' => $emoji ]);
	return [ [ 'channel' => $channel, 'ts' => $ts, 'emoji' => $emoji ], ":{$emoji}: added to {$ts}" ];
    }

    private function opHistory(\Closure $text, \Closure $value) {
	$channel = $this->channel($value('channel'));
	$args = array_filter([ 'channel' => $channel, 'oldest' => self::timeOf($value('since'), 'Since'), 'latest' => self::timeOf($value('until'), 'Until') ], fn($v) => $v !== null);
	return $this->listOf('conversations.history', $args, 'messages', 'message', fn($m) => self::messageOf($m, $channel));
    }

    private function opReplies(\Closure $text, \Closure $value) {
	$channel = $this->channel($value('channel'));
	return $this->listOf('conversations.replies', [ 'channel' => $channel, 'ts' => \util\Slack::ts($text('ts')) ], 'messages', 'message', fn($m) => self::messageOf($m, $channel));
    }

    private function opPermalink(\Closure $text, \Closure $value) {
	$channel = $this->channel($value('channel'));
	$ts = \util\Slack::ts($text('ts'));
	$link = (string)($this->slack->call('chat.getPermalink', [ 'channel' => $channel, 'message_ts' => $ts ])['permalink'] ?? '');
	return [ [ 'channel' => $channel, 'ts' => $ts, 'link' => $link ], $link ];
    }
}
