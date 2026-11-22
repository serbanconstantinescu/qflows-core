<?php

/*
    CLD: 2026-11-22T12:00:00+02:00: Log watch - starts the workflow with the new lines of log files on a server (through an SSH credential of the project): on its schedule
    (models\wf\WfSchedule, every minute by default; util\Scheduler runs runScheduled) it reads the files from where the last check stopped (util\LogFiles: only under the
    credential's log folders; rotation, truncation, whole lines, a filter). Where it stopped is kept in models\TriggerState, locked during a check: { position }:
	the first check only notes where the files end (nothing started); later checks start runs for the lines written since
	at most MAX_LINES lines a check (more: the next check goes on from there)
	runs: one per check with lines ({ lines, count, more, notes }), or one per line ({ file, text }, at most MAX_RUNS a check: the others the next check)
    Run in the designer: one run with the last lines of the files; the position is not changed
*/

namespace models\node;

class NodeTriggerLog extends \models\wf\WfNode {
    //static data/structure
    public static $type = 'trigger';
    public static $name = 'log';
    public static $label = 'Log watch';
    public static $icon = 'bi-file-earmark-text';
    public static $inputs = 0;
    public static $outputs = 1;
    public static $scheduled = true;

    const RUNS = [ 'batch' => 'One run per check with lines', 'each' => 'One run per line' ];
    const MAX_LINES = 5000;
    const MAX_RUNS = 100;
    const TRY_LINES = 20;
    const TIMEOUT = 120;

    public static function configSchema() {
	$choices = [];
	if (\V::app()->config('app.mode') == 'web' && !\V::app()->user->isGuest && !empty(\V::app()->user->crtProject))
	    foreach(\models\Credential::choicesFor('ssh', \V::app()->config('rt.account')->_id ?? null, \V::app()->user->crtProject) as $c)
		$choices[$c['value']] = $c['label'];
	return array_merge([
	    [
		'key' => 'credential',
		'type' => 'select',
		'label' => 'SSH credential',
		'choices' => $choices,
		'help' => 'The server; only files under its log folders are read',
		'required' => true,
	    ],
	    [
		'key' => 'paths',
		'type' => 'textarea',
		'label' => 'Log files',
		'required' => true,
		'default' => '/var/log/messages',
		'help' => 'One path per line, shell patterns allowed (/var/log/nginx/*.log); compressed files are left out',
		'ui' => [ 'rows' => 2, 'code' => true ],
	    ],
	    [
		'key' => 'filter',
		'type' => 'text',
		'label' => 'Only lines matching',
		'default' => '',
		'help' => 'A regular expression, e.g. (?i)error|warn|fail (empty: every line)',
		'ui' => [ 'width' => 6 ],
	    ],
	    [
		'key' => 'runs',
		'type' => 'select',
		'label' => 'Runs',
		'required' => true,
		'default' => 'batch',
		'choices' => self::RUNS,
		'ui' => [ 'width' => 6 ],
	    ],
	    [
		'key' => 'sudo',
		'type' => 'switch',
		'label' => 'Read with sudo',
		'default' => false,
		'help' => 'Off: the user reads them (on most systems: in group adm); on: sudo -n readlink, stat, find, tail need a NOPASSWD rule',
		'ui' => [ 'width' => 6, 'newRow' => true ],
	    ],
	    [
		'key' => 'variable',
		'type' => 'text',
		'label' => 'Log variable',
		'required' => true,
		'default' => 'log',
		'help' => 'e.g. {{ log.lines }}, {{ log.count }}; one per line: {{ log.text }}',
		'validate' => [ [ 'identifier', 'skipEmpty' => true, 'message' => 'Use letters, digits or underscore, not starting with a digit' ] ],
		'ui' => [ 'width' => 6, 'variableName' => 'write' ],
	    ],
	], \models\wf\WfSchedule::schema([ 'mode' => 'interval', 'every' => 1, 'every_unit' => 'minutes' ]));
    }

    public static function matches(array $cfg, \DateTimeInterface $t) {
	return \models\wf\WfSchedule::matches($cfg, $t);
    }

    public function validates($options = []) {
	$valid = parent::validates($options);
	$error = \models\wf\WfSchedule::validate(is_array($this->config) ? $this->config : []);
	if ($error !== null)
	    $valid = $this->configError($error);
	$id = (string)$this->cfg('credential', '');
	if ($id !== '' && (!$this->workflow || empty(\models\Credential::findFor($this->workflow, $id, 'ssh'))))
	    $valid = $this->configError('Log watch: choose an SSH credential of this project');
	try {
	    if (!$this->cfgEmpty('paths'))
		\util\LogFiles::paths($this->cfg('paths', ''));
	    \util\LogFiles::filter($this->cfg('filter', ''));
	} catch (\RuntimeException $e) {
	    $valid = $this->configError('Log watch: ' . $e->getMessage());
	}
	return $valid;
    }

    public static function outputVariables($config) {
	$name = is_array($config) ? (string)($config['variable'] ?? 'log') : 'log';
	if (!\util\Validator::isIdentifier($name))
	    return [];
	if (is_array($config) && ($config['runs'] ?? 'batch') === 'each')
	    return [ $name => [ 'type' => 'object', 'info' => 'the line: { file, text }', 'fields' => [ 'file' => [ 'type' => 'string' ], 'text' => [ 'type' => 'string' ] ] ] ];
	$info = NodeAppSsh::logInfo();
	unset($info['fields']['position']);
	$info['info'] = '{ lines: [ { file, text } ], count, more, notes }';
	return [ $name => $info ];
    }

    //the lines of the files: read from $position ([] with $from end or mark): util\LogFiles::read's result
    protected static function read($wf, array $cfg, array $position, $from, $lines) {
	$cred = \models\Credential::findFor($wf, (string)($cfg['credential'] ?? ''), 'ssh');
	if (!$cred)
	    throw new \Exception('Log watch: the credential is no longer available');
	return \util\LogFiles::read($cred->client($wf->aid), \util\LogFiles::paths($cfg['paths'] ?? ''), [ 'folders' => \util\LogFiles::folders($cred->logFolders ?? ''),
	    'filter' => \util\LogFiles::filter($cfg['filter'] ?? ''), 'from' => $from, 'position' => $position, 'lines' => $lines, 'sudo' => !empty($cfg['sudo']),
	    'timeout' => self::TIMEOUT ]);
    }

    public static function runScheduled($wf, array $node, $at) {
	$state = \models\TriggerState::acquire($wf, $node['wfid']);
	if (!$state) {
	    \V::app()->log->info("Log watch: workflow {$wf->_id}, step {$node['wfid']}: the previous check is still running, skipped");
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

    //one check: [ the new state data, the executions started ]
    public static function check($wf, array $node, array $data) {
	$cfg = (array)($node['config'] ?? []);
	if (!isset($data['position'])) {
	    $r = self::read($wf, $cfg, [], 'mark', 1);
	    return [ [ 'position' => $r['position'] ], [] ];
	}
	if (array_filter($wf->validateFlow(), fn($i) => $i['level'] == 'error'))
	    throw new \Exception("workflow `{$wf->name}` has errors and cannot run");
	$each = ($cfg['runs'] ?? 'batch') === 'each';
	$r = self::read($wf, $cfg, \util\LogFiles::position((array)$data['position']), 'position', $each ? self::MAX_RUNS : self::MAX_LINES);
	if ($r['notes'])
	    \V::app()->log->info("Log watch: workflow {$wf->_id}, step {$node['wfid']}: " . implode('; ', $r['notes']));
	$name = \util\Validator::isIdentifier((string)($cfg['variable'] ?? '')) ? (string)$cfg['variable'] : 'log';
	$values = !$r['lines'] ? [] : ($each ? $r['lines'] : [ [ 'lines' => $r['lines'], 'count' => $r['count'], 'more' => $r['more'], 'notes' => $r['notes'] ] ]);
	$started = [];
	foreach($values as $v) {
	    $exe = \models\wf\WfExecution::startFor($wf, 'log', [ 'started_at' => new \MongoDB\BSON\UTCDateTime(), 'vars' => [ $name => $v ] ], $node['wfid']);
	    $exe->log($node['wfid'], isset($v['lines']) ? 'Log watch: ' . \util\Format::plural($v['count'], 'line') . ($v['more'] ? ', more waiting' : '') : "Log watch: a line of {$v['file']}");
	    $exe->queue();
	    $started[] = $exe;
	}
	return [ [ 'position' => $r['position'] ], $started ];
    }

    //Run in the designer: one run with the last lines of the files (one: the last line); the trigger's state is not changed
    public static function startNow($wf, array $node) {
	$cfg = (array)($node['config'] ?? []);
	$each = ($cfg['runs'] ?? 'batch') === 'each';
	try {
	    $r = self::read($wf, $cfg, [], 'end', $each ? 1 : self::TRY_LINES);
	} catch (\Throwable $e) {
	    return [ 'executions' => [], 'message' => 'Log watch: ' . $e->getMessage() ];
	}
	if (!$r['lines'])
	    return [ 'executions' => [], 'message' => 'Log watch: no line' . ($r['notes'] ? ' (' . implode('; ', $r['notes']) . ')' : ' in the files') ];
	$name = \util\Validator::isIdentifier((string)($cfg['variable'] ?? '')) ? (string)$cfg['variable'] : 'log';
	$value = $each ? $r['lines'][count($r['lines']) - 1] : [ 'lines' => $r['lines'], 'count' => $r['count'], 'more' => false, 'notes' => $r['notes'] ];
	$exe = \models\wf\WfExecution::startFor($wf, 'log', [ 'started_at' => new \MongoDB\BSON\UTCDateTime(), 'vars' => [ $name => $value ] ], (string)$node['wfid']);
	$exe->save();
	return [ 'executions' => [ $exe ], 'message' => $each ? 'Run with the last line' : 'Run with the last ' . \util\Format::plural($r['count'], 'line') ];
    }
}
