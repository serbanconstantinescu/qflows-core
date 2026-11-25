<?php

/*
    CLD: 2026-11-25T14:00:00+02:00: Slack API - any method of Slack's Web API as the credential's app (what the other Slack steps do not have: pins, bookmarks, reminders,
    user groups, views.open, …): the method (e.g. pins.add) and its arguments (a JSON object; lists and objects in it sent as JSON text). The result: Slack's answer
    (ok true); a list's further pages are not read (response_metadata.next_cursor is in the answer: pass it as `cursor`)
*/

namespace models\node;

class NodeAppSlackApi extends NodeAppSlack {
    public static $name = 'slack-api';
    public static $label = 'Slack API';
    public static $icon = 'bi-braces';
    public static $audience = 'advanced';
    public static $defaultOperation = 'call';
    public static $defaultVariable = 'slack';

    const OPERATIONS = [ 'call' => 'A Web API method' ];
    const WRITES = [ 'call' ];

    protected static function operationFields() {
	return [
	    self::field('method', 'Method', [ 'call' ], [ 'required' => true, 'help' => 'e.g. pins.add, bookmarks.list, usergroups.users.update (api.slack.com/methods)',
		'ui' => [ 'expression' => 'text', 'width' => 6, 'placeholder' => 'pins.add' ] ]),
	    self::field('args', 'Arguments', [ 'call' ], [ 'type' => 'textarea', 'help' => 'A JSON object (empty: none), e.g. {"channel": "C0123", "timestamp": "{{ slack.ts }}"}',
		'ui' => [ 'rows' => 4, 'expression' => true, 'code' => true ] ]),
	];
    }

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	$method = $text('method');
	return [ $this->slack->call($method, $this->jsonOf('args', 'Arguments') ?? []), $method ];
    }
}
