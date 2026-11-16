<?php

namespace commands;

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;

/*
    a demo account with realistic data, for a demo site; CLD: 2026-11-17T00:30:00+02:00: every workflow runs without a credential or an AI provider
	../v demo::create [--name="Northwind Supplies"] [--password=<the users' password>] [--ai-from=<account id>] [--keep-emails]
	../v demo::delete --account=<id>

    create: the account (marked `demo`), its roles (+ "On call") and five users (nw.maria: owner and approver; nw.daniel, nw.aisha: managers; nw.tom: IT, on call; nw.elena: HR),
	with the password given (asked, hidden, when not: at least 12 characters). Each workflow is published (v1, Expense approval v1..v3) and has finished past runs, run here
	(not queued: a running daemon would take them) and moved back in time; the tasks they open are completed as the users would (TaskController), the users' actions written
	in the audit log (dated now: a sealed entry is never back-dated), from addresses of the documentation ranges (192.0.2.0/24, 198.51.100.0/24, 203.0.113.0/24).
	- "Finance":
	  * the data table `expenses` (employee and their name, amount, category, reason, status, decided by, comment), ten earlier requests, two saved views;
	  * Expense approval (the docs' guide: Form -> Data Table insert -> Human task (3 days) -> Data Table update -> If -> emails; a reminder on the time limit): v1..v3, five runs
	    started by its form as the members' requests: four decided by Maria, one waiting at the approval (in her My tasks);
	  * Weekly pending expenses report (Mondays 08:00): the pending expenses (Data Table query), grouped by employee (Code), a Spreadsheet file (.xlsx) mailed to finance@
	    (Send email, attached); two runs, the last two Mondays;
	  * New hire onboarding: a form (name, email, role, start date, manager) -> `hires` -> three Human tasks side by side (IT: Tom, the manager, HR: Elena; reminders on their
	    time limits) -> Merge, Merge -> `hires` updated -> a welcome email; three hires: done, HR still open, just started (all three open);
	  * Supplier check (RegionalRO and RegionalEU): a form of codes -> ANAF Company Lookup (Romanian CUIs) and VIES (EU VAT numbers) -> the valid ones into `suppliers`, the others
	    (inactive, not a VAT payer, invalid, unknown) a Human task to Maria; three runs with real public codes: all valid, one decided, one waiting;
	- "IT Operations":
	  * Security alert playbook (simulated): a webhook receiving a Wazuh-shaped alert -> Switch on the rule level: 10 or more a Human task "Block <srcip>?" for the On call role
	    (approved: `blocklist`; rejected / expired: `alerts`), below 10 into `alerts`; a Note (FortiGate in production); four runs: blocked, rejected, low, waiting;
	  * Certificate expiry watch (daily 07:00): `hosts` (4 public hosts: the project's site, this server's host or github.com, example.com, expired.badssl.com) -> Loop: Read a
	    certificate -> each row updated (days left, issuer, valid to, checked at) -> fewer than 30 days or expired: a Human task (Tom, Maria); two runs (yesterday's task done);
	- "Romania" (RegionalRO): Foreign-currency invoice: a form (client CUI, number, lines in EUR) -> ANAF Company Lookup -> BNR rates -> Code (UBL 2.1 / CIUS-RO XML) -> ANAF e-Factura
	  validate (public) -> valid: e-Factura PDF (into Files) + `invoices`; not valid: a Human task. Nothing is uploaded to ANAF. Three runs: two valid, one not (waiting);
	- "EU tenders" (RegionalEU): EU tender alerts: the TED notice trigger (CPV 72000000, Romania, calls for competition) -> `tenders` -> a Human task "Go / no-go" to Maria ->
	  `tenders` updated; runs for the newest notices of a real TED search made now (none when TED does not answer: said so);
	- with --ai-from (an account of this server): its Assistant and embedding settings copied (the keys stay encrypted with this server's key) and the knowledge base "Expense
	  policy" (five policy texts) indexed; without it there is no knowledge base (it needs an embedding model).
	A plugin's workflows only when the plugin is on (plugins.json): the others skipped, said in one line. The network steps (ANAF, BNR, VIES, TED, certificates) call public
	services: without network their runs end in error (each run's line says so).
	Emails: the Send email steps queue them as always; the addresses are @northwind.example (reserved, never delivered). A running daemon takes them at once (Docker:
	Mailpit catches them); without --keep-emails those still waiting at the end are removed.
	Prints the account id and the users.
    delete: an account made by demo::create (its `demo` mark), with everything of its id (its data tables' collections too)

    build($params): create() without printing the users, for the check suite (CheckCommand demo): $params + prefix (the users' names: <prefix><name>, default nw.), quiet;
	returns [ account, projects (name => Project), workflows (name => Workflow), tables (name => DataTableDef), users (key => User), skipped (names), lines ]
*/

class DemoCommand extends \sys\BaseController {

    //key => first name, last name, account role, title
    const PEOPLE = [
	'maria' => [ 'Maria', 'Lopez', 'owner', 'Finance lead' ],
	'daniel' => [ 'Daniel', 'Kim', 'member', 'Sales manager' ],
	'aisha' => [ 'Aisha', 'Patel', 'member', 'Operations manager' ],
	'tom' => [ 'Tom', 'Becker', 'member', 'IT administrator' ],
	'elena' => [ 'Elena', 'Rossi', 'member', 'HR partner' ],
    ];

    //the addresses the users' actions come from (documentation ranges, RFC 5737)
    const IPS = [ 'maria' => '203.0.113.10', 'daniel' => '198.51.100.24', 'aisha' => '198.51.100.31', 'tom' => '192.0.2.15', 'elena' => '198.51.100.47' ];
    const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:132.0) Gecko/20100101 Firefox/132.0';

    //earlier requests: who, amount, category, reason, status, comment, days ago (all decided by Maria)
    const HISTORY = [
	[ 'daniel', 1240, 'Travel', 'Flights and hotel, customer workshop in Lyon', 'approved', 'Booked within the travel policy.', 26 ],
	[ 'aisha', 89.5, 'Meals', 'Team lunch after the quarterly close', 'approved', '', 24 ],
	[ 'tom', 2350, 'Equipment', 'Laptop replacement (battery failure)', 'approved', 'IT confirmed the failure.', 21 ],
	[ 'elena', 420, 'Training', 'Online course: advanced Excel modelling', 'approved', '', 19 ],
	[ 'daniel', 76.2, 'Meals', 'Client dinner, Hamburg', 'rejected', 'Over the per-person limit: resubmit with the attendees.', 17 ],
	[ 'aisha', 199, 'Software', 'Diagram tool, yearly licence', 'approved', '', 14 ],
	[ 'tom', 610, 'Travel', 'Train tickets, supplier audit in Milan', 'approved', '', 12 ],
	[ 'elena', 1890, 'Equipment', 'Second monitor and docking station', 'rejected', 'Use the spare stock in the office first.', 9 ],
	[ 'daniel', 345, 'Training', 'Conference ticket: FinOps Summit', 'approved', '', 7 ],
	[ 'aisha', 58.4, 'Meals', 'Working lunch with the auditors', 'approved', '', 6 ],
    ];

    //the runs: who, amount, category, reason, Maria's decision (null: still waiting), comment, hours ago
    const RUNS = [
	[ 'tom', 135.8, 'Meals', 'Dinner with the logistics partner', 'approved', '', 52 ],
	[ 'elena', 980, 'Travel', 'Hotel for the trade fair in Frankfurt (3 nights)', 'approved', 'Fine: within the fair budget.', 29 ],
	[ 'daniel', 2400, 'Equipment', 'Ergonomic chair and standing desk', 'rejected', 'Please order through facilities: they have a framework contract.', 26 ],
	[ 'aisha', 129, 'Software', 'PDF editor licence', 'approved', '', 5 ],
	[ 'tom', 460, 'Training', 'Forklift safety certificate renewal', null, null, 1.5 ],
    ];

    const CATEGORIES = [ 'Travel', 'Meals', 'Software', 'Equipment', 'Training' ];

    //the new hires: name, email, role, start in days, manager, who completed which task (it / manager / hr; missing: still open), hours ago
    const HIRES = [
	[ 'Sofia Marin', 'sofia.marin@northwind.example', 'Account executive', -10, 'daniel', [ 'it' => 'tom', 'manager' => 'daniel', 'hr' => 'elena' ], 20 * 24 ],
	[ 'Lucas Weber', 'lucas.weber@northwind.example', 'Warehouse coordinator', 6, 'aisha', [ 'it' => 'tom', 'manager' => 'aisha' ], 4 * 24 ],
	[ 'Hana Novak', 'hana.novak@northwind.example', 'Junior accountant', 14, 'daniel', [], 3 ],
    ];

    //the supplier checks: codes (real public ones), Maria's decision on the problems (null: waiting; none when all are valid), hours ago
    const SUPPLIER_RUNS = [
	[ "RO14399840\n1590082\nIE6388047V\nFR59542051180", null, 70 ],
	[ "2816464\n427320\nIT00905811006\nNL001765220B01", [ 'action' => 'contact', 'comment' => 'Asked Orange for its VAT registration; the NL number is a typo, waiting for the right one.' ], 26 ],
	[ "160796\nBE0403170701\nDE143454214\n1234567", null, 2 ],
    ];

    //the alerts: rule id, level, description, agent, source address, groups, Maria's decision (null: waiting), comment, hours ago
    const ALERTS = [
	[ '5763', 12, 'sshd: brute force trying to get access to the system. Authentication failed.', 'web-01', '203.0.113.45', [ 'syslog', 'sshd', 'authentication_failures' ], 'block', 'Hundreds of attempts in 2 minutes.', 30 ],
	[ '31151', 10, 'Multiple web server 400 error codes from same source ip.', 'web-02', '198.51.100.23', [ 'web', 'accesslog', 'web_scan', 'recon' ], 'ignore', 'Our own vulnerability scanner: scheduled scan.', 22 ],
	[ '5710', 5, 'sshd: Attempt to login using a non-existent user', 'vpn-gw', '192.0.2.77', [ 'syslog', 'sshd', 'invalid_login' ], null, null, 9 ],
	[ '31106', 13, 'A web attack returned code 200 (success).', 'shop-01', '203.0.113.99', [ 'web', 'accesslog', 'attack' ], null, null, 1 ],
    ];

    //the invoices: client CUI, number, lines (description | quantity | unit price in EUR), hours ago; the third typed with commas: no lines, not valid
    const INVOICES = [
	[ '2816464', '2026-0141', "Pallet racking, 3 m bays | 12 | 185.50\nInstallation, per day | 2 | 640\nSafety inspection | 1 | 220", 50 ],
	[ '14399840', '2026-0142', "Barcode scanners, wireless | 8 | 289\nScanner docking stations | 8 | 45.90", 23 ],
	[ '160796', '2026-0143', "Forklift maintenance, 4 units, 1, 860\nSpare parts kit, 2, 315", 3 ],
    ];

    //the fictional seller: a CUI with its control digit that ANAF has no company for (checked 2026-11-16)
    const SELLER_CUI = '99999909';

    const POLICY = [
	'Travel' => "Book trips through the travel desk at least 14 days before departure when you can. Economy class is the rule for flights. Business class may be booked for flights longer than 6 hours, or when a customer pays for the trip. Trains are preferred to flights for journeys under 4 hours.\n\nHotels: up to 160 EUR a night in capital cities, 120 EUR elsewhere. Trade fairs and conferences may exceed this with your manager's approval, written in the request.\n\nTaxis are reimbursed between the airport or station and the hotel, and late at night after 22:00. Use public transport otherwise. Car rental needs a reason: several sites in one day, or no public transport.",
	'Meals and entertainment' => "While travelling, meals are reimbursed up to 55 EUR a day, with receipts. Breakfast included in the hotel price is not claimed again.\n\nCustomer and supplier meals: up to 70 EUR per person, alcohol included. List the attendees and their companies in the request; a request without them is sent back.\n\nTeam lunches and celebrations are paid from the team budget: ask your manager before booking. Tips up to 10% are accepted.",
	'Equipment and software' => "Laptops, phones and monitors are ordered by IT from the framework contract; personal purchases are not reimbursed, except in an emergency approved by IT (for example a broken laptop during a customer visit).\n\nOffice furniture, including chairs and standing desks, is ordered through facilities, who check the ergonomic needs with you.\n\nSoftware licences up to 300 EUR a year may be bought by the employee with their manager's approval. Larger licences go through procurement. Cancel monthly subscriptions you no longer use.",
	'Training' => "Each employee has a training budget of 1,200 EUR a year. Courses, certificates and conference tickets come from this budget. Mandatory certificates (safety, forklift, first aid) are paid by the company and do not count towards it.\n\nAsk for approval before you register. Keep the invoice and the certificate of attendance: both are attached to the request.",
	'Reimbursement' => "Send each expense within 30 days with a photo of the receipt. Approved expenses are paid with the next salary. Expenses in another currency are converted at the rate of the card statement, or the ECB rate of the day when paid in cash.\n\nA request without a receipt is reimbursed up to 25 EUR, with a written explanation. Lost receipts above that amount need your manager's and finance's approval.",
    ];

    //the build's state: the account, its users and names, the server's address, the demo's tables (iname => DataTableDef, to move their rows back), printing
    private $account;
    private $u = [];
    private $names = [];
    private $base = '';
    private $tables = [];
    private $quiet = false;
    private $lines = [];

    public function create($req = null, $resp = null) {
	$params = is_array($req->params ?? null) ? $req->params : [];
	if (trim((string)($params['password'] ?? '')) === '')
	    $params['password'] = $this->askPassword();
	try {
	    $r = $this->build($params);
	} catch (\RuntimeException $e) {
	    fwrite(STDERR, 'demo: ' . $e->getMessage() . "\n");
	    exit(1);
	}
	echo "\ndemo account {$r['account']->_id} ({$r['account']->name}): projects " . implode(', ', array_keys($r['projects'])) . "\n";
	foreach($r['lines'] as $l)
	    echo "  {$l}\n";
	echo "users:\n";
	foreach(self::PEOPLE as $k => [ $first, $last, $role, $title ])
	    echo "  {$r['users'][$k]->username}  {$first} {$last} ({$title}" . ($role === 'owner' ? ', owner' : '') . ")\n";
    }

    public function build(array $params) {
	$om = \V::app()->om;
	$db = \V::app()->mongo->selectDatabase(MONGO_DB);
	$this->quiet = !empty($params['quiet']);
	$name = trim((string)($params['name'] ?? '')) ?: 'Northwind Supplies';
	$password = (string)($params['password'] ?? '');
	if (strlen($password) < 12)
	    throw new \RuntimeException('the password needs at least 12 characters');
	$prefix = (string)($params['prefix'] ?? 'nw.');
	if (!preg_match('/^[a-z][a-z0-9.-]{0,20}$/', $prefix))
	    throw new \RuntimeException('--prefix: lowercase letters, digits, dots, dashes');
	$aiFrom = (string)($params['ai-from'] ?? '');
	if ($aiFrom !== '' && !\util\Validator::rule('oid', $aiFrom))
	    throw new \RuntimeException('--ai-from: an account id');
	foreach(array_keys(self::PEOPLE) as $k) {
	    if ($om->count('User', [ 'username' => "{$prefix}{$k}" ]))
		throw new \RuntimeException("a user {$prefix}{$k} exists already (a demo account is there: ../v demo::delete --account=<id>)");
	}
	$ro = self::pluginOn('RegionalRO');
	$eu = self::pluginOn('RegionalEU');
	$skipped = array_merge($ro && $eu ? [] : [ 'Supplier check (RegionalRO and RegionalEU)' ], $ro ? [] : [ 'Foreign-currency invoice (RegionalRO)' ], $eu ? [] : [ 'EU tender alerts (RegionalEU)' ]);

	//the account, its roles, the users
	$account = \models\Account::create([ 'state' => 'active', 'name' => $name ]);
	if (!$account->validates() || !$account->save())
	    throw new \RuntimeException('account: ' . json_encode($account->errors()));
	$db->selectCollection(\models\Account::_collection())->updateOne([ '_id' => $account->_id ], [ '$set' => [ 'demo' => true ] ]);
	$this->account = $account;
	\V::app()->var('rt.account', $account);
	$roles = $account->setupRoles();
	//the On call role (a member's rights): the security alerts' approvers
	$member = $roles['member'];
	$oncall = \models\Role::create([ 'aid' => $account->_id, 'name' => 'On call', 'iname' => 'oncall', 'permissions' => $member->permissions ?? [], 'override' => $member->override ?? [] ]);
	if (!$oncall->validates() || !$oncall->save())
	    throw new \RuntimeException('role On call: ' . json_encode($oncall->errors()));
	$roles['oncall'] = $oncall;
	$mail = $prefix === 'nw.' ? '' : preg_replace('/[^a-z0-9]+/', '-', $prefix);
	foreach(self::PEOPLE as $k => [ $first, $last, $role ]) {
	    $user = \models\User::create([ 'aid' => $account->_id, 'username' => "{$prefix}{$k}", 'email' => "{$mail}{$k}@northwind.example", 'firstname' => $first, 'lastname' => $last,
		'password' => \V::app()->security->generatePasswordHash($password), 'state' => 'active', 'role' => $roles[$role]->_id ]);
	    if (!$user->validates() || !$user->save())
		throw new \RuntimeException("user {$prefix}{$k}: " . json_encode($user->errors()));
	    $this->u[$k] = $user;
	    $this->names[$k] = "{$first} {$last}";
	    $this->audit($k, 'login', null, 'signed in');
	}
	$u = $this->u;

	//the projects: Finance (everyone), IT Operations (Maria and Tom on call, Daniel), Romania and EU tenders (Maria, Daniel, Aisha)
	$all = [];
	foreach($u as $k => $user)
	    $all[$k] = self::PEOPLE[$k][2];
	$projects = [];
	$projects['Finance'] = $this->project('Finance', $all, $roles);
	$projects['IT Operations'] = $this->project('IT Operations', [ 'maria' => 'oncall', 'tom' => 'oncall', 'daniel' => 'member' ], $roles);
	if ($ro)
	    $projects['Romania'] = $this->project('Romania', [ 'maria' => 'owner', 'daniel' => 'member', 'aisha' => 'member' ], $roles);
	if ($eu)
	    $projects['EU tenders'] = $this->project('EU tenders', [ 'maria' => 'owner', 'daniel' => 'member', 'aisha' => 'member' ], $roles);
	//Maria opens Finance when she signs in (her personal project made now, as sys\Boot would)
	$om->create('Project', [ 'aid' => $account->_id, 'name' => 'Personal', 'owner' => $u['maria']->_id, 'personal' => true,
	    'members' => [ (string)$u['maria']->_id => [ 'role' => $roles['owner']->_id ] ] ])->save();
	$u['maria']->prefs = [ 'last_project' => $projects['Finance']->_id ];
	$u['maria']->save();

	//the AI settings of another account of this server
	if ($aiFrom !== '') {
	    foreach([ \models\assistant\AssistantSettings::class, \models\kb\EmbeddingSettings::class ] as $class) {
		$coll = $db->selectCollection($class::_collection());
		$src = $coll->findOne([ 'aid' => new ObjectId($aiFrom) ], [ 'typeMap' => [ 'root' => 'array', 'document' => 'array', 'array' => 'array' ] ]);
		if ($src) {
		    unset($src['_id']);
		    $src['aid'] = $account->_id;
		    $coll->insertOne($src);
		}
	    }
	}

	$this->base = rtrim(defined('BASE_URL') ? BASE_URL : '', '/');
	$workflows = [];
	$workflows += $this->finance($projects['Finance'], $ro && $eu);
	$workflows += $this->itOperations($projects['IT Operations'], $roles['oncall']);
	if ($ro)
	    $workflows += $this->romania($projects['Romania']);
	if ($eu)
	    $workflows += $this->tenders($projects['EU tenders']);

	//the emails of the runs still queued (to @northwind.example), unless kept (Mailpit)
	if (empty($params['keep-emails'])) {
	    $left = $om->deleteMany('Job', [ 'aid' => $account->_id, 'job_spec' => '\util\EmailSend::deliver', 'state' => 'new' ]);
	    if ($left)
		$this->lines[] = "{$left} queued emails removed (--keep-emails keeps them)";
	}

	//the knowledge base, with an embedding model
	$settings = \models\kb\EmbeddingSettings::of($account->_id);
	$this->lines[] = $settings->ready() ? $this->knowledgeBase($account, $projects['Finance'], $settings) : 'no knowledge base (no embedding model: --ai-from)';
	if ($skipped)
	    $this->lines[] = 'skipped (plugin off): ' . implode(', ', $skipped);

	$tables = [];
	foreach($this->tables as $t)
	    $tables[(string)$t->name] = $t;
	return [ 'account' => $account, 'projects' => $projects, 'workflows' => $workflows, 'tables' => $tables, 'users' => $u, 'skipped' => $skipped, 'lines' => $this->lines ];
    }

    public function delete($req = null, $resp = null) {
	$id = (string)($req->params['account'] ?? '');
	if (!\util\Validator::rule('oid', $id))
	    $this->fail('usage: ../v demo::delete --account=<id>');
	$n = self::remove($id);
	if ($n === null)
	    $this->fail("{$id}: not an account made by demo::create");
	echo "deleted the demo account {$id} and {$n} records\n";
    }

    //the account $id made by demo::create, with everything of its id: the number of records removed, null when it is not a demo account
    public static function remove($id) {
	$db = \V::app()->mongo->selectDatabase(MONGO_DB);
	$aid = new ObjectId($id);
	if (!$db->selectCollection(\models\Account::_collection())->findOne([ '_id' => $aid, 'demo' => true ]))
	    return null;
	$names = iterator_to_array($db->listCollectionNames(), false);
	foreach(\V::app()->om->find('DataTableDef', [ 'aid' => $aid ]) as $t) {
	    $db->selectCollection(\sys\fields\Field::_collection())->deleteMany([ 'objectName' => (string)$t->iname ]);
	    foreach($names as $c) {
		if ($c === (string)$t->iname || str_starts_with($c, (string)$t->iname . '.'))
		    $db->dropCollection($c);
	    }
	}
	$n = 0;
	foreach(iterator_to_array($db->listCollectionNames(), false) as $c) {
	    if ($c === \models\Account::_collection())
		continue;
	    //the files (GridFS: aid at the top and in their metadata), with their chunks
	    if (str_ends_with($c, '.files')) {
		$ids = array_map(fn($f) => $f['_id'], iterator_to_array($db->selectCollection($c)->find([ '$or' => [ [ 'aid' => $aid ], [ 'metadata.aid' => $aid ] ] ], [ 'projection' => [ '_id' => 1 ] ]), false));
		if ($ids)
		    $db->selectCollection(substr($c, 0, -6) . '.chunks')->deleteMany([ 'files_id' => [ '$in' => $ids ] ]);
		$n += $db->selectCollection($c)->deleteMany([ 'metadata.aid' => $aid ])->getDeletedCount();
	    }
	    $n += $db->selectCollection($c)->deleteMany([ 'aid' => $aid ])->getDeletedCount();
	}
	$db->selectCollection(\models\AuditSeal::_collection())->deleteOne([ '_id' => $aid ]);
	$db->selectCollection(\models\Account::_collection())->deleteOne([ '_id' => $aid ]);
	return $n;
    }

    // ------------------------------------------------------------------------------------
    //Finance

    private function finance($project, $suppliers) {
	$u = $this->u;
	$maria = (string)$u['maria']->_id;
	$token = bin2hex(random_bytes(16));
	$tables = [ 'DT-EXPENSES' => [ 'expenses', self::expenseColumns(), self::historyRows($u, $this->names) ], 'DT-HIRES' => [ 'hires', self::hireColumns(), [] ] ];
	$flows = [
	    'Expense approval' => [ 'receipt::primary', self::expenseNodes('DT-EXPENSES', $token, $this->base, $maria) ],
	    'Weekly pending expenses report' => [ 'calendar-week::info', self::reportNodes('DT-EXPENSES') ],
	    'New hire onboarding' => [ 'person-plus::warning', self::onboardingNodes('DT-HIRES', (string)$u['tom']->_id, (string)$u['elena']->_id, $this->people()) ],
	];
	if ($suppliers) {
	    $tables['DT-SUPPLIERS'] = [ 'suppliers', self::supplierColumns(), [] ];
	    $flows['Supplier check'] = [ 'building-check::success', self::supplierNodes('DT-SUPPLIERS', $maria) ];
	}
	[ $wfs, $dts ] = $this->import($project, $tables, $flows);
	$dt = $dts['expenses'];

	//Expense approval: v1 .. v3, published by Maria, dated 20, 11 and 3 days ago; the runs as FormController starts them
	$version = $this->publish($wfs['Expense approval'], [ [ 'First version: form, manager approval, emails', 20 ], [ 'Record each request and decision in the expenses table', 11 ],
	    [ 'Remind finance after 3 days without a decision', 3 ] ]);
	foreach(self::RUNS as [ $who, $amount, $category, $reason, $decision, $comment, $hours ]) {
	    $exe = $this->formRun($wfs['Expense approval'], $version, $who, [ 'amount' => $amount, 'category' => $category, 'reason' => $reason ]);
	    if ($decision !== null)
		$exe = $this->answer($exe, 'approve', [ 'decision' => $decision, 'comment' => $comment ], 'maria');
	    $this->done($exe, $hours, "Expense approval: {$this->names[$who]}, {$amount} EUR");
	}
	//saved views
	foreach([ [ 'Approved over 300 EUR', [ [ 'field' => 'status', 'op' => 'eq', 'value' => 'approved' ], [ 'field' => 'amount', 'op' => 'gt', 'value' => '300' ] ] ],
	    [ 'Waiting for a decision', [ [ 'field' => 'status', 'op' => 'eq', 'value' => 'pending' ] ] ] ] as [ $vname, $filter ]) {
	    \V::app()->om->create('DataTableView', [ 'aid' => $this->account->_id, 'table' => $dt->_id, 'name' => $vname, 'filter' => $filter, 'user' => $u['maria']->_id ])->save();
	}

	//the report: the last two Mondays at 08:00
	$v = $this->publish($wfs['Weekly pending expenses report'], [ [ 'Pending expenses by employee, as an Excel file', 16 ] ]);
	foreach([ 2, 1 ] as $weeks) {
	    $at = (new \DateTimeImmutable('monday this week 08:00'))->modify('-' . (($weeks - 1) * 7) . ' days');
	    if ($at > new \DateTimeImmutable())
		$at = $at->modify('-7 days');
	    $exe = $this->scheduleRun($wfs['Weekly pending expenses report'], $v, $at);
	    $this->done($exe, max(1, (int)round((time() - $at->getTimestamp()) / 3600)), 'Weekly pending expenses report: ' . $at->format('D j M'));
	}

	//onboarding: started by Elena (HR)
	$v = $this->publish($wfs['New hire onboarding'], [ [ 'IT, manager and HR tasks side by side, then the welcome email', 25 ] ]);
	foreach(self::HIRES as [ $hname, $email, $role, $startIn, $manager, $done, $hours ]) {
	    $exe = $this->formRun($wfs['New hire onboarding'], $v, 'elena', [ 'name' => $hname, 'email' => $email, 'role' => $role,
		'start_date' => date('Y-m-d', strtotime(($startIn >= 0 ? '+' : '') . $startIn . ' days')), 'manager' => (string)$u[$manager]->email ]);
	    $answers = [ 'it' => [ 'ready' => true, 'notes' => 'Laptop, badge and accounts ready.' ], 'manager' => [ 'plan' => 'Day 1: welcome and tour. Days 2-5: shadowing the team, first customer calls.' ],
		'hr' => [ 'signed' => true, 'notes' => 'Contract signed, payroll set up.' ] ];
	    foreach($done as $node => $who)
		$exe = $this->answer($exe, $node, $answers[$node], $who);
	    $this->done($exe, $hours, "New hire onboarding: {$hname}");
	}

	//supplier checks: started by Aisha (operations), the problems decided by Maria
	if ($suppliers) {
	    $v = $this->publish($wfs['Supplier check'], [ [ 'ANAF for Romanian codes, VIES for the EU ones', 9 ] ]);
	    foreach(self::SUPPLIER_RUNS as [ $codes, $decision, $hours ]) {
		$exe = $this->formRun($wfs['Supplier check'], $v, 'aisha', [ 'codes' => $codes ]);
		if ($decision !== null)
		    $exe = $this->answer($exe, 'review', $decision, 'maria');
		$this->done($exe, $hours, 'Supplier check: ' . str_replace("\n", ', ', $codes));
	    }
	}
	return $wfs;
    }

    //the managers as a select's choices (email => name), for the onboarding form (a Human task's Also assigned to takes an email)
    private function people() {
	$list = [];
	foreach([ 'daniel', 'aisha', 'maria' ] as $k)
	    $list[(string)$this->u[$k]->email] = $this->names[$k];
	return $list;
    }

    private static function expenseColumns() {
	return [
	    [ 'key' => 'employee', 'label' => 'Employee', 'type' => 'object', 'object' => 'User', 'required' => true ],
	    [ 'key' => 'employee_name', 'label' => 'Employee name', 'type' => 'text' ],
	    [ 'key' => 'amount', 'label' => 'Amount (EUR)', 'type' => 'number', 'required' => true ],
	    [ 'key' => 'category', 'label' => 'Category', 'type' => 'select', 'choices' => implode("\n", self::CATEGORIES), 'required' => true ],
	    [ 'key' => 'reason', 'label' => 'Reason', 'type' => 'textarea', 'required' => true ],
	    [ 'key' => 'status', 'label' => 'Status', 'type' => 'select', 'choices' => "pending\napproved\nrejected", 'required' => true ],
	    [ 'key' => 'decided_by', 'label' => 'Decided by', 'type' => 'object', 'object' => 'User' ],
	    [ 'key' => 'comment', 'label' => 'Comment', 'type' => 'textarea' ],
	];
    }

    //the earlier requests as rows (MongoDB extended JSON, as an export has them)
    private static function historyRows(array $u, array $names) {
	$rows = [];
	foreach(self::HISTORY as [ $who, $amount, $category, $reason, $status, $comment, $days ]) {
	    $at = (int)(string)self::daysAgo($days, 9 + $days % 6, 15 + $days);
	    $rows[] = [ 'employee' => [ '$oid' => (string)$u[$who]->_id ], 'employee_name' => $names[$who], 'amount' => $amount, 'category' => $category, 'reason' => $reason,
		'status' => $status, 'decided_by' => [ '$oid' => (string)$u['maria']->_id ], 'comment' => $comment,
		'created_at' => [ '$date' => [ '$numberLong' => (string)$at ] ], 'updated_at' => [ '$date' => [ '$numberLong' => (string)($at + 5 * 3600 * 1000) ] ] ];
	}
	return $rows;
    }

    //the docs' guide "Expense approval" (docs: guides/examples/expense-approval), with a reminder when nobody decides in 3 days; the employee's name kept too (the weekly report)
    private static function expenseNodes($table, $token, $base, $approver) {
	$list = [
	    self::node('form', 'trigger::form', 'Expense request', 0, 200, [
		'url' => "{$base}/form/{$token}", 'token' => $token, 'access' => 'members', 'submitLabel' => 'Send request',
		'message' => 'Thank you: your manager has been asked to approve it.', 'variable' => 'form',
		'form' => [ 'direction' => 'vertical', 'fields' => [
		    [ 'type' => 'number', 'key' => 'amount', 'label' => 'Amount (EUR)', 'required' => true, 'min' => 0, 'step' => 0.01 ],
		    [ 'type' => 'select', 'key' => 'category', 'label' => 'Category', 'required' => true, 'choices' => self::choicesOf(array_combine(self::CATEGORIES, self::CATEGORIES)) ],
		    [ 'type' => 'textarea', 'key' => 'reason', 'label' => 'Reason', 'required' => true, 'rows' => 3 ],
		] ] ]),
	    self::node('record', 'app::table', 'Record request', 220, 200, [ 'table' => $table, 'operation' => 'insert', 'variable' => 'expense', 'onError' => 'fail', 'data' => [
		[ 'column' => 'employee', 'value' => '{{ form_user.id }}' ], [ 'column' => 'employee_name', 'value' => '{{ form_user.name }}' ], [ 'column' => 'amount', 'value' => '{{ form.amount }}' ],
		[ 'column' => 'category', 'value' => '{{ form.category }}' ], [ 'column' => 'reason', 'value' => '{{ form.reason }}' ], [ 'column' => 'status', 'value' => 'pending' ] ] ]),
	    self::node('approve', 'app::task', 'Manager approval', 440, 200, [
		'title' => 'Approve {{ form.amount }} EUR for {{ form_user.name }}',
		'instructions' => "{{ form.category }}: {{ form.reason }}\n\nCheck the amount against the expense policy before you approve it.",
		'users' => [ $approver ], 'roles' => [], 'assignTo' => null, 'submitLabel' => 'Send decision', 'timeoutUnit' => 'days', 'timeout' => 3, 'variable' => 'task',
		'form' => [ 'direction' => 'vertical', 'fields' => [
		    [ 'type' => 'select', 'key' => 'decision', 'label' => 'Decision', 'required' => true, 'choices' => self::choicesOf([ 'approved' => 'Approve', 'rejected' => 'Reject' ]) ],
		    [ 'type' => 'textarea', 'key' => 'comment', 'label' => 'Comment', 'rows' => 3, 'helpText' => 'Sent to the employee with the decision.' ],
		] ] ]),
	    self::node('decide', 'app::table', 'Record decision', 660, 200, [ 'table' => $table, 'operation' => 'update', 'rowId' => '{{ expense.row.id }}', 'variable' => 'expense',
		'onError' => 'fail', 'data' => [ [ 'column' => 'status', 'value' => '{{ task.decision }}' ], [ 'column' => 'decided_by', 'value' => '{{ task_user.id }}' ],
		[ 'column' => 'comment', 'value' => '{{ task.comment }}' ] ] ]),
	    self::node('check', 'core::if', 'If approved', 880, 200, [ 'match' => 'all', 'conditions' => [ [ 'left' => '{{ task.decision }}', 'operator' => 'eq', 'value' => 'approved' ] ] ]),
	    self::node('ok', 'app::send-email', 'Tell: approved', 1100, 80, [ 'to' => '{{ form_user.email }}', 'subject' => 'Your expense of {{ form.amount }} EUR is approved',
		'fromName' => 'Northwind Finance', 'email' => self::emailOf('<p>Hello {{ form_user.name }},</p><p>{{ task_user.name }} approved your expense: {{ form.reason }}. It will be paid with your next salary.</p>') ]),
	    self::node('no', 'app::send-email', 'Tell: rejected', 1100, 320, [ 'to' => '{{ form_user.email }}', 'subject' => 'Your expense of {{ form.amount }} EUR was rejected',
		'fromName' => 'Northwind Finance', 'email' => self::emailOf('<p>Hello {{ form_user.name }},</p><p>{{ task_user.name }} rejected your expense: {{ task.comment }}</p>') ]),
	    self::node('remind', 'app::send-email', 'Remind finance', 660, 420, [ 'to' => 'finance@northwind.example', 'subject' => 'No decision yet: {{ form.amount }} EUR for {{ form_user.name }}',
		'fromName' => 'Northwind Finance', 'email' => self::emailOf('<p>The expense request of {{ form_user.name }} ({{ form.amount }} EUR, {{ form.category }}) has waited 3 days for a decision.</p>') ]),
	    self::edge('e1', 'form', 'record'), self::edge('e2', 'record', 'approve'), self::edge('e3', 'approve', 'decide', 0), self::edge('e4', 'approve', 'remind', 1),
	    self::edge('e5', 'decide', 'check'), self::edge('e6', 'check', 'ok', 0), self::edge('e7', 'check', 'no', 1),
	];
	$nodes = [];
	foreach($list as $n)
	    $nodes[$n['wfid']] = $n;
	return $nodes;
    }

    //Mondays 08:00: the pending expenses, grouped by employee (every request goes to Maria: by approver would be one group), as an Excel file mailed to finance
    private static function reportNodes($table) {
	$code = <<<'J'
// the pending requests by employee: a row each for the file, a line per employee for the email
const by = {};
for (const r of pending.rows) {
  const name = r.employee_name || 'Unknown';
  if (!by[name]) by[name] = { name: name, count: 0, total: 0 };
  by[name].count += 1;
  by[name].total += r.amount;
}
const groups = Object.values(by).sort((a, b) => b.total - a.total);
report = {
  count: pending.rows.length,
  total: (Math.round(pending.rows.reduce((s, r) => s + r.amount, 0) * 100) / 100).toFixed(2),
  week: String(schedule_time).slice(0, 10),
  summary: groups.map((g) => g.name + ': ' + g.count + ' (' + g.total.toFixed(2) + ' EUR)').join('; ') || 'none',
  rows: pending.rows.slice().sort((a, b) => a.employee_name < b.employee_name ? -1 : 1).map((r) => ({
    employee: r.employee_name, amount: r.amount, category: r.category, reason: r.reason, requested: r.created_at })),
};
J;
	return self::flow([
	    [ 'id' => 'monday', 'type' => 'trigger::schedule', 'title' => 'Mondays 08:00', 'config' => [ 'mode' => 'weekly', 'weekdays' => [ '1' ], 'time' => '08:00' ] ],
	    [ 'id' => 'pending', 'type' => 'app::table', 'title' => 'Pending expenses', 'config' => [ 'table' => $table, 'operation' => 'query', 'match' => 'all',
		'filter' => [ [ 'column' => 'status', 'operator' => 'eq', 'value' => 'pending' ] ], 'sortBy' => 'created_at', 'sortDir' => 'asc', 'limit' => 500,
		'variable' => 'pending', 'onError' => 'fail' ] ],
	    [ 'id' => 'group', 'type' => 'app::code', 'title' => 'Group by employee', 'config' => [ 'code' => $code, 'timeout' => 5, 'onError' => 'fail' ] ],
	    [ 'id' => 'sheet', 'type' => 'app::spreadsheet', 'title' => 'Excel file', 'config' => [ 'rows' => '{{ report.rows }}', 'format' => 'xlsx',
		'filename' => 'pending-expenses-{{ report.week }}', 'sheet' => 'Pending', 'variable' => 'file', 'onError' => 'fail', 'columns' => [
		    [ 'key' => 'employee', 'label' => 'Employee' ], [ 'key' => 'amount', 'label' => 'Amount (EUR)' ], [ 'key' => 'category', 'label' => 'Category' ],
		    [ 'key' => 'reason', 'label' => 'Reason' ], [ 'key' => 'requested', 'label' => 'Requested' ] ] ] ],
	    [ 'id' => 'mail', 'type' => 'app::send-email', 'title' => 'Mail finance', 'config' => [ 'to' => 'finance@northwind.example',
		'subject' => 'Pending expenses, week of {{ report.week }}: {{ report.count }} requests, {{ report.total }} EUR', 'fromName' => 'Northwind Finance',
		'attachments' => '{{ file.id }}',
		'email' => self::emailOf('<p>Good morning,</p><p>{{ report.count }} expense requests ({{ report.total }} EUR) are waiting for a decision.</p><p>By employee: {{ report.summary }}</p><p>The list is attached ({{ file.filename }}).</p>') ] ],
	], [ [ 'from' => 'monday', 'to' => 'pending' ], [ 'from' => 'pending', 'to' => 'group' ], [ 'from' => 'group', 'to' => 'sheet' ], [ 'from' => 'sheet', 'to' => 'mail' ] ]);
    }

    private static function hireColumns() {
	return [
	    [ 'key' => 'name', 'label' => 'Name', 'type' => 'text', 'required' => true ],
	    [ 'key' => 'email', 'label' => 'Email', 'type' => 'text' ],
	    [ 'key' => 'role', 'label' => 'Role', 'type' => 'text', 'required' => true ],
	    [ 'key' => 'start_date', 'label' => 'Start date', 'type' => 'date', 'required' => true ],
	    [ 'key' => 'manager', 'label' => 'Manager', 'type' => 'text' ],
	    [ 'key' => 'status', 'label' => 'Status', 'type' => 'select', 'choices' => "onboarding\nready", 'required' => true ],
	];
    }

    //a form -> hires -> IT, the manager, HR side by side (reminders on their time limits) -> Merge, Merge -> ready -> welcome email
    private static function onboardingNodes($table, $it, $hr, array $people) {
	$task = fn($id, $title, $instructions, array $who, $days, array $fields) => [ 'id' => $id, 'type' => 'app::task', 'title' => $title, 'config' => [
	    'title' => $instructions[0], 'instructions' => $instructions[1], 'users' => $who['users'] ?? [], 'roles' => [], 'assignTo' => $who['assignTo'] ?? null,
	    'submitLabel' => 'Done', 'timeoutUnit' => 'days', 'timeout' => $days, 'variable' => $id, 'form' => [ 'direction' => 'vertical', 'fields' => $fields ] ] ];
	$remind = fn($id, $title, $to, $what) => [ 'id' => $id, 'type' => 'app::send-email', 'title' => $title, 'config' => [ 'to' => $to,
	    'subject' => 'Reminder: {{ form.name }} starts on {{ form.start_date }}', 'fromName' => 'Northwind HR',
	    'email' => self::emailOf("<p>{$what} for {{ form.name }} ({{ form.role }}) is still open: they start on {{ form.start_date }}.</p>") ] ];
	return self::flow([
	    [ 'id' => 'form', 'type' => 'trigger::form', 'title' => 'New hire', 'config' => [ 'access' => 'members', 'submitLabel' => 'Start onboarding',
		'message' => 'Onboarding started: IT, the manager and HR have their tasks.', 'variable' => 'form', 'form' => [ 'direction' => 'vertical', 'fields' => [
		    [ 'type' => 'text', 'key' => 'name', 'label' => 'Name', 'required' => true ],
		    [ 'type' => 'email', 'key' => 'email', 'label' => 'Work email', 'required' => true ],
		    [ 'type' => 'text', 'key' => 'role', 'label' => 'Role', 'required' => true ],
		    [ 'type' => 'date', 'key' => 'start_date', 'label' => 'Start date', 'required' => true ],
		    [ 'type' => 'select', 'key' => 'manager', 'label' => 'Manager', 'required' => true, 'choices' => self::choicesOf($people) ],
		] ] ] ],
	    [ 'id' => 'record', 'type' => 'app::table', 'title' => 'Add to hires', 'config' => [ 'table' => $table, 'operation' => 'insert', 'variable' => 'hire', 'onError' => 'fail', 'data' => [
		[ 'column' => 'name', 'value' => '{{ form.name }}' ], [ 'column' => 'email', 'value' => '{{ form.email }}' ], [ 'column' => 'role', 'value' => '{{ form.role }}' ],
		[ 'column' => 'start_date', 'value' => '{{ form.start_date }}' ], [ 'column' => 'manager', 'value' => '{{ form.manager }}' ], [ 'column' => 'status', 'value' => 'onboarding' ] ] ] ],
	    $task('it', 'IT: laptop ready', [ 'Laptop and accounts for {{ form.name }}', "{{ form.name }} starts on {{ form.start_date }} as {{ form.role }}.\n\nPrepare the laptop, the badge and the accounts." ],
		[ 'users' => [ $it ] ], 3, [ [ 'type' => 'checkbox', 'key' => 'ready', 'label' => 'Laptop, badge and accounts are ready', 'required' => true ],
		    [ 'type' => 'textarea', 'key' => 'notes', 'label' => 'Notes', 'rows' => 2 ] ]),
	    $task('manager', 'Manager: first-week plan', [ 'First-week plan for {{ form.name }}', "{{ form.name }} joins your team on {{ form.start_date }}. Write their first week: who they meet, what they learn." ],
		[ 'assignTo' => '{{ form.manager }}' ], 5, [ [ 'type' => 'textarea', 'key' => 'plan', 'label' => 'First-week plan', 'required' => true, 'rows' => 5 ] ]),
	    $task('hr', 'HR: contract signed', [ 'Contract of {{ form.name }}', "Get the contract of {{ form.name }} signed and set up payroll before {{ form.start_date }}." ],
		[ 'users' => [ $hr ] ], 2, [ [ 'type' => 'checkbox', 'key' => 'signed', 'label' => 'The contract is signed', 'required' => true ],
		    [ 'type' => 'textarea', 'key' => 'notes', 'label' => 'Notes', 'rows' => 2 ] ]),
	    $remind('remind_it', 'Remind IT', 'it@northwind.example', 'The laptop and accounts'),
	    $remind('remind_manager', 'Remind the manager', '{{ form.manager }}', 'The first-week plan'),
	    $remind('remind_hr', 'Remind HR', 'hr@northwind.example', 'The contract'),
	    [ 'id' => 'both', 'type' => 'core::merge', 'title' => 'IT and manager done', 'config' => [ 'mode' => 'all' ] ],
	    [ 'id' => 'all', 'type' => 'core::merge', 'title' => 'All three done', 'config' => [ 'mode' => 'all' ] ],
	    [ 'id' => 'ready', 'type' => 'app::table', 'title' => 'Mark ready', 'config' => [ 'table' => $table, 'operation' => 'update', 'rowId' => '{{ hire.row.id }}', 'variable' => 'hire',
		'onError' => 'fail', 'data' => [ [ 'column' => 'status', 'value' => 'ready' ] ] ] ],
	    [ 'id' => 'welcome', 'type' => 'app::send-email', 'title' => 'Welcome email', 'config' => [ 'to' => '{{ form.email }}', 'cc' => '{{ form.manager }}',
		'subject' => 'Welcome to Northwind, {{ form.name }}!', 'fromName' => 'Northwind HR', 'email' => self::emailOf('<p>Hello {{ form.name }},</p><p>Welcome to Northwind Supplies! Your laptop and accounts are ready, and your manager has planned your first week.</p><p>See you on {{ form.start_date }}.</p>') ] ],
	], [
	    [ 'from' => 'form', 'to' => 'record' ], [ 'from' => 'record', 'to' => 'it' ], [ 'from' => 'record', 'to' => 'manager' ], [ 'from' => 'record', 'to' => 'hr' ],
	    [ 'from' => 'it', 'output' => 'done', 'to' => 'both', 'input' => 0 ], [ 'from' => 'it', 'output' => 'timeout', 'to' => 'remind_it' ], [ 'from' => 'remind_it', 'to' => 'both', 'input' => 0 ],
	    [ 'from' => 'manager', 'output' => 'done', 'to' => 'both', 'input' => 1 ], [ 'from' => 'manager', 'output' => 'timeout', 'to' => 'remind_manager' ], [ 'from' => 'remind_manager', 'to' => 'both', 'input' => 1 ],
	    [ 'from' => 'both', 'to' => 'all', 'input' => 0 ],
	    [ 'from' => 'hr', 'output' => 'done', 'to' => 'all', 'input' => 1 ], [ 'from' => 'hr', 'output' => 'timeout', 'to' => 'remind_hr' ], [ 'from' => 'remind_hr', 'to' => 'all', 'input' => 1 ],
	    [ 'from' => 'all', 'to' => 'ready' ], [ 'from' => 'ready', 'to' => 'welcome' ],
	]);
    }

    private static function supplierColumns() {
	return [
	    [ 'key' => 'code', 'label' => 'VAT code', 'type' => 'text', 'required' => true ],
	    [ 'key' => 'name', 'label' => 'Name', 'type' => 'text' ],
	    [ 'key' => 'country', 'label' => 'Country', 'type' => 'text' ],
	    [ 'key' => 'address', 'label' => 'Address', 'type' => 'textarea' ],
	    [ 'key' => 'source', 'label' => 'Checked in', 'type' => 'select', 'choices' => "ANAF\nVIES" ],
	];
    }

    //a form of codes -> ANAF (RO) and VIES (the others) -> the valid ones into suppliers, the others a Human task to Maria
    private static function supplierNodes($table, $maria) {
	$split = <<<'J'
// Romanian codes (RO and digits, or digits only) to ANAF, the others to VIES
const codes = String(form.codes).split(/[\s,;]+/).map((s) => s.trim().toUpperCase()).filter((s) => s);
ro = codes.filter((c) => /^(RO)?\d{2,10}$/.test(c));
eu = codes.filter((c) => !ro.includes(c));
J;
	$sort = <<<'J'
// the valid suppliers (rows for the table) and the problems (for Maria)
valid = [];
problems = [];
for (const c of (companies ? companies.items : [])) {
  if (c.inactive) problems.push('RO' + c.cui + ' ' + c.name + ': inactive at ANAF since ' + c.inactiveSince);
  else if (!c.vatPayer) problems.push('RO' + c.cui + ' ' + c.name + ': not a VAT payer');
  else valid.push({ code: 'RO' + c.cui, name: c.name, country: 'RO', address: c.address, source: 'ANAF' });
}
for (const c of (companies ? companies.notFound : [])) problems.push(c + ': ANAF has no company with this code');
for (const c of (companies ? companies.invalid : [])) problems.push(c + ': not a valid CUI (control digit)');
if (!companies && ro.length) problems.push(ro.join(', ') + ': ANAF did not answer, check again later');
for (const v of (vat ? vat.items : [])) {
  if (v.valid) valid.push({ code: v.vatNumber, name: v.name || '', country: v.country, address: v.address || '', source: 'VIES' });
  else problems.push(v.input + ': ' + (v.error ? 'VIES could not check it (' + v.error + ')' : 'not a valid VAT number in VIES'));
}
if (!vat && eu.length) problems.push(eu.join(', ') + ': VIES did not answer, check again later');
J;
	return self::flow([
	    [ 'id' => 'form', 'type' => 'trigger::form', 'title' => 'Supplier codes', 'config' => [ 'access' => 'members', 'submitLabel' => 'Check',
		'message' => 'Checking: the valid suppliers are added, finance looks at the others.', 'variable' => 'form', 'form' => [ 'direction' => 'vertical', 'fields' => [
		    [ 'type' => 'textarea', 'key' => 'codes', 'label' => 'VAT codes', 'required' => true, 'rows' => 5,
			'helpText' => 'One per line: a Romanian CUI (with or without RO) or an EU VAT number with its country prefix' ],
		] ] ] ],
	    [ 'id' => 'split', 'type' => 'app::code', 'title' => 'Romanian or EU', 'config' => [ 'code' => $split, 'timeout' => 5, 'onError' => 'fail' ] ],
	    [ 'id' => 'anaf', 'type' => 'app::ro-company', 'title' => 'ANAF Company Lookup', 'config' => [ 'cuis' => '{{ ro }}', 'variable' => 'companies', 'onError' => 'output', 'timeout' => 30 ] ],
	    [ 'id' => 'vies', 'type' => 'app::eu-vies', 'title' => 'VIES VAT Check', 'config' => [ 'operation' => 'check', 'numbers' => '{{ eu }}', 'variable' => 'vat', 'onError' => 'output', 'timeout' => 60 ] ],
	    [ 'id' => 'sort', 'type' => 'app::code', 'title' => 'Valid or not', 'config' => [ 'code' => $sort, 'timeout' => 5, 'onError' => 'fail' ] ],
	    [ 'id' => 'any', 'type' => 'core::if', 'title' => 'Any valid', 'config' => [ 'match' => 'all', 'conditions' => [ [ 'left' => '{{ valid }}', 'operator' => 'notEmpty', 'value' => '' ] ] ] ],
	    [ 'id' => 'add', 'type' => 'app::table', 'title' => 'Add to suppliers', 'config' => [ 'table' => $table, 'operation' => 'insertMany', 'rows' => '{{ valid }}', 'variable' => 'added', 'onError' => 'fail' ] ],
	    [ 'id' => 'issues', 'type' => 'core::if', 'title' => 'Any problem', 'config' => [ 'match' => 'all', 'conditions' => [ [ 'left' => '{{ problems }}', 'operator' => 'notEmpty', 'value' => '' ] ] ] ],
	    [ 'id' => 'review', 'type' => 'app::task', 'title' => 'Finance: review', 'config' => [ 'title' => 'Supplier codes to review ({{ problems.length }})',
		'instructions' => "{{ form_user.name }} checked these codes; they were not added to the suppliers:\n\n{{ problems.join('\\n') }}",
		'users' => [ $maria ], 'roles' => [], 'assignTo' => null, 'submitLabel' => 'Send', 'timeoutUnit' => 'none', 'timeout' => 1, 'variable' => 'review',
		'form' => [ 'direction' => 'vertical', 'fields' => [
		    [ 'type' => 'select', 'key' => 'action', 'label' => 'Next', 'required' => true, 'choices' => self::choicesOf([ 'contact' => 'Contact the supplier', 'drop' => 'Do not buy from them' ]) ],
		    [ 'type' => 'textarea', 'key' => 'comment', 'label' => 'Comment', 'rows' => 3 ],
		] ] ] ],
	], [
	    [ 'from' => 'form', 'to' => 'split' ], [ 'from' => 'split', 'to' => 'anaf' ],
	    [ 'from' => 'anaf', 'output' => 'success', 'to' => 'vies' ], [ 'from' => 'anaf', 'output' => 'error', 'to' => 'vies' ],
	    [ 'from' => 'vies', 'output' => 'success', 'to' => 'sort' ], [ 'from' => 'vies', 'output' => 'error', 'to' => 'sort' ],
	    [ 'from' => 'sort', 'to' => 'any' ], [ 'from' => 'any', 'output' => 'true', 'to' => 'add' ], [ 'from' => 'any', 'output' => 'false', 'to' => 'issues' ], [ 'from' => 'add', 'to' => 'issues' ],
	    [ 'from' => 'issues', 'output' => 'true', 'to' => 'review' ],
	]);
    }

    // ------------------------------------------------------------------------------------
    //IT Operations

    private function itOperations($project, $oncall) {
	$tom = (string)$this->u['tom']->_id;
	$maria = (string)$this->u['maria']->_id;
	$hosts = [ 'www.qflows.eu', self::serverHost($this->base) ?? 'github.com', 'example.com', 'expired.badssl.com' ];
	$rows = array_map(fn($h) => [ 'host' => $h, 'address' => "https://{$h}" ], $hosts);
	[ $wfs ] = $this->import($project, [
	    'DT-ALERTS' => [ 'alerts', self::alertColumns(), [] ], 'DT-BLOCKLIST' => [ 'blocklist', self::blocklistColumns(), [] ], 'DT-HOSTS' => [ 'hosts', self::hostColumns(), $rows ],
	], [
	    'Security alert playbook (simulated)' => [ 'shield-exclamation::danger', self::playbookNodes('DT-ALERTS', 'DT-BLOCKLIST', (string)$oncall->_id) ],
	    'Certificate expiry watch' => [ 'shield-lock::info', self::certNodes('DT-HOSTS', [ $tom, $maria ]) ],
	]);

	//the alerts as Wazuh posts them; decided by Maria (on call)
	$v = $this->publish($wfs['Security alert playbook (simulated)'], [ [ 'Critical alerts to the on-call person, the others logged', 6 ] ]);
	foreach(self::ALERTS as $i => [ $rule, $level, $description, $agent, $srcip, $groups, $decision, $comment, $hours ]) {
	    $exe = $this->webhookRun($wfs['Security alert playbook (simulated)'], $v, self::wazuhAlert($i, $rule, $level, $description, $agent, $srcip, $groups, $hours));
	    if ($decision !== null)
		$exe = $this->answer($exe, 'ask', [ 'decision' => $decision, 'comment' => $comment ], 'maria');
	    $this->done($exe, $hours, "Security alert playbook: level {$level} from {$srcip}");
	}

	//the certificates: yesterday's run (its task done by Tom) and today's
	$v = $this->publish($wfs['Certificate expiry watch'], [ [ 'Every host each morning; a task when one expires within 30 days', 4 ] ]);
	foreach([ 1, 0 ] as $days) {
	    $at = (new \DateTimeImmutable('today 07:00'))->modify("-{$days} days");
	    if ($at > new \DateTimeImmutable())
		$at = $at->modify('-1 day');
	    $exe = $this->scheduleRun($wfs['Certificate expiry watch'], $v, $at);
	    if ($days === 1)
		$exe = $this->answer($exe, 'renew', [ 'action' => 'known', 'comment' => 'expired.badssl.com is a test host: its certificate expired on purpose.' ], 'tom');
	    $this->done($exe, max(1, (int)round((time() - $at->getTimestamp()) / 3600)), 'Certificate expiry watch: ' . $at->format('D j M'));
	}
	return $wfs;
    }

    //the host of this server's address when it is a public https one (the demo instance), else null
    private static function serverHost($base) {
	$host = (string)parse_url($base, PHP_URL_HOST);
	if (parse_url($base, PHP_URL_SCHEME) !== 'https' || $host === '' || $host === 'localhost' || filter_var($host, FILTER_VALIDATE_IP) || !str_contains($host, '.'))
	    return null;
	return in_array($host, [ 'www.qflows.eu', 'example.com', 'expired.badssl.com' ], true) ? null : $host;
    }

    private static function alertColumns() {
	return [
	    [ 'key' => 'level', 'label' => 'Level', 'type' => 'number', 'required' => true ],
	    [ 'key' => 'rule', 'label' => 'Rule', 'type' => 'text' ],
	    [ 'key' => 'description', 'label' => 'Description', 'type' => 'textarea' ],
	    [ 'key' => 'agent', 'label' => 'Agent', 'type' => 'text' ],
	    [ 'key' => 'srcip', 'label' => 'Source address', 'type' => 'text' ],
	    [ 'key' => 'outcome', 'label' => 'Outcome', 'type' => 'select', 'choices' => "logged\nnot blocked\nno answer", 'required' => true ],
	    [ 'key' => 'comment', 'label' => 'Comment', 'type' => 'textarea' ],
	];
    }

    private static function blocklistColumns() {
	return [
	    [ 'key' => 'address', 'label' => 'Address', 'type' => 'text', 'required' => true ],
	    [ 'key' => 'reason', 'label' => 'Reason', 'type' => 'textarea' ],
	    [ 'key' => 'agent', 'label' => 'Seen on', 'type' => 'text' ],
	    [ 'key' => 'level', 'label' => 'Level', 'type' => 'number' ],
	    [ 'key' => 'approved_by', 'label' => 'Approved by', 'type' => 'object', 'object' => 'User' ],
	];
    }

    private static function hostColumns() {
	return [
	    [ 'key' => 'host', 'label' => 'Host', 'type' => 'text', 'required' => true ],
	    [ 'key' => 'address', 'label' => 'Address', 'type' => 'text', 'required' => true ],
	    [ 'key' => 'days_left', 'label' => 'Days left', 'type' => 'number' ],
	    [ 'key' => 'valid_to', 'label' => 'Valid to', 'type' => 'text' ],
	    [ 'key' => 'issuer', 'label' => 'Issuer', 'type' => 'text' ],
	    [ 'key' => 'status', 'label' => 'Status', 'type' => 'text' ],
	    [ 'key' => 'checked_at', 'label' => 'Checked at', 'type' => 'text' ],
	];
    }

    //a Wazuh alert (its JSON as the integrations send it), the $i-th of the demo, $hours ago
    private static function wazuhAlert($i, $rule, $level, $description, $agent, $srcip, array $groups, $hours) {
	$at = (new \DateTimeImmutable("-{$hours} hours"));
	return [
	    'timestamp' => $at->format('Y-m-d\TH:i:s.vO'),
	    'rule' => [ 'level' => $level, 'description' => $description, 'id' => $rule, 'firedtimes' => 3 + $i * 7, 'mail' => $level >= 10, 'groups' => $groups ],
	    'agent' => [ 'id' => sprintf('%03d', 4 + $i), 'name' => $agent, 'ip' => '192.0.2.' . (20 + $i) ],
	    'manager' => [ 'name' => 'wazuh-manager' ],
	    'id' => sprintf('%d.%d', $at->getTimestamp(), 104857 + $i * 811),
	    'decoder' => [ 'name' => str_contains(implode(',', $groups), 'sshd') ? 'sshd' : 'web-accesslog' ],
	    'data' => [ 'srcip' => $srcip, 'srcport' => (string)(40000 + $i * 1111) ],
	    'location' => str_contains(implode(',', $groups), 'sshd') ? '/var/log/secure' : '/var/log/nginx/access.log',
	];
    }

    //a webhook (a Wazuh alert) -> Switch on the level: 10+ a task for On call (block: blocklist; not: alerts), below 10: alerts; a Note (FortiGate in production)
    private static function playbookNodes($alerts, $blocklist, $oncall) {
	$read = <<<'J'
// the fields of the Wazuh alert the playbook uses
const a = webhook.body;
alert = { level: Number(a.rule.level), rule: String(a.rule.id), description: a.rule.description, agent: a.agent.name, srcip: a.data ? a.data.srcip : '' };
J;
	$row = fn($outcome, $comment) => [ [ 'column' => 'level', 'value' => '{{ alert.level }}' ], [ 'column' => 'rule', 'value' => '{{ alert.rule }}' ],
	    [ 'column' => 'description', 'value' => '{{ alert.description }}' ], [ 'column' => 'agent', 'value' => '{{ alert.agent }}' ], [ 'column' => 'srcip', 'value' => '{{ alert.srcip }}' ],
	    [ 'column' => 'outcome', 'value' => $outcome ], [ 'column' => 'comment', 'value' => $comment ] ];
	return self::flow([
	    [ 'id' => 'hook', 'type' => 'trigger::webhook', 'title' => 'Wazuh alert', 'config' => [ 'method' => 'POST', 'contentType' => 'json', 'respond' => 'received', 'auth' => 'none', 'variable' => 'webhook' ] ],
	    [ 'id' => 'note', 'type' => 'core::note', 'title' => 'Note', 'config' => [ 'text' => 'Simulated: replace the Blocklist step with FortiGate → Banned IPs in production.' ] ],
	    [ 'id' => 'read', 'type' => 'app::code', 'title' => 'Read the alert', 'config' => [ 'code' => $read, 'timeout' => 5, 'onError' => 'fail' ] ],
	    [ 'id' => 'level', 'type' => 'core::switch', 'title' => 'Severity', 'config' => [ 'variable' => '{{ alert.level }}', 'cases' => [ [ 'label' => 'critical', 'operator' => 'gte', 'value' => '10' ] ] ] ],
	    [ 'id' => 'ask', 'type' => 'app::task', 'title' => 'On call: block?', 'config' => [ 'title' => 'Block {{ alert.srcip }}?',
		'instructions' => "Level {{ alert.level }} on {{ alert.agent }}: {{ alert.description }} (rule {{ alert.rule }}).\n\nBlock the address at the firewall?",
		'users' => [], 'roles' => [ $oncall ], 'assignTo' => null, 'submitLabel' => 'Send decision', 'timeoutUnit' => 'hours', 'timeout' => 4, 'variable' => 'task',
		'form' => [ 'direction' => 'vertical', 'fields' => [
		    [ 'type' => 'select', 'key' => 'decision', 'label' => 'Decision', 'required' => true, 'choices' => self::choicesOf([ 'block' => 'Block the address', 'ignore' => 'Do not block' ]) ],
		    [ 'type' => 'textarea', 'key' => 'comment', 'label' => 'Why', 'rows' => 2 ],
		] ] ] ],
	    [ 'id' => 'decided', 'type' => 'core::if', 'title' => 'If block', 'config' => [ 'match' => 'all', 'conditions' => [ [ 'left' => '{{ task.decision }}', 'operator' => 'eq', 'value' => 'block' ] ] ] ],
	    [ 'id' => 'block', 'type' => 'app::table', 'title' => 'Blocklist', 'config' => [ 'table' => $blocklist, 'operation' => 'insert', 'variable' => 'blocked', 'onError' => 'fail', 'data' => [
		[ 'column' => 'address', 'value' => '{{ alert.srcip }}' ], [ 'column' => 'reason', 'value' => '{{ alert.description }} {{ task.comment }}' ], [ 'column' => 'agent', 'value' => '{{ alert.agent }}' ],
		[ 'column' => 'level', 'value' => '{{ alert.level }}' ], [ 'column' => 'approved_by', 'value' => '{{ task_user.id }}' ] ] ] ],
	    [ 'id' => 'kept', 'type' => 'app::table', 'title' => 'Log: not blocked', 'config' => [ 'table' => $alerts, 'operation' => 'insert', 'variable' => 'logged', 'onError' => 'fail',
		'data' => $row('not blocked', '{{ task_user.name }}: {{ task.comment }}') ] ],
	    [ 'id' => 'expired', 'type' => 'app::table', 'title' => 'Log: no answer', 'config' => [ 'table' => $alerts, 'operation' => 'insert', 'variable' => 'logged', 'onError' => 'fail',
		'data' => $row('no answer', 'Nobody on call answered in 4 hours') ] ],
	    [ 'id' => 'low', 'type' => 'app::table', 'title' => 'Log', 'config' => [ 'table' => $alerts, 'operation' => 'insert', 'variable' => 'logged', 'onError' => 'fail', 'data' => $row('logged', '') ] ],
	], [
	    [ 'from' => 'hook', 'to' => 'read' ], [ 'from' => 'read', 'to' => 'level' ],
	    [ 'from' => 'level', 'output' => 'critical', 'to' => 'ask' ], [ 'from' => 'level', 'output' => 'otherwise', 'to' => 'low' ],
	    [ 'from' => 'ask', 'output' => 'done', 'to' => 'decided' ], [ 'from' => 'ask', 'output' => 'timeout', 'to' => 'expired' ],
	    [ 'from' => 'decided', 'output' => 'true', 'to' => 'block' ], [ 'from' => 'decided', 'output' => 'false', 'to' => 'kept' ],
	]);
    }

    //daily 07:00: hosts -> Loop: Read a certificate -> the row updated -> the ones expiring (30 days) or expired: a Human task
    private static function certNodes($table, array $users) {
	$one = <<<'J'
// this host's result: its row's values and whether it needs someone
const days = cert ? cert.daysLeft : null;
check = {
  host: host.host,
  days: days,
  validTo: cert ? String(cert.validTo).slice(0, 10) : '',
  issuer: cert ? (cert.issuer.O || cert.issuer.CN || '') : '',
  status: !cert ? 'no answer' : (cert.expired ? 'expired' : (days < 30 ? 'expires soon' : (cert.hostnameMatches === false ? 'other name' : (cert.trusted === false ? 'not trusted' : 'ok')))),
  checkedAt: Date().toISOString().slice(0, 16).replace('T', ' '),
};
check.problem = check.status === 'expired' || check.status === 'expires soon';
J;
	$list = <<<'J'
// the hosts that need someone
renew = checks.results.filter((c) => c && c.problem).map((c) => c.host + ': ' + (c.status === 'expired' ? 'expired on ' + c.validTo : c.days + ' days left (' + c.validTo + ')'));
J;
	return self::flow([
	    [ 'id' => 'daily', 'type' => 'trigger::schedule', 'title' => 'Every day 07:00', 'config' => [ 'mode' => 'daily', 'time' => '07:00' ] ],
	    [ 'id' => 'hosts', 'type' => 'app::table', 'title' => 'Hosts', 'config' => [ 'table' => $table, 'operation' => 'query', 'match' => 'all', 'filter' => [], 'sortBy' => 'host',
		'sortDir' => 'asc', 'limit' => 100, 'variable' => 'hosts', 'onError' => 'fail' ] ],
	    [ 'id' => 'each', 'type' => 'core::loop', 'title' => 'Each host', 'config' => [ 'list' => '{{ hosts.rows }}', 'itemVariable' => 'host', 'indexVariable' => 'index',
		'collect' => '{{ check }}', 'variable' => 'checks', 'onError' => 'skip' ] ],
	    [ 'id' => 'cert', 'type' => 'app::certificate', 'title' => 'Read a certificate', 'parent' => 'each', 'config' => [ 'source' => 'server', 'url' => '{{ host.address }}',
		'variable' => 'cert', 'onError' => 'output', 'timeout' => 15, 'keepPem' => false ] ],
	    [ 'id' => 'result', 'type' => 'app::code', 'title' => 'Days left', 'parent' => 'each', 'config' => [ 'code' => $one, 'timeout' => 5, 'onError' => 'fail' ] ],
	    [ 'id' => 'save', 'type' => 'app::table', 'title' => 'Update the host', 'parent' => 'each', 'config' => [ 'table' => $table, 'operation' => 'update', 'rowId' => '{{ host.id }}',
		'variable' => 'saved', 'onError' => 'fail', 'data' => [ [ 'column' => 'days_left', 'value' => '{{ check.days }}' ], [ 'column' => 'valid_to', 'value' => '{{ check.validTo }}' ],
		    [ 'column' => 'issuer', 'value' => '{{ check.issuer }}' ], [ 'column' => 'status', 'value' => '{{ check.status }}' ], [ 'column' => 'checked_at', 'value' => '{{ check.checkedAt }}' ] ] ] ],
	    [ 'id' => 'list', 'type' => 'app::code', 'title' => 'Expiring', 'config' => [ 'code' => $list, 'timeout' => 5, 'onError' => 'fail' ] ],
	    [ 'id' => 'any', 'type' => 'core::if', 'title' => 'Any to renew', 'config' => [ 'match' => 'all', 'conditions' => [ [ 'left' => '{{ renew }}', 'operator' => 'notEmpty', 'value' => '' ] ] ] ],
	    [ 'id' => 'renew', 'type' => 'app::task', 'title' => 'Renew certificates', 'config' => [ 'title' => 'Certificates to renew ({{ renew.length }})',
		'instructions' => "These certificates expire within 30 days or have expired:\n\n{{ renew.join('\\n') }}", 'users' => $users, 'roles' => [], 'assignTo' => null,
		'submitLabel' => 'Send', 'timeoutUnit' => 'none', 'timeout' => 1, 'variable' => 'renewal', 'form' => [ 'direction' => 'vertical', 'fields' => [
		    [ 'type' => 'select', 'key' => 'action', 'label' => 'Done', 'required' => true, 'choices' => self::choicesOf([ 'renewed' => 'Renewed', 'ordered' => 'Ordered a new one', 'known' => 'Known, nothing to do' ]) ],
		    [ 'type' => 'textarea', 'key' => 'comment', 'label' => 'Comment', 'rows' => 2 ],
		] ] ] ],
	], [
	    [ 'from' => 'daily', 'to' => 'hosts' ], [ 'from' => 'hosts', 'to' => 'each' ],
	    [ 'from' => 'cert', 'output' => 'success', 'to' => 'result' ], [ 'from' => 'cert', 'output' => 'error', 'to' => 'result' ], [ 'from' => 'result', 'to' => 'save' ],
	    [ 'from' => 'each', 'to' => 'list' ], [ 'from' => 'list', 'to' => 'any' ], [ 'from' => 'any', 'output' => 'true', 'to' => 'renew' ],
	]);
    }

    // ------------------------------------------------------------------------------------
    //Romania (RegionalRO)

    private function romania($project) {
	[ $wfs ] = $this->import($project, [ 'DT-INVOICES' => [ 'invoices', self::invoiceColumns(), [] ] ],
	    [ 'Foreign-currency invoice' => [ 'receipt-cutoff::primary', self::invoiceNodes('DT-INVOICES', (string)$this->u['maria']->_id) ] ]);
	$v = $this->publish($wfs['Foreign-currency invoice'], [ [ 'EUR lines in RON at the BNR rate; checked and turned into a PDF by ANAF, never uploaded', 8 ] ]);
	//issued by Daniel (sales)
	foreach(self::INVOICES as [ $cui, $number, $lines, $hours ]) {
	    $exe = $this->formRun($wfs['Foreign-currency invoice'], $v, 'daniel', [ 'cui' => $cui, 'number' => $number, 'lines' => $lines ]);
	    $this->done($exe, $hours, "Foreign-currency invoice: NW-{$number} for {$cui}");
	}
	return $wfs;
    }

    private static function invoiceColumns() {
	return [
	    [ 'key' => 'number', 'label' => 'Number', 'type' => 'text', 'required' => true ],
	    [ 'key' => 'buyer', 'label' => 'Buyer', 'type' => 'text' ],
	    [ 'key' => 'cui', 'label' => 'CUI', 'type' => 'text' ],
	    [ 'key' => 'amount_eur', 'label' => 'Amount (EUR)', 'type' => 'number' ],
	    [ 'key' => 'rate', 'label' => 'BNR rate', 'type' => 'number' ],
	    [ 'key' => 'net_ron', 'label' => 'Net (RON)', 'type' => 'number' ],
	    [ 'key' => 'vat_ron', 'label' => 'VAT (RON)', 'type' => 'number' ],
	    [ 'key' => 'total_ron', 'label' => 'Total (RON)', 'type' => 'number' ],
	    [ 'key' => 'pdf', 'label' => 'PDF', 'type' => 'text' ],
	];
    }

    //the Code step: UBL 2.1 / CIUS-RO (checked with ANAF's validation 2026-11-16: valid for Bucharest sectors and towns)
    const INVOICE_CODE = <<<'J'
// UBL 2.1 invoice, CIUS-RO (RO e-Factura): the seller Northwind, the buyer from ANAF, the lines in EUR converted to RON at BNR's rate
const esc = (s) => String(s ?? '').replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;').replaceAll('"', '&quot;');
const money = (n) => (Math.round(n * 100) / 100).toFixed(2);
const round = (n) => Math.round(n * 100) / 100;
// a Bucharest address: the sector as the city (CIUS-RO: SECTOR1 .. SECTOR6); elsewhere the locality without Mun. / Orş. / Com.
const city = (a) => {
  const m = String(a.locality ?? '').match(/Sector\s*(\d)/i);
  return a.countyCode === 'B' && m ? 'SECTOR' + m[1] : String(a.locality ?? '').replace(/^(Mun\.|Orş\.|Oraş|Com\.|Sat|Loc\.)\s*/u, '').replace(/\s+(Mun\.|Orş\.|Oraş|Com\.).*$/u, '').trim();
};
const street = (a) => [ a.street, a.number ? 'nr. ' + a.number : '', a.details ].filter((s) => s).join(' ').trim() || '-';
const party = (p) => '<cac:Party><cac:PostalAddress><cbc:StreetName>' + esc(p.street) + '</cbc:StreetName><cbc:CityName>' + esc(p.city) + '</cbc:CityName>'
  + '<cbc:CountrySubentity>' + esc(p.county) + '</cbc:CountrySubentity><cac:Country><cbc:IdentificationCode>RO</cbc:IdentificationCode></cac:Country></cac:PostalAddress>'
  + (p.vat ? '<cac:PartyTaxScheme><cbc:CompanyID>RO' + esc(p.cui) + '</cbc:CompanyID><cac:TaxScheme><cbc:ID>VAT</cbc:ID></cac:TaxScheme></cac:PartyTaxScheme>' : '')
  + '<cac:PartyLegalEntity><cbc:RegistrationName>' + esc(p.name) + '</cbc:RegistrationName><cbc:CompanyID>' + esc(p.regCom || p.cui) + '</cbc:CompanyID></cac:PartyLegalEntity></cac:Party>';

const rate = rates.rates.EUR;
const c = companies.company;
const buyer = { name: c.name, cui: c.cui, regCom: c.regCom, vat: c.vatPayer, street: street(c.headOffice), city: city(c.headOffice), county: 'RO-' + c.headOffice.countyCode };
// the seller: fictional (a CUI ANAF has no company for)
const seller = { name: 'NORTHWIND SUPPLIES SRL', cui: '99999909', regCom: 'J40/1234/2020', vat: true, street: 'Str. Fabricii nr. 46', city: 'SECTOR6', county: 'RO-B' };
// the lines: description | quantity | unit price in EUR
const lines = String(form.lines).split('\n').map((l) => l.split('|').map((s) => s.trim())).filter((p) => p.length >= 3 && p[0])
  .map((p, i) => { const qty = Number(p[1]); const eur = Number(p[2]); const price = round(eur * rate); return { n: i + 1, name: p[0], qty: qty, eur: eur, price: price, net: round(qty * price) }; });
const net = round(lines.reduce((s, l) => s + l.net, 0));
const tax = round(net * 0.21);
const number = 'NW-' + form.number;
const issue = Date().toISOString().slice(0, 10);
const due = Date(Date.now() + 30 * 86400000).toISOString().slice(0, 10);

xml = '<?xml version="1.0" encoding="UTF-8"?>\n<Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2" xmlns:cac="urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2" xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2">'
  + '<cbc:CustomizationID>urn:cen.eu:en16931:2017#compliant#urn:efactura.mfinante.ro:CIUS-RO:1.0.1</cbc:CustomizationID>'
  + '<cbc:ID>' + esc(number) + '</cbc:ID><cbc:IssueDate>' + issue + '</cbc:IssueDate><cbc:DueDate>' + due + '</cbc:DueDate><cbc:InvoiceTypeCode>380</cbc:InvoiceTypeCode>'
  + '<cbc:Note>' + esc('Converted from EUR at the BNR rate of ' + rates.date + ': 1 EUR = ' + rate + ' RON') + '</cbc:Note>'
  + '<cbc:DocumentCurrencyCode>RON</cbc:DocumentCurrencyCode>'
  + '<cac:AccountingSupplierParty>' + party(seller) + '</cac:AccountingSupplierParty><cac:AccountingCustomerParty>' + party(buyer) + '</cac:AccountingCustomerParty>'
  + '<cac:PaymentMeans><cbc:PaymentMeansCode>30</cbc:PaymentMeansCode><cac:PayeeFinancialAccount><cbc:ID>RO49AAAA1B31007593840000</cbc:ID></cac:PayeeFinancialAccount></cac:PaymentMeans>'
  + '<cac:TaxTotal><cbc:TaxAmount currencyID="RON">' + money(tax) + '</cbc:TaxAmount><cac:TaxSubtotal><cbc:TaxableAmount currencyID="RON">' + money(net) + '</cbc:TaxableAmount>'
  + '<cbc:TaxAmount currencyID="RON">' + money(tax) + '</cbc:TaxAmount><cac:TaxCategory><cbc:ID>S</cbc:ID><cbc:Percent>21</cbc:Percent><cac:TaxScheme><cbc:ID>VAT</cbc:ID></cac:TaxScheme></cac:TaxCategory></cac:TaxSubtotal></cac:TaxTotal>'
  + '<cac:LegalMonetaryTotal><cbc:LineExtensionAmount currencyID="RON">' + money(net) + '</cbc:LineExtensionAmount><cbc:TaxExclusiveAmount currencyID="RON">' + money(net) + '</cbc:TaxExclusiveAmount>'
  + '<cbc:TaxInclusiveAmount currencyID="RON">' + money(net + tax) + '</cbc:TaxInclusiveAmount><cbc:PayableAmount currencyID="RON">' + money(net + tax) + '</cbc:PayableAmount></cac:LegalMonetaryTotal>'
  + lines.map((l) => '<cac:InvoiceLine><cbc:ID>' + l.n + '</cbc:ID><cbc:InvoicedQuantity unitCode="H87">' + l.qty + '</cbc:InvoicedQuantity><cbc:LineExtensionAmount currencyID="RON">' + money(l.net) + '</cbc:LineExtensionAmount>'
    + '<cac:Item><cbc:Name>' + esc(l.name) + '</cbc:Name><cac:ClassifiedTaxCategory><cbc:ID>S</cbc:ID><cbc:Percent>21</cbc:Percent><cac:TaxScheme><cbc:ID>VAT</cbc:ID></cac:TaxScheme></cac:ClassifiedTaxCategory></cac:Item>'
    + '<cac:Price><cbc:PriceAmount currencyID="RON">' + money(l.price) + '</cbc:PriceAmount></cac:Price></cac:InvoiceLine>').join('')
  + '</Invoice>';
invoice = { number: number, buyer: buyer.name, cui: buyer.cui, rate: rate, rateDate: rates.date, eur: round(lines.reduce((s, l) => s + l.qty * l.eur, 0)), net: net, vat: tax,
  total: round(net + tax), lines: lines.length };
J;

    //a form -> ANAF Company Lookup -> BNR -> Code (UBL) -> e-Factura validate -> valid: PDF + invoices; not: a Human task
    private static function invoiceNodes($table, $maria) {
	return self::flow([
	    [ 'id' => 'form', 'type' => 'trigger::form', 'title' => 'Invoice in EUR', 'config' => [ 'access' => 'members', 'submitLabel' => 'Make the invoice',
		'message' => 'The invoice is being checked; its PDF goes to Files.', 'variable' => 'form', 'form' => [ 'direction' => 'vertical', 'fields' => [
		    [ 'type' => 'text', 'key' => 'cui', 'label' => 'Client CUI', 'required' => true, 'placeholder' => 'RO14399840' ],
		    [ 'type' => 'text', 'key' => 'number', 'label' => 'Invoice number', 'required' => true, 'placeholder' => '2026-0144' ],
		    [ 'type' => 'textarea', 'key' => 'lines', 'label' => 'Lines', 'required' => true, 'rows' => 5, 'helpText' => 'One per line: description | quantity | unit price in EUR' ],
		] ] ] ],
	    [ 'id' => 'client', 'type' => 'app::ro-company', 'title' => 'ANAF Company Lookup', 'config' => [ 'cuis' => '{{ form.cui }}', 'variable' => 'companies', 'onError' => 'fail', 'timeout' => 30 ] ],
	    [ 'id' => 'rate', 'type' => 'app::ro-bnr-rates', 'title' => 'BNR EUR rate', 'config' => [ 'operation' => 'rates', 'currencies' => 'EUR', 'variable' => 'rates', 'onError' => 'fail', 'timeout' => 30 ] ],
	    [ 'id' => 'ubl', 'type' => 'app::code', 'title' => 'UBL invoice (CIUS-RO)', 'config' => [ 'code' => self::INVOICE_CODE, 'timeout' => 5, 'onError' => 'fail' ] ],
	    [ 'id' => 'check', 'type' => 'app::ro-efactura', 'title' => 'e-Factura: validate', 'config' => [ 'operation' => 'validate', 'xml' => '{{ xml }}', 'document' => 'invoice',
		'variable' => 'validation', 'onError' => 'fail', 'timeout' => 60 ] ],
	    [ 'id' => 'valid', 'type' => 'core::if', 'title' => 'If valid', 'config' => [ 'match' => 'all', 'conditions' => [ [ 'left' => '{{ validation.state }}', 'operator' => 'eq', 'value' => 'ok' ] ] ] ],
	    [ 'id' => 'pdf', 'type' => 'app::ro-efactura', 'title' => 'e-Factura: PDF', 'config' => [ 'operation' => 'pdf', 'xml' => '{{ xml }}', 'document' => 'invoice', 'checkFirst' => false,
		'filename' => '{{ invoice.number }}.pdf', 'variable' => 'pdf', 'onError' => 'fail', 'timeout' => 60 ] ],
	    [ 'id' => 'record', 'type' => 'app::table', 'title' => 'Add to invoices', 'config' => [ 'table' => $table, 'operation' => 'insert', 'variable' => 'saved', 'onError' => 'fail', 'data' => [
		[ 'column' => 'number', 'value' => '{{ invoice.number }}' ], [ 'column' => 'buyer', 'value' => '{{ invoice.buyer }}' ], [ 'column' => 'cui', 'value' => '{{ invoice.cui }}' ],
		[ 'column' => 'amount_eur', 'value' => '{{ invoice.eur }}' ], [ 'column' => 'rate', 'value' => '{{ invoice.rate }}' ], [ 'column' => 'net_ron', 'value' => '{{ invoice.net }}' ],
		[ 'column' => 'vat_ron', 'value' => '{{ invoice.vat }}' ], [ 'column' => 'total_ron', 'value' => '{{ invoice.total }}' ], [ 'column' => 'pdf', 'value' => '{{ pdf.filename }}' ] ] ] ],
	    [ 'id' => 'fix', 'type' => 'app::task', 'title' => 'Fix the invoice', 'config' => [ 'title' => 'Invoice {{ invoice.number }} is not valid',
		'instructions' => "ANAF's validation of {{ invoice.number }} for {{ invoice.buyer }} ({{ invoice.lines }} lines read):\n\n{{ validation.messages.join('\\n') }}\n\nLines are: description | quantity | unit price in EUR.",
		'users' => [ $maria ], 'roles' => [], 'assignTo' => null, 'submitLabel' => 'Send', 'timeoutUnit' => 'none', 'timeout' => 1, 'variable' => 'fix',
		'form' => [ 'direction' => 'vertical', 'fields' => [
		    [ 'type' => 'select', 'key' => 'action', 'label' => 'Next', 'required' => true, 'choices' => self::choicesOf([ 'again' => 'Sent back to sales to issue it again', 'cancel' => 'Cancelled' ]) ],
		    [ 'type' => 'textarea', 'key' => 'comment', 'label' => 'Comment', 'rows' => 2 ],
		] ] ] ],
	], [
	    [ 'from' => 'form', 'to' => 'client' ], [ 'from' => 'client', 'to' => 'rate' ], [ 'from' => 'rate', 'to' => 'ubl' ], [ 'from' => 'ubl', 'to' => 'check' ], [ 'from' => 'check', 'to' => 'valid' ],
	    [ 'from' => 'valid', 'output' => 'true', 'to' => 'pdf' ], [ 'from' => 'pdf', 'to' => 'record' ], [ 'from' => 'valid', 'output' => 'false', 'to' => 'fix' ],
	]);
    }

    // ------------------------------------------------------------------------------------
    //EU tenders (RegionalEU)

    const TED_FILTERS = [ 'cpv' => '72000000', 'countries' => 'RO', 'kinds' => [ 'competition' ] ];

    private function tenders($project) {
	[ $wfs ] = $this->import($project, [ 'DT-TENDERS' => [ 'tenders', self::tenderColumns(), [] ] ],
	    [ 'EU tender alerts' => [ 'megaphone::warning', self::tenderNodes('DT-TENDERS', (string)$this->u['maria']->_id) ] ]);
	$wf = $wfs['EU tender alerts'];
	$v = $this->publish($wf, [ [ 'IT services tenders in Romania, a go / no-go for each', 12 ] ]);
	//the newest notices TED has now, as the trigger would start them (the first two decided)
	try {
	    $cfg = self::TED_FILTERS;
	    $filters = \plugins\RegionalEU\models\node\NodeAppEuTed::filters(fn($k) => (string)($cfg[$k] ?? ''), fn($k) => $cfg[$k] ?? '', $cfg['kinds']);
	    [ $raw ] = \plugins\RegionalEU\util\Ted::search(\plugins\RegionalEU\util\Ted::query($filters), array_keys(\plugins\RegionalEU\util\Ted::FIELDS), 4, 'ACTIVE', 60);
	} catch (\Throwable $e) {
	    $this->lines[] = 'EU tender alerts: no tenders seeded, TED did not answer (' . $e->getMessage() . ')';
	    return $wfs;
	}
	$decisions = [ [ 'go', 'Fits our ERP integration team; ask for the documents.' ], [ 'no-go', 'Too large for us alone: look for a partner next time.' ] ];
	foreach(array_values($raw) as $i => $n) {
	    $notice = \plugins\RegionalEU\util\Ted::notice($n, 'eng');
	    $exe = \models\wf\WfExecution::startFor($wf->forVersion($v), 'eu-ted-notice', [ 'started_at' => new UTCDateTime(), 'vars' => [ 'notice' => $notice ] ], 'ted');
	    $exe->log('ted', "TED notice {$notice['publicationNumber']}: " . mb_substr((string)$notice['title'], 0, 200));
	    $exe->save();
	    $exe->run();
	    $exe = \V::app()->om->findById('WfExecution', (string)$exe->_id);
	    if (isset($decisions[$i]))
		$exe = $this->answer($exe, 'decide', [ 'decision' => $decisions[$i][0], 'comment' => $decisions[$i][1] ], 'maria');
	    $this->done($exe, [ 20, 12, 3, 1 ][$i] ?? 1, "EU tender alerts: {$notice['publicationNumber']}");
	}
	if (!$raw)
	    $this->lines[] = 'EU tender alerts: TED has no notice for these filters now: no tenders seeded';
	return $wfs;
    }

    private static function tenderColumns() {
	return [
	    [ 'key' => 'number', 'label' => 'Notice', 'type' => 'text', 'required' => true ],
	    [ 'key' => 'title', 'label' => 'Title', 'type' => 'textarea' ],
	    [ 'key' => 'buyer', 'label' => 'Buyer', 'type' => 'text' ],
	    [ 'key' => 'published', 'label' => 'Published', 'type' => 'text' ],
	    [ 'key' => 'deadline', 'label' => 'Deadline', 'type' => 'text' ],
	    [ 'key' => 'value', 'label' => 'Estimated value', 'type' => 'number' ],
	    [ 'key' => 'currency', 'label' => 'Currency', 'type' => 'text' ],
	    [ 'key' => 'url', 'label' => 'TED page', 'type' => 'text' ],
	    [ 'key' => 'decision', 'label' => 'Decision', 'type' => 'select', 'choices' => "pending\ngo\nno-go\nno answer", 'required' => true ],
	];
    }

    //TED notice (CPV 72000000, RO, calls for competition) -> tenders -> Go / no-go (Maria) -> tenders updated
    private static function tenderNodes($table, $maria) {
	$cfg = self::TED_FILTERS;
	return self::flow([
	    [ 'id' => 'ted', 'type' => 'trigger::eu-ted-notice', 'title' => 'IT tenders in Romania', 'config' => [ 'keywords' => '', 'countries' => $cfg['countries'], 'cpv' => $cfg['cpv'],
		'kinds' => $cfg['kinds'], 'query' => '', 'language' => 'eng', 'runs' => 'each', 'variable' => 'notice', 'mode' => 'interval', 'every' => 1, 'every_unit' => 'hours' ] ],
	    [ 'id' => 'record', 'type' => 'app::table', 'title' => 'Add to tenders', 'config' => [ 'table' => $table, 'operation' => 'insert', 'variable' => 'tender', 'onError' => 'fail', 'data' => [
		[ 'column' => 'number', 'value' => '{{ notice.publicationNumber }}' ], [ 'column' => 'title', 'value' => '{{ notice.title }}' ], [ 'column' => 'buyer', 'value' => '{{ notice.buyer }}' ],
		[ 'column' => 'published', 'value' => '{{ notice.publicationDate }}' ], [ 'column' => 'deadline', 'value' => '{{ notice.deadline }}' ],
		[ 'column' => 'value', 'value' => '{{ notice.estimatedValue }}' ], [ 'column' => 'currency', 'value' => '{{ notice.currency }}' ], [ 'column' => 'url', 'value' => '{{ notice.url }}' ],
		[ 'column' => 'decision', 'value' => 'pending' ] ] ] ],
	    [ 'id' => 'decide', 'type' => 'app::task', 'title' => 'Go / no-go', 'config' => [ 'title' => 'Go / no-go: {{ notice.buyer }}',
		'instructions' => "{{ notice.title }}\n\nDeadline {{ notice.deadline }}, estimated {{ notice.estimatedValue }} {{ notice.currency }}.\n{{ notice.url }}",
		'users' => [ $maria ], 'roles' => [], 'assignTo' => null, 'submitLabel' => 'Send decision', 'timeoutUnit' => 'days', 'timeout' => 7, 'variable' => 'task',
		'form' => [ 'direction' => 'vertical', 'fields' => [
		    [ 'type' => 'select', 'key' => 'decision', 'label' => 'Decision', 'required' => true, 'choices' => self::choicesOf([ 'go' => 'Go: we bid', 'no-go' => 'No-go' ]) ],
		    [ 'type' => 'textarea', 'key' => 'comment', 'label' => 'Why', 'rows' => 2 ],
		] ] ] ],
	    [ 'id' => 'save', 'type' => 'app::table', 'title' => 'Record the decision', 'config' => [ 'table' => $table, 'operation' => 'update', 'rowId' => '{{ tender.row.id }}', 'variable' => 'tender',
		'onError' => 'fail', 'data' => [ [ 'column' => 'decision', 'value' => '{{ task.decision }}' ] ] ] ],
	    [ 'id' => 'late', 'type' => 'app::table', 'title' => 'No answer in 7 days', 'config' => [ 'table' => $table, 'operation' => 'update', 'rowId' => '{{ tender.row.id }}', 'variable' => 'tender',
		'onError' => 'fail', 'data' => [ [ 'column' => 'decision', 'value' => 'no answer' ] ] ] ],
	], [ [ 'from' => 'ted', 'to' => 'record' ], [ 'from' => 'record', 'to' => 'decide' ], [ 'from' => 'decide', 'output' => 'done', 'to' => 'save' ],
	    [ 'from' => 'decide', 'output' => 'timeout', 'to' => 'late' ] ]);
    }

    // ------------------------------------------------------------------------------------
    //the build's helpers

    private static function pluginOn($id) {
	return in_array($id, (array)\V::app()->config('modules'), true) && class_exists("plugins\\{$id}\\Plugin");
    }

    //a project with $members (user key => role iname)
    private function project($name, array $members, array $roles) {
	$list = [];
	foreach($members as $k => $role)
	    $list[(string)$this->u[$k]->_id] = [ 'role' => $roles[$role]->_id ];
	$project = \V::app()->om->create('Project', [ 'aid' => $this->account->_id, 'name' => $name, 'owner' => $this->u['maria']->_id, 'members' => $list ]);
	if (!$project->validates() || !$project->save())
	    throw new \RuntimeException("project {$name}: " . json_encode($project->errors()));
	return $project;
    }

    //the data tables (bundle id => [ name, columns, rows ]) and workflows (name => [ icon, nodes ], their table settings the bundle ids) imported into $project (util\Transfer):
    //[ workflows (name => Workflow), tables (name => DataTableDef) ]
    private function import($project, array $tables, array $flows) {
	\V::app()->user->crtProject = (string)$project->_id;
	$bundle = [ 'format' => \util\Transfer::FORMAT, 'version' => \util\Transfer::VERSION, 'exported_at' => date(DATE_ATOM), 'source' => [ 'url' => $this->base, 'project' => 'demo' ],
	    'secrets' => false, 'credentials' => [], 'refs' => [ 'credentials' => [], 'datatables' => [], 'workflows' => [] ], 'datatables' => [], 'workflows' => [] ];
	foreach($tables as $id => [ $name, $columns, $rows ])
	    $bundle['datatables'][] = [ 'id' => $id, 'iname' => 'data-table-' . strtolower($id), 'name' => $name, 'columns' => $columns, 'rows' => $rows ];
	$i = 0;
	foreach($flows as $name => [ $icon, $nodes ])
	    $bundle['workflows'][] = [ 'id' => 'WF-' . (++$i), 'name' => $name, 'icon' => $icon, 'nodes' => $nodes ];
	$r = \util\Transfer::apply($bundle, $project, []);
	foreach($r['warnings'] as $w)
	    $this->lines[] = "warning ({$project->name}): {$w}";
	$wfs = [];
	foreach(array_keys($flows) as $name) {
	    $wfs[$name] = \V::app()->om->findOne('Workflow', [ 'aid' => $this->account->_id, 'project' => $project->_id, 'name' => $name ]);
	    if (!$wfs[$name])
		throw new \RuntimeException("{$project->name}: the workflow {$name} was not imported: " . json_encode($r));
	}
	$dts = [];
	foreach($tables as [ $name ]) {
	    $dts[$name] = \V::app()->om->findOne('DataTableDef', [ 'aid' => $this->account->_id, 'project' => $project->_id, 'name' => $name ]);
	    if (!$dts[$name])
		throw new \RuntimeException("{$project->name}: the data table {$name} was not imported: " . json_encode($r));
	    $this->tables[(string)$dts[$name]->iname] = $dts[$name];
	}
	return [ $wfs, $dts ];
    }

    //the published versions of $wf ([ note, days ago ], the last one published), by Maria (as WorkflowController::publish): the last version
    private function publish($wf, array $versions) {
	$db = \V::app()->mongo->selectDatabase(MONGO_DB);
	$version = null;
	foreach($versions as [ $note, $days ]) {
	    $version = \V::app()->om->create('WfVersion', [ 'aid' => $wf->aid, 'project' => $wf->project, 'wf' => $wf->_id, 'number' => $wf->nextVersionNumber(), 'name' => (string)$wf->name,
		'nodes' => $wf->nodes, 'note' => $note, 'from_number' => null, 'published_by' => $this->u['maria']->_id ]);
	    if (!$version->validates() || !$version->save())
		throw new \RuntimeException("{$wf->name}: version: " . json_encode($version->errors()));
	    $db->selectCollection(\models\wf\WfVersion::_collection())->updateOne([ '_id' => $version->_id ], [ '$set' => [ 'created_at' => self::daysAgo($days, 16, 20) ] ]);
	    $wf = \V::app()->om->findById('Workflow', (string)$wf->_id);
	}
	$before = (string)$wf->status;
	$wf->version = $version->_id;
	$wf->status = \models\Workflow::PUBLISHED;
	if (!$wf->save())
	    throw new \RuntimeException("{$wf->name}: publish: " . json_encode($wf->errors()));
	$this->audit('maria', 'update', $wf, null, [ 'status' => $before, 'version' => null ]);
	return $version;
    }

    //a run as FormController starts it: sent by user $who with $answers, run here
    private function formRun($wf, $version, $who, array $answers) {
	$token = '';
	foreach((array)$wf->nodes as $n) {
	    if (($n['fqname'] ?? '') === 'trigger::form') {
		$token = (string)($n['config']['token'] ?? '');
		$node = (string)$n['wfid'];
	    }
	}
	$person = [ 'id' => (string)$this->u[$who]->_id, 'name' => $this->names[$who], 'email' => (string)$this->u[$who]->email ];
	$exe = \models\wf\WfExecution::startFor($wf->forVersion($version), 'form', [ 'form' => $token, 'user' => $person['id'], 'received_at' => new UTCDateTime(),
	    'vars' => [ 'form' => $answers, 'form_user' => $person ] ], $node ?? 'form');
	$exe->log($node ?? 'form', 'form sent by ' . $person['name']);
	$exe->save();
	$exe->run();
	return \V::app()->om->findById('WfExecution', (string)$exe->_id);
    }

    //a scheduled run at $at (as NodeTriggerSchedule::runScheduled), run now
    private function scheduleRun($wf, $version, \DateTimeImmutable $at) {
	$node = null;
	foreach((array)$wf->nodes as $n) {
	    if (($n['fqname'] ?? '') === 'trigger::schedule')
		$node = (string)$n['wfid'];
	}
	$when = $at->format('Y-m-d\TH:i:00P');
	$exe = \models\wf\WfExecution::startFor($wf->forVersion($version), 'schedule', [ 'scheduled_at' => $when, 'started_at' => new UTCDateTime(), 'vars' => [ 'schedule_time' => $when ] ], $node);
	$exe->log($node, 'scheduled run ' . $when);
	$exe->save();
	$exe->run();
	return \V::app()->om->findById('WfExecution', (string)$exe->_id);
    }

    //a run of a webhook (as HookController starts it) with JSON $body, from the Wazuh manager's address
    private function webhookRun($wf, $version, array $body) {
	$node = null;
	$token = '';
	foreach((array)$wf->nodes as $n) {
	    if (($n['fqname'] ?? '') === 'trigger::webhook') {
		$node = (string)$n['wfid'];
		$token = (string)($n['config']['token'] ?? '');
	    }
	}
	$raw = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	$received = new UTCDateTime();
	$vars = [ 'webhook' => [ 'method' => 'POST', 'path' => '/hook/' . $token, 'url' => "{$this->base}/hook/{$token}", 'query' => [],
	    'headers' => [ 'content-type' => 'application/json', 'user-agent' => 'Wazuh-Integratord/4.14' ], 'content_type' => 'application/json', 'body' => $body, 'raw' => $raw,
	    'ip' => '192.0.2.5', 'received_at' => $received->toDateTime()->format(DATE_ATOM) ] ];
	$exe = \models\wf\WfExecution::startFor($wf->forVersion($version), 'webhook', [ 'method' => 'POST', 'contentType' => 'application/json', 'query' => '[]', 'body' => $raw,
	    'received_at' => $received, 'vars' => $vars ], $node);
	$exe->log($node, 'webhook POST received');
	$exe->save();
	$exe->run();
	return \V::app()->om->findById('WfExecution', (string)$exe->_id);
    }

    //the open task of step $node of $exe completed by user $who with $answers (as TaskController), the run then run again: the execution
    private function answer($exe, $node, array $answers, $who) {
	$om = \V::app()->om;
	$task = $om->findOne('WfTask', [ 'execution' => $exe->_id, 'node' => $node, 'state' => 'open' ]);
	if (!$task)
	    return $exe;
	$om->updateMany('WfTask', [ '_id' => $task->_id ], [ '$set' => [ 'state' => 'done' ] ]);
	$task = $om->findById('WfTask', (string)$task->_id);
	$task->answers = $answers;
	$task->completed_by = $this->u[$who]->_id;
	$task->completed_at = new UTCDateTime();
	$task->save();
	$this->audit($who, 'update', $task, null, [ 'state' => 'open', 'answers' => null, 'completed_by' => null, 'completed_at' => null ]);
	$exe = $om->findById('WfExecution', (string)$exe->_id);
	$exe->log(null, 'task completed by ' . $this->names[$who]);
	$exe->run();
	return $om->findById('WfExecution', (string)$exe->_id);
    }

    //a run finished: moved $hours back, its line kept
    private function done($exe, $hours, $label) {
	$exe = \V::app()->om->findById('WfExecution', (string)$exe->_id);
	$this->backdate($exe, (int)($hours * 3600));
	$this->lines[] = "run: {$label}: {$exe->state}" . ($exe->error ? " ({$exe->error})" : '');
	if (!$this->quiet)
	    echo "run: {$label}: {$exe->state}" . ($exe->error ? " ({$exe->error})" : '') . "\n";
    }

    //an action of user $who in the audit log (as the web records it: the user, an address of theirs, a browser), dated now: [ key => value before ] for an update
    private function audit($who, $action, $o, $details = null, array $before = []) {
	\models\AuditLog::recordAs($this->u[$who], $action, $o, $before, $details, self::IPS[$who], self::USER_AGENT);
    }

    private static function node($id, $fq, $title, $x, $y, array $config) {
	return [ 'wfid' => $id, 'isNode' => true, 'parentId' => null, 'fqname' => $fq, 'title' => $title, 'geometry' => [ 'x' => $x, 'y' => $y, 'w' => 120, 'h' => 120 ], 'config' => $config ];
    }

    private static function edge($id, $from, $to, $port = 0) {
	return [ 'wfid' => $id, 'isEdge' => true, 'fromNode' => $from, 'fromPort' => $port, 'toNode' => $to, 'toPort' => 0, 'branch' => null ];
    }

    //the designer's data of a workflow from a spec (util\WorkflowSpec: outputs by their labels, laid out, the steps' server-set values made)
    private static function flow(array $steps, array $connections) {
	return \util\WorkflowSpec::nodesFrom($steps, $connections, [], true);
    }

    //an email of the Send Email step: one HTML block
    private static function emailOf($html) {
	return [ 'preheader' => '', 'blocks' => [ [ 'type' => 'html', 'html' => $html ] ] ];
    }

    //the choices of a select field from $list (value => label)
    private static function choicesOf(array $list) {
	return array_map(fn($v, $l) => [ 'value' => $v, 'label' => $l ], array_keys($list), array_values($list));
    }

    //$days ago at $hour:$minute (server time) as a date
    private static function daysAgo($days, $hour, $minute) {
	return new UTCDateTime((new \DateTime("-{$days} days"))->setTime($hour, $minute)->getTimestamp() * 1000);
    }

    //a run made now, moved $seconds back: its dates, its tasks', the rows and files made since it started (runs are made one after another: they are its own)
    private function backdate($exe, $seconds) {
	$db = \V::app()->mongo->selectDatabase(MONGO_DB);
	$map = [ 'typeMap' => [ 'root' => 'array', 'document' => 'array', 'array' => 'array' ] ];
	$since = $exe->created_at;
	foreach([ \models\wf\WfExecution::_collection() => [ '_id' => $exe->_id ], \models\wf\WfTask::_collection() => [ 'execution' => $exe->_id ] ] as $c => $q) {
	    $coll = $db->selectCollection($c);
	    foreach($coll->find($q, $map) as $doc)
		$coll->replaceOne([ '_id' => $doc['_id'] ], self::shiftDates($doc, $seconds));
	}
	if (!$since instanceof UTCDateTime)
	    return;
	foreach($this->tables as $t) {
	    $rows = $db->selectCollection(\V::app()->om->registry[(string)$t->iname]['collection'] ?? (string)$t->iname);
	    foreach($rows->find([ 'aid' => $exe->aid, 'created_at' => [ '$gte' => $since ] ], $map) as $row)
		$rows->replaceOne([ '_id' => $row['_id'] ], self::shiftDates($row, $seconds));
	}
	$files = $db->selectCollection(\models\File::_bucket() . '.files');
	foreach($files->find([ 'metadata.aid' => $exe->aid, 'metadata.created_at' => [ '$gte' => $since ] ], $map) as $doc)
	    $files->replaceOne([ '_id' => $doc['_id'] ], self::shiftDates($doc, $seconds));
    }

    //$v with its dates (also inside arrays) $seconds earlier
    private static function shiftDates($v, $seconds) {
	if ($v instanceof UTCDateTime)
	    return new UTCDateTime((int)(string)$v - $seconds * 1000);
	if (is_array($v)) {
	    foreach($v as $k => $x)
		$v[$k] = self::shiftDates($x, $seconds);
	}
	return $v;
    }

    //the knowledge base "Expense policy", indexed here (KnowledgeBase::queue without the daemon's job, then its job)
    private function knowledgeBase($account, $project, $settings) {
	$kb = \V::app()->om->create('KnowledgeBase', [ 'aid' => $account->_id, 'project' => $project->_id, 'name' => 'Expense policy',
	    'description' => 'The travel and expense rules every request is checked against', 'model' => trim((string)$settings->model), 'chunkSize' => 600, 'overlap' => 100 ]);
	if (!$kb->validates() || !$kb->save())
	    return 'knowledge base not made: ' . json_encode($kb->errors());
	foreach(self::POLICY as $title => $text) {
	    $s = \models\kb\KnowledgeBase::textSource($title, $title . "\n\n" . $text, '');
	    $now = new UTCDateTime();
	    //save(), as KnowledgeBase::addDocument does; save() sets created_at
	    $doc = \V::app()->om->create('KbDocument', [ 'aid' => $kb->aid, 'kb' => $kb->_id, 'key' => $s['key'], 'title' => $s['title'], 'source' => 'text', 'file' => null, 'url' => null,
		'meta' => (object)[], 'hash' => '', 'chunks' => 0, 'chars' => 0, 'status' => 'pending', 'error' => null, 'updated_at' => $now ]);
	    if (!$doc->save())
		return 'knowledge base document not made: ' . $s['title'];
	    \models\kb\KnowledgeBase::indexJob([ 'aid' => (string)$kb->aid, 'project' => (string)$kb->project, 'kb' => (string)$kb->_id, 'key' => $s['key'],
		'src' => [ 'type' => 'text', 'title' => $s['title'], 'text' => $s['text'], 'key' => $s['key'] ] ]);
	}
	$kb = \V::app()->om->findById('KnowledgeBase', (string)$kb->_id);
	$errors = \V::app()->om->count('KbDocument', [ 'kb' => $kb->_id, 'aid' => $kb->aid, 'status' => 'error' ]);
	return "knowledge base Expense policy: {$kb->documents} documents, {$kb->chunks} chunks" . ($errors ? ", {$errors} not indexed" : '');
    }

    private function askPassword() {
	fwrite(STDERR, 'Password for the demo users: ');
	$tty = function_exists('posix_isatty') && posix_isatty(STDIN);
	if ($tty)
	    system('stty -echo');
	$password = rtrim((string)fgets(STDIN), "\r\n");
	if ($tty)
	    system('stty echo');
	fwrite(STDERR, "\n");
	return $password;
    }

    private function fail($message) {
	fwrite(STDERR, "demo: {$message}\n");
	exit(1);
    }
}
