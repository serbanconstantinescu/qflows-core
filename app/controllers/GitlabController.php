<?php

/*
    CLD: 2026-11-26T00:00:00+02:00: the public endpoint of the GitLab event trigger receiving a webhook (/gitlab/<token>, NodeTriggerGitLab, Receive: a webhook): GitLab POSTs JSON
    with the step's secret token in X-Gitlab-Token, the kind in object_kind (and X-Gitlab-Event), the delivery's ID in X-Gitlab-Event-UUID (or Idempotency-Key). An event chosen
    (and its actions, branches, project): one run of the published version, each delivery once; others are answered 200 and start nothing (GitLab turns a webhook off after
    repeated failures). Refusals (unknown token, secret, not published, errors) as HookController's, logged for the workflow
*/

namespace controllers;

use \models\node\NodeTriggerGitLab;

class GitlabController extends \sys\APIController {

    const REFUSED = 'gitlab.refused';

    //called by GitLab: no CSRF token, the URL's token and the secret token are the credential
    public function receive($req, $resp) {
	[ $entry, $wf, $live, $node ] = $this->triggerOf('trigger::gitlab', (string)($req->query['id'] ?? ''));
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
	    $this->refuse(409, 'The step does not receive a webhook (Receive: Check a project on a schedule)');
	if (!\util\GitLab::verify((string)($_SERVER['HTTP_X_GITLAB_TOKEN'] ?? ''), (string)($cfg['secret'] ?? '')))
	    $this->refuse(401, 'Unauthorized (the secret token is not the step\'s)');
	$data = json_decode($raw, true);
	if (!is_array($data))
	    $this->refuse(400, 'Not JSON');
	$this->requireRunnable($wf, $live);
	$delivery = (string)($_SERVER['HTTP_X_GITLAB_EVENT_UUID'] ?? $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? '');
	$value = NodeTriggerGitLab::valueOf($data, $delivery);
	if (!NodeTriggerGitLab::wanted($cfg, $value)) {
	    $resp->sendJSON([ 'state' => 'ignored', 'event' => mb_substr((string)($value['event'] ?? ''), 0, 100), 'action' => mb_substr((string)($value['action'] ?? ''), 0, 100) ]);
	    return;
	}
	if (NodeTriggerGitLab::seenDelivery($wf, $entry->node, $delivery)) {
	    $resp->sendJSON([ 'state' => 'duplicate' ]);
	    return;
	}
	$started = NodeTriggerGitLab::startRuns($live, $node, [ $value + [ 'data' => self::clean($data) ] ]);
	$resp->sendJSON([ 'execution' => (string)$started[0]->_id, 'state' => 'accepted' ]);
    }
}
