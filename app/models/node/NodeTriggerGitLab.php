<?php

/*
    CLD: 2026-11-26T00:00:00+02:00: GitLab event - starts the workflow on what happens in GitLab. How events are noticed (setting Receive):
	webhook  a project's (or group's) webhook posts to the step's address /gitlab/<token> (controllers\GitlabController) with the step's secret token (X-Gitlab-Token): the
	         events chosen (by their object_kind), optionally only some actions (open, close, merge, …; a pipeline's: its status), branches (pushes, pipelines) and a project;
	         each delivery once (X-Gitlab-Event-UUID, Idempotency-Key)
	poll     the step checks a project on its schedule (models\wf\WfSchedule, every 5 minutes by default) with the credential: issues opened, merge requests opened or merged,
	         pipelines finished or failed, releases, since the last check (models\TriggerState: { since, seen }); the first check only notes the time
    The variable (default `gitlab`): { event, action, project, user, number, title, url, state, description, ref, branch, tag, sha, source, target, status, commits, delivery,
    data (the payload as GitLab sent it; polling: the item) }
*/

namespace models\node;

class NodeTriggerGitLab extends \models\wf\WfNode {
    public static $type = 'trigger';
    public static $name = 'gitlab';
    public static $label = 'GitLab event';
    public static $icon = 'bi-gitlab';
    public static $inputs = 0;
    public static $outputs = 1;
    public static $scheduled = true;

    const RECEIVE = [ 'webhook' => 'GitLab sends the events (a webhook)', 'poll' => 'Check a project on a schedule' ];
    const EVENTS = [ 'push' => 'Pushes', 'tag_push' => 'Tags pushed', 'merge_request' => 'Merge requests', 'issue' => 'Issues', 'note' => 'Comments', 'pipeline' => 'Pipelines',
	'build' => 'Jobs', 'release' => 'Releases', 'deployment' => 'Deployments' ];
    const POLLS = [ 'issues' => 'Issues opened', 'mrs' => 'Merge requests opened', 'merged' => 'Merge requests merged', 'pipelines' => 'Pipelines finished', 'failed' => 'Pipelines failed',
	'releases' => 'Releases' ];
    const FINISHED = [ 'success', 'failed', 'canceled' ];
    const MAX_RUNS = 50;
    const MAX_SEEN = 500;
    const MAX_DELIVERIES = 300;
    const TIMEOUT = 60;

    public static function tokenData(array $node) {
	return [];
    }

    public static function initConfig() {
	$token = \models\TriggerToken::newToken('hex');
	return [ 'token' => $token, 'url' => \V::app()->createAbsoluteUrl('/gitlab/' . $token), 'secret' => bin2hex(random_bytes(20)) ];
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
	    [ 'key' => 'receive', 'type' => 'select', 'label' => 'Receive', 'required' => true, 'default' => 'webhook', 'choices' => self::RECEIVE, 'ui' => [ 'width' => 6 ] ],
	    [ 'key' => 'credential', 'type' => 'select', 'label' => 'GitLab credential', 'choices' => NodeAppGitLab::credentialChoices(), 'help' => 'Polling reads with it (a webhook needs none)',
		'requiredIf' => [ 'if' => 'receive', 'in' => [ 'poll' ] ], 'ui' => [ 'width' => 6 ] ],
	    [ 'key' => 'url', 'type' => 'text', 'label' => 'URL', 'perm:update' => 'nobody', 'help' => 'In GitLab: the project\'s (or group\'s) Settings > Webhooks > Add new webhook',
		'ui' => [ 'placeholder' => 'Generating…', 'showIf' => $hook ] ],
	    [ 'key' => 'secret', 'type' => 'text', 'label' => 'Secret token', 'perm:update' => 'nobody', 'help' => 'The webhook\'s Secret token: GitLab sends it with each event. Keep it private',
		'ui' => [ 'showIf' => $hook ] ],
	    [ 'key' => 'events', 'type' => 'select', 'label' => 'Events', 'multiple' => true, 'default' => [ 'merge_request' ], 'choices' => self::EVENTS, 'help' => 'Tick the same triggers in GitLab',
		'ui' => [ 'size' => 5, 'showIf' => $hook ] ],
	    [ 'key' => 'actions', 'type' => 'text', 'label' => 'Actions', 'default' => '', 'help' => 'Optional: only these, e.g. open, merge, close (pipelines, jobs: their status, e.g. failed)',
		'ui' => [ 'width' => 6, 'showIf' => $hook ] ],
	    [ 'key' => 'branches', 'type' => 'text', 'label' => 'Branches', 'default' => '', 'help' => 'Optional: pushes and pipelines of these, e.g. main, release/*', 'ui' => [ 'width' => 6, 'showIf' => $hook ] ],
	    [ 'key' => 'project', 'type' => 'text', 'label' => 'Project', 'default' => '', 'help' => 'group/project. Polling: the one checked (empty: the credential\'s default); a webhook: optional, only its events',
		'ui' => [ 'width' => 6 ] ],
	    [ 'key' => 'polls', 'type' => 'select', 'label' => 'Start on', 'multiple' => true, 'default' => [ 'failed' ], 'choices' => self::POLLS, 'ui' => [ 'size' => 4, 'showIf' => $poll ] ],
	    [ 'key' => 'variable', 'type' => 'text', 'label' => 'Event variable', 'required' => true, 'default' => 'gitlab', 'help' => 'e.g. {{ gitlab.event }}, {{ gitlab.action }}, {{ gitlab.title }}, {{ gitlab.url }}',
		'validate' => [ [ 'identifier', 'skipEmpty' => true, 'message' => 'Use letters, digits or underscore, not starting with a digit' ] ], 'ui' => [ 'width' => 6, 'variableName' => 'write' ] ],
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
	    if (!NodeTriggerGitHub::chosen($cfg, 'polls', self::POLLS)) {
		$this->errors('config', 'GitLab event: choose what to start on');
		$valid = false;
	    }
	} else {
	    if (!NodeTriggerGitHub::chosen($cfg, 'events', self::EVENTS)) {
		$this->errors('config', 'GitLab event: choose at least one event');
		$valid = false;
	    }
	    if (strlen((string)($cfg['secret'] ?? '')) < 20) {
		$this->errors('config', 'GitLab event: the step has no secret token (add the step again)');
		$valid = false;
	    }
	}
	$id = (string)$this->cfg('credential', '');
	$cred = $id !== '' && $this->workflow ? \models\Credential::findFor($this->workflow, $id, 'gitlab') : null;
	if ($id !== '' && !$cred) {
	    $this->errors('config', 'GitLab event: choose a GitLab credential of this project');
	    $valid = false;
	}
	$project = trim((string)($cfg['project'] ?? ''));
	if ($project !== '' && !preg_match(\util\vendor\GitLab::PROJECT, $project) && !preg_match('/^\d{1,20}$/', $project)) {
	    $this->errors('config', 'GitLab event: Project: group/project');
	    $valid = false;
	} elseif ($poll && $project === '' && $cred && trim((string)$cred->defaultProject) === '') {
	    $this->errors('config', 'GitLab event: name the project (the credential has no default)');
	    $valid = false;
	}
	return \models\TriggerToken::checkToken($this, 'hex') && $valid;
    }

    public static function outputVariables($config) {
	$name = is_array($config) ? (string)($config['variable'] ?? 'gitlab') : 'gitlab';
	if (!\util\Validator::isIdentifier($name))
	    return [];
	return [ $name => [ 'type' => 'object', 'info' => 'the event', 'fields' => [ 'event' => [ 'type' => 'string', 'info' => 'push, merge_request, issue, pipeline, …' ],
	    'action' => [ 'type' => 'string', 'info' => 'open, close, merge, update, … (a pipeline\'s, a job\'s: its status; polling: opened, merged, finished, released)' ], 'project' => [ 'type' => 'string' ],
	    'user' => [ 'type' => 'string' ], 'number' => [ 'type' => 'number' ], 'title' => [ 'type' => 'string' ], 'url' => [ 'type' => 'string' ], 'state' => [ 'type' => 'string' ],
	    'branch' => [ 'type' => 'string' ], 'tag' => [ 'type' => 'string' ], 'sha' => [ 'type' => 'string' ], 'source' => [ 'type' => 'string' ], 'target' => [ 'type' => 'string' ],
	    'status' => [ 'type' => 'string' ], 'commits' => [ 'type' => 'number', 'info' => 'a push\'s' ], 'data' => [ 'type' => 'object', 'info' => 'as GitLab sent it' ] ] ] ];
    }

    private static function list($v) {
	return array_values(array_filter(array_map('trim', preg_split('/[\s,]+/', (string)$v)), fn($x) => $x !== ''));
    }

    //the event's value of webhook payload $p
    public static function valueOf(array $p, $delivery = '') {
	$kind = (string)($p['object_kind'] ?? $p['event_type'] ?? '');
	$a = (array)($p['object_attributes'] ?? []);
	$ref = (string)($p['ref'] ?? $a['ref'] ?? '');
	$branch = str_starts_with($ref, 'refs/heads/') ? substr($ref, 11) : (in_array($kind, [ 'pipeline', 'build' ], true) && empty($a['tag']) && empty($p['tag']) ? $ref : '');
	$tag = str_starts_with($ref, 'refs/tags/') ? substr($ref, 10) : ((!empty($a['tag']) || !empty($p['tag'])) ? $ref : (string)($p['tag'] ?? ''));
	$status = (string)($a['status'] ?? $p['build_status'] ?? $p['status'] ?? '');
	$action = (string)($a['action'] ?? (in_array($kind, [ 'pipeline', 'build', 'deployment' ], true) ? $status : ($p['action'] ?? '')));
	$noteOn = $kind === 'note' ? ($p['merge_request'] ?? $p['issue'] ?? null) : null;
	return array_filter([
	    'event' => $kind,
	    'action' => $action,
	    'project' => (string)($p['project']['path_with_namespace'] ?? $p['project_name'] ?? ''),
	    'user' => (string)($p['user']['username'] ?? $p['user_username'] ?? ''),
	    'number' => isset($a['iid']) ? (int)$a['iid'] : (isset($noteOn['iid']) ? (int)$noteOn['iid'] : null),
	    'title' => (string)($a['title'] ?? $noteOn['title'] ?? $p['name'] ?? $p['commits'][0]['title'] ?? $p['build_name'] ?? ''),
	    'url' => (string)($a['url'] ?? $p['url'] ?? $p['project']['web_url'] ?? ''),
	    'state' => (string)($a['state'] ?? ''),
	    'description' => (string)($a['description'] ?? $a['note'] ?? $p['description'] ?? ''),
	    'ref' => $ref,
	    'branch' => $branch,
	    'tag' => $tag,
	    'sha' => (string)($p['checkout_sha'] ?? $p['after'] ?? $a['sha'] ?? $a['last_commit']['id'] ?? $p['sha'] ?? ''),
	    'source' => (string)($a['source_branch'] ?? $a['source'] ?? ''),
	    'target' => (string)($a['target_branch'] ?? ''),
	    'status' => $status,
	    'commits' => isset($p['total_commits_count']) ? (int)$p['total_commits_count'] : null,
	    'delivery' => (string)$delivery,
	], fn($v) => $v !== null && $v !== '');
    }

    //whether the webhook event is one the step starts on
    public static function wanted(array $cfg, array $v) {
	if (!in_array($v['event'] ?? '', NodeTriggerGitHub::chosen($cfg, 'events', self::EVENTS), true))
	    return false;
	$actions = self::list($cfg['actions'] ?? '');
	if ($actions && isset($v['action']) && !in_array($v['action'], $actions, true))
	    return false;
	$branches = self::list($cfg['branches'] ?? '');
	if ($branches && in_array($v['event'], [ 'push', 'pipeline', 'build' ], true)) {
	    $b = (string)($v['branch'] ?? '');
	    if ($b === '' || !array_filter($branches, fn($p) => fnmatch($p, $b)))
		return false;
	}
	$project = trim((string)($cfg['project'] ?? ''));
	return $project === '' || strcasecmp($project, (string)($v['project'] ?? '')) === 0;
    }

    public static function startRuns($wf, array $node, array $values, ?callable $start = null) {
	$cfg = (array)($node['config'] ?? []);
	$name = \util\Validator::isIdentifier((string)($cfg['variable'] ?? '')) ? (string)$cfg['variable'] : 'gitlab';
	$started = [];
	foreach($values as $v) {
	    $exe = \models\wf\WfExecution::startFor($wf, 'gitlab', [ 'received_at' => new \MongoDB\BSON\UTCDateTime(), 'vars' => [ $name => $v ] ], (string)$node['wfid']);
	    $exe->log($node['wfid'], "GitLab {$v['event']}" . (isset($v['action']) ? " {$v['action']}" : '') . (isset($v['project']) ? " in {$v['project']}" : '')
		. (isset($v['number']) ? " #{$v['number']}" : '') . (isset($v['branch']) ? " on {$v['branch']}" : ''));
	    ($start ?? fn($e) => $e->queue())($exe);
	    $started[] = $exe;
	}
	return $started;
    }

    public static function seenDelivery($wf, $nodeId, $id) {
	return NodeTriggerGitHub::seenDelivery($wf, $nodeId, $id);
    }

    // ------------------------------------------------------------------------------------
    //polling

    public static function runScheduled($wf, array $node, $at) {
	if (($node['config']['receive'] ?? 'webhook') !== 'poll')
	    return [];
	$state = \models\TriggerState::acquire($wf, $node['wfid']);
	if (!$state) {
	    \V::app()->log->info("GitLab event: workflow {$wf->_id}, step {$node['wfid']}: the previous check is still running, skipped");
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
	$cred = \models\Credential::findFor($wf, (string)($cfg['credential'] ?? ''), 'gitlab');
	if (!$cred)
	    throw new \Exception('GitLab event: the credential is no longer available');
	$g = $cred->client($wf->aid, self::TIMEOUT);
	$project = $g->project($cfg['project'] ?? '');
	$path = rawurldecode($project);
	$since = (string)$data['since'];
	$seen = array_map('strval', (array)($data['seen'] ?? []));
	$after = fn($t) => (string)$t !== '' && strcmp(gmdate('Y-m-d\TH:i:s\Z', strtotime((string)$t)), $since) > 0;
	$polls = NodeTriggerGitHub::chosen($cfg, 'polls', self::POLLS);
	$found = [];
	if (in_array('issues', $polls, true))
	    foreach((array)$g->request('GET', "/projects/{$project}/issues", [ 'created_after' => $since, 'order_by' => 'created_at', 'sort' => 'desc', 'per_page' => 50 ]) as $i)
		if ($after($i['created_at'] ?? ''))
		    $found['i' . $i['id']] = [ $i['created_at'], [ 'event' => 'issue', 'action' => 'opened', 'number' => (int)$i['iid'], 'title' => (string)$i['title'], 'url' => (string)($i['web_url'] ?? ''),
			'state' => (string)$i['state'], 'user' => (string)($i['author']['username'] ?? ''), 'description' => (string)($i['description'] ?? ''), 'project' => $path, 'data' => $i ] ];
	if (in_array('mrs', $polls, true) || in_array('merged', $polls, true))
	    foreach((array)$g->request('GET', "/projects/{$project}/merge_requests", [ 'updated_after' => $since, 'order_by' => 'updated_at', 'sort' => 'desc', 'per_page' => 50 ]) as $m) {
		$base = [ 'event' => 'merge_request', 'number' => (int)$m['iid'], 'title' => (string)$m['title'], 'url' => (string)($m['web_url'] ?? ''), 'state' => (string)$m['state'],
		    'user' => (string)($m['author']['username'] ?? ''), 'source' => (string)($m['source_branch'] ?? ''), 'target' => (string)($m['target_branch'] ?? ''), 'sha' => (string)($m['sha'] ?? ''), 'project' => $path ];
		if (in_array('mrs', $polls, true) && $after($m['created_at'] ?? ''))
		    $found['o' . $m['id']] = [ $m['created_at'], [ 'action' => 'opened' ] + $base + [ 'data' => $m ] ];
		if (in_array('merged', $polls, true) && ($m['state'] ?? '') === 'merged' && $after($m['merged_at'] ?? ''))
		    $found['m' . $m['id']] = [ $m['merged_at'], [ 'action' => 'merged' ] + $base + [ 'data' => $m ] ];
	    }
	if (in_array('pipelines', $polls, true) || in_array('failed', $polls, true))
	    foreach((array)$g->request('GET', "/projects/{$project}/pipelines", [ 'updated_after' => $since, 'order_by' => 'updated_at', 'sort' => 'desc', 'per_page' => 50 ]) as $p) {
		$st = (string)($p['status'] ?? '');
		if (!in_array($st, self::FINISHED, true) || !$after($p['updated_at'] ?? '') || !(in_array('pipelines', $polls, true) || ($st === 'failed' && in_array('failed', $polls, true))))
		    continue;
		$found['p' . $p['id'] . '.' . $st] = [ $p['updated_at'], [ 'event' => 'pipeline', 'action' => $st, 'status' => $st, 'title' => "pipeline {$p['id']}", 'url' => (string)($p['web_url'] ?? ''),
		    'branch' => (string)($p['ref'] ?? ''), 'ref' => (string)($p['ref'] ?? ''), 'sha' => (string)($p['sha'] ?? ''), 'source' => (string)($p['source'] ?? ''), 'project' => $path, 'data' => $p ] ];
	    }
	if (in_array('releases', $polls, true))
	    foreach((array)$g->request('GET', "/projects/{$project}/releases", [ 'per_page' => 30 ]) as $r)
		if ($after($r['released_at'] ?? $r['created_at'] ?? '') && strcmp(gmdate('Y-m-d\TH:i:s\Z', strtotime((string)($r['released_at'] ?? ''))), gmdate('Y-m-d\TH:i:s\Z', $now)) <= 0)
		    $found['r' . ($r['tag_name'] ?? '')] = [ $r['released_at'], [ 'event' => 'release', 'action' => 'released', 'title' => (string)($r['name'] ?? $r['tag_name'] ?? ''), 'tag' => (string)($r['tag_name'] ?? ''),
			'url' => (string)($r['_links']['self'] ?? ''), 'user' => (string)($r['author']['username'] ?? ''), 'description' => (string)($r['description'] ?? ''), 'project' => $path, 'data' => $r ] ];

	$found = array_filter($found, fn($k) => !in_array((string)$k, $seen, true), ARRAY_FILTER_USE_KEY);
	uasort($found, fn($a, $b) => strcmp((string)$a[0], (string)$b[0]));
	$found = array_slice($found, 0, self::MAX_RUNS, true);
	$values = [];
	foreach($found as $f) {
	    $v = array_filter($f[1], fn($x) => $x !== null && $x !== '');
	    $v['data'] = NodeTriggerGitHub::clean($f[1]['data'] ?? null);
	    $values[] = $v;
	}
	$started = self::startRuns($wf, $node, $values, $start);
	$seen = array_slice(array_merge($seen, array_map('strval', array_keys($found))), -self::MAX_SEEN);
	$next = gmdate('Y-m-d\TH:i:s\Z', $now - 60);
	return [ [ 'since' => strcmp($next, $since) > 0 ? $next : $since, 'seen' => $seen ], $started ];
    }
}
