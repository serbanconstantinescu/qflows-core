<?php

/*
    ITOps: the steps and credentials of IT infrastructure products, for the installations that automate IT operations. Shipped with the core (app/plugins/ITOps, in the core's
    repository) and on by default (config.php: DEFAULT_PLUGINS); plugins.json `"ITOps": { "enabled": false }` turns it off. Its steps and credentials are registered here
    (\V::app()->om->registerObjects: `wfNode` for a step, `variant-of` Credential for a credential), as the core's are in data\SysObjects; they are in the designer's palette of
    every account of the installation (no tier feature). One family per product, each a credential, a base step (models/node/NodeApp<Product>, not registered), one step per
    area and a client in util/:
	Security          Bitdefender GravityZone (util\GravityZone; event trigger: GravityZone's push service to /gravityzone/<token>), FortiGate (util\FortiGate; event
	                  trigger: an automation stitch's webhook to /fortigate/<token>), Sophos Firewall (util\Sophos, its XML API; no trigger), Wazuh (util\Wazuh; alert trigger)
	Backup            Veeam Backup & Replication (util\Veeam; event trigger)
	Monitoring        Checkmk (util\Checkmk; problem trigger), Zabbix (util\Zabbix; problem trigger)
	Virtualization    VMware vCenter (util\VSphere, VSphereError; alarm trigger), Proxmox VE (util\Proxmox; task trigger), Hyper-V (util\WinRM: PowerShell over WinRM; VM
	                  state trigger)
	IT management     GLPI (util\Glpi; ticket trigger), Microsoft Intune (util\Intune: Graph, client credentials; device trigger)
	Domains & DNS     Namecheap (util\Namecheap)
      The polling triggers keep their state in the core's models\TriggerState; the clients use the core's util\Http (private addresses: the account's allow_private_addresses)
      and util\Ip.
    the endpoints of the push triggers (controllers/): /gravityzone/<token> -> GravityzoneController::receive, /fortigate/<token> -> FortigateController::receive; callers are
	not users: no session; their refusals in the execution log (gravityzone.refused, fortigate.refused)
    the steps as the tools of AI steps (the core's util\AgentTools, hook agent:families): one family per product (prefix of the tools' names: gz, fgt, sfos, wazuh, namecheap,
	intune, glpi, veeam, vmware, proxmox, hyperv, cmk, zbx); the core's agent suites (agenttools, agentapproval, agentguard, agenttrace) use the GravityZone and FortiGate ones
    its checks in the core's check suite (hook check:suites, outside the web): checks.php, one suite per product against its fake server in tests/
*/

namespace plugins\ITOps;

class Plugin {
    public function __construct() {
	\V::app()->om->registerObjects([
	    //a Checkmk site (the Checkmk steps and problem trigger)
	    'CredentialCheckmk' => [
		'class' => __NAMESPACE__ . '\\models\\credential\\CredentialCheckmk',
		'variant-of' => 'Credential',
	    ],
	    //a Zabbix server (the Zabbix steps and problem trigger)
	    'CredentialZabbix' => [
		'class' => __NAMESPACE__ . '\\models\\credential\\CredentialZabbix',
		'variant-of' => 'Credential',
	    ],
	    //a Wazuh installation (the Wazuh steps and alert trigger)
	    'CredentialWazuh' => [
		'class' => __NAMESPACE__ . '\\models\\credential\\CredentialWazuh',
		'variant-of' => 'Credential',
	    ],
	    //a Namecheap account's API access (the Namecheap steps)
	    'CredentialNamecheap' => [
		'class' => __NAMESPACE__ . '\\models\\credential\\CredentialNamecheap',
		'variant-of' => 'Credential',
	    ],
	    //a Microsoft Intune tenant's app (the Intune steps and device trigger)
	    'CredentialIntune' => [
		'class' => __NAMESPACE__ . '\\models\\credential\\CredentialIntune',
		'variant-of' => 'Credential',
	    ],
	    //a GLPI server (the GLPI steps and ticket trigger)
	    'CredentialGlpi' => [
		'class' => __NAMESPACE__ . '\\models\\credential\\CredentialGlpi',
		'variant-of' => 'Credential',
	    ],
	    //a FortiGate firewall (the FortiGate steps)
	    'CredentialFortiGate' => [
		'class' => __NAMESPACE__ . '\\models\\credential\\CredentialFortiGate',
		'variant-of' => 'Credential',
	    ],
	    //a Sophos Firewall (the Sophos Firewall steps: its XML API)
	    'CredentialSophos' => [
		'class' => __NAMESPACE__ . '\\models\\credential\\CredentialSophos',
		'variant-of' => 'Credential',
	    ],
	    //a Hyper-V host (the Hyper-V steps and VM state trigger; PowerShell over WinRM)
	    'CredentialHyperV' => [
		'class' => __NAMESPACE__ . '\\models\\credential\\CredentialHyperV',
		'variant-of' => 'Credential',
	    ],
	    //a Proxmox VE cluster or server (the Proxmox steps and task trigger)
	    'CredentialProxmox' => [
		'class' => __NAMESPACE__ . '\\models\\credential\\CredentialProxmox',
		'variant-of' => 'Credential',
	    ],
	    //a VMware vCenter Server (the VMware steps and alarm trigger)
	    'CredentialVmware' => [
		'class' => __NAMESPACE__ . '\\models\\credential\\CredentialVmware',
		'variant-of' => 'Credential',
	    ],
	    //a Veeam Backup & Replication server (the Veeam steps and event trigger)
	    'CredentialVeeam' => [
		'class' => __NAMESPACE__ . '\\models\\credential\\CredentialVeeam',
		'variant-of' => 'Credential',
	    ],
	    //a Bitdefender GravityZone console (the GravityZone steps and event trigger)
	    'CredentialGravityZone' => [
		'class' => __NAMESPACE__ . '\\models\\credential\\CredentialGravityZone',
		'variant-of' => 'Credential',
	    ],
	    //Checkmk (one step per area, NodeAppCheckmk their base; the problem trigger, polling)
	    'NodeAppCmkStatus' => [
		'class' => __NAMESPACE__ . '\\models\\node\\NodeAppCmkStatus',
		'wfNode' => true,
	    ],
	    'NodeAppCmkAck' => [
		'class' => __NAMESPACE__ . '\\models\\node\\NodeAppCmkAck',
		'wfNode' => true,
	    ],
	    'NodeAppCmkHosts' => [
		'class' => __NAMESPACE__ . '\\models\\node\\NodeAppCmkHosts',
		'wfNode' => true,
	    ],
	    'NodeAppCmkApi' => [
		'class' => __NAMESPACE__ . '\\models\\node\\NodeAppCmkApi',
		'wfNode' => true,
	    ],
	    'NodeTriggerCheckmk' => [
		'class' => __NAMESPACE__ . '\\models\\node\\NodeTriggerCheckmk',
		'wfNode' => true,
	    ],
	    //Zabbix (one step per area, NodeAppZabbix their base; the problem trigger, polling)
	    'NodeAppZbxProblems' => [
		'class' => __NAMESPACE__ . '\\models\\node\\NodeAppZbxProblems',
		'wfNode' => true,
	    ],
	    'NodeAppZbxAck' => [
		'class' => __NAMESPACE__ . '\\models\\node\\NodeAppZbxAck',
		'wfNode' => true,
	    ],
	    'NodeAppZbxHosts' => [
		'class' => __NAMESPACE__ . '\\models\\node\\NodeAppZbxHosts',
		'wfNode' => true,
	    ],
	    'NodeAppZbxApi' => [
		'class' => __NAMESPACE__ . '\\models\\node\\NodeAppZbxApi',
		'wfNode' => true,
	    ],
	    'NodeTriggerZabbix' => [
		'class' => __NAMESPACE__ . '\\models\\node\\NodeTriggerZabbix',
		'wfNode' => true,
	    ],
	    //Wazuh (one step per area, NodeAppWazuh their base; the alert trigger, polling the indexer)
	    'NodeAppWzAlerts' => [
		'class' => __NAMESPACE__ . '\\models\\node\\NodeAppWzAlerts',
		'wfNode' => true,
	    ],
	    'NodeAppWzVulnerabilities' => [
		'class' => __NAMESPACE__ . '\\models\\node\\NodeAppWzVulnerabilities',
		'wfNode' => true,
	    ],
	    'NodeAppWzAgents' => [
		'class' => __NAMESPACE__ . '\\models\\node\\NodeAppWzAgents',
		'wfNode' => true,
	    ],
	    'NodeAppWzResponse' => [
		'class' => __NAMESPACE__ . '\\models\\node\\NodeAppWzResponse',
		'wfNode' => true,
	    ],
	    'NodeAppWzScans' => [
		'class' => __NAMESPACE__ . '\\models\\node\\NodeAppWzScans',
		'wfNode' => true,
	    ],
	    'NodeAppWzInventory' => [
		'class' => __NAMESPACE__ . '\\models\\node\\NodeAppWzInventory',
		'wfNode' => true,
	    ],
	    'NodeAppWzManager' => [
		'class' => __NAMESPACE__ . '\\models\\node\\NodeAppWzManager',
		'wfNode' => true,
	    ],
	    'NodeAppWzApi' => [
		'class' => __NAMESPACE__ . '\\models\\node\\NodeAppWzApi',
		'wfNode' => true,
	    ],
	    'NodeTriggerWazuh' => [
		'class' => __NAMESPACE__ . '\\models\\node\\NodeTriggerWazuh',
		'wfNode' => true,
	    ],
	    //Namecheap (one step per area, NodeAppNamecheap their base)
	    'NodeAppNcDomains' => [
		'class' => __NAMESPACE__ . '\\models\\node\\NodeAppNcDomains',
		'wfNode' => true,
	    ],
	    'NodeAppNcDns' => [
		'class' => __NAMESPACE__ . '\\models\\node\\NodeAppNcDns',
		'wfNode' => true,
	    ],
	    'NodeAppNcAccount' => [
		'class' => __NAMESPACE__ . '\\models\\node\\NodeAppNcAccount',
		'wfNode' => true,
	    ],
	    'NodeAppNcApi' => [
		'class' => __NAMESPACE__ . '\\models\\node\\NodeAppNcApi',
		'wfNode' => true,
	    ],
	    //Microsoft Intune (one step per area, NodeAppIntune their base; the device trigger: devices read on a schedule)
	    'NodeAppIntuneDevices' => [
		'class' => __NAMESPACE__ . '\\models\\node\\NodeAppIntuneDevices',
		'wfNode' => true,
	    ],
	    'NodeAppIntunePolicies' => [
		'class' => __NAMESPACE__ . '\\models\\node\\NodeAppIntunePolicies',
		'wfNode' => true,
	    ],
	    'NodeAppIntuneApps' => [
		'class' => __NAMESPACE__ . '\\models\\node\\NodeAppIntuneApps',
		'wfNode' => true,
	    ],
	    'NodeAppIntuneAutopilot' => [
		'class' => __NAMESPACE__ . '\\models\\node\\NodeAppIntuneAutopilot',
		'wfNode' => true,
	    ],
	    'NodeAppIntuneApi' => [
		'class' => __NAMESPACE__ . '\\models\\node\\NodeAppIntuneApi',
		'wfNode' => true,
	    ],
	    'NodeTriggerIntune' => [
		'class' => __NAMESPACE__ . '\\models\\node\\NodeTriggerIntune',
		'wfNode' => true,
	    ],
	    //GLPI (one step per area, NodeAppGlpi their base; the ticket trigger: a search on a schedule)
	    'NodeAppGlpiTickets' => [
		'class' => __NAMESPACE__ . '\\models\\node\\NodeAppGlpiTickets',
		'wfNode' => true,
	    ],
	    'NodeAppGlpiAssets' => [
		'class' => __NAMESPACE__ . '\\models\\node\\NodeAppGlpiAssets',
		'wfNode' => true,
	    ],
	    'NodeAppGlpiSetup' => [
		'class' => __NAMESPACE__ . '\\models\\node\\NodeAppGlpiSetup',
		'wfNode' => true,
	    ],
	    'NodeAppGlpiApi' => [
		'class' => __NAMESPACE__ . '\\models\\node\\NodeAppGlpiApi',
		'wfNode' => true,
	    ],
	    'NodeTriggerGlpi' => [
		'class' => __NAMESPACE__ . '\\models\\node\\NodeTriggerGlpi',
		'wfNode' => true,
	    ],
	    //FortiGate (one step per area, NodeAppFortiGate their base; the event trigger: an automation stitch's webhook)
	    'NodeAppFgtAddresses' => [
		'class' => __NAMESPACE__ . '\\models\\node\\NodeAppFgtAddresses',
		'wfNode' => true,
	    ],
	    'NodeAppFgtBanned' => [
		'class' => __NAMESPACE__ . '\\models\\node\\NodeAppFgtBanned',
		'wfNode' => true,
	    ],
	    'NodeAppFgtPolicies' => [
		'class' => __NAMESPACE__ . '\\models\\node\\NodeAppFgtPolicies',
		'wfNode' => true,
	    ],
	    'NodeAppFgtUsers' => [
		'class' => __NAMESPACE__ . '\\models\\node\\NodeAppFgtUsers',
		'wfNode' => true,
	    ],
	    'NodeAppFgtSystem' => [
		'class' => __NAMESPACE__ . '\\models\\node\\NodeAppFgtSystem',
		'wfNode' => true,
	    ],
	    'NodeAppFgtApi' => [
		'class' => __NAMESPACE__ . '\\models\\node\\NodeAppFgtApi',
		'wfNode' => true,
	    ],
	    'NodeTriggerFortiGate' => [
		'class' => __NAMESPACE__ . '\\models\\node\\NodeTriggerFortiGate',
		'wfNode' => true,
	    ],
	    //Sophos Firewall (one step per area, NodeAppSophos their base; no trigger: SFOS sends no webhooks)
	    'NodeAppSfosHosts' => [
		'class' => __NAMESPACE__ . '\\models\\node\\NodeAppSfosHosts',
		'wfNode' => true,
	    ],
	    'NodeAppSfosRules' => [
		'class' => __NAMESPACE__ . '\\models\\node\\NodeAppSfosRules',
		'wfNode' => true,
	    ],
	    'NodeAppSfosUsers' => [
		'class' => __NAMESPACE__ . '\\models\\node\\NodeAppSfosUsers',
		'wfNode' => true,
	    ],
	    'NodeAppSfosWeb' => [
		'class' => __NAMESPACE__ . '\\models\\node\\NodeAppSfosWeb',
		'wfNode' => true,
	    ],
	    'NodeAppSfosSystem' => [
		'class' => __NAMESPACE__ . '\\models\\node\\NodeAppSfosSystem',
		'wfNode' => true,
	    ],
	    'NodeAppSfosApi' => [
		'class' => __NAMESPACE__ . '\\models\\node\\NodeAppSfosApi',
		'wfNode' => true,
	    ],
	    //Hyper-V (one step per area, NodeAppHyperV their base; the VM state trigger, polling)
	    'NodeAppHyperVVms' => [
		'class' => __NAMESPACE__ . '\\models\\node\\NodeAppHyperVVms',
		'wfNode' => true,
	    ],
	    'NodeAppHyperVCheckpoints' => [
		'class' => __NAMESPACE__ . '\\models\\node\\NodeAppHyperVCheckpoints',
		'wfNode' => true,
	    ],
	    'NodeAppHyperVHost' => [
		'class' => __NAMESPACE__ . '\\models\\node\\NodeAppHyperVHost',
		'wfNode' => true,
	    ],
	    'NodeAppHyperVPowerShell' => [
		'class' => __NAMESPACE__ . '\\models\\node\\NodeAppHyperVPowerShell',
		'wfNode' => true,
	    ],
	    'NodeTriggerHyperV' => [
		'class' => __NAMESPACE__ . '\\models\\node\\NodeTriggerHyperV',
		'wfNode' => true,
	    ],
	    //Proxmox VE (one step per area, NodeAppProxmox their base; the task trigger, polling)
	    'NodeAppProxmoxGuests' => [
		'class' => __NAMESPACE__ . '\\models\\node\\NodeAppProxmoxGuests',
		'wfNode' => true,
	    ],
	    'NodeAppProxmoxSnapshots' => [
		'class' => __NAMESPACE__ . '\\models\\node\\NodeAppProxmoxSnapshots',
		'wfNode' => true,
	    ],
	    'NodeAppProxmoxBackups' => [
		'class' => __NAMESPACE__ . '\\models\\node\\NodeAppProxmoxBackups',
		'wfNode' => true,
	    ],
	    'NodeAppProxmoxCluster' => [
		'class' => __NAMESPACE__ . '\\models\\node\\NodeAppProxmoxCluster',
		'wfNode' => true,
	    ],
	    'NodeAppProxmoxApi' => [
		'class' => __NAMESPACE__ . '\\models\\node\\NodeAppProxmoxApi',
		'wfNode' => true,
	    ],
	    'NodeTriggerProxmox' => [
		'class' => __NAMESPACE__ . '\\models\\node\\NodeTriggerProxmox',
		'wfNode' => true,
	    ],
	    //VMware vCenter (one step per area, NodeAppVmware their base; the alarm trigger, polling)
	    'NodeAppVmwareVms' => [
		'class' => __NAMESPACE__ . '\\models\\node\\NodeAppVmwareVms',
		'wfNode' => true,
	    ],
	    'NodeAppVmwareSnapshots' => [
		'class' => __NAMESPACE__ . '\\models\\node\\NodeAppVmwareSnapshots',
		'wfNode' => true,
	    ],
	    'NodeAppVmwareInventory' => [
		'class' => __NAMESPACE__ . '\\models\\node\\NodeAppVmwareInventory',
		'wfNode' => true,
	    ],
	    'NodeAppVmwareAlarms' => [
		'class' => __NAMESPACE__ . '\\models\\node\\NodeAppVmwareAlarms',
		'wfNode' => true,
	    ],
	    'NodeAppVmwareApi' => [
		'class' => __NAMESPACE__ . '\\models\\node\\NodeAppVmwareApi',
		'wfNode' => true,
	    ],
	    'NodeTriggerVmware' => [
		'class' => __NAMESPACE__ . '\\models\\node\\NodeTriggerVmware',
		'wfNode' => true,
	    ],
	    //Veeam Backup & Replication (one step per area, NodeAppVeeam their base; the event trigger, polling)
	    'NodeAppVeeamJobs' => [
		'class' => __NAMESPACE__ . '\\models\\node\\NodeAppVeeamJobs',
		'wfNode' => true,
	    ],
	    'NodeAppVeeamSessions' => [
		'class' => __NAMESPACE__ . '\\models\\node\\NodeAppVeeamSessions',
		'wfNode' => true,
	    ],
	    'NodeAppVeeamBackups' => [
		'class' => __NAMESPACE__ . '\\models\\node\\NodeAppVeeamBackups',
		'wfNode' => true,
	    ],
	    'NodeAppVeeamInfrastructure' => [
		'class' => __NAMESPACE__ . '\\models\\node\\NodeAppVeeamInfrastructure',
		'wfNode' => true,
	    ],
	    'NodeAppVeeamRestore' => [
		'class' => __NAMESPACE__ . '\\models\\node\\NodeAppVeeamRestore',
		'wfNode' => true,
	    ],
	    'NodeAppVeeamApi' => [
		'class' => __NAMESPACE__ . '\\models\\node\\NodeAppVeeamApi',
		'wfNode' => true,
	    ],
	    'NodeTriggerVeeam' => [
		'class' => __NAMESPACE__ . '\\models\\node\\NodeTriggerVeeam',
		'wfNode' => true,
	    ],
	    //Bitdefender GravityZone (one step per API area, NodeAppGravityZone their base; the event trigger)
	    'NodeAppGzEndpoints' => [
		'class' => __NAMESPACE__ . '\\models\\node\\NodeAppGzEndpoints',
		'wfNode' => true,
	    ],
	    'NodeAppGzIncidents' => [
		'class' => __NAMESPACE__ . '\\models\\node\\NodeAppGzIncidents',
		'wfNode' => true,
	    ],
	    'NodeAppGzBlocklist' => [
		'class' => __NAMESPACE__ . '\\models\\node\\NodeAppGzBlocklist',
		'wfNode' => true,
	    ],
	    'NodeAppGzQuarantine' => [
		'class' => __NAMESPACE__ . '\\models\\node\\NodeAppGzQuarantine',
		'wfNode' => true,
	    ],
	    'NodeAppGzInvestigation' => [
		'class' => __NAMESPACE__ . '\\models\\node\\NodeAppGzInvestigation',
		'wfNode' => true,
	    ],
	    'NodeAppGzReports' => [
		'class' => __NAMESPACE__ . '\\models\\node\\NodeAppGzReports',
		'wfNode' => true,
	    ],
	    'NodeAppGzApi' => [
		'class' => __NAMESPACE__ . '\\models\\node\\NodeAppGzApi',
		'wfNode' => true,
	    ],
	    'NodeTriggerGravityZone' => [
		'class' => __NAMESPACE__ . '\\models\\node\\NodeTriggerGravityZone',
		'wfNode' => true,
	    ],
	]);

	//the families of the AI steps' tools
	\V::app()->hooks->addHook('agent:families', function(\ArrayObject $families) {
	    $families['gravityzone'] = [ 'label' => 'Bitdefender GravityZone', 'prefix' => 'gz', 'capability' => 'gravityzone', 'base' => models\node\NodeAppGravityZone::class ];
	    $families['fortigate'] = [ 'label' => 'FortiGate', 'prefix' => 'fgt', 'capability' => 'fortigate', 'base' => models\node\NodeAppFortiGate::class ];
	    $families['sophos'] = [ 'label' => 'Sophos Firewall', 'prefix' => 'sfos', 'capability' => 'sophos', 'base' => models\node\NodeAppSophos::class ];
	    $families['wazuh'] = [ 'label' => 'Wazuh', 'prefix' => 'wazuh', 'capability' => 'wazuh', 'base' => models\node\NodeAppWazuh::class ];
	    $families['namecheap'] = [ 'label' => 'Namecheap', 'prefix' => 'namecheap', 'capability' => 'namecheap', 'base' => models\node\NodeAppNamecheap::class ];
	    $families['intune'] = [ 'label' => 'Microsoft Intune', 'prefix' => 'intune', 'capability' => 'intune', 'base' => models\node\NodeAppIntune::class ];
	    $families['glpi'] = [ 'label' => 'GLPI', 'prefix' => 'glpi', 'capability' => 'glpi', 'base' => models\node\NodeAppGlpi::class ];
	    $families['veeam'] = [ 'label' => 'Veeam Backup & Replication', 'prefix' => 'veeam', 'capability' => 'veeam', 'base' => models\node\NodeAppVeeam::class ];
	    $families['vmware'] = [ 'label' => 'VMware vCenter', 'prefix' => 'vmware', 'capability' => 'vmware', 'base' => models\node\NodeAppVmware::class ];
	    $families['proxmox'] = [ 'label' => 'Proxmox VE', 'prefix' => 'proxmox', 'capability' => 'proxmox', 'base' => models\node\NodeAppProxmox::class ];
	    $families['hyperv'] = [ 'label' => 'Hyper-V', 'prefix' => 'hyperv', 'capability' => 'hyperv', 'base' => models\node\NodeAppHyperV::class ];
	    $families['checkmk'] = [ 'label' => 'Checkmk', 'prefix' => 'cmk', 'capability' => 'checkmk', 'base' => models\node\NodeAppCheckmk::class ];
	    $families['zabbix'] = [ 'label' => 'Zabbix', 'prefix' => 'zbx', 'capability' => 'zabbix', 'base' => models\node\NodeAppZabbix::class ];
	});

	//the refusals of the push triggers' endpoints, in the execution log
	\models\ExecutionLog::$pluginActions += [
	    //a GravityZone event push refused, or events left out (GravityzoneController)
	    'gravityzone.refused' => 'GravityZone event refused',
	    //a FortiGate event refused (FortigateController)
	    'fortigate.refused' => 'FortiGate event refused',
	];

	//this plugin's checks in the core's check suite (commands\CheckCommand, hook check:suites; checks.php)
	if (\V::app()->config('app.mode') != 'web') {
	    \V::app()->hooks->addHook('check:suites', function(\ArrayObject $suites) {
		foreach(require(__DIR__ . '/checks.php') as $name => $fn)
		    $suites[$name] = $fn;
	    });
	    return;
	}

	//the push triggers' endpoints: `/gravityzone/<32 hex token>` (GravityZone's Event Push Service), `/fortigate/<32 hex token>` (an automation stitch's webhook); no session
	foreach([ 'gravityzone', 'fortigate' ] as $c) {
	    \V::app()->session->noSessionFor("/{$c}/");
	    \V::app()->router->add_rule("{$c}/<id:[a-f0-9]{32}>", "ITOps/{$c}/receive", true);
	}
    }
}
