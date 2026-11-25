<?php

/*
    CLD: 2026-11-26T01:00:00+02:00: the public endpoint of the Entra ID change trigger's Graph change notifications (/entra/<token>, NodeTriggerEntra, Receive: notifications):
	?validationToken=…  Graph checks the address when the step subscribes: the token answered as text, at once
	POST { value: [ … ] } the notifications: each one's clientState must be the step's secret; the step's check is queued (util\Scheduler::run, one per minute at most), which
	                      reads what changed with its delta queries; answered 202 at once (Graph wants an answer within 3 seconds)
    Refusals (unknown token, clientState, not published) as HookController's, logged for the workflow
*/

namespace controllers;

class EntraController extends \sys\APIController {

    const REFUSED = 'entra.refused';

    //called by Graph: no CSRF token, the URL's token and the clientState are the credential
    public function receive($req, $resp) {
	//the validation comes before the workflow is checked: Graph sends it while the step subscribes, from its own check
	$validation = (string)($req->query['validationToken'] ?? '');
	[ $entry, $wf, $live, $node ] = $this->triggerOf('trigger::entra', (string)($req->query['id'] ?? ''));
	$cfg = (array)$node['config'];
	if ($validation !== '') {
	    $resp->addHeader('Content-Type', 'text/plain; charset=utf-8');
	    $resp->write(mb_substr($validation, 0, 2000), true);
	    return;
	}
	if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
	    $resp->addHeader('Allow', 'POST');
	    $this->refuse(405, 'Method not allowed, use POST');
	}
	$raw = $req->body();
	if (strlen($raw) > \data\Constants::MAX_REQUEST_BODY)
	    $this->refuse(413, 'Request body too large');
	$data = json_decode($raw, true);
	if (!is_array($data) || !is_array($data['value'] ?? null))
	    $this->refuse(400, 'Not Graph\'s notifications');
	$secret = (string)($cfg['secret'] ?? '');
	foreach($data['value'] as $n)
	    if (!is_array($n) || $secret === '' || !hash_equals($secret, (string)($n['clientState'] ?? '')))
		$this->refuse(401, 'Unauthorized (a clientState is not the step\'s)');
	$this->requireRunnable($wf, $live);
	$id = \models\Job::submit('\util\Scheduler::run', [ 'wf' => (string)$wf->_id, 'node' => (string)$entry->node, 'at' => gmdate(DATE_ATOM) ], [
	    'aid' => $wf->aid,
	    'project' => $wf->project,
	    'key' => 'entra:' . $wf->_id . ':' . $entry->node . ':' . gmdate('YmdHi'),
	    'delete_on_done' => true,
	    'notify' => false,
	]);
	$resp->sendJSON([ 'state' => $id !== null ? 'queued' : 'already queued', 'notifications' => count($data['value']) ], true, 202);
    }
}
