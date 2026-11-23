<?php

/*
    the execution log:
	what happens without a person acting.
	Create / update / delete of objects (the hooks of models\AuditLog) made while a workflow execution runs (also when a user clicked Run: `started_by`), by the daemon, the console or a webhook call; and
//named events: execution.start / end / error / sleep / queued / cancel / continue (WfExecution::run, kill; continue: DaemonCommand::do_job), webhook.refused (HookController, when the workflow is known), job.failed
//(daemon). `source` says what acted: the execution's trigger (manual, webhook, schedule, imap), else daemon / console / webhook / web.
//The running execution and step are set by WfExecution (ExecutionLog::$execution / $step), the daemon worker sets $source. Entries are never changed and
//expire after the account's `execution_log_retention`. Read with ExecutionLog::read (global: the account, project: the entries of the current project)

*/
namespace models;

class ExecutionLog extends \sys\BaseModel {
    use \models\log\LogTrait;

    protected static $collection = 'execution_log';

    public static $indexes = [
	[ [ 'aid' => 1, 'created_at' => -1 ], [] ],
	[ [ 'aid' => 1, 'project' => 1, 'created_at' => -1 ], [] ],
	[ [ 'execution' => 1 ], [] ],
    ];


    const OBJECT = 'ExecutionLog';
    const ACTIONS = [
	'execution.start' => 'Execution started',
	'execution.end' => 'Execution completed',
	'execution.error' => 'Execution failed',
	'execution.sleep' => 'Execution waiting',
	//CLD: 2026-11-12T12:00:00+02:00: a sleeping run continued (WfExecution::run): where it slept, when it was due, how late
	'execution.resume' => 'Execution resumed',
	//CLD: 2026-11-23T12:00:00Z: a running one continued from its last save, its job run again after the daemon restarted (DaemonCommand::do_job)
	'execution.continue' => 'Execution continued',
	//a run inside a web request handed to the daemon: no longer written (every execution runs in the daemon); kept for the older entries
	'execution.queued' => 'Execution moved to the daemon',
	//killed by a user (WfExecution::kill, the Overview)
	'execution.cancel' => 'Execution killed',
	'webhook.refused' => 'Webhook refused',
	//CLD: 2026-11-12T10:00:00+02:00: a Shopify webhook refused (ShopifyController)
	'shopify.refused' => 'Shopify event refused',
	//CLD: 2026-11-12T23:30:00+02:00: a Yousign webhook refused (YousignController)
	'yousign.refused' => 'Yousign event refused',
	//CLD: 2026-11-13T00:30:00+02:00: an Infobip post refused (InfobipController)
	'infobip.refused' => 'Infobip message refused',
	'job.failed' => 'Job failed',
	//an AI step's tool calls sent for approval; the decisions applied (approved, rejected, not decided in time)
	'ai.approval.ask' => 'Agent action awaiting approval',
	'ai.approval' => 'Agent action decided',
    ];

    //the execution running now (WfExecution::run), its current step's title, and what acts outside executions (e.g. daemon; default: by app mode)
    public static $execution = null;
    public static $step = null;
    public static $source = null;

    public function getSchema() {
	return $this->logFields([
	    [
		'key' => 'source',
		'type' => 'select',
		'label' => 'Source',
		'help' => false,
		'perm:update' => 'nobody',
		'choices' => [
		    'manual' => 'Run (designer)',
		    'webhook' => 'Webhook',
		    'schedule' => 'Schedule',
		    'imap' => 'Mail (IMAP)',
		    'email' => 'Email (inbound)',
		    'file' => 'File upload',
		    'daemon' => 'Daemon',
		    'console' => 'Console',
		    'web' => 'Web (no user)',
		],
	    ],
	    [
		'key' => 'workflow',
		'type' => 'object',
		'object' => 'Workflow',
		'label' => 'Workflow',
		'help' => false,
		'perm:update' => 'nobody'
	    ],
	    [
		'key' => 'execution',
		'type' => 'text',
		'label' => 'Execution',
		'help' => false,
		'perm:read' => 'nobody',
		'perm:update' => 'nobody',
		'showInForms' => false
	    ],
	    //the title of the step that made the change
	    [
		'key' => 'step',
		'type' => 'text',
		'label' => 'Step',
		'help' => false,
		'perm:update' => 'nobody'
	    ],
	    //the user who clicked Run (designer)
	    [
		'key' => 'started_by',
		'type' => 'text',
		'label' => 'Started by',
		'help' => false,
		'perm:update' => 'nobody'
	    ],
	]);
    }

    //hook model:after-save: $before = [ key => value before ] of the changed keys (update) or null (create)
    public static function afterModelSave($o, $before) {
	if (!static::isRecorded($o))
	    return;

	$changes = static::savedChanges($o, $before);
	//only bookkeeping changed (e.g. updated_at): nothing to record
	if ($before !== null && empty($changes))
	    return;

	static::record($before === null ? 'create' : 'update', $o, null, $changes);
    }

    //hook model:after-delete: $data = the deleted document
    public static function afterModelDelete($o, $data) {
	if (static::isRecorded($o))
	    static::record('delete', $o, null, static::deletedChanges($o, $data), $data);
    }

    //records $action on $o (may be null), with the running execution's workflow / execution / step; $data: the object's values when $o no longer has
    //them (deleted); $aid: the account when neither $o nor the execution gives it (e.g. a failed job)
    public static function record($action, $o = null, $details = null, $changes = [], $data = null, $aid = null) {
	$exe = static::$execution;
	$fields = [
	    'source' => static::source(),
	    'changes' => $changes,
	    'details' => $details,
	];
	if ($aid)
	    $fields['aid'] = $aid;
	if ($exe) {
	    $fields['aid'] = $fields['aid'] ?? $exe->aid;
	    $fields['workflow'] = $exe->wf;
	    $fields['execution'] = $exe->_id ? (string)$exe->_id : null;
	    $fields['step'] = static::$step;
	    //CLD: 2026-11-22T12:00:00Z: who started it and the projects of the data tables its steps change are looked up once per execution (memo): a loop
	    //upserting 13000 rows took three lookups per row
	    if (self::$memo['exe'] !== $exe)
		self::$memo = [ 'exe' => $exe, 'user' => null, 'projects' => [], 'from' => null ];
	    if (self::$memo['user'] === null) {
		$user = $exe->trigger == 'manual' && !empty($exe->input['user']) ? \V::app()->om->findById('User', $exe->input['user']) : null;
		self::$memo['user'] = [ $user ? (string)$user : null ];
	    }
	    $fields['started_by'] = self::$memo['user'][0];
	    if (!$o)
		$fields['project'] = $exe->project;
	    else {
		$fields['project'] = self::projectMemo($o, $data ?? $o->data());
		//the entry is in the log of the object's project: when the run is of another project (a shared table), it says which
		if (empty($fields['details']) && $fields['project'] && (string)$fields['project'] !== (string)$exe->project)
		    $fields['details'] = 'By a workflow of project ' . (self::$memo['from'] ??= (string)\V::app()->om->findById('Project', $exe->project));
	    }
	}
	if ($o instanceof \models\Workflow)
	    $fields['workflow'] = $o->_id;

	static::write($action, $o, $data, $fields);
    }

    //the running execution (record) and what was looked up for it: [ user name ], data table iname => project, the name of its project
    private static $memo = [ 'exe' => null, 'user' => null, 'projects' => [], 'from' => null ];

    //projectOf, kept per execution for the objects without their own `project` (data table rows: their table's)
    private static function projectMemo($o, $data) {
	if ($o instanceof \models\Project || $o instanceof \sys\fields\Field || !empty($data['project']))
	    return static::projectOf($o, $data);
	$name = (string)$o->getObjectName();
	if (!array_key_exists($name, self::$memo['projects']))
	    self::$memo['projects'][$name] = static::projectOf($o, $data);
	return self::$memo['projects'][$name];
    }

    //what acts: the running execution's trigger, else what was set (daemon), else by app mode (console; web: a webhook call or another request without a user)
    public static function source() {
	if (static::$execution)
	    return (string)static::$execution->trigger;
	if (static::$source)
	    return static::$source;
	if (\V::app()->config('app.mode') != 'web')
	    return 'console';
	return str_starts_with((string)($_SERVER['REQUEST_URI'] ?? ''), '/hook/') ? 'webhook' : 'web';
    }

    //recorded: changes made while an execution runs, or without a logged-in user (daemon, console, webhook), of an object the logs record
    public static function isRecorded($o) {
	$byPerson = \V::app()->config('app.mode') == 'web' && !\V::app()->user->isGuest && static::$execution === null;
	return !$byPerson && static::isLoggable($o);
    }
}
