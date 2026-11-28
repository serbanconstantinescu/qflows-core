<?php

/*
    CLD: 2026-11-25T14:00:00+02:00: the public endpoint of the Slack message trigger receiving events (/slack/<token>, NodeTriggerSlack): Slack POSTs JSON, signed with the app's
    signing secret (X-Slack-Signature, X-Slack-Request-Timestamp; the trigger's credential holds it):
	url_verification  Slack checks the address when it is entered (Event Subscriptions): its challenge answered
	event_callback    a message event (message, app_mention): a run of the published version when it is for the app (NodeTriggerSlack::startMessage), each message once
	                  (Slack retries an answer that took over 3 s; a mention comes as app_mention and as message)
    Anything else is answered 200 and starts nothing (Slack retries other answers, and turns the events off after many failures). Refusals (unknown token, signature, not
    published, errors) as HookController's, logged for the workflow
*/

namespace controllers;

use \models\node\NodeTriggerSlack;

class SlackController extends \sys\APIController {

    const REFUSED = 'slack.refused';

    //called by Slack: no CSRF token, the URL's token and the signature are the credential
    public function receive($req, $resp) {
	[ $entry, $wf, $live, $node ] = $this->triggerOf('trigger::slack', (string)($req->query['id'] ?? ''));
	$cfg = (array)$node['config'];

	if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
	    $resp->addHeader('Allow', 'POST');
	    $this->refuse(405, 'Method not allowed, use POST');
	}
	if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > \data\Constants::MAX_REQUEST_BODY)
	    $this->refuse(413, 'Request body too large');
	//the body as sent (sys\http\Request): the signature is of these bytes
	$raw = $req->body();
	if (strlen($raw) > \data\Constants::MAX_REQUEST_BODY)
	    $this->refuse(413, 'Request body too large');
	$cred = \models\Credential::findFor($wf, (string)($cfg['credential'] ?? ''), 'slack');
	if (!$cred)
	    $this->refuse(409, 'The trigger\'s Slack credential is no longer available');
	if (trim((string)$cred->signingSecret) === '')
	    $this->refuse(401, 'Unauthorized (the credential has no signing secret)');
	if (!\util\vendor\Slack::verify($raw, (string)($_SERVER['HTTP_X_SLACK_REQUEST_TIMESTAMP'] ?? ''), (string)($_SERVER['HTTP_X_SLACK_SIGNATURE'] ?? ''), trim((string)$cred->signingSecret)))
	    $this->refuse(401, 'Unauthorized (the signature does not match the signing secret, or the request is older than 5 minutes)');
	$data = json_decode($raw, true);
	if (!is_array($data))
	    $this->refuse(400, 'Not JSON');

	if (($data['type'] ?? '') === 'url_verification') {
	    $resp->sendJSON([ 'challenge' => (string)($data['challenge'] ?? '') ]);
	    return;
	}
	$this->requireRunnable($wf, $live);
	$event = (array)($data['event'] ?? []);
	$type = (string)($event['type'] ?? '');
	if (($data['type'] ?? '') !== 'event_callback' || !in_array($type, [ 'message', 'app_mention' ], true) || ($cfg['receive'] ?? 'events') !== 'events') {
	    $resp->sendJSON([ 'state' => 'ignored', 'event' => mb_substr($type !== '' ? $type : (string)($data['type'] ?? ''), 0, 100) ]);
	    return;
	}
	$channel = (string)($event['channel'] ?? '');
	$ts = (string)($event['ts'] ?? '');
	//the app's user: the event's authorizations (Slack sends it), else asked
	$me = '';
	foreach((array)($data['authorizations'] ?? []) as $a)
	    if (!empty($a['is_bot']) && !empty($a['user_id']))
		$me = (string)$a['user_id'];
	if ($me === '') {
	    try {
		$me = (string)($cred->identity(5)['user_id'] ?? '');
	    } catch (\RuntimeException $e) {
		$this->refuse(502, 'Slack: ' . $e->getMessage());
	    }
	}
	if ($channel === '' || $ts === '' || !NodeTriggerSlack::isPersons($event, $me)) {
	    $resp->sendJSON([ 'state' => 'ignored', 'event' => $type ]);
	    return;
	}
	if (NodeTriggerSlack::seenEvent($wf, $entry->node, "{$channel}:{$ts}")) {
	    $resp->sendJSON([ 'state' => 'duplicate' ]);
	    return;
	}
	$exe = NodeTriggerSlack::startMessage($live, $node, $cred, $me, self::clean($event), $channel);
	$resp->sendJSON($exe ? [ 'execution' => (string)$exe->_id, 'state' => 'accepted' ] : [ 'state' => 'ignored', 'event' => $type ]);
    }
}
