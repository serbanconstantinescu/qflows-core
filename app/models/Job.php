<?php

/*
    the queue of work for the daemon Fields keep the names the daemon and worker use:
	job_spec        `Class::method` run by a worker as Class::method($job_data)
	job_data        array, given to the method as an object
	state           new -> run -> done | fail
	run_count, worker_pid, start_run_at, delete_on_done, error
	no_retry        true: a failed job the daemon does not run again (its worker was lost while it ran an execution, which ended as error: DaemonCommand::reap_job)

    \models\Job::submit('\models\node\NodeTriggerEmail::deliver', [ 'aid' => $aid, 'project' => $project, ... ]);
*/
namespace models;

class Job extends \sys\BaseModel {
    protected static $collection = 'jobs';

    //CLD: 2026-11-23T12:00:00Z: the id (string) of the job this worker process runs now, null otherwise (DaemonCommand::worker_command); an execution run by it
    //records it (WfExecution::run, `job`), so the master finds the executions of a worker it lost
    public static $current = null;

    public static $indexes = [
	[ [ 'state' => 1, '_id' => 1 ], [] ],
	[ [ 'state' => 1, 'aid' => 1 ], [] ],
	[ [ 'state' => 1, 'project' => 1 ], [] ],
	[ [ 'key' => 1 ], [ 'unique' => true, 'sparse' => true ] ],
    ];


    //events are not objects of an account (`aid` is optional, inside job_data when the work belongs to one)
    public function _init() {
	$this->_config['global'] = true;
	parent::_init();
    }

    public function getSchema() {
	return [
	    [
		'key' => 'job_spec',
		'type' => 'text',
		'label' => 'Work',
		'required' => true
	    ],
	    [
		'key' => 'job_data',
		'type' => 'array',
		'label' => 'Data'
	    ],
	    [
		'key' => 'aid',
		'type' => 'object',
		'object' => 'Account',
		'label' => 'Account'
	    ],
	    [
		'key' => 'project',
		'type' => 'object',
		'object' => 'Project',
		'label' => 'Project'
	    ],
	    [
		'key' => 'state',
		'type' => 'select',
		'label' => 'State',
		'default' => 'new',
		'choices' => [
		    'new' => 'Waiting',
		    'run' => 'Running',
		    'done' => 'Done',
		    'fail' => 'Failed'
		],
	    ],
	    [
		'key' => 'run_count',
		'type' => 'number',
		'label' => 'Runs'
	    ],
	    [
		'key' => 'worker_pid',
		'type' => 'number',
		'label' => 'Worker'
	    ],
	    [
		'key' => 'start_run_at',
		'type' => 'datetime',
		'label' => 'Started at'
	    ],
	    [
		'key' => 'delete_on_done',
		'type' => 'checkbox',
		'label' => 'Delete when done'
	    ],
	    [
		'key' => 'error',
		'type' => 'text',
		'label' => 'Error'
	    ],
	    [
		'key' => 'finished_at',
		'type' => 'datetime',
		'label' => 'Finished at'
	    ],
	    //CLD: 2026-11-23T12:00:00Z: not run again when failed (see the top)
	    [
		'key' => 'no_retry',
		'type' => 'checkbox',
		'label' => 'Not run again when failed'
	    ],
	    //optional unique key (unique sparse index, created by the daemon): the same work is queued once
	    [
		'key' => 'key',
		'type' => 'text',
		'label' => 'Key'
	    ],
	];
    }

    //queue `Class::method` with $data; options: aid, project (default: $data['aid'] / $data['project']), delete_on_done, key (unique), notify
    //(default true: tell the daemon; false inside the daemon itself, which dispatches on its own); returns the event id.
    //The daemon is told at once (it also checks the queue every 10 s)
    //aid / project kept at the top level for the daemon's limits; no `mode`
    public static function submit($spec, $data = [], $options = []) {
	$data = (array)$data;
	$doc = [
	    'job_spec' => (string)$spec,
	    'job_data' => $data,
	    'aid' => \V::app()->om->to_oid($options['aid'] ?? $data['aid'] ?? null),
	    'project' => \V::app()->om->to_oid($options['project'] ?? $data['project'] ?? null),
	    'state' => 'new',
	    'run_count' => 0,
	    'delete_on_done' => !empty($options['delete_on_done']),
	    'created_at' => new \MongoDB\BSON\UTCDateTime(),
	];
	//options['key']: null when an event with this key already exists (it is not queued again)
	if (!empty($options['key'])) {
	    $doc['key'] = (string)$options['key'];
	}
	//insertOne, not save(): save() rethrows any error as \ErrorException, the duplicate key (11000) of options['key'] would be lost
	try {
	    $id = \V::app()->om->insertOne('Job', \V::app()->om->create('Job', $doc));
	} catch (\MongoDB\Driver\Exception\BulkWriteException $e) {
	    if (!empty($doc['key']) && $e->getCode() == 11000)
		return null;
	    throw $e;
	}
	if (!empty($options['notify']) || !isset($options['notify']))
	    \util\Daemon::notify();
	return $id;
    }

    //log an error and mail it to ERRORS_EMAIL (app.env) when set; $error: a message or a Throwable
    public static function reportError($subject, $error) {
	$text = $error instanceof \Throwable
	    ? get_class($error) . ': ' . $error->getMessage() . "\n" . $error->getFile() . ':' . $error->getLine() . "\n\n" . $error->getTraceAsString()
	    : (string)$error;
	\V::app()->log->error($subject . ': ' . strtok($text, "\n"));

	if (!defined('ERRORS_EMAIL') || !defined('SMTP_HOST') || empty(ERRORS_EMAIL))
	    return;

	$host = gethostname() ?: 'localhost';
	$from = 'daemon@' . (defined('SMTP_DOMAIN') && SMTP_DOMAIN ? SMTP_DOMAIN : $host);
	$subjectLine = '[' . \V::app()->config('app.name') . ' ' . $host . '] ' . $subject;
	$headers = implode("\r\n", [
	    'From: ' . $from,
	    'To: ' . ERRORS_EMAIL,
	    'Subject: ' . mb_encode_mimeheader($subjectLine, 'UTF-8'),
	    'Date: ' . date(DATE_RFC2822),
	    'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $host . '>',
	    'MIME-Version: 1.0',
	    'Content-Type: text/plain; charset=utf-8',
	    'Content-Transfer-Encoding: 8bit',
	]);
	//CRLF line ends (util\SMTP::data doubles leading dots itself)
	$body = str_replace([ "\r\n", "\n" ], "\r\n", $text) . "\r\n";

	try {
	    \util\EmailSend::send([ 'sender' => $from, 'to' => [ ERRORS_EMAIL => '' ], 'headers' => $headers, 'body' => $body ]);
	} catch (\Throwable $e) {
	    \V::app()->log->error('reportError: mail not sent: ' . $e->getMessage());
	}
    }
}
