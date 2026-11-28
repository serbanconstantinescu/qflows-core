<?php

/*
    FortiGate Banned IPs - the firewall's quarantine of IP addresses (the banned users list: monitor/user/banned): traffic from them is
    dropped until the ban expires. Operations (setting `operation`):
	ban        IPs banned (add_users: ip_addresses, expiry in seconds; 0: until unbanned)
	list       the banned IPs (GET monitor/user/banned)
	unban      IPs unbanned (clear_users)
	clear      every ban lifted (clear_all)
*/

namespace plugins\ITOps\models\node;

class NodeAppFgtBanned extends NodeAppFortiGate {
    public static $name = 'fgt-banned';
    public static $label = 'FortiGate Banned IPs';
    public static $icon = 'bi-sign-stop';
    public static $defaultOperation = 'ban';
    public static $defaultVariable = 'banned';

    const OPERATIONS = [
	'ban' => 'Ban IPs',
	'list' => 'List banned IPs',
	'unban' => 'Unban IPs',
	'clear' => 'Unban every IP',
    ];
    //the operations that change something; the others only read (an AI step's tools: the read ones ticked by default, util\agent\Tools)
    const WRITES = [ 'ban', 'unban', 'clear' ];
    const LISTS = [ 'list' ];
    const MAX_IPS = 1000;

    protected static function operationFields() {
	return [
	    self::field('ips', 'IP addresses', [ 'ban', 'unban' ], [ 'type' => 'textarea', 'required' => true, 'help' => 'A list, or separated by commas / lines (IPv4 or IPv6)',
		'ui' => [ 'rows' => 2, 'expression' => true ] ]),
	    //CLD: 2026-11-22T00:00:00+02:00: a number or an expression (a playbook's {{ minutes }}), checked when the step runs: 0 to 525600 (a year)
	    self::field('minutes', 'For (minutes)', [ 'ban' ], [ 'default' => '60', 'help' => '0: until unbanned; up to 525600 (a year)', 'ui' => [ 'width' => 6, 'expression' => 'text' ] ]),
	];
    }

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	switch($operation) {
	    case 'list':
		return $this->listOf('/api/v2/monitor/user/banned', [], 'banned IP');
	    case 'ban':
		$ips = \util\Ip::addresses($value('ips'), 'IP addresses', self::MAX_IPS);
		$m = trim((string)$value('minutes'));
		if ($m === '' || !preg_match('/^\d+$/', $m) || (int)$m > 525600)
		    throw new \RuntimeException("For (minutes): from 0 to 525600, not `{$m}`");
		$minutes = (int)$m;
		$r = $this->change('POST', '/api/v2/monitor/user/banned/add_users', [ 'ip_addresses' => $ips, 'expiry' => $minutes * 60 ]);
		return [ [ 'ips' => $ips, 'count' => count($ips), 'minutes' => $minutes, 'results' => $r ],
		    \util\Format::plural(count($ips), 'IP') . ' banned ' . ($minutes ? "for {$minutes} min" : 'until unbanned') ];
	    case 'unban':
		$ips = \util\Ip::addresses($value('ips'), 'IP addresses', self::MAX_IPS);
		$r = $this->change('POST', '/api/v2/monitor/user/banned/clear_users', [ 'ip_addresses' => $ips ]);
		return [ [ 'ips' => $ips, 'count' => count($ips), 'results' => $r ], \util\Format::plural(count($ips), 'IP') . ' unbanned' ];
	    case 'clear':
		return [ $this->change('POST', '/api/v2/monitor/user/banned/clear_all'), 'every ban lifted' ];
	}
	throw new \RuntimeException("unknown operation `{$operation}`");
    }
}
