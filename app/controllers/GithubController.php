<?php

/*
    CLD: 2026-11-25T21:00:00+02:00: the public endpoint of the GitHub event trigger receiving a webhook (/github/<token>, NodeTriggerGitHub, Receive: a webhook): GitHub POSTs JSON
    (content type application/json; a form's payload= is read too), signed with the step's secret (X-Hub-Signature-256), the event in X-GitHub-Event, the delivery's ID in
    X-GitHub-Delivery. ping (when the webhook is made) answered; an event chosen (and its actions, branches, repository): one run of the published version, each delivery once.
    Anything else is answered 200 and starts nothing. Refusals (unknown token, signature, not published, errors) as HookController's, logged for the workflow
*/

namespace controllers;

use \models\node\NodeTriggerGitHub;

class GithubController extends \sys\APIController {

    const REFUSED = 'github.refused';

    //called by GitHub: no CSRF token, the URL's token and the signature are the credential
    public function receive($req, $resp) {
	[ $entry, $wf, $live, $node ] = $this->triggerOf('trigger::github', (string)($req->query['id'] ?? ''));
	$cfg = (array)$node['config'];

	if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
	    $resp->addHeader('Allow', 'POST');
	    $this->refuse(405, 'Method not allowed, use POST');
	}
	if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > \data\Constants::MAX_REQUEST_BODY)
	    $this->refuse(413, 'Request body too large');
	$raw = $req->body();
	if (strlen($raw) > \data\Constants::MAX_REQUEST_BODY)
	    $this->refuse(413, 'Request body too large');
	if (($cfg['receive'] ?? 'webhook') !== 'webhook')
	    $this->refuse(409, 'The step does not receive a webhook (Receive: Check a repository on a schedule)');
	if (!\util\GitHub::verify($raw, (string)($_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? ''), (string)($cfg['secret'] ?? '')))
	    $this->refuse(401, 'Unauthorized (the signature does not match the step\'s secret)');
	$body = $raw;
	if (str_starts_with(strtolower((string)($_SERVER['CONTENT_TYPE'] ?? '')), 'application/x-www-form-urlencoded')) {
	    parse_str($raw, $form);
	    $body = (string)($form['payload'] ?? '');
	}
	$data = json_decode($body, true);
	if (!is_array($data))
	    $this->refuse(400, 'Not JSON');
	$event = (string)($_SERVER['HTTP_X_GITHUB_EVENT'] ?? '');
	if ($event === 'ping') {
	    $resp->sendJSON([ 'state' => 'pong', 'zen' => mb_substr((string)($data['zen'] ?? ''), 0, 200) ]);
	    return;
	}
	$this->requireRunnable($wf, $live);
	$delivery = (string)($_SERVER['HTTP_X_GITHUB_DELIVERY'] ?? '');
	$value = NodeTriggerGitHub::valueOf($event, $data, $delivery);
	if (!NodeTriggerGitHub::wanted($cfg, $value)) {
	    $resp->sendJSON([ 'state' => 'ignored', 'event' => mb_substr($event, 0, 100), 'action' => mb_substr((string)($value['action'] ?? ''), 0, 100) ]);
	    return;
	}
	if (NodeTriggerGitHub::seenDelivery($wf, $entry->node, $delivery)) {
	    $resp->sendJSON([ 'state' => 'duplicate' ]);
	    return;
	}
	$started = NodeTriggerGitHub::startRuns($live, $node, [ $value + [ 'data' => self::clean($data) ] ]);
	$resp->sendJSON([ 'execution' => (string)$started[0]->_id, 'state' => 'accepted' ]);
    }
}
