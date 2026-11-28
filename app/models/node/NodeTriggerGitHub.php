<?php

/*
    CLD: 2026-11-25T21:00:00+02:00: GitHub event - starts the workflow on what happens in GitHub. How events are noticed (setting Receive):
	webhook  a repository's (or organization's) webhook posts to the step's address /github/<token> (controllers\GithubController), signed with the step's secret
	         (X-Hub-Signature-256): the events chosen, optionally only some actions (opened, closed, …), branches (push, workflow runs) and a repository; each delivery once
	poll     the step checks a repository on its schedule (models\wf\WfSchedule, every 5 minutes by default) with the credential: issues opened, pull requests opened or merged,
	         workflow runs completed or failed, releases published, since the last check (models\TriggerState: { since, seen }); the first check only notes the time
    The variable (default `github`): { event, action, repository, sender, number, title, url, state, user, ref, branch, tag, sha, head, base, merged, status, conclusion, commits,
    workflow, delivery, data (the payload as GitHub sent it; polling: the item) }
*/

namespace models\node;

class NodeTriggerGitHub extends \models\wf\WfNode {
    public static $type = 'trigger';
    public static $name = 'github';
    public static $label = 'GitHub event';
    public static $icon = 'bi-github';
    public static $inputs = 0;
    public static $outputs = 1;
    public static $scheduled = true;

    const RECEIVE = [ 'webhook' => 'GitHub sends the events (a webhook)', 'poll' => 'Check a repository on a schedule' ];
    const EVENTS = [ 'push' => 'Pushes', 'pull_request' => 'Pull requests', 'pull_request_review' => 'Pull request reviews', 'issues' => 'Issues', 'issue_comment' => 'Comments on issues and pull requests',
	'release' => 'Releases', 'workflow_run' => 'Workflow runs', 'create' => 'Branches or tags created', 'delete' => 'Branches or tags deleted' ];
    const POLLS = [ 'issues' => 'Issues opened', 'pulls' => 'Pull requests opened', 'merged' => 'Pull requests merged', 'runs' => 'Workflow runs completed', 'failed' => 'Workflow runs failed',
	'releases' => 'Releases published' ];
    const FAILED = [ 'failure', 'timed_out', 'startup_failure' ];
    const MAX_RUNS = 50;
    const MAX_SEEN = 500;
    const MAX_DELIVERIES = 300;
    const TIMEOUT = 60;

    public static function tokenData(array $node) {
	return [];
    }

    public static function initConfig() {
	$token = \models\TriggerToken::newToken('hex');
	return [ 'token' => $token, 'url' => \V::app()->createAbsoluteUrl('/github/' . $token), 'secret' => bin2hex(random_bytes(20)) ];
    }

    //an import (util\Transfer): a secret of its own here
    public static function forTransfer(array $config) {
	$config['secret'] = bin2hex(random_bytes(20));
	return $config;
    }

    public static function configSchema() {
	$hook = [ 'key' => 'receive', 'operator' => 'eq', 'value' => 'webhook' ];
	$poll = [ 'key' => 'receive', 'operator' => 'eq', 'value' => 'poll' ];
	$schedule = array_map(function($f) {
	    $f['ui'] = ($f['ui'] ?? []) + [ 'hideIf' => [ 'key' => 'receive', 'operator' => 'eq', 'value' => 'webhook' ] ];
	    return $f;
	}, \models\wf\WfSchedule::schema([ 'mode' => 'interval', 'every' => 5, 'every_unit' => 'minutes' ]));
	return array_merge([
	    [
		'key' => 'receive',
		'type' => 'select',
		'label' => 'Receive',
		'required' => true,
		'default' => 'webhook',
		'choices' => self::RECEIVE,
		'ui' => [ 'width' => 6 ],
	    ],
	    [
		'key' => 'credential',
		'type' => 'select',
		'label' => 'GitHub credential',
		'choices' => NodeAppGitHub::credentialChoices(),
		'help' => 'Polling reads with it (a webhook needs none)',
		'requiredIf' => [ 'if' => 'receive', 'in' => [ 'poll' ] ],
		'ui' => [ 'width' => 6 ],
	    ],
	    [
		'key' => 'url',
		'type' => 'text',
		'label' => 'Payload URL',
		'perm:update' => 'nobody',
		'help' => 'In GitHub: the repository\'s (or organization\'s) Settings > Webhooks > Add webhook, content type application/json',
		'ui' => [ 'placeholder' => 'Generating…', 'showIf' => $hook ],
	    ],
	    [
		'key' => 'secret',
		'type' => 'text',
		'label' => 'Secret',
		'perm:update' => 'nobody',
		'help' => 'The webhook\'s Secret: GitHub signs each delivery with it. Keep it private',
		'ui' => [ 'showIf' => $hook ],
	    ],
	    [
		'key' => 'events',
		'type' => 'select',
		'label' => 'Events',
		'multiple' => true,
		'default' => [ 'pull_request' ],
		'choices' => self::EVENTS,
		'help' => 'Choose the same in GitHub (Let me select individual events)',
		'ui' => [ 'size' => 5, 'showIf' => $hook ],
	    ],
	    [
		'key' => 'actions',
		'type' => 'text',
		'label' => 'Actions',
		'default' => '',
		'help' => 'Optional: only these, e.g. opened, closed, published, completed',
		'ui' => [ 'width' => 6, 'showIf' => $hook ],
	    ],
	    [
		'key' => 'branches',
		'type' => 'text',
		'label' => 'Branches',
		'default' => '',
		'help' => 'Optional: pushes and runs of these, e.g. main, release/*',
		'ui' => [ 'width' => 6, 'showIf' => $hook ],
	    ],
	    [
		'key' => 'repository',
		'type' => 'text',
		'label' => 'Repository',
		'default' => '',
		'help' => 'owner/repository. Polling: the one checked (empty: the credential\'s default); a webhook: optional, only its events',
		'ui' => [ 'width' => 6 ],
	    ],
	    [
		'key' => 'polls',
		'type' => 'select',
		'label' => 'Start on',
		'multiple' => true,
		'default' => [ 'failed' ],
		'choices' => self::POLLS,
		'ui' => [ 'size' => 4, 'showIf' => $poll ],
	    ],
	    [
		'key' => 'variable',
		'type' => 'text',
		'label' => 'Event variable',
		'required' => true,
		'default' => 'github',
		'help' => 'e.g. {{ github.event }}, {{ github.action }}, {{ github.title }}, {{ github.url }}',
		'validate' => [ [ 'identifier', 'skipEmpty' => true, 'message' => 'Use letters, digits or underscore, not starting with a digit' ] ],
		'ui' => [ 'width' => 6, 'variableName' => 'write' ],
	    ],
	], $schedule);
    }

    public static function matches(array $cfg, \DateTimeInterface $t) {
	return ($cfg['receive'] ?? 'webhook') === 'poll' && \models\wf\WfSchedule::matches($cfg, $t);
    }

    public function validates($options = []) {
	$valid = parent::validates($options);
	$cfg = is_array($this->config) ? $this->config : [];
	$poll = ($cfg['receive'] ?? 'webhook') === 'poll';
	if ($poll) {
	    $error = \models\wf\WfSchedule::validate($cfg);
	    if ($error !== null) {
		$this->errors('config', $error);
		$valid = false;
	    }
	    if (!self::chosen($cfg, 'polls', self::POLLS)) {
		$this->errors('config', 'GitHub event: choose what to start on');
		$valid = false;
	    }
	} else {
	    if (!self::chosen($cfg, 'events', self::EVENTS)) {
		$this->errors('config', 'GitHub event: choose at least one event');
		$valid = false;
	    }
	    if (strlen((string)($cfg['secret'] ?? '')) < 20) {
		$this->errors('config', 'GitHub event: the step has no secret (add the step again)');
		$valid = false;
	    }
	}
	$id = (string)$this->cfg('credential', '');
	$cred = $id !== '' && $this->workflow ? \models\Credential::findFor($this->workflow, $id, 'github') : null;
	if ($id !== '' && !$cred) {
	    $this->errors('config', 'GitHub event: choose a GitHub credential of this project');
	    $valid = false;
	}
	$repo = trim((string)($cfg['repository'] ?? ''));
	if ($repo !== '' && !preg_match(\util\vendor\GitHub::REPO, $repo)) {
	    $this->errors('config', 'GitHub event: Repository: owner/repository');
	    $valid = false;
	} elseif ($poll && $repo === '' && $cred && trim((string)$cred->repository) === '') {
	    $this->errors('config', 'GitHub event: name the repository (the credential has no default)');
	    $valid = false;
	}
	return \models\TriggerToken::checkToken($this, 'hex') && $valid;
    }

    public static function outputVariables($config) {
	$name = is_array($config) ? (string)($config['variable'] ?? 'github') : 'github';
	if (!\util\Validator::isIdentifier($name))
	    return [];
	return [ $name => [ 'type' => 'object', 'info' => 'the event', 'fields' => [ 'event' => [ 'type' => 'string', 'info' => 'push, pull_request, issues, workflow_run, …' ],
	    'action' => [ 'type' => 'string', 'info' => 'opened, closed, completed, published, … (polling: opened, merged, completed, published)' ], 'repository' => [ 'type' => 'string' ],
	    'sender' => [ 'type' => 'string' ], 'number' => [ 'type' => 'number' ], 'title' => [ 'type' => 'string' ], 'url' => [ 'type' => 'string' ], 'state' => [ 'type' => 'string' ],
	    'branch' => [ 'type' => 'string' ], 'tag' => [ 'type' => 'string' ], 'sha' => [ 'type' => 'string' ], 'merged' => [ 'type' => 'boolean' ], 'conclusion' => [ 'type' => 'string' ],
	    'commits' => [ 'type' => 'number', 'info' => 'a push\'s' ], 'data' => [ 'type' => 'object', 'info' => 'as GitHub sent it' ] ] ] ];
    }

    //the choices made of setting $key (known ones)
    public static function chosen(array $cfg, $key, array $choices) {
	return array_values(array_intersect(array_map('strval', (array)($cfg[$key] ?? [])), array_keys($choices)));
    }

    //texts of a setting separated by commas / spaces
    private static function list($v) {
	return array_values(array_filter(array_map('trim', preg_split('/[\s,]+/', (string)$v)), fn($x) => $x !== ''));
    }

    //the event's value of webhook event $event's payload $p
    public static function valueOf($event, array $p, $delivery = '') {
	$item = $p['pull_request'] ?? $p['issue'] ?? $p['release'] ?? $p['workflow_run'] ?? $p['comment'] ?? null;
	$ref = (string)($p['ref'] ?? $p['workflow_run']['head_branch'] ?? '');
	$refType = (string)($p['ref_type'] ?? '');
	$branch = str_starts_with($ref, 'refs/heads/') ? substr($ref, 11) : ($refType === 'branch' || ($event === 'workflow_run' && $ref !== '') ? $ref : '');
	$tag = str_starts_with($ref, 'refs/tags/') ? substr($ref, 10) : ($refType === 'tag' ? $ref : (string)($p['release']['tag_name'] ?? ''));
	$pr = $p['pull_request'] ?? null;
	$issue = $p['issue'] ?? null;
	return array_filter([
	    'event' => (string)$event,
	    'action' => (string)($p['action'] ?? ''),
	    'repository' => (string)($p['repository']['full_name'] ?? ''),
	    'sender' => (string)($p['sender']['login'] ?? ''),
	    'number' => isset($pr['number']) ? (int)$pr['number'] : (isset($issue['number']) ? (int)$issue['number'] : null),
	    'title' => (string)($pr['title'] ?? $issue['title'] ?? $p['release']['name'] ?? $p['workflow_run']['name'] ?? $p['head_commit']['message'] ?? ''),
	    'url' => (string)($p['comment']['html_url'] ?? $item['html_url'] ?? $p['compare'] ?? ''),
	    'state' => isset($pr) ? (!empty($pr['merged']) ? 'merged' : (string)($pr['state'] ?? '')) : (string)($issue['state'] ?? $p['review']['state'] ?? ''),
	    'user' => (string)($pr['user']['login'] ?? $issue['user']['login'] ?? ''),
	    'body' => (string)($p['comment']['body'] ?? $p['review']['body'] ?? $pr['body'] ?? $issue['body'] ?? $p['release']['body'] ?? ''),
	    'ref' => $ref,
	    'branch' => $branch,
	    'tag' => $tag,
	    'sha' => (string)($p['after'] ?? $pr['head']['sha'] ?? $p['workflow_run']['head_sha'] ?? ''),
	    'head' => (string)($pr['head']['ref'] ?? ''),
	    'base' => (string)($pr['base']['ref'] ?? ''),
	    'merged' => isset($pr) ? !empty($pr['merged']) : null,
	    'status' => (string)($p['workflow_run']['status'] ?? ''),
	    'conclusion' => (string)($p['workflow_run']['conclusion'] ?? ''),
	    'workflow' => (string)($p['workflow_run']['name'] ?? $p['workflow']['name'] ?? ''),
	    'commits' => isset($p['commits']) ? count((array)$p['commits']) : null,
	    'delivery' => (string)$delivery,
	], fn($v) => $v !== null && $v !== '');
    }

    //whether the webhook event (its value) is one the step starts on: the events, actions, branches, repository chosen
    public static function wanted(array $cfg, array $v) {
	if (!in_array($v['event'], self::chosen($cfg, 'events', self::EVENTS), true))
	    return false;
	$actions = self::list($cfg['actions'] ?? '');
	//events without actions (push, create, delete) are not filtered by them
	if ($actions && isset($v['action']) && !in_array($v['action'], $actions, true) && !($v['action'] === 'closed' && !empty($v['merged']) && in_array('merged', $actions, true)))
	    return false;
	$branches = self::list($cfg['branches'] ?? '');
	if ($branches && in_array($v['event'], [ 'push', 'workflow_run', 'create', 'delete' ], true)) {
	    $b = (string)($v['branch'] ?? '');
	    if ($b === '' || !array_filter($branches, fn($p) => fnmatch($p, $b)))
		return false;
	}
	$repo = trim((string)($cfg['repository'] ?? ''));
	return $repo === '' || strcasecmp($repo, (string)($v['repository'] ?? '')) === 0;
    }

    //runs of event values $values, queued unless $start says otherwise
    public static function startRuns($wf, array $node, array $values, ?callable $start = null) {
	$cfg = (array)($node['config'] ?? []);
	$name = \util\Validator::isIdentifier((string)($cfg['variable'] ?? '')) ? (string)$cfg['variable'] : 'github';
	$started = [];
	foreach($values as $v) {
	    $exe = \models\wf\WfExecution::startFor($wf, 'github', [ 'received_at' => new \MongoDB\BSON\UTCDateTime(), 'vars' => [ $name => $v ] ], (string)$node['wfid']);
	    $exe->log($node['wfid'], "GitHub {$v['event']}" . (isset($v['action']) ? " {$v['action']}" : '') . (isset($v['repository']) ? " in {$v['repository']}" : '')
		. (isset($v['number']) ? " #{$v['number']}" : '') . (isset($v['branch']) ? " on {$v['branch']}" : ''));
	    ($start ?? fn($e) => $e->queue())($exe);
	    $started[] = $exe;
	}
	return $started;
    }

    //whether delivery $id was taken by the step already (GitHub's redelivery); noted when not
    public static function seenDelivery($wf, $nodeId, $id) {
	if ((string)$id === '')
	    return false;
	try {
	    return \V::app()->om->updateOne('TriggerState', [ 'wf' => $wf->_id, 'node' => (string)$nodeId, 'aid' => $wf->aid, 'data.deliveries' => [ '$ne' => (string)$id ] ],
		[ '$push' => [ 'data.deliveries' => [ '$each' => [ (string)$id ], '$slice' => -self::MAX_DELIVERIES ] ], '$setOnInsert' => [ 'project' => $wf->project, 'locked_until' => null,
		'created_at' => new \MongoDB\BSON\UTCDateTime() ] ], [ 'upsert' => true ]) === 0;
	} catch (\MongoDB\Driver\Exception\ServerException $e) {
	    if ($e->getCode() == 11000)
		return true;
	    throw $e;
	}
    }

    // ------------------------------------------------------------------------------------
    //polling

    public static function runScheduled($wf, array $node, $at) {
	if (($node['config']['receive'] ?? 'webhook') !== 'poll')
	    return [];
	$state = \models\TriggerState::acquire($wf, $node['wfid']);
	if (!$state) {
	    \V::app()->log->info("GitHub event: workflow {$wf->_id}, step {$node['wfid']}: the previous check is still running, skipped");
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

    //one check: [ the new state data { since, seen: [ keys ] }, the executions started ]; $start: what is done with a run (default: queued; the check suite saves it)
    public static function check($wf, array $node, array $data, ?callable $start = null) {
	$cfg = (array)($node['config'] ?? []);
	$now = time();
	if (empty($data['since']))
	    return [ [ 'since' => gmdate('Y-m-d\TH:i:s\Z', $now), 'seen' => [] ], [] ];
	if (array_filter($wf->validateFlow(), fn($i) => $i['level'] == 'error'))
	    throw new \Exception("workflow `{$wf->name}` has errors and cannot run");
	$cred = \models\Credential::findFor($wf, (string)($cfg['credential'] ?? ''), 'github');
	if (!$cred)
	    throw new \Exception('GitHub event: the credential is no longer available');
	$g = $cred->client($wf->aid, self::TIMEOUT);
	$repo = $g->repo($cfg['repository'] ?? '');
	$since = (string)$data['since'];
	$seen = array_map('strval', (array)($data['seen'] ?? []));
	$after = fn($t) => (string)$t !== '' && strcmp(gmdate('Y-m-d\TH:i:s\Z', strtotime((string)$t)), $since) > 0;
	$polls = self::chosen($cfg, 'polls', self::POLLS);
	$found = [];
	$repoValue = fn() => [ 'repository' => $repo ];
	if (in_array('issues', $polls, true))
	    foreach((array)$g->request('GET', "/repos/{$repo}/issues", [ 'state' => 'all', 'sort' => 'created', 'direction' => 'desc', 'since' => $since, 'per_page' => 50 ]) as $i)
		if (!isset($i['pull_request']) && $after($i['created_at'] ?? ''))
		    $found['i' . $i['id']] = [ $i['created_at'], [ 'event' => 'issues', 'action' => 'opened', 'number' => (int)$i['number'], 'title' => (string)$i['title'], 'url' => (string)$i['html_url'],
			'state' => (string)$i['state'], 'user' => (string)($i['user']['login'] ?? ''), 'sender' => (string)($i['user']['login'] ?? ''), 'body' => (string)($i['body'] ?? '') ] + $repoValue() + [ 'data' => $i ] ];
	if (in_array('pulls', $polls, true) || in_array('merged', $polls, true)) {
	    $list = (array)$g->request('GET', "/repos/{$repo}/pulls", [ 'state' => 'all', 'sort' => 'updated', 'direction' => 'desc', 'per_page' => 50 ]);
	    foreach($list as $p) {
		$pv = NodeAppGitHub::pullOf($p);
		$base = [ 'event' => 'pull_request', 'number' => $pv['number'], 'title' => $pv['title'], 'url' => $pv['url'], 'state' => $pv['state'], 'user' => $pv['user'], 'sender' => $pv['user'],
		    'head' => $pv['head'], 'base' => $pv['base'], 'sha' => $pv['sha'], 'merged' => $pv['merged'], 'body' => $pv['body'] ] + $repoValue();
		if (in_array('pulls', $polls, true) && $after($p['created_at'] ?? ''))
		    $found['p' . $p['id']] = [ $p['created_at'], [ 'action' => 'opened' ] + $base + [ 'data' => $p ] ];
		if (in_array('merged', $polls, true) && $after($p['merged_at'] ?? ''))
		    $found['m' . $p['id']] = [ $p['merged_at'], [ 'action' => 'merged' ] + $base + [ 'data' => $p ] ];
	    }
	}
	if (in_array('runs', $polls, true) || in_array('failed', $polls, true)) {
	    $d = $g->request('GET', "/repos/{$repo}/actions/runs", [ 'status' => 'completed', 'per_page' => 50 ]);
	    foreach((array)($d['workflow_runs'] ?? []) as $r) {
		$failed = in_array($r['conclusion'] ?? '', self::FAILED, true);
		if (!$after($r['updated_at'] ?? '') || !(in_array('runs', $polls, true) || ($failed && in_array('failed', $polls, true))))
		    continue;
		$found['r' . $r['id'] . '.' . ($r['run_attempt'] ?? 1)] = [ $r['updated_at'], [ 'event' => 'workflow_run', 'action' => 'completed', 'title' => (string)($r['display_title'] ?? $r['name'] ?? ''),
		    'workflow' => (string)($r['name'] ?? ''), 'url' => (string)($r['html_url'] ?? ''), 'branch' => (string)($r['head_branch'] ?? ''), 'sha' => (string)($r['head_sha'] ?? ''),
		    'status' => 'completed', 'conclusion' => (string)($r['conclusion'] ?? ''), 'sender' => (string)($r['actor']['login'] ?? '') ] + $repoValue() + [ 'data' => $r ] ];
	    }
	}
	if (in_array('releases', $polls, true))
	    foreach((array)$g->request('GET', "/repos/{$repo}/releases", [ 'per_page' => 30 ]) as $r)
		if (empty($r['draft']) && $after($r['published_at'] ?? ''))
		    $found['l' . $r['id']] = [ $r['published_at'], [ 'event' => 'release', 'action' => 'published', 'title' => (string)($r['name'] ?? $r['tag_name'] ?? ''), 'tag' => (string)($r['tag_name'] ?? ''),
			'url' => (string)($r['html_url'] ?? ''), 'sender' => (string)($r['author']['login'] ?? ''), 'body' => (string)($r['body'] ?? '') ] + $repoValue() + [ 'data' => $r ] ];

	$found = array_filter($found, fn($k) => !in_array((string)$k, $seen, true), ARRAY_FILTER_USE_KEY);
	uasort($found, fn($a, $b) => strcmp((string)$a[0], (string)$b[0]));
	$found = array_slice($found, 0, self::MAX_RUNS, true);
	$values = array_map(fn($f) => array_filter($f[1], fn($v) => $v !== null && $v !== '') + [ 'data' => null ], array_values($found));
	foreach($values as &$v)
	    $v['data'] = self::clean($v['data']);
	unset($v);
	$started = self::startRuns($wf, $node, $values, $start);
	$seen = array_slice(array_merge($seen, array_map('strval', array_keys($found))), -self::MAX_SEEN);
	//a minute back (GitHub's times are not this server's clock); `seen` keeps an item from starting two runs
	$next = gmdate('Y-m-d\TH:i:s\Z', $now - 60);
	return [ [ 'since' => strcmp($next, $since) > 0 ? $next : $since, 'seen' => $seen ], $started ];
    }

    //data as a variable value: keys a database field cannot have left out
    public static function clean($data, $depth = 0) {
	if (!is_array($data))
	    return $data;
	$out = [];
	foreach($data as $k => $v) {
	    if (is_string($k) && ($k === '' || str_contains($k, '.') || $k[0] === '$'))
		continue;
	    $out[$k] = is_array($v) ? ($depth >= 12 ? null : self::clean($v, $depth + 1)) : $v;
	}
	return $out;
    }
}
