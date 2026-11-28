<?php

/*
    app/v.php daemon::run [--debug] [--logfile=daemon] [--worker-logfile=daemon_worker]
    app/v.php daemon::run --worker [--debug] [--logfile=...]       a worker (started by the master; talks to it over stdin/stdout)

    options:
	--min-workers=2            started at once and always kept
	--max-workers=2 x CPUs - 1 more are started while allowed jobs wait; the extra ones stop after --idle-timeout=60 s without work
	--max-per-account=max - 1  running jobs of one account (models\Job::aid); 0 = no limit
	--max-per-project=max - 1  running jobs of one project (models\Job::project); 0 = no limit
	--job-timeout=0            seconds a job may run before its worker is killed; 0 = no limit
	--max-worker-memory=256 (MB): a worker over it (after a job, or while idle) exits and the master starts a new one;
	--worker-memory-limit=     PHP memory_limit of the workers (e.g. 1024M, -1 = none); default: php.ini. A job over it is a PHP fatal error: its executions end as error
	--debug                    DEBUG messages in the logs
    CLD: 2026-11-23T14:00:00Z: each option also in app.env as DAEMON_<OPTION> (e.g. DAEMON_MAX_WORKER_MEMORY=512, DAEMON_DEBUG=true; config.php app.daemon); the command line wins.
    app/v.php daemon::settings     the settings the daemon starts with (scripts/setup_daemon.sh status)
*/
namespace commands;

class DaemonCommand extends \sys\BaseController {
    //workers info: pid => [ process, state START|IDLE|ASSIGNED|BUSY|STOP, jid, aid, project, created_at, updated_at, idle_since, buffer ]
    public $workers = [];

    //the config
    public $config = [];

    //the worker command
    public $worker_cmd = '';

    //ReactPHP's loop
    public $loop = null;

    //for cron-like scheduler
    public $timer = null;

    //whether is dispatching
    public $dispatching = false;
    public $last_dispatch_time = 0;
    public $new_jobs_count = 0;

    //the daemon is shutting down: no new workers, no dispatching
    public $stopping = false;

    //for alerting
    public $prev_jobs_count = 0;

    //the job listener (a TCP stream server on 127.0.0.1) and its connections (id => [ stream, buffer ])
    public $job_listener = null;
    public $job_conns = [];

    //CLD: 2026-11-28T10:00:00Z: the browsers' live events (setup_push_listener): the listener and its streams (id => [ stream, head, claims, out, since, writing, closing ])
    public $push_listener = null;
    public $push_conns = [];

    //worker mode
    public $pid = null;

    public $stdin;
    public $stdout;

    public $job_counter = 0;

    //the worker is leaving (over its memory limit)
    public $exiting = false;
    public $crt_job_id = null;

    public $base_process_title = '';

    public $master_last_seen = 0;

    //partial line from the master (worker mode)
    public $stdin_buffer = '';


    //-------------------------------------------------------------------
    //worker related functions: start, kill, dispatch jobs
    public function kill_worker($pid) {
	if (empty($this->workers[$pid]))
	    return;

	$process = $this->workers[$pid]['process'];
	$this->workers[$pid]['state'] = 'STOP';
	\V::app()->log->debug("WMGR: worker {$pid} will get SIGTERM");
	$process->terminate(SIGTERM);

	$this->loop->addTimer(2, function() use ($process, $pid) {
	    if ($process->isRunning()) {
		\V::app()->log->debug("WMGR: worker {$pid} still running after SIGTERM, send SIGKILL");
		posix_kill($pid, SIGKILL);
	    }
	});
    }

    //the worker's job goes back to the queue (state new) or fails after 3 runs; used when a worker exits
    //or is killed while it has a job
    //CLD: 2026-11-23T12:00:00Z: $restart: the daemon stopped (or restarted) while it ran: queued again, a run again continues the executions it started (do_job). Otherwise
    //the worker crashed or was killed (out of memory, --job-timeout): the executions it was running end as error (WfExecution::endLost) and the job fails, not run again
    //(no_retry): it would likely crash the same way and repeat the steps. A job without executions running is queued again as before. After 3 runs: failed, its executions too
    protected function reap_job($jid, $reason, $restart = false) {
	if (empty($jid))
	    return;
	$job = \V::app()->om->findOne('Job', [ '_id' => $jid, 'state' => 'run' ], [ 'arrays' => true ]);
	if (empty($job))
	    return;

	$failed = (int)($job['run_count'] ?? 0) >= 3;
	$ended = $restart && !$failed ? 0 : \models\wf\WfExecution::endLost($jid, $reason);
	if ($ended && !$failed) {
	    \V::app()->log->warn("WMGR: job {$jid}: {$reason}, failed: {$ended} execution(s) ended as error, not run again");
	    if (!empty($job['aid']))
		\models\ExecutionLog::record('job.failed', null, "job {$jid} (" . ($job['job_spec'] ?? '') . "): {$reason}, its executions ended as error, not run again", [], null, $job['aid']);
	    \V::app()->om->updateOne('Job',
		[ '_id' => $job['_id'], 'state' => 'run' ],
		[ '$set' => [ 'state' => 'fail', 'error' => $reason, 'no_retry' => true, 'finished_at' => new \MongoDB\BSON\UTCDateTime() ] ],
		[ 'writeConcern' => new \MongoDB\Driver\WriteConcern(\MongoDB\Driver\WriteConcern::MAJORITY) ]
	    );
	    return;
	}
	\V::app()->log->warn("WMGR: job {$jid}: {$reason}, " . ($failed ? 'failed after 3 runs' : 'queued again'));
	//a job given up (its worker died or timed out 3 times) in the account's execution log
	if ($failed && !empty($job['aid']))
	    \models\ExecutionLog::record('job.failed', null, "job {$jid} (" . ($job['job_spec'] ?? '') . "): {$reason}, failed after 3 runs", [], null, $job['aid']);
	\V::app()->om->updateOne('Job',
	    [ '_id' => $job['_id'], 'state' => 'run' ],
	    [ '$set' => $failed
		? [ 'state' => 'fail', 'error' => $reason, 'finished_at' => new \MongoDB\BSON\UTCDateTime() ]
		: [ 'state' => 'new', 'worker_pid' => null ] ],
	    [ 'writeConcern' => new \MongoDB\Driver\WriteConcern(\MongoDB\Driver\WriteConcern::MAJORITY) ]
	);
    }

    public function start_worker() {
	\V::app()->log->info("WMGR: create new worker...");
	$process = new \React\ChildProcess\Process($this->worker_cmd);

	//start the process or stop here when it cannot start (the pipes below did not exist)
	try {
	    $process->start($this->loop);
	} catch (\RuntimeException $e) {
	    \V::app()->log->error("WMGR: could not create worker: " . $e->getMessage());
	    return false;
	}

	$pid = $process->getPid();

	//on exit
	$process->on('exit', function ($code, $term) use ($pid) {
	    if ($code > 0)
		\V::app()->log->warn("WMGR: worker with pid:{$pid} exited with code: $code");

	    //reap hung-up jobs
	    //CLD: 2026-11-23T12:00:00Z: the reason says how it ended (exit code or signal; killed over --job-timeout); while the daemon stops: a restart (reap_job)
	    if (!empty($this->workers[$pid]['jid']))
		$this->reap_job($this->workers[$pid]['jid'], $this->stopping ? 'the daemon stopped while it ran' : $this->workers[$pid]['lost'] ?? ("the daemon worker running it stopped (" . ($term !== null ? "signal {$term}" : "exit code {$code}")
		    . ($code == 255 ? ': a PHP fatal error, e.g. out of memory' : '') . ')'), $this->stopping);

	    unset($this->workers[$pid]);

	    //keep the minimum; its slot may let a waiting event run
	    if (!$this->stopping) {
		$this->ensure_workers();
		$this->dispatch_jobs();
	    }
	});

	$process->stdout->on('data', function($chunk) use ($pid) {
	    if (empty($this->workers[$pid]))
		return;
	    $this->workers[$pid]['buffer'] .= $chunk;
	    while (!empty($this->workers[$pid]) && ($nl = strpos($this->workers[$pid]['buffer'], "\n")) !== false) {
		$msg = trim(substr($this->workers[$pid]['buffer'], 0, $nl));
		$this->workers[$pid]['buffer'] = substr($this->workers[$pid]['buffer'], $nl + 1);
		if ($msg !== '')
		    $this->worker_message($pid, $msg);
	    }
	});

	$this->workers[$pid] = [
	    'process' => $process,
	    'state' => 'START',
	    'created_at' => microtime(true),
	    'updated_at' => microtime(true),
	    'idle_since' => null,
	    'jid' => null,
	    'aid' => null,
	    'project' => null,
	    'buffer' => '',
	];
	return true;
    }

    //one message of worker $pid (see the protocol at the top)
    protected function worker_message($pid, $msg) {
	$w = &$this->workers[$pid];
	$cmd = explode(':', $msg, 2);
	$jid = trim($cmd[1] ?? '');

	switch($cmd[0]) {
	    case 'PING':
		$w['process']->stdin->write("PONG\n");
		return;

	    //the worker took the job
	    case 'BUSY':
		if ($jid !== '' && $jid === $w['jid'] && $w['state'] != 'STOP') {
		    $w['state'] = 'BUSY';
		    $w['updated_at'] = microtime(true);
		}
		return;

	    //the event no longer exists / is not runnable: the worker skipped it
	    case 'RELEASE':
		\V::app()->log->warn("WMGR: worker {$pid} released job {$jid}");
		if ($jid !== '' && \util\Validator::rule('oid', $jid))
		    \V::app()->om->updateOne('Job',
			[ '_id' => $jid, 'state' => 'run', 'worker_pid' => $pid ],
			[ '$set' => [ 'state' => 'new', 'worker_pid' => null ] ]
		    );
		//fall through: the worker is free
	    case 'DONE':
	    case 'IDLE':
		$w['jid'] = $w['aid'] = $w['project'] = null;
		//a worker being stopped gets no more work
		if ($w['state'] == 'STOP')
		    return;
		$w['state'] = 'IDLE';
		$w['updated_at'] = microtime(true);
		$w['idle_since'] = microtime(true);
		$this->dispatch_jobs();
		return;

	    //the worker leaves (over its memory limit): no more work for it; its exit starts a new one
	    case 'EXIT':
		\V::app()->log->info("WMGR: worker {$pid} is leaving (memory limit)");
		$w['state'] = 'STOP';
		return;

	    default:
		\V::app()->log->warn("WMGR: unknown message from worker {$pid}: {$msg}");
	}
    }

    //the query of events this daemon may run now: new, not of an account / project at its limit
    protected function allowed_jobs_query() {
	$query = [ 'state' => 'new' ];

	//running events per account / project (assigned or busy workers)
	$per_aid = $per_project = [];
	foreach($this->workers as $w) {
	    if (empty($w['jid']))
		continue;
	    if (!empty($w['aid']))
		$per_aid[$w['aid']] = ($per_aid[$w['aid']] ?? 0) + 1;
	    if (!empty($w['project']))
		$per_project[$w['project']] = ($per_project[$w['project']] ?? 0) + 1;
	}

	//$nin also matches events without aid / project (system work: not limited); the suspended accounts' jobs (models\Account::isSuspended) wait in the queue
	$aids = array_merge(self::fullIds($per_aid, (int)$this->config['max-per-account']), array_map(fn($id) => \V::app()->om->to_oid($id), array_keys(\models\Account::suspendedIds())));
	if ($aids)
	    $query['aid'] = [ '$nin' => $aids ];
	if ($projects = self::fullIds($per_project, (int)$this->config['max-per-project']))
	    $query['project'] = [ '$nin' => $projects ];

	return $query;
    }

    //the ids of $counts (id => running jobs) that reached $limit, as ObjectIds; none without a limit (0)
    private static function fullIds($counts, $limit) {
	return $limit > 0 ? array_map(fn($id) => \V::app()->om->to_oid($id), array_keys(array_filter($counts, fn($n) => $n >= $limit))) : [];
    }

    //hand out allowed events to idle workers until there is no idle worker or no allowed event
    //when events wait and no worker is idle, more workers are started (up to --max-workers)
    public function dispatch_jobs() {
	if ($this->stopping)
	    return;
	if ($this->dispatching) {
	    return;
	}

	//mark
	$this->dispatching = true;
	$this->last_dispatch_time = time();

	try {
	    while (true) {
		$idle_pids = array_keys(array_filter($this->workers, fn($w) => $w['state'] == 'IDLE'));

		if (empty($idle_pids)) {
		    $this->grow_workers();
		    break;
		}

		//claim the oldest allowed event for this worker
		$pid = $idle_pids[0];
		$job = \V::app()->om->findOneAndUpdate('Job',
		    $this->allowed_jobs_query(),
		    [
			'$set' => [
			    'state' => 'run',
			    'worker_pid' => $pid,
			    'start_run_at' => new \MongoDB\BSON\UTCDateTime(),
			],
			'$inc' => [ 'run_count' => 1 ],
		    ],
		    [
			'arrays' => true,
			'returnDocument' => 'before',
			'sort' => [ '_id' => 1 ],
			'writeConcern' => new \MongoDB\Driver\WriteConcern(\MongoDB\Driver\WriteConcern::MAJORITY),
		    ]
		);
		if (!$job)
		    break;

		$jid = (string)$job['_id'];
		$this->workers[$pid]['state'] = 'ASSIGNED';
		$this->workers[$pid]['jid'] = $jid;
		$this->workers[$pid]['aid'] = empty($job['aid']) ? null : (string)$job['aid'];
		$this->workers[$pid]['project'] = empty($job['project']) ? null : (string)$job['project'];
		$this->workers[$pid]['updated_at'] = microtime(true);
		$this->workers[$pid]['idle_since'] = null;
		$this->workers[$pid]['process']->stdin->write("NEWJOB:{$jid}\n");
	    }
	} finally {
	    $this->dispatching = false;
	}
    }

    //--min-workers always run
    protected function ensure_workers() {
	if ($this->stopping)
	    return;
	while (count($this->workers) < $this->config['min-workers']) {
	    if (!$this->start_worker())
		break;
	}
    }

    //no idle worker: start one more per waiting allowed event (counting the ones still starting), up to --max-workers
    protected function grow_workers() {
	$room = (int)$this->config['max-workers'] - count($this->workers);
	if ($room <= 0 || $this->stopping)
	    return;
	$starting = count(array_filter($this->workers, fn($w) => $w['state'] == 'START'));
	$waiting = \V::app()->om->count('Job', $this->allowed_jobs_query(), [ 'limit' => $room + $starting ]);
	for ($i = 0; $i < min($room, $waiting - $starting); $i++) {
	    if (!$this->start_worker())
		break;
	}
    }

    //workers above --min-workers that were idle for --idle-timeout seconds stop; events running longer
    //than --job-timeout (when set) are given up: their worker is killed (the exit handler queues them again)
    protected function check_workers() {
	$now = microtime(true);
	$extra = count(array_filter($this->workers, fn($w) => $w['state'] != 'STOP')) - (int)$this->config['min-workers'];
	foreach($this->workers as $pid => $w) {
	    if ($extra > 0 && $w['state'] == 'IDLE' && $w['idle_since'] && $now - $w['idle_since'] > $this->config['idle-timeout']) {
		\V::app()->log->info("WMGR: worker {$pid} idle for more than {$this->config['idle-timeout']} s, stop it");
		$this->kill_worker($pid);
		$extra--;
		continue;
	    }
	    $timeout = (int)$this->config['job-timeout'];
	    if ($timeout > 0 && in_array($w['state'], [ 'ASSIGNED', 'BUSY' ]) && $now - $w['updated_at'] > $timeout) {
		\V::app()->log->warn("WMGR: worker {$pid}: job {$w['jid']} runs for more than {$timeout} s, kill it");
		//CLD: 2026-11-23T12:00:00Z: the reason its executions end with (the exit handler: reap_job)
		$this->workers[$pid]['lost'] = "it ran for more than {$timeout} s (the daemon's --job-timeout): stopped";
		$this->kill_worker($pid);
	    }
	}
    }

    private function timer_tick() {
	//each minute is now the `daemon:tick` hook;
	// Schedule triggers, sleeping executions, IMAP checks) attach to it and queue their work with \models\Job::submit()
	$now_ts = new \MongoDB\BSON\UTCDateTime();
	$time = time();
	//a failing handler must not stop the daemon; then hand out what the handlers queued
	try {
	    \V::app()->hooks->applyHook('daemon:tick', [ $now_ts ]);
	} catch (\Throwable $e) {
	    \models\Job::reportError('daemon:tick failed', $e);
	}
	$this->dispatch_jobs();

        if ($time % 60 != 0) {
    	    \V::app()->log->warn("Timer tick: needs re-align, offset=" . $time % 60);
    	    $this->scheduler();
    	}
    }

    //install a 60s periodic timer, aligned at second 0 of minute
    public function scheduler() {
        if ($this->timer) {
            $this->loop->cancelTimer($this->timer);
            $this->timer = null;
        }

	//seconds from time
        $second = time() % 60;

        if ($second != 0) {
            $this->timer = $this->loop->addTimer(60 - $second, function () {
                $this->timer = null;
                $this->scheduler();
            });
        } else {
    	    $this->timer = $this->loop->addPeriodicTimer(60, function () {
        	$this->timer_tick();
    	    });
    	    $this->timer_tick();
    	}
    }

    //the job listener is a plain TCP stream server on 127.0.0.1 (app.daemon_port: DAEMON_PORT of app.env, default 9000) watched by the loop,
    // so that Haraka (another user) and other local processes reach it
    // a port taken (e.g. by another installation): logged, tried again at the next check
    //one command per line: NEWJOB, KILLJOB:<event id>, PUSH:<json> (the live events: push_publish)
    public function setup_job_listener() {
	if (!empty($this->job_listener))
	    return;

	//its listening address (app.daemon_listen: 127.0.0.1 unless set; Docker: 0.0.0.0)
	$address = \util\Daemon::address(true);
	$server = @stream_socket_server($address, $errno, $errstr);
	if (!$server) {
	    \V::app()->log->error("WMGR: cannot listen on {$address}: {$errstr}");
	    return;
	}
	\V::app()->log->info("WMGR: listening on {$address}");
	stream_set_blocking($server, false);
	$this->job_listener = $server;

	$this->loop->addReadStream($server, function($server) {
	    $conn = @stream_socket_accept($server, 0);
	    if (!$conn)
		return;
	    stream_set_blocking($conn, false);
	    $id = (int)$conn;
	    $this->job_conns[$id] = [ 'stream' => $conn, 'buffer' => '' ];

	    $this->loop->addReadStream($conn, function($conn) use ($id) {
		$chunk = fread($conn, 8192);
		if ($chunk === '' || $chunk === false) {
		    //closed: a last command may come without a newline
		    $rest = trim($this->job_conns[$id]['buffer'] ?? '');
		    $this->loop->removeReadStream($conn);
		    fclose($conn);
		    unset($this->job_conns[$id]);
		    if ($rest !== '')
			$this->job_command($rest);
		    return;
		}
		$this->job_conns[$id]['buffer'] .= $chunk;
		while (($nl = strpos($this->job_conns[$id]['buffer'], "\n")) !== false) {
		    $line = trim(substr($this->job_conns[$id]['buffer'], 0, $nl));
		    $this->job_conns[$id]['buffer'] = substr($this->job_conns[$id]['buffer'], $nl + 1);
		    if ($line !== '')
			$this->job_command($line);
		}
	    });
	});
    }

    //one command from the job
    protected function job_command($message) {
	$cmd = explode(':', $message, 2);
	//CLD: 2026-11-28T10:00:00Z: not the live events (many): push_publish logs them
	if ($cmd[0] !== 'PUSH')
	    \V::app()->log->debug("WMGR: received command: $message");
	switch($cmd[0]) {
	    case 'KILLJOB':
		$jid = trim($cmd[1] ?? '');
		if ($jid === '') {
		    \V::app()->log->debug("WMGR: KILLJOB: no job to kill");
		    return;
		}
		foreach($this->workers as $pid => $w) {
		    if ($w['jid'] == $jid)
			$this->kill_worker($pid);
		}
		break;
	    case 'NEWJOB':
		//submit job
		$this->dispatch_jobs();
		break;
	    //CLD: 2026-11-28T10:00:00Z: an event for the open pages (util\Daemon::push)
	    case 'PUSH':
		try {
		    $this->push_publish($cmd[1] ?? '');
		} catch (\Throwable $e) {
		    \V::app()->log->error('WMGR: live events: ' . $e->getMessage());
		}
		break;

	    default:
		\V::app()->log->warn("WMGR: do not know how to handle command: $message");
		break;
	}
    }

    public function close_job_listener() {
	foreach($this->job_conns as $c) {
	    $this->loop->removeReadStream($c['stream']);
	    @fclose($c['stream']);
	}
	$this->job_conns = [];

	if (!empty($this->job_listener)) {
	    $this->loop->removeReadStream($this->job_listener);
	    @fclose($this->job_listener);
	    $this->job_listener = null;
	}
    }

    //CLD: 2026-11-28T10:00:00Z: the browsers' live events (Server-Sent Events), a plain TCP stream server on app.daemon_listen:app.daemon_push_port (DAEMON_PUSH_PORT),
    // reached through nginx (location /push). GET /push?t=<token> (util\Daemon::pushToken): the stream stays open, PUSH:<json> of the command port (util\Daemon::push)
    // goes to the streams whose claims hold each key of its scope, as `data: {"type", "data"}`; a comment every 25 s keeps them open (push_tick), an expired
    // ticket gets `event: expired` and is closed (the page asks for a new one). A failure stays here (logged, that stream closed): the jobs never wait for it;
    // a page that reads too slowly is dropped (PUSH_MAX_BACKLOG). A port taken: logged, tried again at the next check
    const PUSH_MAX_CONNS = 5000;
    const PUSH_MAX_HEAD = 8192;
    const PUSH_MAX_BACKLOG = 262144;

    public function setup_push_listener() {
	if (!empty($this->push_listener))
	    return;

	$address = \util\Daemon::pushAddress();
	$server = @stream_socket_server($address, $errno, $errstr);
	if (!$server) {
	    \V::app()->log->error("WMGR: cannot serve the live events on {$address}: {$errstr}");
	    return;
	}
	\V::app()->log->info("WMGR: live events on {$address}");
	stream_set_blocking($server, false);
	$this->push_listener = $server;

	$this->loop->addReadStream($server, function($server) {
	    $conn = @stream_socket_accept($server, 0);
	    if (!$conn)
		return;
	    if (count($this->push_conns) >= self::PUSH_MAX_CONNS) {
		@fwrite($conn, "HTTP/1.1 503 Service Unavailable\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");
		@fclose($conn);
		return;
	    }
	    stream_set_blocking($conn, false);
	    $id = (int)$conn;
	    $this->push_conns[$id] = [ 'stream' => $conn, 'head' => '', 'claims' => null, 'out' => '', 'since' => time(), 'writing' => false, 'closing' => false ];
	    $this->loop->addReadStream($conn, function($conn) use ($id) {
		try {
		    $this->push_read($id);
		} catch (\Throwable $e) {
		    \V::app()->log->error('WMGR: live events: ' . $e->getMessage());
		    $this->push_close($id);
		}
	    });
	});
    }

    //the request, until its empty line; after it only the end of the stream matters
    protected function push_read($id) {
	if (!isset($this->push_conns[$id]))
	    return;
	$stream = $this->push_conns[$id]['stream'];
	$chunk = @fread($stream, 8192);
	if ($chunk === false || ($chunk === '' && feof($stream))) {
	    $this->push_close($id);
	    return;
	}
	if ($this->push_conns[$id]['claims'] !== null || $this->push_conns[$id]['closing'])
	    return;

	$head = $this->push_conns[$id]['head'] . $chunk;
	if (strlen($head) > self::PUSH_MAX_HEAD) {
	    $this->push_reply($id, '431 Request Header Fields Too Large');
	    return;
	}
	if (strpos($head, "\r\n\r\n") === false) {
	    $this->push_conns[$id]['head'] = $head;
	    return;
	}
	$this->push_conns[$id]['head'] = '';

	if (!preg_match('#^GET (/push(?:\?\S*)?) HTTP/1\.[01]\r\n#', $head, $m)) {
	    $this->push_reply($id, '400 Bad Request');
	    return;
	}
	parse_str((string)parse_url($m[1], PHP_URL_QUERY), $query);
	$claims = \util\Daemon::pushClaims(is_string($query['t'] ?? null) ? $query['t'] : '');
	if (!$claims) {
	    $this->push_reply($id, '401 Unauthorized');
	    return;
	}
	$this->push_conns[$id]['claims'] = $claims;
	//X-Accel-Buffering: nginx passes each event at once; retry: the browser's wait before it connects again
	$this->push_write($id, "HTTP/1.1 200 OK\r\nContent-Type: text/event-stream\r\nCache-Control: no-cache, no-store\r\nX-Accel-Buffering: no\r\nConnection: close\r\n\r\nretry: 5000\n\n");
	\V::app()->log->debug('WMGR: live events: a page of account ' . ($claims['aid'] ?? '-') . ' connected (' . count($this->push_conns) . ' open)');
    }

    //an answer that ends the stream (sent, then closed)
    protected function push_reply($id, $status) {
	$this->push_conns[$id]['closing'] = true;
	$this->push_write($id, "HTTP/1.1 {$status}\r\nContent-Type: text/plain\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");
    }

    protected function push_write($id, $data) {
	if (!isset($this->push_conns[$id]))
	    return;
	if (strlen($this->push_conns[$id]['out']) + strlen($data) > self::PUSH_MAX_BACKLOG) {
	    \V::app()->log->debug('WMGR: live events: a page reads too slowly, closed');
	    $this->push_close($id);
	    return;
	}
	$this->push_conns[$id]['out'] .= $data;
	if (!$this->push_conns[$id]['writing'])
	    $this->push_flush($id);
    }

    //what the socket takes now; the rest when it is writable again
    protected function push_flush($id) {
	if (!isset($this->push_conns[$id]))
	    return;
	$c = $this->push_conns[$id];
	$n = $c['out'] === '' ? 0 : @fwrite($c['stream'], $c['out']);
	if ($n === false) {
	    $this->push_close($id);
	    return;
	}
	$out = (string)substr($c['out'], $n);
	$this->push_conns[$id]['out'] = $out;
	if ($out === '') {
	    if ($c['writing']) {
		$this->loop->removeWriteStream($c['stream']);
		$this->push_conns[$id]['writing'] = false;
	    }
	    if ($c['closing'])
		$this->push_close($id);
	} elseif (!$c['writing']) {
	    $this->push_conns[$id]['writing'] = true;
	    $this->loop->addWriteStream($c['stream'], function() use ($id) {
		try {
		    $this->push_flush($id);
		} catch (\Throwable $e) {
		    \V::app()->log->error('WMGR: live events: ' . $e->getMessage());
		    $this->push_close($id);
		}
	    });
	}
    }

    protected function push_close($id) {
	if (!isset($this->push_conns[$id]))
	    return;
	$c = $this->push_conns[$id];
	unset($this->push_conns[$id]);
	$this->loop->removeReadStream($c['stream']);
	if ($c['writing'])
	    $this->loop->removeWriteStream($c['stream']);
	@fclose($c['stream']);
    }

    //PUSH:<json> { type, scope: { key: value }, data }: to the streams whose claims hold each key of the scope (a claim may be a list); an empty scope: to none
    protected function push_publish($json) {
	$msg = json_decode((string)$json, true);
	$type = is_array($msg) ? ($msg['type'] ?? null) : null;
	$scope = is_array($msg) ? ($msg['scope'] ?? null) : null;
	if (!is_string($type) || !preg_match('/^[A-Za-z0-9_.:-]{1,100}$/', $type) || !is_array($scope) || !$scope || isset($scope['exp'])) {
	    \V::app()->log->warn('WMGR: live events: not an event: ' . substr((string)$json, 0, 200));
	    return;
	}
	$frame = 'data: ' . \util\JSON::encode([ 'type' => $type, 'data' => (object)(is_array($msg['data'] ?? null) ? $msg['data'] : []) ]) . "\n\n";
	$sent = 0;
	foreach($this->push_conns as $id => $c) {
	    if ($c['claims'] === null || $c['closing'])
		continue;
	    foreach($scope as $key => $value) {
		if (!isset($c['claims'][$key]) || !in_array((string)$value, array_map('strval', (array)$c['claims'][$key]), true))
		    continue 2;
	    }
	    $this->push_write($id, $frame);
	    $sent++;
	}
	\V::app()->log->debug("WMGR: live events: {$type} to {$sent} page(s)");
    }

    //every 25 s: a comment on each stream (nginx and the proxies keep it open), the expired tickets told and closed, requests not finished in 10 s closed
    protected function push_tick() {
	$now = time();
	foreach($this->push_conns as $id => $c) {
	    if ($c['closing'])
		continue;
	    if ($c['claims'] === null) {
		if ($c['since'] < $now - 10)
		    $this->push_close($id);
	    } elseif ((int)($c['claims']['exp'] ?? 0) <= $now) {
		$this->push_conns[$id]['closing'] = true;
		$this->push_write($id, "event: expired\ndata: {}\n\n");
	    } else {
		$this->push_write($id, ": ping\n\n");
	    }
	}
    }

    public function close_push_listener() {
	foreach(array_keys($this->push_conns) as $id)
	    $this->push_close($id);
	if (!empty($this->push_listener)) {
	    $this->loop->removeReadStream($this->push_listener);
	    @fclose($this->push_listener);
	    $this->push_listener = null;
	}
    }

    //number of CPUs (for the --max-workers default)
    protected static function cpu_count() {
	$n = 2;
	if (is_readable('/proc/cpuinfo'))
	    $n = preg_match_all('/^processor\s*:/m', (string)file_get_contents('/proc/cpuinfo'));
	return max(1, $n);
    }

    public function run_master() {
	//what this process changes is recorded in the execution log as the daemon's
	\models\ExecutionLog::$source = 'daemon';
	//CLD: 2026-11-23T14:00:00Z: the settings: defaults, app.env, the command line (readSettings)
	$this->config = $this->readSettings();

	//raise level to DEBUG if intructed
	\V::app()->log->setLevel('INFO');
	if ($this->config['debug'])
	    \V::app()->log->setLevel('DEBUG');

	\V::app()->log->setFacility($this->config['logfile']);
	\V::app()->log->prefixWithCaller(false);

	//workers are this command with --worker
	//CLD: 2026-11-23T14:00:00Z: with their PHP memory_limit when set (--worker-memory-limit)
	$this->worker_cmd = 'exec ' . escapeshellarg(PHP_BINARY)
	    . ($this->config['worker-memory-limit'] !== '' ? ' -d ' . escapeshellarg('memory_limit=' . $this->config['worker-memory-limit']) : '')
	    . ' -f ' . escapeshellarg(BASE . '/app/v.php') . " daemon::run --worker --logfile=" . escapeshellarg($this->config['worker-logfile']);
	if ($this->config['max-worker-memory'] > 0)
	    $this->worker_cmd .= ' --max-memory=' . (int)$this->config['max-worker-memory'];
	if ($this->config['debug'])
	    $this->worker_cmd .= ' --debug';


	//show this process title
	cli_set_process_title('[' . \V::app()->config('app.name') . ' Daemon ' . gethostname() . ']');

	//restart hung-up jobs
	//CLD: 2026-11-23T12:00:00Z: as a restart (reap_job): queued again, their executions continue (do_job); failed after 3 runs
	foreach(\V::app()->om->find('Job', [ 'state' => 'run' ], [ 'arrays' => true, 'projection' => [ '_id' => 1 ] ]) as $job)
	    $this->reap_job($job['_id'], 'the daemon restarted while it ran', true);

	//create the loop runner
	$this->loop = \React\EventLoop\Loop::get();

	//setup signal handlers
	//one handler for both signals, kept: removeSignal() finds it by identity
	$this->stopHandler = $this->stopMaster(...);

	$this->loop->addSignal(SIGINT, $this->stopHandler);
	$this->loop->addSignal(SIGTERM, $this->stopHandler);

	//create initial workers via a 1s timer
    	$this->loop->addTimer(1, function() {
	    //setup job_listener
	    $this->setup_job_listener();
	    //CLD: 2026-11-28T10:00:00Z: the live events
	    $this->setup_push_listener();

	    $this->ensure_workers();
    	});

	//add a 10s periodic timer
	// - keeps the minimum of workers, stops idle extra ones, gives up events over --job-timeout
	// - check if there are jobs in the queue and dispatch them
    	$this->loop->addPeriodicTimer(10, function() {
	    $this->ensure_workers();
	    $this->check_workers();

	    $this->new_jobs_count = \V::app()->om->count('Job', [ 'state' => 'new' ]);

	    $d_delta = time() - $this->last_dispatch_time;
	    if ($d_delta > 10 && !$this->dispatching && $this->new_jobs_count > 0) {
		\V::app()->log->info("WMGR: There are {$this->new_jobs_count} jobs in queue. Dispatch them...");
		$this->dispatch_jobs();
	    }

	    //check/setup job_listener
	    $this->setup_job_listener();
	    //CLD: 2026-11-28T10:00:00Z: the live events' listener too
	    $this->setup_push_listener();
    	});

	//CLD: 2026-11-28T10:00:00Z: the live events' streams kept open (push_tick)
	$this->loop->addPeriodicTimer(25, function() {
	    try {
		$this->push_tick();
	    } catch (\Throwable $e) {
		\V::app()->log->error('WMGR: live events: ' . $e->getMessage());
	    }
	});

	//add a 5min periodic timer
	// - warn via email if there are too many jobs in queue
	// - report some stats
    	$this->loop->addPeriodicTimer(5*60, function() {
	    //count jobs in new state; if greater than limit and increasing versus last time, issue a warning
	    $new_jobs_count = \V::app()->om->count('Job', [ 'state' => 'new' ]);
	    if ($new_jobs_count > 100 && $this->prev_jobs_count > 100) {
		\V::app()->log->warn("WMGR: there are more than 100 jobs in queue for more than 5 minutes, now: {$new_jobs_count}");
		\models\Job::reportError('too many jobs in queue', "WARNING: {$new_jobs_count} jobs waiting for more than 5 minutes");
	    }
    	    //save
    	    $this->prev_jobs_count = $new_jobs_count;

	    //SC: 2024-11-20: added
	    //re-run failed jobs no more than 3 times
	    //CLD: 2026-11-23T12:00:00Z: not those marked no_retry (reap_job)
	    $rerun = \V::app()->om->updateMany('Job',
		[ 'state' => 'fail', 'run_count' => [ '$lt' => 3 ], 'no_retry' => [ '$ne' => true ] ],
		[ '$set' => [ 'state' => 'new', 'worker_pid' => null ] ],
		[ 'writeConcern' => new \MongoDB\Driver\WriteConcern(\MongoDB\Driver\WriteConcern::MAJORITY) ]
	    );
	    //logged only when there are some
	    if ($rerun > 0) {
		\V::app()->log->info("re-run failed jobs count: " . $rerun);
		$this->dispatch_jobs();
	    }
    	});

	//add a 2-hour periodic timer: cleanup AuditLog and ExecutionLog
	$this->loop->addPeriodicTimer(2*60*60, function() {
	    //get all accounts
	    $accounts = \V::app()->om->find('Account');
	    foreach($accounts as $acct) {
		//audit log
		//AuditLog::prune: at least 1 year (AuditLog::MIN_RETENTION_DAYS), the seal keeps what the retention removed, and its state
		//is logged
		\models\AuditLog::prune($acct);
		//execution log
		if ($acct->execution_log_retention > 0) {
		    $time_limit = new \MongoDB\BSON\UTCDateTime((time() - $acct->execution_log_retention * 60*60*24) * 1000);
		    \V::app()->om->deleteMany('ExecutionLog', [ 'aid' => $acct->_id, 'created_at' => [ '$lte' => $time_limit ] ]);
		}
		//the AI steps' traces: the same setting
		\models\agent\AgentTrace::prune($acct);
		//the conversations with agents and the runs that hold their text: the same setting
		\models\agent\AgentConversation::prune($acct);
	    }
	});


	//install 60s periodic timer, aligned to second 0
	$this->scheduler();

	\V::app()->log->info("WMGR: ready: min-workers={$this->config['min-workers']}, max-workers={$this->config['max-workers']}, "
	    . "max-per-account={$this->config['max-per-account']}, max-per-project={$this->config['max-per-project']}, max-worker-memory={$this->config['max-worker-memory']} MB, "
	    . "worker-memory-limit=" . $this->workerMemoryLimit() . ", job-timeout={$this->config['job-timeout']} s");
	//CLD: 2026-11-23T14:00:00Z: a worker is replaced only after its job: with memory_limit not above --max-worker-memory, it fails a job (PHP fatal error) before it is replaced
	$limit = self::bytes($this->workerMemoryLimit());
	if ($this->config['max-worker-memory'] > 0 && $limit > 0 && $limit <= $this->config['max-worker-memory'] * 1048576)
	    \V::app()->log->warn("WMGR: the workers' memory_limit (" . $this->workerMemoryLimit() . ") is not above --max-worker-memory ({$this->config['max-worker-memory']} MB): "
		. "a worker reaches it (a PHP fatal error, its job's executions end as error) before it is replaced; raise DAEMON_WORKER_MEMORY_LIMIT or lower DAEMON_MAX_WORKER_MEMORY");
	$this->loop->run();

	//stop the workers and wait (up to 5 s) for them to exit, so their running events are queued again
	\V::app()->log->info("WMGR: main loop exited, kill workers....");
	$this->stopping = true;
	$this->close_job_listener();
	//CLD: 2026-11-28T10:00:00Z: the pages connect again to the next daemon
	$this->close_push_listener();
	if ($this->timer) {
	    $this->loop->cancelTimer($this->timer);
	    $this->timer = null;
	}
	foreach ($this->workers as $pid => $w)
	    $w['process']->terminate(SIGTERM);

	$deadline = microtime(true) + 5;
	while (!empty($this->workers) && microtime(true) < $deadline) {
	    $this->loop->addTimer(0.1, function() { $this->loop->stop(); });
	    $this->loop->run();
	}

	foreach ($this->workers as $pid => $w) {
	    posix_kill($pid, SIGKILL);
	    $this->reap_job($w['jid'], 'the daemon stopped');
	}

	\V::app()->log->info("WMGR: exiting.");
    }

    //SIGINT / SIGTERM: the loop stops; both signals are then no longer handled
    public function stopMaster($signo) {
	\V::app()->log->info("WMGR: got signal $signo. Stopping....");
	$this->loop->removeSignal(SIGINT, $this->stopHandler);
	$this->loop->removeSignal(SIGTERM, $this->stopHandler);
	$this->loop->stop();
    }

    //-------------------------------------------------------------------
    //master or worker
    //CLD: 2026-11-23T14:00:00Z: the master's settings: the defaults, over them app.env's DAEMON_<OPTION> (config.php app.daemon; empty: the default), over them the command line;
    //checked (an exception says which is wrong and where it came from)
    protected function readSettings() {
	//min / max workers, per account / project limits, idle and job timeouts
	$config = [
	    'debug' => false,
	    'logfile' => 'daemon',
	    'worker-logfile' => 'daemon_worker',
	    'min-workers' => 2,
	    'max-workers' => 2 * self::cpu_count() - 1,
	    'max-per-account' => null,
	    'max-per-project' => null,
	    'idle-timeout' => 60,
	    'job-timeout' => 0,
	    //memory (MB) a worker may use before it is replaced
	    'max-worker-memory' => 256,
	    //the workers' PHP memory_limit ('': php.ini's)
	    'worker-memory-limit' => '',
	];
	$from = [];
	foreach((array)\V::app()->config('app.daemon') as $k => $v) {
	    if (in_array($k, \DAEMON_OPTIONS, true) && trim((string)$v) !== '') {
		$config[$k] = trim((string)$v);
		$from[$k] = 'DAEMON_' . strtoupper(str_replace('-', '_', $k)) . ' of app.env';
	    }
	}
	foreach(array_merge([ 'logfile', 'worker-logfile' ], \DAEMON_OPTIONS) as $p) {
	    if (isset(\V::app()->request->params[$p])) {
		$config[$p] = \V::app()->request->params[$p];
		$from[$p] = "--{$p}";
	    }
	}
	$where = fn($k) => $from[$k] ?? "--{$k}";

	//true / false (app.env: true, false, 1, 0, yes, no; the command line: --debug)
	$debug = filter_var($config['debug'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
	if ($debug === null)
	    throw new \ErrorException($where('debug') . ' must be true or false');
	$config['debug'] = $debug;

	//numbers; max >= min >= 1; per account / project default to max - 1 (at least 1), 0 = no limit
	foreach([ 'min-workers', 'max-workers', 'idle-timeout', 'job-timeout', 'max-worker-memory' ] as $k) {
	    if (!is_numeric($config[$k]) || $config[$k] < 0)
		throw new \ErrorException($where($k) . ' must be a number >= 0');
	    $config[$k] = (int)$config[$k];
	}

	$config['min-workers'] = max(1, $config['min-workers']);
	$config['max-workers'] = max($config['min-workers'], $config['max-workers']);
	foreach([ 'max-per-account', 'max-per-project' ] as $k) {
	    if ($config[$k] === null)
		$config[$k] = max(1, $config['max-workers'] - 1);
	    if (!is_numeric($config[$k]) || $config[$k] < 0)
		throw new \ErrorException($where($k) . ' must be a number >= 0 (0 = no limit)');
	    $config[$k] = (int)$config[$k];
	}

	//PHP's notation: a number of bytes, with K / M / G, or -1 (no limit)
	$config['worker-memory-limit'] = (string)$config['worker-memory-limit'];
	if ($config['worker-memory-limit'] !== '' && preg_match('/^(-1|[1-9]\d*[KMG]?)$/i', $config['worker-memory-limit']) !== 1)
	    throw new \ErrorException($where('worker-memory-limit') . ' must be a PHP memory_limit, e.g. 1024M, 2G or -1 (no limit)');
	return $config;
    }

    //CLD: 2026-11-23T14:00:00Z: the workers' memory_limit: --worker-memory-limit, else php.ini's (the master's)
    protected function workerMemoryLimit() {
	return $this->config['worker-memory-limit'] !== '' ? $this->config['worker-memory-limit'] : (string)ini_get('memory_limit');
    }

    //CLD: 2026-11-23T14:00:00Z: a memory_limit in bytes (-1: 0, no limit)
    protected static function bytes($limit) {
	if (preg_match('/^([1-9]\d*)([KMG]?)$/i', trim((string)$limit), $m) !== 1)
	    return 0;
	return (int)$m[1] * [ '' => 1, 'K' => 1024, 'M' => 1048576, 'G' => 1073741824 ][strtoupper($m[2])];
    }

    //CLD: 2026-11-23T14:00:00Z: daemon::settings: what the daemon starts with (each setting, where it comes from) and the warning of run_master; changes nothing
    public function settings() {
	$this->config = $this->readSettings();
	$env = array_filter((array)\V::app()->config('app.daemon'), fn($v) => trim((string)$v) !== '');
	foreach(\DAEMON_OPTIONS as $k) {
	    $v = $this->config[$k];
	    $v = is_bool($v) ? ($v ? 'true' : 'false') : ($k == 'worker-memory-limit' && $v === '' ? $this->workerMemoryLimit() . ' (php.ini)' : (string)$v);
	    $src = isset(\V::app()->request->params[$k]) ? "--{$k}" : (isset($env[$k]) ? 'DAEMON_' . strtoupper(str_replace('-', '_', $k)) : 'default');
	    printf("%-22s %-16s %s\n", $k, $v, $src);
	}
	$limit = self::bytes($this->workerMemoryLimit());
	if ($this->config['max-worker-memory'] > 0 && $limit > 0 && $limit <= $this->config['max-worker-memory'] * 1048576)
	    echo "warning: worker-memory-limit is not above max-worker-memory: a worker fails a job (PHP fatal error) before it is replaced\n";
    }

    public function run() {
	if (!empty(\V::app()->request->params['worker']))
	    return $this->run_worker();
	return $this->run_master();
    }

    //-------------------------------------------------------------------
    //worker
    public function do_job(&$job) {
	$account = null;

	//logout any user as well
	\V::app()->user->logout();

	$job_data = $job->job_data;

	if (is_array($job_data))
	    $job_data = (object)$job_data;
	if (!is_object($job_data))
	    $job_data = new \stdClass();

	//the account is the event's (aid at the top level), else job_data's
	if (empty($job_data->aid) && !empty($job->aid))
	    $job_data->aid = $job->aid;

	if (!empty($job_data->aid)) {
	    $account = \V::app()->var('rt.account');
	    if (empty($account) || (string)$account->_id != (string)$job_data->aid) {
		$account = \V::app()->om->findById('Account', (string)$job_data->aid);
		if (empty($account))
		    throw new \Exception("account {$job_data->aid} not found");
		\V::app()->var('rt.account', $account);
	    }

	    $job_data->aid = \V::app()->om->to_oid($job_data->aid);
	}

	cli_set_process_title($this->base_process_title . ' ' . $job->job_spec . '::' . $this->crt_job_id);

	//handle job-spec
	//decode job_spec
	//a job that cannot run fails (exception -> state fail + error)
	$job_spec = explode('::', (string)$job->job_spec);
	if (count($job_spec) != 2)
	    throw new \Exception("invalid job_spec: {$job->job_spec}");

	$job_class = $job_spec[0];
	$job_method = $job_spec[1];

	if (!class_exists($job_class))
	    throw new \Exception("unknown class `{$job_class}`");

	if (!method_exists($job_class, $job_method))
	    throw new \Exception("unknown method `{$job_method}` in class `{$job_class}`");

	//ok, run it
	//each job starts without the step definitions cached by an earlier one (models\wf\WfNode::getAll: per request)
	\models\wf\WfNode::resetCache();
	//...and without the data tables / columns cached by an earlier one (another process may have changed them)
	\V::app()->om->resetCache();
	//CLD: 2026-11-23T12:00:00Z: run again after the daemon restarted while it ran: what it started continues (continue_executions), the work is not done again
	if ((int)$job->run_count > 1 && $this->continue_executions($job))
	    return;
	$job_class::$job_method($job_data);
    }

    //CLD: 2026-11-23T12:00:00Z: a job run again after the daemon restarted while it ran (reap_job): the executions it had started (WfExecution `job`) continue from their
    //last save, instead of the work starting new ones (e.g. a Schedule run started a second time); a nested one (a Run a workflow step waiting for it, in the same job)
    //ends as error, its caller runs that step again. False when it had started none: the work runs as usual
    protected function continue_executions($job) {
	$jid = (string)$job->_id;
	$exes = [];
	foreach(\V::app()->om->find('WfExecution', [ 'job' => $jid ], [ 'projection' => [ '_id' => 1, 'aid' => 1, 'state' => 1, 'input' => 1 ] ]) as $exe)
	    $exes[(string)$exe->_id] = $exe;
	if (empty($exes))
	    return false;

	$top = $nested = [];
	foreach($exes as $id => $exe) {
	    if (!in_array($exe->state, [ 'new', 'run' ], true))
		continue;
	    $caller = is_array($exe->input) ? ($exe->input['caller'] ?? null) : null;
	    if (is_array($caller) && !empty($caller['wait']) && isset($exes[(string)($caller['execution'] ?? '')]))
		$nested[] = $id;
	    else
		$top[] = $id;
	}
	if ($nested)
	    \models\wf\WfExecution::endLost($jid, 'the daemon restarted while it ran; the workflow that called it runs its step again', $nested);
	foreach($top as $id) {
	    $exe = \V::app()->om->findOne('WfExecution', [ '_id' => $exes[$id]->_id, 'aid' => $exes[$id]->aid ]);
	    if (empty($exe) || !in_array($exe->state, [ 'new', 'run' ], true))
		continue;
	    \V::app()->log->info("Worker[{$this->pid}]: job {$jid}: execution {$id} continues after a daemon restart");
	    $exe->log(null, 'continued after a daemon restart (from its last save: the steps since may run again)');
	    $outer = \models\ExecutionLog::$execution;
	    \models\ExecutionLog::$execution = $exe;
	    \models\ExecutionLog::record('execution.continue', null, 'after a daemon restart');
	    \models\ExecutionLog::$execution = $outer;
	    $exe->run();
	}
	return true;
    }

    //one line from the master: NEWJOB:<id> or PONG
    protected function worker_command($msg) {
	$cmd = explode(':', $msg, 2);

	//keepalive
	if ($cmd[0] == 'PONG') {
	    $this->master_last_seen = time();
	    return;
	}

	//leaving: no new job (the master queues it again when this process ends)
	if ($this->exiting)
	    return;

	if ($cmd[0] != 'NEWJOB') {
	    //unknown command/message
	    if ($this->config['debug'])
		\V::app()->log->info("Worker[{$this->pid}]: unknown command: {$cmd[0]}");
	    return;
	}

	//---------------- NEWJOB -------------------
	$jobid = trim($cmd[1] ?? '');
	if ($jobid === '') {
	    if ($this->config['debug'])
		\V::app()->log->info("Worker[{$this->pid}]: invalid NEWJOB spec: missing `jobid`");
	    $this->stdout->write("IDLE\n");
	    return;
	}

	$job = \util\Validator::rule('oid', $jobid)
	    ? \V::app()->om->findById('Job', $jobid, [ 'readConcern' => new \MongoDB\Driver\ReadConcern(\MongoDB\Driver\ReadConcern::MAJORITY) ])
	    : null;
	//also when it is not (any more) assigned to this worker
	if (empty($job) || $job->state != 'run' || (int)$job->worker_pid !== (int)$this->pid) {
	    if ($this->config['debug'])
		\V::app()->log->info("Worker[{$this->pid}]: {$jobid} has gone");
	    $this->stdout->write("RELEASE:$jobid\n");
	    return;
	}

	//inform manager
	$this->stdout->write("BUSY:{$jobid}\n");
	$this->job_counter++;
	$this->crt_job_id = $jobid;

	//process the job
	$job_spec = $job->job_spec;
	try {
	    $start = microtime(true);
	    \V::app()->log->info("Worker[{$this->pid}]: {$job_spec}: START: --------------------------------------------------");

	    //moved to a function so that we can test it
	    //CLD: 2026-11-23T12:00:00Z: the executions it runs record it (models\Job::$current)
	    \models\Job::$current = $jobid;
	    $this->do_job($job);

	    //done, update status
	    if ($job->delete_on_done) {
		$job->delete();
	    } else {
		$job->state = 'done';
		$job->finished_at = new \MongoDB\BSON\UTCDateTime();
		$job->save([ 'writeConcern' => new \MongoDB\Driver\WriteConcern(\MongoDB\Driver\WriteConcern::MAJORITY) ]);
	    }

	    //mark timing
	    $delta = sprintf("%01.3f", (microtime(true) - $start)*1000 );
	    $mem = (int)(memory_get_usage() / (1024*1024));
	    \V::app()->log->info("Worker[{$this->pid}]: {$job_spec}: STOP: $delta ms, $mem MB -----------------------------------");
	} catch(\Exception|\Error $e) {
	    \V::app()->log->info("Worker[{$this->pid}]: {$jobid} failed: " . $e->getMessage());
	    //if error, mark job failed + the error and when
	    try {
		$job->state = 'fail';
		$job->error = mb_substr(get_class($e) . ': ' . $e->getMessage(), 0, 2000);
		$job->finished_at = new \MongoDB\BSON\UTCDateTime();
		$job->save([ 'writeConcern' => new \MongoDB\Driver\WriteConcern(\MongoDB\Driver\WriteConcern::MAJORITY) ]);
	    } catch (\Throwable $e2) {
		\V::app()->log->error("Worker[{$this->pid}]: {$jobid}: cannot save the failure: " . $e2->getMessage());
	    }

	    \models\Job::reportError("job {$jobid} ({$job_spec}) failed", $e);
	    //mark in the account's execution log (jobs without an account: daemon log only)
	    if (!empty($job->aid))
		\models\ExecutionLog::record('job.failed', null, "job {$jobid} ({$job_spec}): " . $e->getMessage(), [], null, $job->aid);
	} //try-catch block

	$this->crt_job_id = null;
	\models\Job::$current = null;
	//reset for long running processes
	$this->master_last_seen = time();

	$leaving = $this->over_memory();
	if ($leaving)
	    $this->stdout->write("EXIT\n");
	$this->stdout->write("DONE:{$jobid}\n");
	cli_set_process_title($this->base_process_title);
	if ($leaving)
	    $this->leave();
    }

    //memory PHP holds from the system (MB) over --max-memory: exit once the pending output is written; the master
    //starts a new worker. Not while a job runs (that would lose it): checked after each job and while idle
    //split into over_memory() (the check) and leave() (EXIT is written by the callers, before DONE after a job)
    protected function over_memory() {
	$limit = (int)$this->config['max-memory'];
	$used = memory_get_usage(true) / 1048576;
	if ($limit <= 0 || $used <= $limit || !empty($this->crt_job_id) || $this->exiting)
	    return false;
	\V::app()->log->info(sprintf("Worker[%d]: uses %.1f MB, over the limit of %d MB: exit, the master starts a new worker", $this->pid, $used, $limit));
	return true;
    }

    protected function leave() {
	$this->exiting = true;
	$this->stdout->on('close', function() {
	    $this->loop->stop();
	});
	$this->stdout->end();
    }

    public function run_worker() {
	//what this process changes is recorded in the execution log as the daemon's (executions: their trigger)
	\models\ExecutionLog::$source = 'daemon';
    	//parse params, set some defaults
	$this->config = [
	    'debug'	=> false,
	    'logfile'	=> 'job_worker',
	    'max-memory' => 0,
	];
	$params = [ 'debug', 'logfile', 'max-memory' ];
	foreach($params as $p) {
	    if (isset(\V::app()->request->params[$p]))
		$this->config[$p] = \V::app()->request->params[$p];
	}

	//raise level to DEBUG if intructed
	\V::app()->log->setLevel('INFO');
	if ($this->config['debug'])
	    \V::app()->log->setLevel('DEBUG');
	\V::app()->log->setFacility($this->config['logfile']);
	\V::app()->log->prefixWithCaller(false);

	//get my pid
	$this->pid = getmypid();

	//show this process title
	$this->base_process_title = '[' . \V::app()->config('app.name') . ' Worker ' . gethostname() . ' ' . $this->pid . ']';
	cli_set_process_title($this->base_process_title);


	//create the loop runner
	$this->loop = \React\EventLoop\Loop::get();

	//setup signal handlers
	//one handler for both signals, kept: removeSignal() finds it by identity
	$this->stopHandler = $this->stopWorker(...);

	$this->loop->addSignal(SIGINT, $this->stopHandler);
	$this->loop->addSignal(SIGTERM, $this->stopHandler);

	$this->stdin = new \React\Stream\ReadableResourceStream(fopen('php://stdin', 'r+'), $this->loop);
	$this->stdout = new \React\Stream\WritableResourceStream(fopen('php://stdout', 'w+'), $this->loop);

	//lines are buffered across chunks and handled one by one (worker_command)
	$this->stdin->on('data', function ($chunk) {
	    $this->stdin_buffer .= $chunk;
	    while (($nl = strpos($this->stdin_buffer, "\n")) !== false) {
		$msg = trim(substr($this->stdin_buffer, 0, $nl));
		$this->stdin_buffer = substr($this->stdin_buffer, $nl + 1);
		if ($msg !== '')
		    $this->worker_command($msg);
	    }
	});
	//the master is gone (pipe closed)
	$this->stdin->on('close', function() {
	    \V::app()->log->info("Worker[{$this->pid}]: master closed the pipe, exit");
	    $this->loop->stop();
	});

	//add a 10sec periodic timer: keepalive + the memory check while idle
    	$this->loop->addPeriodicTimer(10, function() {
	    if ($this->exiting)
		return;
	    if ($this->over_memory()) {
		$this->stdout->write("EXIT\n");
		$this->leave();
		return;
	    }
	    //SC: 2025-01-09: added keepalive check - only if IDLE
	    if (empty($this->crt_job_id)) {
		if (time() - $this->master_last_seen > 30) {
		    \V::app()->log->error("Worker[{$this->pid}]: master not alive for 30 seconds:" . (time() - $this->master_last_seen));
		    $this->loop->stop();
		}
		$this->stdout->write("PING\n");
	    }
    	});

	\V::app()->log->info("Worker[{$this->pid}]: starting...");

	//init
	$this->master_last_seen = time();

	$this->stdout->write("IDLE\n");
        $this->loop->run();

	\V::app()->log->info("Worker[{$this->pid}]: exiting....");
    }

    //SIGINT / SIGTERM: the loop stops; both signals are then no longer handled
    public function stopWorker($signo) {
	\V::app()->log->info("Worker[{$this->pid}]: got signal $signo. Stopping....");
	$this->loop->removeSignal(SIGINT, $this->stopHandler);
	$this->loop->removeSignal(SIGTERM, $this->stopHandler);
	$this->loop->stop();
    }
}
