<?php

/*
    CLD: 2026-11-26T01:00:00+02:00: Entra ID change - starts the workflow when users are created, changed or deleted in the tenant, or people are added to / removed from groups.
    What changed is read with Graph's delta queries (users/delta, groups/delta with members), from where the last check ended (models\TriggerState: { users, groups: deltaLinks,
    since, subs }); the first check reads to the end and only notes where it is. How often (setting Receive):
	poll           on the step's schedule (models\wf\WfSchedule, every 5 minutes by default)
	notifications  Graph's change notifications: the step subscribes to users and / or groups (notificationUrl /entra/<token>, clientState the step's secret; renewed by
	               the step's hourly check before they end, at most 3 days ahead); each notification makes the check run at once (controllers\EntraController queues it)
    The variable (default `change`): { kind (userCreated, userUpdated, userDeleted, memberAdded, memberRemoved), user: { id, displayName, upn, mail, enabled, … }, group: { id,
    displayName } (members), at }
*/

namespace models\node;

class NodeTriggerEntra extends \models\wf\WfNode {
    public static $type = 'trigger';
    public static $name = 'entra';
    public static $label = 'Entra ID change';
    public static $icon = 'bi-person-badge';
    public static $inputs = 0;
    public static $outputs = 1;
    public static $scheduled = true;

    const RECEIVE = [ 'poll' => 'Check on a schedule', 'notifications' => 'Graph change notifications (at once)' ];
    const KINDS = [ 'userCreated' => 'Users created', 'userUpdated' => 'Users changed', 'userDeleted' => 'Users deleted', 'memberAdded' => 'Members added to groups', 'memberRemoved' => 'Members removed from groups' ];
    const USER_SELECT = 'id,displayName,givenName,surname,userPrincipalName,mail,jobTitle,department,accountEnabled,userType,createdDateTime,employeeId';
    const MAX_RUNS = 100;
    const MAX_CHANGES = 5000;
    const TIMEOUT = 300;
    //a subscription asks for this long; renewed when it ends within RENEW_BEFORE (Graph: users and groups at most 41,760 minutes)
    const SUB_DAYS = 3;
    const RENEW_BEFORE = 86400;

    public static function tokenData(array $node) {
	return [];
    }

    public static function initConfig() {
	$token = \models\TriggerToken::newToken('hex');
	return [ 'token' => $token, 'url' => \V::app()->createAbsoluteUrl('/entra/' . $token), 'secret' => bin2hex(random_bytes(20)) ];
    }

    public static function forTransfer(array $config) {
	$config['secret'] = bin2hex(random_bytes(20));
	return $config;
    }

    public static function configSchema() {
	$notify = [ 'key' => 'receive', 'operator' => 'eq', 'value' => 'notifications' ];
	$schedule = array_map(function($f) {
	    $f['ui'] = ($f['ui'] ?? []) + [ 'hideIf' => [ 'key' => 'receive', 'operator' => 'eq', 'value' => 'notifications' ] ];
	    return $f;
	}, \models\wf\WfSchedule::schema([ 'mode' => 'interval', 'every' => 5, 'every_unit' => 'minutes' ]));
	return array_merge([
	    [ 'key' => 'credential', 'type' => 'select', 'label' => 'Entra ID credential', 'choices' => NodeAppEntra::credentialChoices(), 'required' => true,
		'help' => 'Its app needs User.Read.All (users) and GroupMember.Read.All (memberships)', 'ui' => [ 'width' => 6 ] ],
	    [ 'key' => 'receive', 'type' => 'select', 'label' => 'Receive', 'required' => true, 'default' => 'poll', 'choices' => self::RECEIVE, 'ui' => [ 'width' => 6 ] ],
	    [ 'key' => 'url', 'type' => 'text', 'label' => 'Notification URL', 'perm:update' => 'nobody', 'help' => 'The step subscribes with it by itself once the workflow is published: nothing to set up in Entra',
		'ui' => [ 'placeholder' => 'Generating…', 'showIf' => $notify ] ],
	    [ 'key' => 'kinds', 'type' => 'select', 'label' => 'Start on', 'multiple' => true, 'required' => true, 'default' => [ 'userCreated' ], 'choices' => self::KINDS, 'ui' => [ 'size' => 5 ] ],
	    [ 'key' => 'groups', 'type' => 'text', 'label' => 'Groups', 'default' => '', 'help' => 'Optional: members of these only (IDs or names, separated by commas)', 'ui' => [ 'width' => 6 ] ],
	    [ 'key' => 'variable', 'type' => 'text', 'label' => 'Change variable', 'required' => true, 'default' => 'change', 'help' => 'e.g. {{ change.kind }}, {{ change.user.upn }}, {{ change.group.displayName }}',
		'validate' => [ [ 'identifier', 'skipEmpty' => true, 'message' => 'Use letters, digits or underscore, not starting with a digit' ] ], 'ui' => [ 'width' => 6, 'variableName' => 'write' ] ],
	], $schedule);
    }

    //polling: its schedule; notifications: hourly (the subscriptions renewed, what notifications may have missed read)
    public static function matches(array $cfg, \DateTimeInterface $t) {
	if (($cfg['receive'] ?? 'poll') === 'notifications')
	    return (int)$t->format('i') === 7;
	return \models\wf\WfSchedule::matches($cfg, $t);
    }

    public static function chosen(array $cfg) {
	return array_values(array_intersect(array_map('strval', (array)($cfg['kinds'] ?? [])), array_keys(self::KINDS)));
    }

    public function validates($options = []) {
	$valid = parent::validates($options);
	$cfg = is_array($this->config) ? $this->config : [];
	if (($cfg['receive'] ?? 'poll') === 'poll' && ($error = \models\wf\WfSchedule::validate($cfg)) !== null) {
	    $this->errors('config', $error);
	    $valid = false;
	}
	if (!self::chosen($cfg)) {
	    $this->errors('config', 'Entra ID change: choose what to start on');
	    $valid = false;
	}
	if (($cfg['receive'] ?? 'poll') === 'notifications' && strlen((string)($cfg['secret'] ?? '')) < 20) {
	    $this->errors('config', 'Entra ID change: the step has no secret (add the step again)');
	    $valid = false;
	}
	$id = (string)$this->cfg('credential', '');
	if ($id !== '' && (!$this->workflow || empty(\models\Credential::findFor($this->workflow, $id, 'entra')))) {
	    $this->errors('config', 'Entra ID change: choose an Entra ID credential of this project');
	    $valid = false;
	}
	return \models\TriggerToken::checkToken($this, 'hex') && $valid;
    }

    public static function outputVariables($config) {
	$name = is_array($config) ? (string)($config['variable'] ?? 'change') : 'change';
	if (!\util\Validator::isIdentifier($name))
	    return [];
	return [ $name => [ 'type' => 'object', 'info' => 'the change', 'fields' => [ 'kind' => [ 'type' => 'string', 'info' => 'userCreated, userUpdated, userDeleted, memberAdded or memberRemoved' ],
	    'user' => [ 'type' => 'object', 'info' => '{ id, displayName, upn, mail, enabled, department, jobTitle, … } (deleted: the id only)', 'fields' => [ 'id' => [ 'type' => 'string' ], 'upn' => [ 'type' => 'string' ],
		'displayName' => [ 'type' => 'string' ], 'mail' => [ 'type' => 'string' ], 'enabled' => [ 'type' => 'boolean' ], 'department' => [ 'type' => 'string' ] ] ],
	    'group' => [ 'type' => 'object', 'info' => 'members: { id, displayName }' ], 'at' => [ 'type' => 'string', 'info' => 'when the change was read' ] ] ] ];
    }

    public static function runScheduled($wf, array $node, $at) {
	$state = \models\TriggerState::acquire($wf, $node['wfid']);
	if (!$state) {
	    \V::app()->log->info("Entra ID change: workflow {$wf->_id}, step {$node['wfid']}: the previous check is still running, skipped");
	    return;
	}
	try {
	    [ $data, $started ] = self::check($wf, $node, $state->dataArray());
	} catch (\Throwable $e) {
	    $state->release();
	    throw $e;
	}
	$state->finish($data);
	return $started;
    }

    //the groups' IDs of the Groups setting (names looked up); null: all
    private static function groupFilter(\util\vendor\Entra $g, array $cfg) {
	$list = array_values(array_filter(array_map('trim', explode(',', (string)($cfg['groups'] ?? ''))), fn($v) => $v !== ''));
	if (!$list)
	    return null;
	$ids = [];
	foreach($list as $v) {
	    if (\util\vendor\Entra::isGuid($v)) {
		$ids[] = strtolower($v);
		continue;
	    }
	    foreach((array)($g->request('GET', '/groups', [ '$filter' => 'displayName eq ' . \util\vendor\Entra::quote($v), '$select' => 'id', '$top' => 5 ])['value'] ?? []) as $x)
		$ids[] = strtolower((string)$x['id']);
	}
	return $ids;
    }

    //one check: [ the new state data, the executions started ]; $start: what is done with a run (default: queued; the check suite saves it)
    public static function check($wf, array $node, array $data, ?callable $start = null) {
	$cfg = (array)($node['config'] ?? []);
	$cred = \models\Credential::findFor($wf, (string)($cfg['credential'] ?? ''), 'entra');
	if (!$cred)
	    throw new \Exception('Entra ID change: the credential is no longer available');
	$g = $cred->client($wf->aid, self::TIMEOUT);
	$kinds = self::chosen($cfg);
	$wantUsers = (bool)array_intersect($kinds, [ 'userCreated', 'userUpdated', 'userDeleted' ]);
	$wantMembers = (bool)array_intersect($kinds, [ 'memberAdded', 'memberRemoved' ]);
	$first = empty($data['since']);
	if (!$first && array_filter($wf->validateFlow(), fn($i) => $i['level'] == 'error'))
	    throw new \Exception("workflow `{$wf->name}` has errors and cannot run");
	$since = (string)($data['since'] ?? '');
	$now = gmdate('Y-m-d\TH:i:s\Z');
	$values = [];

	if ($wantUsers) {
	    [ $items, $link ] = $g->delta((string)($data['users'] ?? '') ?: '/users/delta?$select=' . self::USER_SELECT, self::MAX_CHANGES);
	    $data['users'] = $link;
	    if (!$first)
		foreach($items as $u) {
		    if (isset($u['@removed'])) {
			$kind = 'userDeleted';
			$user = [ 'id' => (string)($u['id'] ?? '') ];
		    } else {
			$kind = substr(gmdate('Y-m-d\TH:i:s\Z', strtotime((string)($u['createdDateTime'] ?? '1970-01-01'))), 0, 19) >= substr($since, 0, 19) ? 'userCreated' : 'userUpdated';
			$user = NodeAppEntra::userOf($u);
		    }
		    if (in_array($kind, $kinds, true))
			$values[] = [ 'kind' => $kind, 'user' => $user, 'at' => $now ];
		}
	} else
	    unset($data['users']);

	if ($wantMembers) {
	    [ $items, $link ] = $g->delta((string)($data['groups'] ?? '') ?: '/groups/delta?$select=id,displayName,members', self::MAX_CHANGES);
	    $data['groups'] = $link;
	    if (!$first) {
		$only = self::groupFilter($g, $cfg);
		$names = [];
		foreach($items as $grp) {
		    $gid = strtolower((string)($grp['id'] ?? ''));
		    if ($only !== null && !in_array($gid, $only, true))
			continue;
		    foreach((array)($grp['members@delta'] ?? []) as $m) {
			if (($m['@odata.type'] ?? '#microsoft.graph.user') !== '#microsoft.graph.user')
			    continue;
			$kind = isset($m['@removed']) ? 'memberRemoved' : 'memberAdded';
			if (!in_array($kind, $kinds, true))
			    continue;
			if (!isset($names[$gid]))
			    $names[$gid] = (string)($grp['displayName'] ?? '') !== '' ? (string)$grp['displayName']
				: (string)($g->request('GET', "/groups/{$gid}", [ '$select' => 'displayName' ], null, [ 'ok' => [ 404 ] ])['displayName'] ?? '');
			$user = [ 'id' => (string)($m['id'] ?? '') ];
			if ($kind === 'memberAdded') {
			    $u = $g->request('GET', '/users/' . rawurlencode($user['id']), [ '$select' => self::USER_SELECT ], null, [ 'ok' => [ 404 ] ]);
			    if (isset($u['id']))
				$user = NodeAppEntra::userOf($u);
			}
			$values[] = [ 'kind' => $kind, 'user' => $user, 'group' => [ 'id' => $gid, 'displayName' => $names[$gid] ], 'at' => $now ];
		    }
		}
	    }
	} else
	    unset($data['groups']);

	if (($cfg['receive'] ?? 'poll') === 'notifications')
	    $data['subs'] = self::subscriptions($g, $cfg, (array)($data['subs'] ?? []), $wantUsers, $wantMembers);
	else
	    unset($data['subs']);
	$data['since'] = $now;
	if (count($values) > self::MAX_RUNS) {
	    \V::app()->log->info("Entra ID change: workflow {$wf->_id}, step {$node['wfid']}: " . count($values) . ' changes, the first ' . self::MAX_RUNS . ' started');
	    $values = array_slice($values, 0, self::MAX_RUNS);
	}
	return [ $data, self::startRuns($wf, $node, $values, $start) ];
    }

    public static function startRuns($wf, array $node, array $values, ?callable $start = null) {
	$cfg = (array)($node['config'] ?? []);
	$name = \util\Validator::isIdentifier((string)($cfg['variable'] ?? '')) ? (string)$cfg['variable'] : 'change';
	$started = [];
	foreach($values as $v) {
	    $exe = \models\wf\WfExecution::startFor($wf, 'entra', [ 'received_at' => new \MongoDB\BSON\UTCDateTime(), 'vars' => [ $name => $v ] ], (string)$node['wfid']);
	    $exe->log($node['wfid'], "Entra ID {$v['kind']}: " . ($v['user']['upn'] ?? $v['user']['id']) . (isset($v['group']) ? " ({$v['group']['displayName']})" : ''));
	    ($start ?? fn($e) => $e->queue())($exe);
	    $started[] = $exe;
	}
	return $started;
    }

    //the step's subscriptions kept: made when missing, renewed when they end within a day; [ { id, resource, expires } ]
    public static function subscriptions(\util\vendor\Entra $g, array $cfg, array $subs, $users, $groups) {
	$want = array_values(array_filter([ $users ? 'users' : null, $groups ? 'groups' : null ]));
	$kept = [];
	$until = gmdate('Y-m-d\TH:i:s\Z', time() + self::SUB_DAYS * 86400);
	foreach($subs as $s) {
	    if (!in_array($s['resource'] ?? '', $want, true)) {
		$g->request('DELETE', '/subscriptions/' . rawurlencode((string)$s['id']), [], null, [ 'ok' => [ 404 ] ]);
		continue;
	    }
	    if (strtotime((string)$s['expires']) - time() > self::RENEW_BEFORE) {
		$kept[] = $s;
		continue;
	    }
	    $r = $g->request('PATCH', '/subscriptions/' . rawurlencode((string)$s['id']), [], [ 'expirationDateTime' => $until ], [ 'ok' => [ 404 ] ]);
	    if (isset($r['id']))
		$kept[] = [ 'id' => (string)$r['id'], 'resource' => $s['resource'], 'expires' => (string)($r['expirationDateTime'] ?? $until) ];
	}
	foreach($want as $resource) {
	    if (array_filter($kept, fn($s) => $s['resource'] === $resource))
		continue;
	    $r = $g->request('POST', '/subscriptions', [], [ 'changeType' => $resource === 'users' ? 'created,updated,deleted' : 'updated', 'notificationUrl' => (string)($cfg['url'] ?? ''),
		'resource' => $resource, 'expirationDateTime' => $until, 'clientState' => (string)($cfg['secret'] ?? '') ]);
	    $kept[] = [ 'id' => (string)($r['id'] ?? ''), 'resource' => $resource, 'expires' => (string)($r['expirationDateTime'] ?? $until) ];
	}
	return $kept;
    }
}
