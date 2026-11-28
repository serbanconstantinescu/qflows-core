<?php

/*
    CLD: 2026-11-25T18:00:00+02:00: S3 object - starts the workflow when objects appear (or change, or are removed) in a bucket, below a prefix, names matching patterns.
    How they are noticed (setting Receive):
	poll  the step lists the bucket on its schedule (models\wf\WfSchedule, every 5 minutes by default; util\Scheduler runs runScheduled), with the credential's key: any
	      S3-compatible service, nothing to set up. models\TriggerState keeps what it has seen ({ seen: [ [ md5(key), etag ] ] }, locked during a check): the first check only
	      notes the objects there; later ones start runs for new objects (and, with "New or changed", those whose ETag changed); at most MAX_RUNS per check (the others: the
	      next check), MAX_OBJECTS read (more: not watched, logged)
	sns   Amazon S3's event notifications through an SNS topic subscribed to the step's address /s3/<token> (controllers\S3Controller): at once. Each post's signature is
	      checked (util\vendor\Sns), its topic must be the step's Topic ARN; the subscription is confirmed by itself; each SNS message is taken once
    The variable: the object ({ bucket, key, name, folder, size, modified, etag, storageClass, url, change: new | changed | removed, event (sns: S3's event name) }), or
    { objects, count } per check / post
*/

namespace models\node;

class NodeTriggerS3 extends \models\wf\WfNode {
    //static data/structure
    public static $type = 'trigger';
    public static $name = 's3';
    public static $label = 'S3 object';
    public static $icon = 'bi-bucket';
    public static $inputs = 0;
    public static $outputs = 1;
    public static $scheduled = true;

    const RECEIVE = [ 'poll' => 'Check the bucket on a schedule', 'sns' => 'S3 event notifications (through SNS)' ];
    const CHANGES = [ 'new' => 'New objects', 'changed' => 'New or changed objects' ];
    const EVENTS = [ 'created' => 'Objects created', 'removed' => 'Objects removed' ];
    const RUNS = [ 'each' => 'One run per object', 'batch' => 'One run per check (or post) with objects' ];
    const MAX_RUNS = 200;
    const MAX_OBJECTS = 20000;
    const MAX_MESSAGES = 300;
    const TIMEOUT = 120;
    const TOPIC = '/^arn:aws[a-z-]*:sns:[a-z0-9-]+:\d{12}:[A-Za-z0-9_-]{1,256}$/';

    //its address's token is indexed (models\TriggerToken), with no data of its own
    public static function tokenData(array $node) {
	return [];
    }

    public static function initConfig() {
	$token = \models\TriggerToken::newToken('hex');
	return [ 'token' => $token, 'url' => \V::app()->createAbsoluteUrl('/s3/' . $token) ];
    }

    public static function configSchema() {
	$sns = [ 'key' => 'receive', 'operator' => 'eq', 'value' => 'sns' ];
	$poll = [ 'key' => 'receive', 'operator' => 'eq', 'value' => 'poll' ];
	$schedule = array_map(function($f) {
	    $f['ui'] = ($f['ui'] ?? []) + [ 'hideIf' => [ 'key' => 'receive', 'operator' => 'eq', 'value' => 'sns' ] ];
	    return $f;
	}, \models\wf\WfSchedule::schema([ 'mode' => 'interval', 'every' => 5, 'every_unit' => 'minutes' ]));
	return array_merge([
	    [
		'key' => 'credential',
		'type' => 'select',
		'label' => 'S3 credential',
		'choices' => NodeAppS3::credentialChoices(),
		'help' => 'Polling lists the bucket with its key',
		'required' => true,
		'ui' => [ 'width' => 6 ],
	    ],
	    [
		'key' => 'receive',
		'type' => 'select',
		'label' => 'Receive',
		'required' => true,
		'default' => 'poll',
		'choices' => self::RECEIVE,
		'ui' => [ 'width' => 6 ],
	    ],
	    [
		'key' => 'url',
		'type' => 'text',
		'label' => 'Address',
		'perm:update' => 'nobody',
		'help' => 'In SNS: a subscription of the topic, protocol HTTPS, this endpoint, once the workflow is published (it is confirmed by itself). Keep it private',
		'ui' => [ 'placeholder' => 'Generating…', 'showIf' => $sns ],
	    ],
	    [
		'key' => 'topicArn',
		'type' => 'text',
		'label' => 'Topic ARN',
		'default' => '',
		'transform' => 'trim',
		'help' => 'The SNS topic the bucket\'s events go to; posts of other topics are refused',
		'requiredIf' => [ 'if' => 'receive', 'in' => [ 'sns' ] ],
		'validate' => [ [ 'regex', 'pattern' => substr(self::TOPIC, 1, -1), 'skipEmpty' => true, 'message' => 'arn:aws:sns:<region>:<account>:<topic>' ] ],
		'ui' => [ 'showIf' => $sns, 'placeholder' => 'arn:aws:sns:eu-central-1:123456789012:uploads' ],
	    ],
	    [
		'key' => 'bucket',
		'type' => 'text',
		'label' => 'Bucket',
		'default' => '',
		'transform' => 'trim',
		'help' => 'Empty: the credential\'s default bucket',
		'ui' => [ 'width' => 6, 'newRow' => true ],
	    ],
	    [
		'key' => 'prefix',
		'type' => 'text',
		'label' => 'Prefix',
		'default' => '',
		'help' => 'Optional: keys starting with it, e.g. inbox/',
		'ui' => [ 'width' => 3 ],
	    ],
	    [
		'key' => 'pattern',
		'type' => 'text',
		'label' => 'Names',
		'default' => '',
		'help' => 'Patterns, e.g. *.pdf, invoice-*; empty: all',
		'ui' => [ 'width' => 3 ],
	    ],
	    [
		'key' => 'changes',
		'type' => 'select',
		'label' => 'Start on',
		'required' => true,
		'default' => 'new',
		'choices' => self::CHANGES,
		'ui' => [ 'width' => 6, 'newRow' => true, 'showIf' => $poll ],
	    ],
	    [
		'key' => 'events',
		'type' => 'select',
		'label' => 'Start on',
		'multiple' => true,
		'default' => [ 'created' ],
		'choices' => self::EVENTS,
		'ui' => [ 'width' => 6, 'newRow' => true, 'showIf' => $sns ],
	    ],
	    [
		'key' => 'runs',
		'type' => 'select',
		'label' => 'Runs',
		'required' => true,
		'default' => 'each',
		'choices' => self::RUNS,
		'ui' => [ 'width' => 6 ],
	    ],
	    [
		'key' => 'variable',
		'type' => 'text',
		'label' => 'Object variable',
		'required' => true,
		'default' => 'object',
		'help' => 'e.g. {{ object.key }}, {{ object.size }}, {{ object.url }}',
		'validate' => [ [ 'identifier', 'skipEmpty' => true, 'message' => 'Use letters, digits or underscore, not starting with a digit' ] ],
		'ui' => [ 'width' => 6, 'variableName' => 'write' ],
	    ],
	], $schedule);
    }

    public static function matches(array $cfg, \DateTimeInterface $t) {
	return ($cfg['receive'] ?? 'poll') === 'poll' && \models\wf\WfSchedule::matches($cfg, $t);
    }

    public function validates($options = []) {
	$valid = parent::validates($options);
	$cfg = is_array($this->config) ? $this->config : [];
	$sns = ($cfg['receive'] ?? 'poll') === 'sns';
	if (!$sns) {
	    $error = \models\wf\WfSchedule::validate($cfg);
	    if ($error !== null) {
		$this->errors('config', $error);
		$valid = false;
	    }
	} elseif (!self::chosenEvents($cfg)) {
	    $this->errors('config', 'S3 object: choose at least one kind of event');
	    $valid = false;
	}
	$id = (string)$this->cfg('credential', '');
	$cred = $id !== '' && $this->workflow ? \models\Credential::findFor($this->workflow, $id, 's3') : null;
	if ($id !== '' && !$cred) {
	    $this->errors('config', 'S3 object: choose an S3 storage credential of this project');
	    $valid = false;
	}
	$bucket = trim((string)($cfg['bucket'] ?? ''));
	if ($bucket === '' && $cred && trim((string)$cred->bucket) === '') {
	    $this->errors('config', 'S3 object: name the bucket (the credential has no default bucket)');
	    $valid = false;
	} elseif ($bucket !== '') {
	    try {
		\util\vendor\S3::bucket($bucket);
	    } catch (\RuntimeException $e) {
		$this->errors('config', 'S3 object: ' . $e->getMessage());
		$valid = false;
	    }
	}
	return \models\TriggerToken::checkToken($this, 'hex') && $valid;
    }

    public static function outputVariables($config) {
	$name = is_array($config) ? (string)($config['variable'] ?? 'object') : 'object';
	if (!\util\Validator::isIdentifier($name))
	    return [];
	$object = [ 'bucket' => [ 'type' => 'string' ], 'key' => [ 'type' => 'string' ], 'name' => [ 'type' => 'string' ], 'folder' => [ 'type' => 'string', 'info' => 'the key up to its last /' ],
	    'size' => [ 'type' => 'number', 'info' => 'bytes' ], 'modified' => [ 'type' => 'string', 'info' => 'ISO 8601' ], 'etag' => [ 'type' => 'string' ], 'url' => [ 'type' => 'string', 'info' => 's3://bucket/key' ],
	    'change' => [ 'type' => 'string', 'info' => 'new, changed or removed' ], 'event' => [ 'type' => 'string', 'info' => 'notifications: S3\'s event name, e.g. ObjectCreated:Put' ] ];
	if (is_array($config) && ($config['runs'] ?? 'each') === 'batch')
	    return [ $name => [ 'type' => 'object', 'info' => '{ objects, count }', 'fields' => [ 'objects' => [ 'type' => 'array', 'items' => [ 'type' => 'object', 'fields' => $object ] ],
		'count' => [ 'type' => 'number' ] ] ] ];
	return [ $name => [ 'type' => 'object', 'info' => 'the object', 'fields' => $object ] ];
    }

    //the kinds of events chosen (sns)
    public static function chosenEvents(array $cfg) {
	return array_values(array_intersect(array_map('strval', (array)($cfg['events'] ?? [ 'created' ])), array_keys(self::EVENTS)));
    }

    protected static function credentialOf($wf, array $cfg) {
	$cred = \models\Credential::findFor($wf, (string)($cfg['credential'] ?? ''), 's3');
	if (!$cred)
	    throw new \Exception('S3 object: the credential is no longer available');
	return $cred;
    }

    //the bucket watched: the step's, else the credential's
    public static function bucketOf(array $cfg, $cred) {
	$b = trim((string)($cfg['bucket'] ?? ''));
	return \util\vendor\S3::bucket($b !== '' ? $b : (string)$cred->bucket);
    }

    //whether object key $key is one the step watches (its prefix, its names)
    public static function watches(array $cfg, $key) {
	$prefix = ltrim((string)($cfg['prefix'] ?? ''), '/');
	if ($prefix !== '' && !str_starts_with((string)$key, $prefix))
	    return false;
	if (str_ends_with((string)$key, '/'))
	    return false;
	$slash = strrpos((string)$key, '/');
	return NodeAppNxFiles::matches($slash === false ? $key : substr($key, $slash + 1), (string)($cfg['pattern'] ?? ''));
    }

    //runs of the objects $objects (each, or one with them all), queued unless $start says otherwise
    public static function startRuns($wf, array $node, array $objects, ?callable $start = null) {
	$cfg = (array)($node['config'] ?? []);
	$name = \util\Validator::isIdentifier((string)($cfg['variable'] ?? '')) ? (string)$cfg['variable'] : 'object';
	$values = !$objects ? [] : (($cfg['runs'] ?? 'each') === 'batch' ? [ [ 'objects' => $objects, 'count' => count($objects) ] ] : $objects);
	$started = [];
	foreach($values as $v) {
	    $exe = \models\wf\WfExecution::startFor($wf, 's3', [ 'started_at' => new \MongoDB\BSON\UTCDateTime(), 'vars' => [ $name => $v ] ], (string)$node['wfid']);
	    $exe->log($node['wfid'], isset($v['objects']) ? 'S3: ' . \util\Format::plural($v['count'], 'object') : "S3 object {$v['change']}: {$v['url']}");
	    ($start ?? fn($e) => $e->queue())($exe);
	    $started[] = $exe;
	}
	return $started;
    }

    // ------------------------------------------------------------------------------------
    //polling

    public static function runScheduled($wf, array $node, $at) {
	if (($node['config']['receive'] ?? 'poll') !== 'poll')
	    return [];
	$state = \models\TriggerState::acquire($wf, $node['wfid']);
	if (!$state) {
	    \V::app()->log->info("S3 object: workflow {$wf->_id}, step {$node['wfid']}: the previous check is still running, skipped");
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

    //the objects watched now: [ md5(key) => object ], more than MAX_OBJECTS or not
    protected static function objects($wf, array $cfg) {
	$cred = self::credentialOf($wf, $cfg);
	$bucket = self::bucketOf($cfg, $cred);
	[ $list, , $more ] = $cred->client($wf->aid, self::TIMEOUT)->listObjects($bucket, ltrim((string)($cfg['prefix'] ?? ''), '/'), '', self::MAX_OBJECTS);
	$byKey = [];
	foreach($list as $o)
	    if (self::watches($cfg, $o['key']))
		$byKey[md5($o['key'])] = $o + [ 'url' => "s3://{$bucket}/{$o['key']}" ];
	return [ $byKey, $more ];
    }

    //one check: [ the new state data, the executions started ]; $start: what is done with a run (default: queued; the check suite runs it)
    public static function check($wf, array $node, array $data, ?callable $start = null) {
	$cfg = (array)($node['config'] ?? []);
	[ $now, $more ] = self::objects($wf, $cfg);
	if ($more)
	    \V::app()->log->info("S3 object: workflow {$wf->_id}, step {$node['wfid']}: more than " . self::MAX_OBJECTS . ' objects, the others are not watched');
	if (!isset($data['seen']))
	    return [ [ 'seen' => array_map(fn($k, $o) => [ $k, $o['etag'] ], array_keys($now), array_values($now)) ], [] ];
	if (array_filter($wf->validateFlow(), fn($i) => $i['level'] == 'error'))
	    throw new \Exception("workflow `{$wf->name}` has errors and cannot run");

	$seen = [];
	foreach((array)$data['seen'] as $s) {
	    $s = array_values((array)$s);
	    $seen[(string)$s[0]] = (string)($s[1] ?? '');
	}
	$changed = ($cfg['changes'] ?? 'new') === 'changed';
	$new = [];
	$kept = [];
	foreach($now as $k => $o) {
	    $was = $seen[$k] ?? null;
	    $isNew = $was === null;
	    if (($isNew || ($changed && $was !== $o['etag'])) && count($new) < self::MAX_RUNS) {
		$new[] = $o + [ 'change' => $isNew ? 'new' : 'changed' ];
		$kept[$k] = $o['etag'];
	    } elseif (!$isNew)
		//not started yet (over MAX_RUNS): the old ETag kept, so the next check takes it
		$kept[$k] = $changed ? $was : $o['etag'];
	}
	$started = self::startRuns($wf, $node, $new, $start);
	return [ [ 'seen' => array_map(fn($k, $e) => [ $k, $e ], array_keys($kept), array_values($kept)) ], $started ];
    }

    //Run in the designer: one run with the newest object; the trigger's state is not changed
    public static function startNow($wf, array $node) {
	$cfg = (array)($node['config'] ?? []);
	try {
	    [ $now ] = self::objects($wf, $cfg);
	} catch (\Throwable $e) {
	    return [ 'executions' => [], 'message' => 'S3 object: ' . $e->getMessage() ];
	}
	if (!$now)
	    return [ 'executions' => [], 'message' => 'S3 object: no object of the bucket matches' ];
	$objects = array_values($now);
	usort($objects, fn($a, $b) => strcmp((string)$b['modified'], (string)$a['modified']));
	$name = \util\Validator::isIdentifier((string)($cfg['variable'] ?? '')) ? (string)$cfg['variable'] : 'object';
	$o = $objects[0] + [ 'change' => 'new' ];
	$value = ($cfg['runs'] ?? 'each') === 'batch' ? [ 'objects' => [ $o ], 'count' => 1 ] : $o;
	$exe = \models\wf\WfExecution::startFor($wf, 's3', [ 'started_at' => new \MongoDB\BSON\UTCDateTime(), 'vars' => [ $name => $value ] ], (string)$node['wfid']);
	$exe->save();
	return [ 'executions' => [ $exe ], 'message' => "Run with the newest object: {$o['url']}" ];
    }

    // ------------------------------------------------------------------------------------
    //event notifications (S3Controller)

    //the objects of S3 event message $event (SNS's Message, decoded) the step starts on: [ object + change, event ]
    public static function objectsOfEvent(array $cfg, $bucket, array $event) {
	$kinds = self::chosenEvents($cfg);
	$out = [];
	foreach(array_slice((array)($event['Records'] ?? []), 0, self::MAX_RUNS) as $r) {
	    if (!is_array($r))
		continue;
	    $name = (string)($r['eventName'] ?? '');
	    $kind = str_starts_with($name, 'ObjectCreated:') ? 'created' : (str_starts_with($name, 'ObjectRemoved:') ? 'removed' : null);
	    if ($kind === null || !in_array($kind, $kinds, true) || (string)($r['s3']['bucket']['name'] ?? '') !== $bucket)
		continue;
	    //S3 sends the key URL-encoded (+ for a space)
	    $key = urldecode((string)($r['s3']['object']['key'] ?? ''));
	    if ($key === '' || !self::watches($cfg, $key))
		continue;
	    $slash = strrpos($key, '/');
	    $out[] = [ 'bucket' => $bucket, 'key' => $key, 'name' => $slash === false ? $key : substr($key, $slash + 1), 'folder' => $slash === false ? '' : substr($key, 0, $slash + 1),
		'size' => (int)($r['s3']['object']['size'] ?? 0), 'modified' => isset($r['eventTime']) ? gmdate('Y-m-d\TH:i:s\Z', strtotime((string)$r['eventTime'])) : '',
		'etag' => trim((string)($r['s3']['object']['eTag'] ?? ''), '"'), 'storageClass' => '', 'url' => "s3://{$bucket}/{$key}",
		'change' => $kind === 'removed' ? 'removed' : 'new', 'event' => $name ];
	}
	return $out;
    }

    //whether SNS message $id was already taken by the step (SNS delivers again when unsure); noted when not
    public static function seenMessage($wf, $nodeId, $id) {
	try {
	    return \V::app()->om->updateOne('TriggerState', [ 'wf' => $wf->_id, 'node' => (string)$nodeId, 'aid' => $wf->aid, 'data.messages' => [ '$ne' => (string)$id ] ],
		[ '$push' => [ 'data.messages' => [ '$each' => [ (string)$id ], '$slice' => -self::MAX_MESSAGES ] ], '$setOnInsert' => [ 'project' => $wf->project, 'locked_until' => null,
		'created_at' => new \MongoDB\BSON\UTCDateTime() ] ], [ 'upsert' => true ]) === 0;
	} catch (\MongoDB\Driver\Exception\ServerException $e) {
	    if ($e->getCode() == 11000)
		return true;
	    throw $e;
	}
    }
}
