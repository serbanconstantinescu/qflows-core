<?php

/*
    PatchMon Patching - patch runs on PatchMon's hosts, signed in as the credential's PatchMon user (the web interface's API: PatchMon's patching module, a user with
    Manage patching; PatchMon does not document this API for integrations). Operations (setting `operation`):
	patch      a run on a host (POST patching/trigger { host_id, patch_type: patch_all | patch_package, package_names, dry_run, pending_approval, schedule_override }):
	           everything, or the packages listed (up to 100); When: as the host's patch policy says, now (schedule_override immediate), a dry run (packages only,
	           on Linux) or submitted for approval (pending_approval: an approver runs it later). Answer { message, patch_run_id, run_at, queued }
	approve    a run waiting for approval, or a dry run, approved (POST patching/runs/<id>/approve { schedule_override }): a new run { patch_run_id, validation_run_id, run_at }
	stop       a run stopped (POST patching/runs/<id>/stop)
	run        a run (GET patching/runs/<id>): status, output, packages affected, reboot_required, items
	runs       the runs, newest first (GET patching/runs ?host_id ?status, 200 per page)
	active     the runs not finished (GET patching/runs/active)
	summary    the counts of runs by status, the recent and active runs (GET patching/dashboard)
	preview    when a run of the host would start, by its policy (GET patching/preview-run?host_id)
    Patch and approve: Wait until it finishes reads the run every POLL seconds until it ends (completed, failed, cancelled, timed out, agent disconnected; a dry run: validated)
    or the step's timeout is near: the result is then the run, with `ok` (completed or validated) and `finished`. Host: its id, or its host name / friendly name; Run: its id
    (or a result with patch_run_id / id)
*/

namespace plugins\ITOps\models\node;

class NodeAppPmPatching extends NodeAppPatchMon {
    public static $name = 'pm-patching';
    public static $label = 'PatchMon Patching';
    public static $icon = 'bi-arrow-repeat';
    public static $defaultOperation = 'patch';
    public static $defaultVariable = 'patch';

    const OPERATIONS = [
	'patch' => 'Patch a host',
	'approve' => 'Approve a run',
	'stop' => 'Stop a run',
	'run' => 'Get a run',
	'runs' => 'List runs',
	'active' => 'List active runs',
	'summary' => 'Get the patching summary',
	'preview' => 'When would a run start',
    ];
    //the operations that change something; the others only read (an AI step's tools: the read ones ticked by default, util\AgentTools)
    const WRITES = [ 'patch', 'approve', 'stop' ];
    const LISTS = [ 'runs', 'active' ];
    const WHAT = [ 'all' => 'All updates', 'packages' => 'These packages' ];
    const WHEN = [ 'policy' => 'As the host\'s patch policy says', 'immediate' => 'Now', 'dry' => 'Dry run (packages, Linux)', 'approval' => 'Submit for approval' ];
    const SCHEDULE = [ 'policy' => 'As the host\'s patch policy says', 'immediate' => 'Now' ];
    const STATUSES = [ '' => 'Any', 'scheduled' => 'Scheduled', 'queued' => 'Queued', 'running' => 'Running', 'completed' => 'Completed', 'failed' => 'Failed',
	'cancelled' => 'Cancelled', 'timed_out' => 'Timed out', 'agent_disconnected' => 'Agent disconnected', 'pending_validation' => 'Dry run in progress',
	'validated' => 'Dry run done', 'pending_approval' => 'Waiting for approval' ];
    //a run that has ended (PatchMon's isTerminalPatchStatus); ok: it did what it was asked
    const ENDED = [ 'completed', 'failed', 'cancelled', 'timed_out', 'agent_disconnected', 'validated', 'dry_run_completed' ];
    const OK = [ 'completed', 'validated', 'dry_run_completed' ];
    const MAX_PACKAGES = 100;
    const PAGE = 200;

    //seconds between two reads of a run being waited for (the check suite shortens it)
    public static $poll = 10;

    protected static function operationFields() {
	return [
	    //List runs: optional
	    self::field('host', 'Host', [ 'patch', 'preview', 'runs' ], [ 'requiredIf' => [ 'if' => 'operation', 'in' => [ 'patch', 'preview' ] ],
		'help' => 'Its id, host name or friendly name (List runs: empty for every host)', 'ui' => [ 'expression' => true, 'width' => 6 ] ]),
	    self::field('what', 'Patch', [ 'patch' ], [ 'type' => 'select', 'default' => 'all', 'choices' => self::WHAT, 'ui' => [ 'width' => 6 ] ]),
	    self::field('packages', 'Packages', [ 'patch' ], [ 'type' => 'textarea', 'help' => 'With Patch: These packages. One per line or separated by commas, or a list (e.g. a '
		. 'Packages result\'s items); up to ' . self::MAX_PACKAGES, 'ui' => [ 'rows' => 3, 'expression' => true ] ]),
	    self::field('when', 'When', [ 'patch' ], [ 'type' => 'select', 'default' => 'policy', 'choices' => self::WHEN, 'ui' => [ 'width' => 6 ] ]),
	    self::field('run', 'Run', [ 'approve', 'stop', 'run' ], [ 'required' => true, 'help' => 'The run\'s id, e.g. {{ patch.patch_run_id }}', 'ui' => [ 'expression' => true, 'width' => 6 ] ]),
	    self::field('schedule', 'When', [ 'approve' ], [ 'type' => 'select', 'default' => 'policy', 'choices' => self::SCHEDULE, 'ui' => [ 'width' => 6 ] ]),
	    self::field('wait', 'Wait until it finishes', [ 'patch', 'approve' ], [ 'type' => 'switch', 'default' => false,
		'help' => 'The result is then the run; give the step a timeout long enough', 'ui' => [ 'width' => 6 ] ]),
	    self::field('status', 'Status', [ 'runs' ], [ 'type' => 'select', 'choices' => self::STATUSES, 'ui' => [ 'width' => 6 ] ]),
	];
    }

    //a run's id: a UUID, or a result holding patch_run_id / id
    public static function runId($value) {
	if (is_array($value))
	    $value = $value['patch_run_id'] ?? $value['id'] ?? '';
	$v = trim(\util\Expression::toText($value));
	if (!preg_match(\plugins\ITOps\util\PatchMon::UUID, $v))
	    throw new \RuntimeException('Run: not a patch run id: `' . mb_substr($v, 0, 80) . '`');
	return strtolower($v);
    }

    //a change through the web API (Other parameters in the body)
    protected function change($path, array $body = []) {
	return $this->pm->user('POST', $path, [], array_replace_recursive($body, $this->extraParams()));
    }

    //the run, read until it ends or the timeout is near: [ run + { ok, finished }, summary ]
    protected function waitFor($runId) {
	$deadline = microtime(true) + max(1, min(self::MAX_TIMEOUT, (int)$this->cfg('timeout', 60))) - 5;
	while(true) {
	    $run = (array)$this->pm->user('GET', "/patching/runs/{$runId}");
	    $status = (string)($run['status'] ?? '');
	    $finished = in_array($status, self::ENDED, true);
	    if ($finished || microtime(true) + self::$poll > $deadline)
		break;
	    sleep(self::$poll);
	}
	$run += [ 'ok' => in_array($status, self::OK, true), 'finished' => $finished ];
	$summary = "run {$runId}: {$status}" . ($finished ? '' : ' (not finished when the wait ended)') . (!empty($run['error_message']) ? ': ' . mb_substr((string)$run['error_message'], 0, 200) : '');
	return [ $run, $summary ];
    }

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	switch($operation) {
	    case 'patch':
		$id = $this->pm->hostId($value('host'));
		$what = (string)$this->cfg('what', 'all') === 'packages' ? 'packages' : 'all';
		$when = isset(self::WHEN[(string)$this->cfg('when', 'policy')]) ? (string)$this->cfg('when', 'policy') : 'policy';
		$body = [ 'host_id' => $id, 'patch_type' => $what === 'packages' ? 'patch_package' : 'patch_all' ];
		if ($what === 'packages') {
		    $names = self::names($value('packages'));
		    if (!$names)
			throw new \RuntimeException('Packages: none given');
		    if (count($names) > self::MAX_PACKAGES)
			throw new \RuntimeException('Packages: at most ' . self::MAX_PACKAGES . ' in one run (' . count($names) . ' given)');
		    $body['package_names'] = $names;
		}
		if ($when === 'dry' && $what !== 'packages')
		    throw new \RuntimeException('a dry run patches packages: choose "These packages"');
		$body += match($when) { 'immediate' => [ 'schedule_override' => 'immediate' ], 'dry' => [ 'dry_run' => true ], 'approval' => [ 'pending_approval' => true ], default => [] };
		$r = (array)$this->change('/patching/trigger', $body);
		$runId = (string)($r['patch_run_id'] ?? '');
		$label = $what === 'packages' ? \util\Format::plural(count($body['package_names']), 'package') : 'all updates';
		if ($this->cfg('wait', false) && $when !== 'approval' && $runId !== '') {
		    [ $run, $summary ] = $this->waitFor($runId);
		    return [ $run, "{$label} on host {$id}: {$summary}" ];
		}
		return [ $r, "{$label} on host {$id}: " . ($r['message'] ?? 'requested') . ($runId !== '' ? " (run {$runId})" : '') ];
	    case 'approve':
		$runId = self::runId($value('run'));
		$r = (array)$this->change("/patching/runs/{$runId}/approve", (string)$this->cfg('schedule', 'policy') === 'immediate' ? [ 'schedule_override' => 'immediate' ] : []);
		$new = (string)($r['patch_run_id'] ?? '');
		if ($this->cfg('wait', false) && $new !== '')
		    return $this->waitFor($new);
		return [ $r, "run {$runId} approved: " . ($r['message'] ?? '') . ($new !== '' ? " (run {$new})" : '') ];
	    case 'stop':
		$runId = self::runId($value('run'));
		return [ $this->change("/patching/runs/{$runId}/stop"), "run {$runId} stopped" ];
	    case 'run':
		$runId = self::runId($value('run'));
		$run = (array)$this->pm->user('GET', "/patching/runs/{$runId}", $this->extraParams());
		return [ $run, "run {$runId}: " . ($run['status'] ?? '?') ];
	    case 'runs':
		$max = max(1, min(self::MAX_ITEMS, (int)$this->cfg('maxItems', 1000)));
		$status = isset(self::STATUSES[(string)$this->cfg('status', '')]) ? (string)$this->cfg('status', '') : '';
		$query = array_replace([ 'host_id' => $text('host') !== '' ? $this->pm->hostId($value('host')) : null, 'status' => $status ?: null ], $this->extraParams());
		$rows = [];
		$total = null;
		for($offset = 0; count($rows) <= $max; $offset += self::PAGE) {
		    $r = (array)$this->pm->user('GET', '/patching/runs', $query + [ 'limit' => self::PAGE, 'offset' => $offset ]);
		    $page = (array)($r['runs'] ?? []);
		    $total = (int)($r['pagination']['total'] ?? count($page));
		    $rows = array_merge($rows, $page);
		    if (count($page) < self::PAGE || count($rows) >= $total)
			break;
		}
		return $this->listResult($rows, 'run', $total);
	    case 'active':
		return $this->listResult((array)($this->pm->user('GET', '/patching/runs/active', $this->extraParams())['runs'] ?? []), 'active run');
	    case 'summary':
		$r = (array)$this->pm->user('GET', '/patching/dashboard', $this->extraParams());
		$s = (array)($r['summary'] ?? []);
		return [ $r, sprintf('%d runs: %d running, %d queued, %d failed', (int)($s['total_runs'] ?? 0), (int)($s['running'] ?? 0), (int)($s['queued'] ?? 0), (int)($s['failed'] ?? 0)) ];
	    case 'preview':
		$id = $this->pm->hostId($value('host'));
		$r = (array)$this->pm->user('GET', '/patching/preview-run', [ 'host_id' => $id ]);
		return [ $r, ($r['host_name'] ?? $id) . ': ' . ($r['run_at_iso'] ?? '?') . ' (' . ($r['policy_name'] ?? '') . ')' ];
	}
	throw new \RuntimeException("unknown operation `{$operation}`");
    }
}
