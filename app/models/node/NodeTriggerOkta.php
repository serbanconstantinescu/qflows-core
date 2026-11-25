<?php

/*
    CLD: 2026-11-26T02:00:00+02:00: Okta event - starts the workflow on the org's System Log events (people created, activated, deactivated, locked out, added to groups, failed
    sign-ins, …), by event type and outcome. How they are noticed (setting Receive):
	poll  the step reads the System Log on its schedule (GET /api/v1/logs since the last check, the types as its filter; models\TriggerState: { since, seen }); the first check
	      only notes the time
	hook  an event hook of the org posts the events to the step's address /okta/<token> (controllers\OktaController), with the step's secret as its Authorization header;
	      Okta's one-time verification (x-okta-verification-challenge) answered. The hook's own event types are what Okta sends; the step's types filter them again
    Each event once (its uuid). The variable (default `event`): { type, outcome, reason, severity, message, actor: { id, login, name, type }, target: { id, login, name, type } (the
    first user, else the first), targets, client: { ip, device, city, country }, uuid, published, data }
*/

namespace models\node;

class NodeTriggerOkta extends \models\wf\WfNode {
    public static $type = 'trigger';
    public static $name = 'okta';
    public static $label = 'Okta event';
    public static $icon = 'bi-shield-lock';
    public static $inputs = 0;
    public static $outputs = 1;
    public static $scheduled = true;

    const RECEIVE = [ 'poll' => 'Read the System Log on a schedule', 'hook' => 'An event hook (at once)' ];
    const OUTCOMES = [ '' => 'Any', 'SUCCESS' => 'Success', 'FAILURE' => 'Failure', 'DENY' => 'Denied' ];
    const MAX_RUNS = 100;
    const MAX_SEEN = 1000;
    const TIMEOUT = 60;
    const TYPE = '/^[a-z][a-z0-9_]*(\.[a-z0-9_]+){1,8}$/';

    public static function tokenData(array $node) {
	return [];
    }

    public static function initConfig() {
	$token = \models\TriggerToken::newToken('hex');
	return [ 'token' => $token, 'url' => \V::app()->createAbsoluteUrl('/okta/' . $token), 'secret' => bin2hex(random_bytes(20)) ];
    }

    public static function forTransfer(array $config) {
	$config['secret'] = bin2hex(random_bytes(20));
	return $config;
    }

    public static function configSchema() {
	$hook = [ 'key' => 'receive', 'operator' => 'eq', 'value' => 'hook' ];
	$schedule = array_map(function($f) {
	    $f['ui'] = ($f['ui'] ?? []) + [ 'hideIf' => [ 'key' => 'receive', 'operator' => 'eq', 'value' => 'hook' ] ];
	    return $f;
	}, \models\wf\WfSchedule::schema([ 'mode' => 'interval', 'every' => 5, 'every_unit' => 'minutes' ]));
	return array_merge([
	    [ 'key' => 'receive', 'type' => 'select', 'label' => 'Receive', 'required' => true, 'default' => 'poll', 'choices' => self::RECEIVE, 'ui' => [ 'width' => 6 ] ],
	    [ 'key' => 'credential', 'type' => 'select', 'label' => 'Okta credential', 'choices' => NodeAppOkta::credentialChoices(), 'help' => 'Polling reads with it (okta.logs.read; an event hook needs none)',
		'requiredIf' => [ 'if' => 'receive', 'in' => [ 'poll' ] ], 'ui' => [ 'width' => 6 ] ],
	    [ 'key' => 'url', 'type' => 'text', 'label' => 'Hook URL', 'perm:update' => 'nobody', 'help' => 'In Okta: Workflow > Event Hooks > Create Event Hook: its URL', 'ui' => [ 'placeholder' => 'Generating…', 'showIf' => $hook ] ],
	    [ 'key' => 'secret', 'type' => 'text', 'label' => 'Authorization header', 'perm:update' => 'nobody', 'help' => 'The hook\'s Authentication field Authorization, its Authentication secret this value. Keep it private',
		'ui' => [ 'showIf' => $hook ] ],
	    [ 'key' => 'types', 'type' => 'textarea', 'label' => 'Event types', 'required' => true, 'default' => "user.lifecycle.create\nuser.lifecycle.deactivate",
		'help' => 'One per line (or separated by commas), e.g. user.lifecycle.activate, user.account.lock, user.session.start, group.user_membership.add, group.user_membership.remove, application.user_membership.add',
		'ui' => [ 'rows' => 3 ] ],
	    [ 'key' => 'outcome', 'type' => 'select', 'label' => 'Outcome', 'default' => '', 'choices' => self::OUTCOMES, 'help' => 'e.g. Failure with user.session.start: failed sign-ins', 'ui' => [ 'width' => 6 ] ],
	    [ 'key' => 'variable', 'type' => 'text', 'label' => 'Event variable', 'required' => true, 'default' => 'event', 'help' => 'e.g. {{ event.type }}, {{ event.target.login }}, {{ event.actor.login }}',
		'validate' => [ [ 'identifier', 'skipEmpty' => true, 'message' => 'Use letters, digits or underscore, not starting with a digit' ] ], 'ui' => [ 'width' => 6, 'variableName' => 'write' ] ],
	], $schedule);
    }

    public static function matches(array $cfg, \DateTimeInterface $t) {
	return ($cfg['receive'] ?? 'poll') === 'poll' && \models\wf\WfSchedule::matches($cfg, $t);
    }

    //the event types of the setting
    public static function types(array $cfg) {
	return array_values(array_unique(array_filter(array_map(fn($t) => strtolower(trim($t)), preg_split('/[\s,]+/', (string)($cfg['types'] ?? ''))), fn($t) => $t !== '')));
    }

    public function validates($options = []) {
	$valid = parent::validates($options);
	$cfg = is_array($this->config) ? $this->config : [];
	$poll = ($cfg['receive'] ?? 'poll') === 'poll';
	if ($poll && ($error = \models\wf\WfSchedule::validate($cfg)) !== null) {
	    $this->errors('config', $error);
	    $valid = false;
	}
	$types = self::types($cfg);
	if (!$types || array_filter($types, fn($t) => !preg_match(self::TYPE, $t))) {
	    $this->errors('config', 'Okta event: Event types: Okta\'s event types, e.g. user.lifecycle.create');
	    $valid = false;
	}
	if (!$poll && strlen((string)($cfg['secret'] ?? '')) < 20) {
	    $this->errors('config', 'Okta event: the step has no secret (add the step again)');
	    $valid = false;
	}
	$id = (string)$this->cfg('credential', '');
	if ($id !== '' && (!$this->workflow || empty(\models\Credential::findFor($this->workflow, $id, 'okta')))) {
	    $this->errors('config', 'Okta event: choose an Okta credential of this project');
	    $valid = false;
	}
	return \models\TriggerToken::checkToken($this, 'hex') && $valid;
    }

    public static function outputVariables($config) {
	$name = is_array($config) ? (string)($config['variable'] ?? 'event') : 'event';
	if (!\util\Validator::isIdentifier($name))
	    return [];
	$who = [ 'type' => 'object', 'fields' => [ 'id' => [ 'type' => 'string' ], 'login' => [ 'type' => 'string' ], 'name' => [ 'type' => 'string' ], 'type' => [ 'type' => 'string' ] ] ];
	return [ $name => [ 'type' => 'object', 'info' => 'the event', 'fields' => [ 'type' => [ 'type' => 'string', 'info' => 'e.g. user.lifecycle.create' ], 'outcome' => [ 'type' => 'string', 'info' => 'SUCCESS, FAILURE, …' ],
	    'message' => [ 'type' => 'string' ], 'actor' => [ 'info' => 'who did it' ] + $who, 'target' => [ 'info' => 'whom it is about (the first user)' ] + $who,
	    'client' => [ 'type' => 'object', 'fields' => [ 'ip' => [ 'type' => 'string' ], 'city' => [ 'type' => 'string' ], 'country' => [ 'type' => 'string' ] ] ], 'published' => [ 'type' => 'string' ],
	    'uuid' => [ 'type' => 'string' ], 'data' => [ 'type' => 'object', 'info' => 'as Okta sent it' ] ] ] ];
    }

    //the event value of a System Log event
    public static function valueOf(array $e) {
	$who = fn($x) => is_array($x) ? [ 'id' => (string)($x['id'] ?? ''), 'login' => (string)($x['alternateId'] ?? ''), 'name' => (string)($x['displayName'] ?? ''), 'type' => (string)($x['type'] ?? '') ] : null;
	$targets = array_values(array_filter(array_map($who, (array)($e['target'] ?? []))));
	$user = array_values(array_filter($targets, fn($t) => $t['type'] === 'User'))[0] ?? ($targets[0] ?? null);
	return [
	    'type' => (string)($e['eventType'] ?? ''),
	    'outcome' => (string)($e['outcome']['result'] ?? ''),
	    'reason' => (string)($e['outcome']['reason'] ?? ''),
	    'severity' => (string)($e['severity'] ?? ''),
	    'message' => (string)($e['displayMessage'] ?? ''),
	    'actor' => $who($e['actor'] ?? null),
	    'target' => $user,
	    'targets' => $targets,
	    'client' => [ 'ip' => (string)($e['client']['ipAddress'] ?? ''), 'device' => (string)($e['client']['device'] ?? ''), 'city' => (string)($e['client']['geographicalContext']['city'] ?? ''),
		'country' => (string)($e['client']['geographicalContext']['country'] ?? '') ],
	    'uuid' => (string)($e['uuid'] ?? ''),
	    'published' => (string)($e['published'] ?? ''),
	];
    }

    //whether event $e is one the step starts on (its type and outcome)
    public static function wanted(array $cfg, array $e) {
	if (!in_array(strtolower((string)($e['eventType'] ?? '')), self::types($cfg), true))
	    return false;
	$outcome = (string)($cfg['outcome'] ?? '');
	return $outcome === '' || strtoupper((string)($e['outcome']['result'] ?? '')) === $outcome;
    }

    public static function startRuns($wf, array $node, array $events, ?callable $start = null) {
	$cfg = (array)($node['config'] ?? []);
	$name = \util\Validator::isIdentifier((string)($cfg['variable'] ?? '')) ? (string)$cfg['variable'] : 'event';
	$started = [];
	foreach($events as $e) {
	    $v = self::valueOf($e) + [ 'data' => NodeTriggerGitHub::clean($e) ];
	    $exe = \models\wf\WfExecution::startFor($wf, 'okta', [ 'received_at' => new \MongoDB\BSON\UTCDateTime(), 'vars' => [ $name => $v ] ], (string)$node['wfid']);
	    $exe->log($node['wfid'], "Okta {$v['type']}" . ($v['outcome'] !== '' ? " ({$v['outcome']})" : '') . (isset($v['target']['login']) && $v['target']['login'] !== '' ? ": {$v['target']['login']}" : ''));
	    ($start ?? fn($x) => $x->queue())($exe);
	    $started[] = $exe;
	}
	return $started;
    }

    //whether event uuid $id was taken already (an event hook's retry, or the same event in a later check); noted when not
    public static function seenEvent($wf, $nodeId, $id) {
	return NodeTriggerGitHub::seenDelivery($wf, $nodeId, $id);
    }

    public static function runScheduled($wf, array $node, $at) {
	if (($node['config']['receive'] ?? 'poll') !== 'poll')
	    return [];
	$state = \models\TriggerState::acquire($wf, $node['wfid']);
	if (!$state) {
	    \V::app()->log->info("Okta event: workflow {$wf->_id}, step {$node['wfid']}: the previous check is still running, skipped");
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

    //one check: [ the new state data { since, seen }, the executions started ]; $start: what is done with a run (default: queued; the check suite saves it)
    public static function check($wf, array $node, array $data, ?callable $start = null) {
	$cfg = (array)($node['config'] ?? []);
	$now = time();
	if (empty($data['since']))
	    return [ [ 'since' => gmdate('Y-m-d\TH:i:s.000\Z', $now), 'seen' => [] ], [] ];
	if (array_filter($wf->validateFlow(), fn($i) => $i['level'] == 'error'))
	    throw new \Exception("workflow `{$wf->name}` has errors and cannot run");
	$cred = \models\Credential::findFor($wf, (string)($cfg['credential'] ?? ''), 'okta');
	if (!$cred)
	    throw new \Exception('Okta event: the credential is no longer available');
	$o = $cred->client($wf->aid, self::TIMEOUT);
	$filter = implode(' or ', array_map(fn($t) => "eventType eq \"{$t}\"", self::types($cfg)));
	$outcome = (string)($cfg['outcome'] ?? '');
	if ($outcome !== '')
	    $filter = "({$filter}) and outcome.result eq \"{$outcome}\"";
	[ $events ] = $o->pages('/logs', [ 'since' => (string)$data['since'], 'filter' => $filter, 'sortOrder' => 'ASCENDING' ], self::MAX_RUNS * 2);
	$seen = array_map('strval', (array)($data['seen'] ?? []));
	$new = [];
	$latest = (string)$data['since'];
	foreach($events as $e) {
	    $uuid = (string)($e['uuid'] ?? '');
	    if ($uuid === '' || in_array($uuid, $seen, true) || !self::wanted($cfg, $e))
		continue;
	    if (count($new) >= self::MAX_RUNS)
		break;
	    $new[] = $e;
	    $seen[] = $uuid;
	    if (strcmp((string)($e['published'] ?? ''), $latest) > 0)
		$latest = (string)$e['published'];
	}
	$started = self::startRuns($wf, $node, $new, $start);
	//a minute back from the newest event read (the log's events arrive a little late); `seen` keeps an event from starting two runs
	$since = count($new) >= self::MAX_RUNS ? $latest : gmdate('Y-m-d\TH:i:s.000\Z', min($now, strtotime($latest) ?: $now) - 60);
	if (strcmp($since, (string)$data['since']) < 0)
	    $since = (string)$data['since'];
	return [ [ 'since' => $since, 'seen' => array_slice($seen, -self::MAX_SEEN) ], $started ];
    }
}
