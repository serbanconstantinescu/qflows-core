<?php

/*
    CLD: 2026-11-26T02:00:00+02:00: the public endpoint of the Okta event trigger's event hook (/okta/<token>, NodeTriggerOkta, Receive: an event hook):
	GET with x-okta-verification-challenge  Okta's one-time verification when the hook is made: { verification: the challenge }
	POST { data: { events: [ … ] } }         its Authorization header must be the step's secret; each event of the step's types and outcome (and not taken already): a run of
	                                         the published version; answered 200 at once (Okta waits 3 seconds, and retries once)
    Refusals (unknown token, the secret, not published) as HookController's, logged for the workflow
*/

namespace controllers;

use \models\node\NodeTriggerOkta;

class OktaController extends \sys\APIController {

    const REFUSED = 'okta.refused';

    //called by Okta: no CSRF token, the URL's token and the Authorization header are the credential
    public function receive($req, $resp) {
	[ $entry, $wf, $live, $node ] = $this->triggerOf('trigger::okta', (string)($req->query['id'] ?? ''));
	$cfg = (array)$node['config'];
	if (($cfg['receive'] ?? 'poll') !== 'hook')
	    $this->refuse(409, 'The step does not receive an event hook (Receive: Read the System Log on a schedule)');
	if (!\util\vendor\Okta::verify((string)($_SERVER['HTTP_AUTHORIZATION'] ?? ''), (string)($cfg['secret'] ?? '')))
	    $this->refuse(401, 'Unauthorized (the Authorization header is not the step\'s secret)');
	$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? '');
	if ($method === 'GET') {
	    $challenge = (string)($_SERVER['HTTP_X_OKTA_VERIFICATION_CHALLENGE'] ?? '');
	    if ($challenge === '')
		$this->refuse(400, 'No verification challenge');
	    $resp->sendJSON([ 'verification' => mb_substr($challenge, 0, 500) ]);
	    return;
	}
	if ($method !== 'POST') {
	    $resp->addHeader('Allow', 'GET, POST');
	    $this->refuse(405, 'Method not allowed');
	}
	$raw = $req->body();
	if (strlen($raw) > \data\Constants::MAX_REQUEST_BODY)
	    $this->refuse(413, 'Request body too large');
	$data = json_decode($raw, true);
	if (!is_array($data) || !is_array($data['data']['events'] ?? null))
	    $this->refuse(400, 'Not an Okta event hook\'s request');
	$this->requireRunnable($wf, $live);
	$events = [];
	foreach(array_slice($data['data']['events'], 0, NodeTriggerOkta::MAX_RUNS) as $e)
	    if (is_array($e) && NodeTriggerOkta::wanted($cfg, $e) && !NodeTriggerOkta::seenEvent($wf, $entry->node, (string)($e['uuid'] ?? '')))
		$events[] = self::clean($e);
	$started = $events ? NodeTriggerOkta::startRuns($live, $node, $events) : [];
	$resp->sendJSON([ 'state' => $started ? 'accepted' : 'ignored', 'executions' => array_map(fn($x) => (string)$x->_id, $started), 'events' => count($data['data']['events']) ]);
    }
}
