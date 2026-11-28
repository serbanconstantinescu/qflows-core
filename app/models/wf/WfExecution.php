<?php

namespace models\wf;

/*
    past the trigger and beforeSave dropped the edges), node receive()/execute() contract (see WfNode),
    output ports followed per edge (If takes one branch),
    Merge joins, re-entrant nodes with a step limit, per-execution variables and log
*/

class WfExecution extends \sys\BaseModel {
    protected static $collection = 'executions';

    public static $indexes = [
	[ [ 'wf' => 1, '_id' => -1 ], [] ],
	[ [ 'state' => 1, 'wake_at' => 1 ], [] ],
	//the workflows a run started and waits for (Run a workflow; kill cancels them)
	[ [ 'input.caller.execution' => 1 ], [ 'sparse' => true ] ],
	//the runs of the conversations with agents, past the retention (models\agent\AgentConversation::prune)
	[ [ 'aid' => 1, 'input.conversation' => 1, 'end_at' => 1 ], [ 'sparse' => true ] ],
	//CLD: 2026-11-23T12:00:00Z: the executions of a daemon job (endLost, DaemonCommand::do_job)
	[ [ 'job' => 1 ], [ 'sparse' => true ] ],
    ];


    //node executions allowed per run() call: \data\Constants::RUN_MAX_STEPS
    //a running execution is saved after a pass at most this often (s), so its progress shows (run())
    const SAVE_EVERY = 2;
    //and before a pass whose steps are not the ones saved (the designer shows the step running, not the one before it during a long step), at most this many
    //times a second (a loop of quick steps)
    const SAVE_RATE = 5;
    //a web request waiting for the result of a queued execution (waitFor): at most WAIT_RESULT s, checked every WAIT_POLL ms
    const WAIT_RESULT = 30;
    const WAIT_POLL = 250;

    //log entries kept per execution
    const MAX_LOG = 500;
    //CLD: 2026-11-22T00:00:00Z: an execution too large to save (save()): its variables larger than this (bytes, as JSON) are replaced by a note
    const SAVE_VALUE_MAX = 1048576;
    //CLD: 2026-11-22T00:00:00Z: a variable or a step's field larger than this (bytes, as BSON) is stored in GridFS bucket VALUE_BUCKET, the document keeps a marker
    //(storageDocument, afterFind, storedValue)
    const STORE_VALUE_MIN = 262144;
    const VALUE_BUCKET = 'execution_values';

    //runtime objects, keyed by wfid
    public $__nodes = [];
    public $__edges = [];
    //a test case running (util\AgentEvalContext; test_run says it is one): never saved
    public $__test = null;

    //the values stored in GridFS (path => [ hash, id ]: an unchanged one is not stored again), those uploaded by the save going on, those to delete after it
    private $storedFiles = [];
    private $uploaded = [];
    private $garbage = [];

    //node executions in the current run()
    private $steps = 0;
    //step executions in the current pass of each active loop (loop wfid => count; reset when a pass starts)
    private $passSteps = [];

    public function _init() {
	parent::_init();

	//the step classes only (WfNode::nodeClasses; WfNode::getAll() builds every step's definition and config form: ~2 s for the Overview's executions)
	$nodeClasses = \models\wf\WfNode::nodeClasses();

	if (!is_array($this->tokens))
	    $this->tokens = [];
	if (!is_array($this->nodes))
	    $this->nodes = [];
	if (!is_array($this->variables))
	    $this->variables = [];
	if (!is_array($this->log))
	    $this->log = [];

	//build nodes and edges; the designer saves a list or a map, the key is always the element's wfid
	foreach($this->nodes as $data) {
	    $wfid = $data['wfid'] ?? null;
	    if (empty($wfid))
		continue;

	    if (!empty($data['isNode'])) {
		$objectName = $nodeClasses[$data['fqname'] ?? ''] ?? null;
		if (empty($objectName))
		    continue; //unknown node type: it will never receive a token (and fails the run if it does)
		$this->__nodes[$wfid] = \V::app()->om->create($objectName, $data);
	    } elseif (!empty($data['isEdge'])) {
		$this->__edges[$wfid] = \V::app()->om->create('WfEdge', $data);
	    }
	}

	//a new execution starts at its trigger node(s)
	if ($this->state == 'new' && empty($this->tokens)) {
	    $tokens = [];
	    foreach($this->__nodes as $wfid => $node) {
		if ($node::$type == 'trigger')
		    $tokens[] = [ 'node' => $wfid, 'edge' => null ];
	    }
	    $this->tokens = $tokens;
	}
    }

    //an execution of workflow $wf, ready to run (not saved): $trigger (manual, webhook, ...) and $input (what started it, e.g. the webhook request; top-level simple
    //values of $input['vars'] become the first variables); $startNode: wfid of the trigger step that started it; only that step gets a token (default: all trigger steps)
    public static function startFor($wf, $trigger = 'manual', $input = null, $startNode = null) {
	//none for a suspended account
	if (\models\Account::isSuspended($wf->aid))
	    throw new \ErrorException('the account is suspended');
	$data = [
	    'aid' => $wf->aid,
	    'project' => $wf->project,
	    'wf' => $wf->_id,
	    'nodes' => is_array($wf->nodes) ? array_values($wf->nodes) : [],
	    'trigger' => $trigger,
	    'input' => $input,
	    //the published version it runs (a Workflow::forVersion copy), null = the draft (designer Run, sys::run)
	    'version' => $wf->runVersion ? (int)$wf->runVersion->number : null,
	];
	//CLD: 2026-11-23T12:00:00Z: started by a daemon job: it records it from the start (also saved before run(), e.g. a Schedule run; see run())
	if (\models\Job::$current !== null)
	    $data['job'] = \models\Job::$current;
	if ($startNode !== null)
	    $data['tokens'] = [ [ 'node' => (string)$startNode, 'edge' => null ] ];
	$exe = \V::app()->om->create('WfExecution', $data);
	foreach((is_array($input['vars'] ?? null) ? $input['vars'] : []) as $name => $value)
	    $exe->setVariable($name, $value);
	return $exe;
    }

    //every way of deleting an execution comes here; the data of other features (e.g. its AI steps' traces) goes in execution:before-delete
    //the traces through the hook (sys\Boot), as workflow:before-delete: the execution does not call the agent models
    public function beforeDelete() {
	parent::beforeDelete();
	\V::app()->hooks->applyHook('execution:before-delete', [ $this ]);
	//CLD: 2026-11-22T00:00:00Z: its values stored in GridFS (also those of a find that left the variables out: stored_values)
	$ids = array_merge(array_column($this->storedFiles, 'id'), is_array($this->stored_values) ? $this->stored_values : []);
	self::deleteStored($ids);
    }

    //CLD: 2026-11-22T00:00:00Z: the values stored in GridFS (STORE_VALUE_MIN): read back, so the execution has them as saved
    public function afterFind() {
	parent::afterFind();
	if (is_array($this->_data['variables'] ?? null)) {
	    $vars = $this->_data['variables'];
	    foreach($vars as $name => $v) {
		if (self::isStored($v))
		    $vars[$name] = $this->loadStored(json_encode([ 'v', (string)$name ]), $v);
	    }
	    $this->loadValue('variables', $vars);
	}
	if (is_array($this->_data['nodes'] ?? null)) {
	    $nodes = $this->_data['nodes'];
	    foreach($nodes as $wfid => $n) {
		foreach((is_array($n) ? $n : []) as $k => $v) {
		    if (!self::isStored($v))
			continue;
		    $nodes[$wfid][$k] = $this->loadStored(json_encode([ 'n', (string)$wfid, (string)$k ]), $v);
		    //the step objects were made from the markers (_init)
		    if (isset($this->__nodes[$wfid]))
			$this->__nodes[$wfid]->loadValue($k, $nodes[$wfid][$k]);
		}
	    }
	    $this->loadValue('nodes', $nodes);
	}
    }

    //the document written: the variables and the steps' fields larger than STORE_VALUE_MIN stored in GridFS (storeValue), their ids in stored_values
    public function storageDocument(array $doc) {
	$doc = parent::storageDocument($doc);
	$paths = [];
	if (is_array($doc['variables'] ?? null)) {
	    foreach($doc['variables'] as $name => $v)
		$doc['variables'][$name] = $this->storeValue($paths[] = json_encode([ 'v', (string)$name ]), $v);
	    $this->dropStored('v', $paths);
	}
	if (is_array($doc['nodes'] ?? null)) {
	    foreach($doc['nodes'] as $wfid => $n) {
		foreach((is_array($n) ? $n : []) as $k => $v)
		    $doc['nodes'][$wfid][$k] = $this->storeValue($paths[] = json_encode([ 'n', (string)$wfid, (string)$k ]), $v);
	    }
	    $this->dropStored('n', $paths);
	}
	if (array_key_exists('variables', $doc) || array_key_exists('nodes', $doc))
	    $doc['stored_values'] = array_values(array_column($this->storedFiles, 'id'));
	return $doc;
    }

    //value $v at $path: as it is, or (larger than STORE_VALUE_MIN) uploaded, unless unchanged since stored, and a marker in its place
    private function storeValue($path, $v) {
	if (!is_array($v) && !(is_string($v) && strlen($v) > self::STORE_VALUE_MIN))
	    $bson = null;
	else
	    $bson = (string)\MongoDB\BSON\Document::fromPHP([ 'v' => $v ]);
	$have = $this->storedFiles[$path] ?? null;
	if ($bson === null || strlen($bson) < self::STORE_VALUE_MIN) {
	    if ($have) {
		$this->garbage[] = $have['id'];
		unset($this->storedFiles[$path]);
	    }
	    return $v;
	}
	$hash = md5($bson);
	if (!$have || $have['hash'] !== $hash) {
	    $id = new \MongoDB\BSON\ObjectId();
	    $stream = \V::app()->mongo->selectDatabase(static::_database())->selectGridFSBucket([ 'bucketName' => self::VALUE_BUCKET ])->openUploadStream($path, [ '_id' => $id, 'metadata' => [ 'execution' => $this->_id, 'aid' => $this->aid ] ]);
	    fwrite($stream, $bson);
	    fclose($stream);
	    $this->uploaded[] = $id;
	    if ($have)
		$this->garbage[] = $have['id'];
	    $this->storedFiles[$path] = [ 'hash' => $hash, 'id' => $id ];
	}
	return [ '__stored' => $this->storedFiles[$path]['id'], 'bytes' => strlen($bson), 'list' => is_array($v) ? array_is_list($v) : null, 'n' => is_array($v) ? count($v) : null ];
    }

    //the stored values under $kind ('v' variables, 'n' steps) no longer in $paths (a variable removed): deleted after the save
    private function dropStored($kind, $paths) {
	$keep = array_flip($paths);
	foreach($this->storedFiles as $path => $f) {
	    if (json_decode($path, true)[0] === $kind && !isset($keep[$path])) {
		$this->garbage[] = $f['id'];
		unset($this->storedFiles[$path]);
	    }
	}
    }

    //marker $m read back (its hash kept: not stored again unless it changes)
    private function loadStored($path, $m) {
	$bson = self::readStored($m['__stored']);
	if ($bson === null)
	    return '(the stored value is missing)';
	$this->storedFiles[$path] = [ 'hash' => md5($bson), 'id' => $m['__stored'] ];
	return \MongoDB\BSON\Document::fromBSON($bson)->toPHP(\sys\Mongo::TYPEMAP)['v'];
    }

    //a marker of a value stored in GridFS (storeValue)
    public static function isStored($v) {
	return is_array($v) && count($v) == 4 && ($v['__stored'] ?? null) instanceof \MongoDB\BSON\ObjectId;
    }

    //the value of marker $m (the Executions panel reads the markers itself: WorkflowController::variablesView)
    public static function storedValue($m) {
	$bson = self::readStored($m['__stored']);
	return $bson === null ? '(the stored value is missing)' : \MongoDB\BSON\Document::fromBSON($bson)->toPHP(\sys\Mongo::TYPEMAP)['v'];
    }

    private static function readStored($id) {
	try {
	    return stream_get_contents(\V::app()->mongo->selectDatabase(static::_database())->selectGridFSBucket([ 'bucketName' => self::VALUE_BUCKET ])->openDownloadStream($id));
	} catch (\MongoDB\GridFS\Exception\FileNotFoundException $e) {
	    return null;
	}
    }

    private static function deleteStored($ids) {
	foreach($ids as $id) {
	    try {
		\V::app()->mongo->selectDatabase(static::_database())->selectGridFSBucket([ 'bucketName' => self::VALUE_BUCKET ])->delete($id);
	    } catch (\MongoDB\GridFS\Exception\FileNotFoundException $e) {
	    }
	}
    }

    //a new execution (not saved) of workflow $wf's draft with the inputs of this finished one: the same trigger, input and first variables, so a change of the steps (e.g. a prompt)
    //can be compared. Not the raw message of an Email trigger (the end of a run removes it, this run must not take the original's) and not a wait on the workflow that called it (it must not resume
    //that one again). \ErrorException when it has not finished, or the trigger is another one now: the inputs would not fit
    public function again($wf) {
	if ($this->canRun())
	    throw new \ErrorException('the execution has not finished');
	//run again outside a test, its tools and steps would really act
	if (!empty($this->test_run))
	    throw new \ErrorException('this execution is a test: run the tests again');
	$trigger = self::triggerOf(is_array($this->nodes) ? $this->nodes : []);
	$now = self::triggerOf(is_array($wf->nodes) ? $wf->nodes : []);
	if ($now === null || ($now['fqname'] ?? '') !== ($trigger['fqname'] ?? ''))
	    throw new \ErrorException('the workflow\'s trigger is not the one this execution started from: its inputs would not fit');
	$input = is_array($this->input) ? $this->input : [];
	unset($input['raw_file']);
	if (isset($input['caller']) && is_array($input['caller']))
	    $input['caller']['wait'] = false;
	$exe = self::startFor($wf, (string)$this->trigger, $input, (string)$now['wfid']);
	$exe->rerun_of = (string)$this->_id;
	return $exe;
    }

    //the trigger step among $nodes (as stored: elements with wfid, fqname); null: none
    private static function triggerOf(array $nodes) {
	$classes = \models\wf\WfNode::nodeClasses();
	foreach($nodes as $n) {
	    $name = $classes[$n['fqname'] ?? ''] ?? null;
	    if (!empty($n['isNode']) && $name && \V::app()->om->getObjectClass($name)::$type === 'trigger')
		return $n;
	}
	return null;
    }

    public function beforeSave() {
	parent::beforeSave();

	//nodes and edges back into `nodes`, with their runtime state
	$nodes = [];
	foreach($this->__nodes as $wfid => $node) {
	    //CLD: 2026-11-22T00:00:00Z: a step's data can depend on the execution (models\node\NodeCoreLoop::data: the list kept only while it sleeps), also one saved without running
	    $node->exec = $this;
	    $nodes[$wfid] = $node->data();
	}
	foreach($this->__edges as $wfid => $edge)
	    $nodes[$wfid] = $edge->data();
	$this->nodes = $nodes;
    }

    //CLD: 2026-11-22T00:00:00Z: an execution is one document (MongoDB: 16 MB at most), its values over STORE_VALUE_MIN in GridFS (saveStored). One still too large to save
    //(many smaller values) fails with the size and its largest variables, those over SAVE_VALUE_MAX replaced by a note and the loops' lists and results dropped, so that
    //the failed state can be saved (not a run left `run` with the daemon's error)
    public function save($options = []) {
	try {
	    return $this->saveStored($options);
	} catch (\ErrorException $e) {
	    if (!preg_match('/too large|larger than|BSONObj size/i', $e->getMessage()))
		throw $e;
	}
	$mb = fn($bytes) => number_format($bytes / 1048576, 1) . ' MB';
	$sizes = [];
	foreach((is_array($this->variables) ? $this->variables : []) as $name => $value)
	    $sizes[$name] = strlen((string)json_encode($value, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR));
	arsort($sizes);
	$total = preg_match('/Document is (\d+) bytes/', $e->getMessage(), $m) ? (int)$m[1] : array_sum($sizes);
	$largest = implode(', ', array_map(fn($name) => $name . ' (' . $mb($sizes[$name]) . ')', array_slice(array_keys($sizes), 0, 3)));
	$vars = $this->variables;
	foreach($sizes as $name => $bytes) {
	    if ($bytes > self::SAVE_VALUE_MAX)
		$vars[$name] = '(removed: ' . $mb($bytes) . ', too large to keep)';
	}
	$this->variables = $vars;
	foreach($this->__nodes as $node) {
	    if ($node instanceof \models\node\NodeCoreLoop) {
		$node->items = null;
		$node->results = null;
		$node->active = false;
	    }
	}
	$this->fail(null, 'the execution\'s data is too large to save (' . $mb($total) . ', at most 16 MB)' . ($largest !== '' ? '; the largest variables: ' . $largest : ''));
	return $this->saveStored($options);
    }

    //CLD: 2026-11-22T00:00:00Z: a save and its GridFS values (storageDocument): saved, the replaced ones are deleted; not saved, those just uploaded are (as before it)
    private function saveStored($options) {
	$before = $this->storedFiles;
	try {
	    $ok = parent::save($options);
	} catch (\Throwable $e) {
	    $ok = $e;
	}
	$drop = $ok === true ? $this->garbage : $this->uploaded;
	if ($ok !== true)
	    $this->storedFiles = $before;
	$this->uploaded = $this->garbage = [];
	self::deleteStored($drop);
	if ($ok instanceof \Throwable)
	    throw $ok;
	//CLD: 2026-11-28T10:00:00Z: the project's open pages told (the designer's Executions panel and canvas: app.wfexecutions); while it runs at most SAVE_RATE a second (run)
	if ($ok === true)
	    \util\Daemon::push('execution::changed', [ 'aid' => $this->aid, 'project' => $this->project ], [ 'wf' => (string)$this->wf, 'execution' => (string)$this->_id, 'state' => (string)$this->state ]);
	return $ok;
    }

    public function getSchema() {
	$fields = [
	    //workflow
	    [
		'key' => 'wf',
		'type' => 'object',
		'object' => 'Workflow',
		'label' => 'Workflow',
		'help' => false,
	    ],
	    //project (of the workflow)
	    [
		'key' => 'project',
		'type' => 'object',
		'object' => 'Project',
		'label' => 'Project',
		'help' => false,
	    ],
	    //nodes: snapshot of the workflow's nodes and edges, with their runtime state, keyed by wfid
	    [
		'key' => 'nodes',
		'type' => 'array',
		'label' => 'Nodes',
		'help' => false,
	    ],
	    //tokens: [ [ node => wfid, edge => wfid of the edge it came through | null ], ... ]
	    [
		'key' => 'tokens',
		'type' => 'array',
		'label' => 'Tokens',
		'help' => false,
	    ],
	    //how the execution was started: manual, webhook, ...
	    [
		'key' => 'trigger',
		'type' => 'text',
		'label' => 'Trigger',
		'help' => false,
		'default' => 'manual',
	    ],
	    //number of the published version it ran; empty: the draft
	    [
		'key' => 'version',
		'type' => 'number',
		'label' => 'Version',
		'help' => false,
	    ],
	    //what started it (e.g. the webhook request: method, query, body, ...)
	    [
		'key' => 'input',
		'type' => 'array',
		'label' => 'Input',
		'help' => false,
	    ],
	    //CLD: 2026-11-23T12:00:00Z: the daemon job that runs it (run())
	    [
		'key' => 'job',
		'type' => 'text',
		'label' => 'Daemon job',
	    ],
	    //the execution whose inputs it ran again with (again())
	    [
		'key' => 'rerun_of',
		'type' => 'text',
		'label' => 'Run again of',
		'help' => false,
	    ],
	    //the test run it belongs to (models\wf\WfTestRun): not listed with the workflow's executions
	    [
		'key' => 'test_run',
		'type' => 'text',
		'label' => 'Test run',
		'help' => false,
	    ],
	    //variables set by the nodes (Set), read by others (If)
	    [
		'key' => 'variables',
		'type' => 'array',
		'label' => 'Variables',
		'help' => false,
	    ],
	    //log: [ [ ts, node, message ], ... ]
	    [
		'key' => 'log',
		'type' => 'array',
		'label' => 'Log',
		'help' => false,
	    ],
	    //state
	    [
		'key' => 'state',
		'type' => 'select',
		'label' => 'State',
		'default' => 'new',
		'choices' => [
		    'new' => 'New',
		    'run' => 'Run',
		    'end' => 'Completed',
		    'cancel' => 'Cancel',
		    'error' => 'Error',
		    'sleep' => 'Sleep',
		]
	    ],
	    //error message when state = error
	    [
		'key' => 'error',
		'type' => 'text',
		'label' => 'Error',
		'help' => false,
	    ],
	    //when a sleeping execution should be resumed (earliest Wait)
	    [
		'key' => 'wake_at',
		'type' => 'datetime',
		'label' => 'Wake at',
	    ],
	    //start_at
	    [
		'key' => 'start_at',
		'type' => 'datetime',
		'label' => 'Start at',
	    ],
	    //end_at
	    [
		'key' => 'end_at',
		'type' => 'datetime',
		'label' => 'End at',
	    ],
	];
	return array_merge($fields, $this->getSystemSchema());
    }

    //---------------------------------------------------------------------------------------------------------------
    //variables and log (used by the nodes)

    //the names expressions see: the execution's variables and `vars` = the project variables (credentials of type Variable; loaded once per run, never saved with the
    //execution: a resumed run sees their current values)
    private $projectVars = null;

    //CLD: 2026-11-22T12:00:00Z: the variables are read and written here without the model's change check (BaseModel::__get / __set compare the whole array,
    //serialized: ~20-40 ms per call with a list of 13000 items, three calls per loop pass); a write marks them changed, the save writes them
    //the project variables are credentials of type Variable (models\credential\CredentialVariable)
    public function expressionVariables() {
	if ($this->projectVars === null)
	    $this->projectVars = \models\credential\CredentialVariable::expressionValues($this->aid, $this->project);
	return array_merge(is_array($this->_data['variables'] ?? null) ? $this->_data['variables'] : [], [ \models\credential\CredentialVariable::EXPRESSION_NAME => $this->projectVars ]);
    }

    public function getVariable($name, $default = null) {
	$vars = is_array($this->_data['variables'] ?? null) ? $this->_data['variables'] : [];
	return array_key_exists($name, $vars) ? $vars[$name] : $default;
    }

    public function setVariable($name, $value) {
	$vars = is_array($this->_data['variables'] ?? null) ? $this->_data['variables'] : [];
	$vars[$name] = $value;
	$this->_data['variables'] = $vars;
	if ($this->_trackChanges)
	    $this->_updated['variables'] = $vars;
    }

    public function log($nodeId, $message) {
	$log = $this->log;
	$log[] = [ 'ts' => new \MongoDB\BSON\UTCDateTime(), 'node' => $nodeId, 'message' => (string)$message ];
	if (count($log) > self::MAX_LOG)
	    $log = array_slice($log, -self::MAX_LOG);
	$this->log = $log;
    }

    //---------------------------------------------------------------------------------------------------------------
    //running

    public function canRun() {
	return in_array($this->state, [ 'new', 'run', 'sleep' ]);
    }

    //an execution of a test case
    public function isTest() {
	return !empty($this->test_run);
    }

    //run until the execution ends, fails, or only sleeping tokens are left; saves the execution. Runs in the daemon (or the console) only: the web requests queue
    //their executions (queue). The execution log: changes made while running are recorded there (ExecutionLog::$execution, also when a user clicked Run), with
    //execution.start (a new execution; saved first, so the entry has its id) and how the run stopped (end / error / sleep)
    public function run() {
	$outer = [ \models\ExecutionLog::$execution, \models\ExecutionLog::$step ];
	\models\ExecutionLog::$execution = $this;
	\models\ExecutionLog::$step = null;
	try {
	    //CLD: 2026-11-23T12:00:00Z: the daemon job running it (models\Job::$current), saved with it: the master ends the executions of a worker it lost (endLost) and a job run
	    //again after a daemon restart continues them (DaemonCommand::do_job). A sleeping one is running from now on in the database (in memory it stays `sleep` for its first
	    //pass: the steps read it), so it is found too
	    $job = \models\Job::$current;
	    if ($job !== null && $this->state == 'sleep' && $this->exists()) {
		if (\V::app()->om->updateOne('WfExecution', [ '_id' => $this->_id, 'aid' => $this->aid, 'state' => 'sleep' ], [ '$set' => [ 'state' => 'run', 'job' => $job ] ])) {
		    $this->_data['job'] = $this->_odata['job'] = $job;
		    $this->_odata['state'] = 'run';
		}
	    } elseif ($job !== null) {
		$this->job = $job;
	    }

	    if ($this->state == 'new') {
		if (!$this->exists())
		    $this->save();
		\models\ExecutionLog::record('execution.start', null, 'trigger: ' . $this->trigger . ($this->version ? ', v' . (int)$this->version : ', draft'));
	    } elseif ($this->state == 'sleep') {
		//CLD: 2026-11-12T12:00:00+02:00: a sleeping run continues: logged with the step it slept at (and the loop item) and how late it woke, so a run that sleeps as
		//planned (a resume after each sleep) can be told from one that is stuck (a sleep without a resume)
		\models\ExecutionLog::$step = $this->sleepingSteps();
		$due = $this->wake_at instanceof \MongoDB\BSON\UTCDateTime ? (int)floor((int)(string)$this->wake_at / 1000) : null;
		\models\ExecutionLog::record('execution.resume', null, $this->sleepingAt() . ($due !== null ? ', due ' . \util\Format::datetime($this->wake_at)
		    . (time() - $due > 0 ? ', ' . (time() - $due) . ' s late' : '') : ''));
		\models\ExecutionLog::$step = null;
	    }

	    //saved as running at once, then after the passes,
	    //at most every SAVE_EVERY s (the designer's Executions panel shows the steps as they run)
	    if ($this->state == 'new') {
		$this->state = 'run';
		if (empty($this->start_at))
		    $this->start_at = new \MongoDB\BSON\UTCDateTime();
		$this->save();
	    }
	    $saved = microtime(true);
	    //the steps the tokens were at when last saved, the times of the latest saves (SAVE_RATE)
	    $savedTokens = json_encode($this->tokens);
	    $recent = [ $saved ];

	    $this->steps = 0;
	    while ($this->canRun()) {
		//the steps about to run saved first (e.g. the trigger done in a few ms, then a long download: shown at the download while it runs)
		if ($this->state == 'run' && json_encode($this->tokens) !== $savedTokens) {
		    $now = microtime(true);
		    $recent = array_values(array_filter($recent, fn($t) => $now - $t < 1));
		    if (count($recent) < self::SAVE_RATE) {
			//killed meanwhile: as below
			if ($this->killed())
			    return $this->state;
			$this->save();
			$saved = $recent[] = microtime(true);
			$savedTokens = json_encode($this->tokens);
			//CLD: 2026-11-22T00:00:00Z: failed by save() (too large to save): no more steps
			if (!$this->canRun())
			    break;
		    }
		}
		$progress = $this->advance();
		if (!$progress)
		    break;
		if ($this->state == 'run' && microtime(true) - $saved >= self::SAVE_EVERY) {
		    //killed meanwhile (kill(), another process): stops here, not saved (would undo the kill)
		    if ($this->killed())
			return $this->state;
		    $this->save();
		    //$recent, $savedTokens
		    $saved = $recent[] = microtime(true);
		    $savedTokens = json_encode($this->tokens);
		}
	    }
	    //as above
	    if ($this->killed())
		return $this->state;
	    $this->save();

	    \models\ExecutionLog::$step = null;
	    switch($this->state) {
		case 'end':
		    \models\ExecutionLog::record('execution.end', null, $this->steps . ' steps');
		    //plugins act on a completed run (e.g. the Email inbound trigger deletes the raw message)
		    \V::app()->hooks->applyHook('execution:end', [ $this ]);
		    break;
		case 'error':
		    \models\ExecutionLog::record('execution.error', null, (string)$this->error);
		    break;
		case 'sleep':
		    //util\Format::datetime (the account's format; the daemon: app.format.datetime)
		    //CLD: 2026-11-12T12:00:00+02:00: with the step(s) sleeping and, inside a loop, the item (sleepingAt): which of the loop's sleeps this is
		    $wake = \util\Format::datetime($this->wake_at) ?: 'unknown';
		    \models\ExecutionLog::$step = $this->sleepingSteps();
		    \models\ExecutionLog::record('execution.sleep', null, $this->sleepingAt() . ', waiting until ' . $wake);
		    \models\ExecutionLog::$step = null;
		    break;
	    }
	    //ended or failed: the workflow waiting for it (Run a workflow) is resumed
	    if (in_array($this->state, [ 'end', 'error' ], true)) {
		$this->notifyCaller();
		//the hook of a run that stopped however it ended (models\agent\AgentConversation::afterRun)
		\V::app()->hooks->applyHook('execution:stopped', [ $this ]);
	    }
	} finally {
	    [ \models\ExecutionLog::$execution, \models\ExecutionLog::$step ] = $outer;
	}
	return $this->state;
    }

    //kill a running (or waiting) execution: `cancel` in the database (a targeted update: the process running it may save meanwhile; it stops at its next
    //pass, see killed()), its daemon jobs removed (not run / queued again) and the worker running one stopped (util\Daemon::kill). $by: who killed it (the log). False when it no longer runs
    public function kill($by = null) {
	$now = new \MongoDB\BSON\UTCDateTime();
	$message = 'killed' . ($by ? ' by ' . $by : '');
	$n = \V::app()->om->updateOne('WfExecution', [ '_id' => $this->_id, 'aid' => $this->aid, 'state' => [ '$in' => [ 'new', 'run', 'sleep' ] ] ],
	    [ '$set' => [ 'state' => 'cancel', 'error' => $message, 'end_at' => $now, 'wake_at' => null ],
		'$push' => [ 'log' => [ 'ts' => $now, 'node' => null, 'message' => $message ] ] ]);
	if (!$n)
	    return false;
	$this->state = 'cancel';
	//CLD: 2026-11-28T10:00:00Z: the project's open pages told (app.wfexecutions)
	\util\Daemon::push('execution::changed', [ 'aid' => $this->aid, 'project' => $this->project ], [ 'wf' => (string)$this->wf, 'execution' => (string)$this->_id, 'state' => (string)$this->state ]);

	$query = [ 'job_data.exe' => (string)$this->_id, 'job_spec' => [ '$in' => [ '\\util\\Scheduler::start', '\\util\\Scheduler::resume' ] ], 'state' => [ '$in' => [ 'new', 'run' ] ] ];
	foreach(\V::app()->om->find('Job', $query, [ 'arrays' => true, 'projection' => [ '_id' => 1, 'state' => 1 ] ]) as $job) {
	    //removed first: the daemon does not queue it again when its worker is stopped
	    \V::app()->om->deleteOne('Job', [ '_id' => $job['_id'] ]);
	    if ($job['state'] === 'run')
		\util\Daemon::kill((string)$job['_id']);
	}

	$outer = \models\ExecutionLog::$execution;
	\models\ExecutionLog::$execution = $this;
	\models\ExecutionLog::record('execution.cancel', null, $message);
	\models\ExecutionLog::$execution = $outer;

	//its open tasks (Human task, an AI step's approval) are cancelled; an AI step's saved conversation is removed
	//CLD: 2026-11-28T10:00:00Z: some cancelled: the open pages of the account told (My tasks, its badge, the Overview)
	if (\V::app()->om->updateMany('WfTask', [ 'aid' => $this->aid, 'execution' => $this->_id, 'state' => 'open' ], [ '$set' => [ 'state' => 'cancelled' ] ]) > 0)
	    \util\Daemon::push('tasks::changed', [ 'aid' => $this->aid ], [ 'project' => (string)$this->project ]);
	\models\node\NodeAppAi::dropSaved($this);
	//its traces end
	\models\agent\AgentTrace::stopOpen($this);

	//the workflows it waits for (Run a workflow) are cancelled too; the workflow waiting for it is resumed (its step: cancelled)
	foreach(\V::app()->om->find('WfExecution', [ 'aid' => $this->aid, 'input.caller.execution' => (string)$this->_id, 'input.caller.wait' => true,
	    'state' => [ '$in' => [ 'new', 'run', 'sleep' ] ] ]) as $child)
	    $child->kill('the calling workflow was cancelled');
	$this->notifyCaller();
	//as a run that stops by itself
	\V::app()->hooks->applyHook('execution:stopped', [ $this ]);
	return true;
    }

    //a workflow started by a Run a workflow step that waits for it has ended (end, error, cancel): the caller, when it is paused, is queued to
    //continue (its step reads the result). A caller still running (this workflow ran nested in it, or it is busy elsewhere) reads it itself
    public function notifyCaller() {
	$caller = is_array($this->input) ? ($this->input['caller'] ?? null) : null;
	if (!is_array($caller) || empty($caller['wait']) || empty($caller['execution']) || !\util\Validator::rule('oid', (string)$caller['execution']))
	    return;
	$parent = \V::app()->om->findOne('WfExecution', [ '_id' => $caller['execution'], 'aid' => $this->aid, 'state' => 'sleep' ]);
	if ($parent)
	    $parent->queue('the workflow it waits for has ended (execution ' . (string)$this->_id . ')');
    }

    //CLD: 2026-11-23T12:00:00Z: the daemon lost the worker of job $jid while it ran (crashed, killed, the daemon restarted): its executions still running ($ids: only these)
    //end as error with $reason, as a run that fails by itself (the workflow waiting for one is resumed, the execution:stopped hook). Run by the master (DaemonCommand::reap_job)
    //and by a worker (do_job). Returns how many ended
    public static function endLost($jid, $reason, ?array $ids = null) {
	$query = [ 'job' => (string)$jid, 'state' => [ '$in' => [ 'new', 'run' ] ] ];
	if ($ids !== null)
	    $query['_id'] = [ '$in' => array_map(fn($id) => \V::app()->om->to_oid($id), $ids) ];
	$ended = 0;
	foreach(\V::app()->om->find('WfExecution', $query, [ 'projection' => [ '_id' => 1, 'aid' => 1, 'project' => 1, 'wf' => 1, 'trigger' => 1, 'input' => 1, 'version' => 1, 'state' => 1, 'rerun_of' => 1 ] ]) as $exe) {
	    $now = new \MongoDB\BSON\UTCDateTime();
	    $n = \V::app()->om->updateOne('WfExecution', [ '_id' => $exe->_id, 'aid' => $exe->aid, 'state' => [ '$in' => [ 'new', 'run' ] ] ],
		[ '$set' => [ 'state' => 'error', 'error' => (string)$reason, 'end_at' => $now, 'wake_at' => null ],
		    '$push' => [ 'log' => [ '$each' => [ [ 'ts' => $now, 'node' => null, 'message' => 'error: ' . $reason ] ], '$slice' => -self::MAX_LOG ] ] ]);
	    if (!$n)
		continue;
	    $exe->state = 'error';
	    $exe->error = (string)$reason;
	    $ended++;
	    //CLD: 2026-11-28T10:00:00Z: the project's open pages told (app.wfexecutions)
	    \util\Daemon::push('execution::changed', [ 'aid' => $exe->aid, 'project' => $exe->project ], [ 'wf' => (string)$exe->wf, 'execution' => (string)$exe->_id, 'state' => (string)$exe->state ]);

	    $outer = \models\ExecutionLog::$execution;
	    \models\ExecutionLog::$execution = $exe;
	    \models\ExecutionLog::record('execution.error', null, (string)$reason);
	    \models\ExecutionLog::$execution = $outer;
	    \models\agent\AgentTrace::stopOpen($exe);
	    $exe->notifyCaller();
	    \V::app()->hooks->applyHook('execution:stopped', [ $exe ]);
	}
	return $ended;
    }

    //whether it was killed meanwhile (state `cancel` in the database; then also here)
    private function killed() {
	if (empty($this->_id) || !\V::app()->om->count('WfExecution', [ '_id' => $this->_id, 'aid' => $this->aid, 'state' => 'cancel' ]))
	    return false;
	$this->state = 'cancel';
	return true;
    }

    //---------------------------------------------------------------------------------------------------------------
    //hand the run to the daemon (saved first): every execution started by a web request (designer Run, Webhook, Form, Human task, MCP run_workflow) is queued here;
    //Job::submit wakes the daemon (NEWJOB on its socket), so an idle worker starts it at once; $reason: the log message
    //CLD: 2026-11-25T10:00:00+02:00: logged only with a reason (a plain queue adds no "queued for the daemon" line to the execution log)
    public function queue($reason = null) {
	if ($reason !== null)
	    $this->log(null, $reason);
	$this->save();
	\models\Job::submit('\util\Scheduler::start', [ 'exe' => (string)$this->_id ], [
	    'aid' => $this->aid,
	    'project' => $this->project,
	    'delete_on_done' => true,
	]);
    }

    //wait (at most $seconds) for the daemon to run this queued execution until it stops (end, error, cancel, sleep); reloaded from the database.
    //Returns its state, or `queued` when it still runs (or waits for a worker) after $seconds. For the callers that answer with the result (Webhook "when finished", MCP run_workflow)
    public function waitFor($seconds) {
	$until = microtime(true) + $seconds;
	do {
	    usleep(self::WAIT_POLL * 1000);
	    if (\V::app()->om->count('WfExecution', [ '_id' => $this->_id, 'aid' => $this->aid, 'state' => [ '$nin' => [ 'new', 'run' ] ] ])) {
		$fresh = \V::app()->om->findOne('WfExecution', [ '_id' => $this->_id, 'aid' => $this->aid ]);
		foreach([ 'state', 'error', 'variables', 'version', 'end_at' ] as $k)
		    $this->$k = $fresh->$k;
		return $this->state;
	    }
	} while (microtime(true) < $until);
	return 'queued';
    }

    //one pass over the current tokens; true if any node completed (more work may follow)
    public function advance() {
	if (!$this->canRun())
	    throw new \ErrorException('state does not permit running');

	if ($this->state == 'new') {
	    $this->state = 'run';
	    if (empty($this->start_at))
		$this->start_at = new \MongoDB\BSON\UTCDateTime();
	}

	$pending = $this->tokens;
	$tokens = [];
	$progress = false;
	//loops whose pass was given up in this pass over the tokens (a step inside failed, the loop skips the item): their other tokens are dropped
	$aborted = [];

	foreach($pending as $token) {
	    $nodeId = $token['node'] ?? null;
	    $node = $this->__nodes[$nodeId] ?? null;
	    if (empty($node))
		return $this->fail($nodeId, 'node not found or of an unknown type');
	    if ($aborted && $this->insideAny($nodeId, $aborted))
		continue;

	    //inside a loop: counted for its pass (not for the run)
	    $loop = $this->activeLoop($nodeId);
	    if ($loop !== null) {
		$this->passSteps[$loop->wfid] = ($this->passSteps[$loop->wfid] ?? 0) + 1;
		if ($this->passSteps[$loop->wfid] > \data\Constants::RUN_MAX_STEPS)
		    return $this->fail($nodeId, 'too many steps in one pass of the loop `' . ($loop->title ?? $loop->wfid) . '` (endless cycle?)');
	    } elseif (++$this->steps > \data\Constants::RUN_MAX_STEPS) {
		return $this->fail($nodeId, 'too many steps (endless loop?)');
	    }

	    $node->exec = $this;
	    //the step making changes now (execution log)
	    \models\ExecutionLog::$step = (string)($node->title ?? $nodeId);
	    try {
		$result = $node->receive($token['edge'] ?? null);
	    } catch (\Throwable $e) {
		$node->state = 'fail';
		//inside a loop that skips failed items: the pass ends, the workflow goes on
		if (($loop = $this->skippingLoop($nodeId)) !== null) {
		    $this->abortPass($loop, (string)($node->title ?? $nodeId), $e->getMessage());
		    $aborted[] = $loop->wfid;
		    $tokens = array_values(array_filter($tokens, fn($t) => !$this->inside((string)$t['node'], $loop->wfid)));
		    $progress = true;
		    continue;
		}
		return $this->fail($nodeId, $e->getMessage());
	    }

	    if ($result === WfNode::SLEEP) {
		$tokens[] = $token; //try again later
		continue;
	    }
	    if (!is_array($result))
		continue;           //taken, nothing continues (e.g. Merge waiting for other inputs)

	    $progress = true;
	    $tokens = array_merge($tokens, $this->tokensFrom($nodeId, $result));
	}

	//loops: a pass ends when nothing runs inside the loop any more; the next one starts, or the loop continues on its output
	$tokens = $this->stepLoops($tokens, $progress);
	if ($this->state === 'error')
	    return false;

	//nothing else can arrive: release Merge nodes still waiting for inputs (e.g. If -> two branches -> Merge)
	if (empty($tokens)) {
	    foreach($this->__nodes as $wfid => $node) {
		if ($node->state == 'wait' && method_exists($node, 'release')) {
		    $node->exec = $this;
		    $result = $node->release();
		    if (is_array($result)) {
			$progress = true;
			$tokens = array_merge($tokens, $this->tokensFrom($wfid, $result));
		    }
		}
	    }
	}

	$this->tokens = $tokens;

	if (empty($tokens)) {
	    $this->state = 'end';
	    $this->end_at = new \MongoDB\BSON\UTCDateTime();
	    $this->wake_at = null;
	    $this->log(null, 'completed');
	} elseif (!$progress) {
	    //only sleeping tokens left
	    $this->state = 'sleep';
	    $this->wake_at = $this->earliestWake();
	} else {
	    $this->state = 'run';
	}
	return $progress;
    }

    //the active loops whose pass is over (no token inside, after releasing the Merge steps waiting inside): next() starts the next pass (a token for the loop's start step)
    //or ends the loop (tokens on its output); a failing next() fails the execution (no loop inside a loop: Workflow::validateFlow)
    private function stepLoops(array $tokens, &$progress) {
	$loops = array_filter($this->__nodes, fn($n) => !empty($n->active) && method_exists($n, 'next'));
	foreach($loops as $id => $loop) {
	    $id = (string)$id;
	    if (empty($loop->active))
		continue;
	    $busy = false;
	    foreach($tokens as $t)
		$busy = $busy || $this->inside((string)$t['node'], $id);
	    if ($busy)
		continue;

	    //Merge steps waiting inside: nothing else arrives in this pass
	    $released = [];
	    foreach($this->__nodes as $wfid => $n) {
		if ($n->state == 'wait' && method_exists($n, 'release') && $this->inside((string)$wfid, $id)) {
		    $n->exec = $this;
		    $result = $n->release();
		    if (is_array($result))
			$released = array_merge($released, $this->tokensFrom($wfid, $result));
		}
	    }
	    if ($released) {
		$tokens = array_merge($tokens, $released);
		$progress = true;
		continue;
	    }

	    $loop->exec = $this;
	    \models\ExecutionLog::$step = (string)($loop->title ?? $id);
	    $this->passSteps[$id] = 0;
	    try {
		$result = $loop->next();
		$start = $this->innerStart($id);
		//an empty loop: its passes do nothing
		while ($result === 'enter' && $start === null)
		    $result = $loop->next();
	    } catch (\Throwable $e) {
		$loop->state = 'fail';
		$loop->active = false;
		$this->fail($id, $e->getMessage());
		return $tokens;
	    }
	    $progress = true;
	    if ($result === 'enter')
		$tokens[] = [ 'node' => $start, 'edge' => null ];
	    elseif (is_array($result))
		$tokens = array_merge($tokens, $this->tokensFrom($id, $result));
	}
	return $tokens;
    }

    //the start step of a loop's passes: the step inside it (not a note) without an incoming connection; null for an empty loop (validateFlow requires exactly one)
    private function innerStart($loopId) {
	foreach($this->__nodes as $wfid => $n) {
	    if ((string)($n->parentId ?? '') === (string)$loopId && !$n::$isNote && !$this->incomingEdges($wfid))
		return (string)$wfid;
	}
	return null;
    }

    //whether step $nodeId is inside loop $loopId
    //its parent (a loop cannot be inside another loop)
    private function inside($nodeId, $loopId) {
	return (string)($this->__nodes[$nodeId]->parentId ?? '') === (string)$loopId;
    }

    private function insideAny($nodeId, array $loopIds) {
	foreach($loopIds as $id) {
	    if ($this->inside($nodeId, $id))
		return true;
	}
	return false;
    }

    //the innermost active loop around step $nodeId (null: none)
    //its parent, when that is an active loop (a loop cannot be inside another loop)
    private function activeLoop($nodeId) {
	$loop = $this->__nodes[(string)($this->__nodes[$nodeId]->parentId ?? '')] ?? null;
	return $loop !== null && !empty($loop->active) && method_exists($loop, 'next') ? $loop : null;
    }

    //the innermost active loop around step $nodeId, when it skips failed items (else null: the failure stops the workflow)
    //through activeLoop
    private function skippingLoop($nodeId) {
	$loop = $this->activeLoop($nodeId);
	return $loop !== null && $loop->cfg('onError', 'stop') === 'skip' ? $loop : null;
    }

    //gives up the current pass of $loop: the failure listed by the loop, the steps inside reset (waiting Merge, sleeping Wait, active loops)
    //no active loop inside to reset (a loop cannot be inside another loop)
    private function abortPass($loop, $stepTitle, $message) {
	$loop->exec = $this;
	$loop->skip($stepTitle, $message);
	foreach($this->__nodes as $wfid => $n) {
	    if (!$this->inside((string)$wfid, $loop->wfid))
		continue;
	    if (in_array($n->state, [ 'wait', 'sleep' ], true))
		$n->state = 'ready';
	    if (!empty($n->arrived))
		$n->arrived = [];
	    if (!empty($n->resume_at))
		$n->resume_at = null;
	}
    }

    //tokens for the edges leaving $nodeId on the given output ports
    //each edge taken is marked passed (state, passed_at: the latest time): the designer shows the connections the execution went through
    private function tokensFrom($nodeId, array $ports) {
	$tokens = [];
	foreach($this->__edges as $edgeId => $edge) {
	    if ($edge->fromNode == $nodeId && in_array((int)$edge->fromPort, $ports, true)) {
		$tokens[] = [ 'node' => $edge->toNode, 'edge' => $edgeId ];
		$edge->state = 'passed';
		$edge->passed_at = new \MongoDB\BSON\UTCDateTime();
	    }
	}
	return $tokens;
    }

    //incoming edges of a node (used by Merge)
    public function incomingEdges($nodeId) {
	$edges = [];
	foreach($this->__edges as $edgeId => $edge) {
	    if ($edge->toNode == $nodeId)
		$edges[] = $edgeId;
	}
	return $edges;
    }

    //CLD: 2026-11-12T12:00:00+02:00: the titles of the steps the run sleeps at (the execution log's step)
    private function sleepingSteps() {
	$titles = [];
	foreach($this->__nodes as $wfid => $node) {
	    if ($node->state == 'sleep')
		$titles[] = (string)($node->title ?? $wfid);
	}
	return $titles ? implode(', ', $titles) : null;
    }

    //CLD: 2026-11-12T12:00:00+02:00: where the run sleeps, for the execution log: each sleeping step, inside a loop with its item, e.g. "Wait 30s (loop `Files`, item 3 of 10)"
    private function sleepingAt() {
	$at = [];
	foreach($this->__nodes as $wfid => $node) {
	    if ($node->state != 'sleep')
		continue;
	    $loop = $this->activeLoop((string)$wfid);
	    $at[] = (string)($node->title ?? $wfid) . ($loop !== null ? ' (loop `' . ($loop->title ?? $loop->wfid) . '`, item ' . ((int)$loop->index + 1) . ' of '
		. (is_array($loop->items) ? count($loop->items) : 0) . ')' : '');
	}
	return $at ? 'at ' . implode(', ', $at) : 'sleeping';
    }

    private function earliestWake() {
	$earliest = null;
	foreach($this->__nodes as $node) {
	    if ($node->state == 'sleep' && $node->resume_at instanceof \MongoDB\BSON\UTCDateTime) {
		if ($earliest === null || (int)(string)$node->resume_at < (int)(string)$earliest)
		    $earliest = $node->resume_at;
	    }
	}
	return $earliest;
    }

    private function fail($nodeId, $message) {
	$this->state = 'error';
	$this->error = (string)$message;
	$this->end_at = new \MongoDB\BSON\UTCDateTime();
	$this->log($nodeId, 'error: ' . $message);
	return false;
    }
}
