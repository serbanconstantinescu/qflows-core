<?php

/*
    CLD: 2026-11-28T10:00:00Z: toasts for the people an event concerns, on the pages they have open (app.live: toast::live, app.utils.toast); nothing is kept: a person
    with no page open does not see it (the error emails are kept as they are). Each goes to one user (scope aid + user), so its text can name what they may see:
	runStopped($exe)        (hook execution:stopped) a run failed: to who started it (a manual Run: input.user), else to who may change the workflow (Workflow::update),
				at most once in FAIL_EVERY s per workflow (a schedule failing every minute), the next one saying how many failed since (the workflow's
				toast_failed_at, toast_failed_more: written by an atomic update only, not in its schema);
				a run started by hand that took LONG_RUN s or more completed: to who started it (not on that workflow's designer: the panel says it). Not a test,
				not a Run again
	taskOpened($task)       a Human task or an AI step's approval: to the members who may complete it (WfTask::isFor)
	shared($resource, $project)   a workflow / data table shared with $project: to its members
	addedTo($project, $user)      $user added to $project
	send(...)               also by the background work a person started, when it ends (a knowledge base's re-index: KnowledgeBase::reindexJob)
    The one who did it is not told (the current user)
*/

namespace util;

class Toasts {
    //seconds: a run started by hand that took this long is told when it completes
    const LONG_RUN = 30;
    //characters of an error in the toast
    const MAX_TEXT = 200;
    //seconds: a workflow's failures told to its editors at most once in so long
    const FAIL_EVERY = 900;

    //one toast to user $user of account $aid: level success | info | warning | error, the title and text (plain), a link (url, its label); $more: wf (not shown on that
    //workflow's designer), full (the link loads the whole page)
    public static function send($user, $aid, $level, $title, $body, $url = null, $label = 'Open', array $more = []) {
	if (empty($user) || empty($aid))
	    return;
	Daemon::push('toast::live', [ 'aid' => $aid, 'user' => $user ], [ 'level' => (string)$level, 'title' => mb_substr((string)$title, 0, 120), 'body' => mb_substr((string)$body, 0, self::MAX_TEXT),
	    'url' => $url, 'label' => (string)$label ] + $more);
    }

    //the ids of the project's members, the current user left out
    private static function members($project) {
	$me = \V::app()->user && !\V::app()->user->isGuest ? (string)\V::app()->user->_id : '';
	return array_values(array_filter(array_map('strval', array_keys((array)($project->members ?? []))), fn($id) => $id !== $me));
    }

    //hook execution:stopped
    public static function runStopped($exe) {
	if (!empty($exe->test_run) || !empty($exe->rerun_of) || (string)$exe->trigger === 'test' || !in_array((string)$exe->state, [ 'end', 'error' ], true))
	    return;
	$wf = \V::app()->om->findOne('Workflow', [ '_id' => $exe->wf, 'aid' => $exe->aid ], [ 'projection' => [ 'nodes' => 0 ] ]);
	if (!$wf)
	    return;
	$input = is_array($exe->input) ? $exe->input : [];
	$starter = \util\Validator::rule('oid', (string)($input['user'] ?? '')) ? (string)$input['user'] : '';
	$url = \V::app()->createUrl('/workflow/index', [ 'id' => $wf->_id, 'execution' => $exe->_id ]);
	$name = (string)$wf->name;

	if ($exe->state === 'error') {
	    $title = $name . ' failed';
	    if ($starter !== '') {
		$to = [ $starter ];
	    } else {
		//the first failure in FAIL_EVERY s takes the turn (one process only), the others are counted for the next toast
		$now = time();
		$turn = \V::app()->om->findOneAndUpdate('Workflow', [ '_id' => $wf->_id, 'aid' => $wf->aid, '$or' => [ [ 'toast_failed_at' => [ '$lt' => new \MongoDB\BSON\UTCDateTime(($now - self::FAIL_EVERY) * 1000) ] ],
		    [ 'toast_failed_at' => null ] ] ], [ '$set' => [ 'toast_failed_at' => new \MongoDB\BSON\UTCDateTime($now * 1000), 'toast_failed_more' => 0 ] ],
		    [ 'arrays' => true, 'returnDocument' => 'before', 'projection' => [ 'toast_failed_more' => 1 ] ]);
		if (!$turn) {
		    \V::app()->om->updateOne('Workflow', [ '_id' => $wf->_id, 'aid' => $wf->aid ], [ '$inc' => [ 'toast_failed_more' => 1 ] ]);
		    return;
		}
		$more = (int)($turn['toast_failed_more'] ?? 0);
		if ($more > 0)
		    $title .= ' (and ' . $more . ' more since the last notice)';
		$project = \V::app()->om->findOne('Project', [ '_id' => $wf->project, 'aid' => $wf->aid ]);
		$to = array_values(array_filter($project ? self::members($project) : [], fn($id) => $wf->checkAccess($id, 'Workflow::update')));
	    }
	    foreach($to as $id)
		self::send($id, $exe->aid, 'error', $title, (string)($exe->error ?: 'The run ended with an error'), $url, 'Open the run');
	    return;
	}

	//completed: a long run started by hand
	$start = $exe->start_at instanceof \MongoDB\BSON\UTCDateTime ? (int)(string)$exe->start_at : null;
	$end = $exe->end_at instanceof \MongoDB\BSON\UTCDateTime ? (int)(string)$exe->end_at : (int)(microtime(true) * 1000);
	if ($starter === '' || $start === null || ($end - $start) < self::LONG_RUN * 1000)
	    return;
	self::send($starter, $exe->aid, 'success', $name . ' completed', 'In ' . \util\Format::duration($end - $start), $url, 'Open the run', [ 'wf' => (string)$wf->_id ]);
    }

    //a new task
    public static function taskOpened($task) {
	$project = \V::app()->om->findOne('Project', [ '_id' => $task->project, 'aid' => $task->aid ]);
	if (!$project)
	    return;
	$ids = self::members($project);
	if (!$ids)
	    return;
	$title = (string)$task->kind === \models\node\NodeAppAi::APPROVAL_KIND ? 'An agent asks for approval' : 'New task';
	foreach(\V::app()->om->find('User', [ '_id' => [ '$in' => array_map(fn($id) => new \MongoDB\BSON\ObjectId($id), $ids) ], 'aid' => $task->aid, 'state' => 'active' ]) as $user) {
	    if ($task->isFor($user))
		self::send((string)$user->_id, $task->aid, 'info', $title, (string)$task->title, '/task/' . (string)$task->_id, 'Open the task');
	}
    }

    //$resource (a workflow, a data table) shared with project $project
    public static function shared($resource, $project) {
	foreach(self::members($project) as $id)
	    self::send($id, $project->aid, 'info', $resource::SHARE_WHAT . ' shared with ' . (string)$project->name, (string)$resource->name);
    }

    //$user added to project $project
    public static function addedTo($project, $user) {
	self::send((string)$user->_id, $project->aid, 'info', 'Added to a project', 'You are now a member of ' . (string)$project->name, \V::app()->createUrl('/site/switch_project', [ 'id' => $project->_id ]), 'Switch to it',
	    [ 'full' => true ]);
    }
}
