<?php

/*
    ITOps: the steps and credentials of IT infrastructure products, for the installations that automate IT operations. Shipped with the core (app/plugins/ITOps, in the core's
    repository) and on by default (config.php: DEFAULT_PLUGINS); plugins.json `"ITOps": { "enabled": false }` turns it off.
    The installation chooses its products: plugins.json `"ITOps": { "products": [ "veeam", "fortigate", ... ] }` (the keys of PRODUCTS; products()); none listed: all of them,
    an empty list: none. Only the chosen products are registered: their credentials, steps and triggers (\V::app()->om->registerObjects: `wfNode` for a step, `variant-of`
    Credential for a credential, as the core's in data\SysObjects), their AI steps' tools, their endpoints and their checks; in the designer's palette of every account of the
    installation (no tier feature). A product left out later: its credentials and the workflows that use it stay in the database. One family per product, each a credential, a
    base step (models/node/NodeApp<Product>, not registered), one step per area and a client in util/:
	Security          gravityzone  Bitdefender GravityZone (util\GravityZone; event trigger: GravityZone's push service to /gravityzone/<token>)
	                  fortigate    FortiGate (util\FortiGate; event trigger: an automation stitch's webhook to /fortigate/<token>)
	                  sophos       Sophos Firewall (util\Sophos, its XML API; no trigger)
	                  wazuh        Wazuh (util\Wazuh; alert trigger)
	Backup            veeam        Veeam Backup & Replication (util\Veeam; event trigger)
	Storage           synology     Synology NAS (util\Synology: DSM's Web API, signed in as a DSM account; NAS trigger, polling; notification trigger: DSM's webhook
	                               to /synology/<token>)
	Monitoring        checkmk      Checkmk (util\Checkmk; problem trigger)
	                  zabbix       Zabbix (util\Zabbix; problem trigger)
	                  patchmon     PatchMon (util\PatchMon: its integration API, and the web API signed in as a user for patching; host trigger)
	Virtualization    vmware       VMware vCenter (util\VSphere, VSphereError; alarm trigger)
	                  proxmox      Proxmox VE (util\Proxmox; task trigger)
	                  hyperv       Hyper-V (util\WinRM: PowerShell over WinRM; VM state trigger)
	IT management     glpi         GLPI (util\Glpi; ticket trigger)
	                  intune       Microsoft Intune (util\Intune: Graph, client credentials; device trigger)
	Domains & DNS     namecheap    Namecheap (util\Namecheap)
      The polling triggers keep their state in the core's models\TriggerState; the clients use the core's util\Http (private addresses: the account's allow_private_addresses)
      and util\Ip.
    the endpoints of the push triggers (controllers/, ENDPOINTS): /gravityzone/<token> -> GravityzoneController::receive, /fortigate/<token> -> FortigateController::receive,
	/synology/<token> -> SynologyController::receive; callers are not users: no session; their refusals in the execution log (gravityzone.refused, fortigate.refused,
	synology.refused)
    the steps as the tools of AI steps (the core's util\agent\Tools, hook agent:families): one family per product, named as the product (PRODUCTS' family: the prefix of the tools'
	names); the core's agent suites (agenttools, agentapproval, agentguard, agenttrace) use the GravityZone and FortiGate ones
    its checks in the core's check suite (hook check:suites, outside the web): checks.php, one suite per product (hyperV: hyperv) against its fake server in tests/
*/

namespace plugins\ITOps;

class Plugin {
    //the products: key (plugins.json `products`; also the AI family's and the suite's) => label, family (the AI steps' tools: prefix of their names, capability of
    //the credential, base of the steps), objects (registered while the product is chosen: its credential, steps and trigger)
    const PRODUCTS = [
	'gravityzone' => [
	    'label' => 'Bitdefender GravityZone',
	    'family' => [ 'prefix' => 'gz', 'capability' => 'gravityzone', 'base' => models\node\NodeAppGravityZone::class ],
	    'objects' => [
		//a Bitdefender GravityZone console (the GravityZone steps and event trigger)
		'CredentialGravityZone' => [
		    'class' => __NAMESPACE__ . '\\models\\credential\\CredentialGravityZone',
		    'variant-of' => 'Credential',
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
	    ],
	],
	'fortigate' => [
	    'label' => 'FortiGate',
	    'family' => [ 'prefix' => 'fgt', 'capability' => 'fortigate', 'base' => models\node\NodeAppFortiGate::class ],
	    'objects' => [
		//a FortiGate firewall (the FortiGate steps)
		'CredentialFortiGate' => [
		    'class' => __NAMESPACE__ . '\\models\\credential\\CredentialFortiGate',
		    'variant-of' => 'Credential',
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
	    ],
	],
	'sophos' => [
	    'label' => 'Sophos Firewall',
	    'family' => [ 'prefix' => 'sfos', 'capability' => 'sophos', 'base' => models\node\NodeAppSophos::class ],
	    'objects' => [
		//a Sophos Firewall (the Sophos Firewall steps: its XML API)
		'CredentialSophos' => [
		    'class' => __NAMESPACE__ . '\\models\\credential\\CredentialSophos',
		    'variant-of' => 'Credential',
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
	    ],
	],
	'wazuh' => [
	    'label' => 'Wazuh',
	    'family' => [ 'prefix' => 'wazuh', 'capability' => 'wazuh', 'base' => models\node\NodeAppWazuh::class ],
	    'objects' => [
		//a Wazuh installation (the Wazuh steps and alert trigger)
		'CredentialWazuh' => [
		    'class' => __NAMESPACE__ . '\\models\\credential\\CredentialWazuh',
		    'variant-of' => 'Credential',
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
	    ],
	],
	'veeam' => [
	    'label' => 'Veeam Backup & Replication',
	    'family' => [ 'prefix' => 'veeam', 'capability' => 'veeam', 'base' => models\node\NodeAppVeeam::class ],
	    'objects' => [
		//a Veeam Backup & Replication server (the Veeam steps and event trigger)
		'CredentialVeeam' => [
		    'class' => __NAMESPACE__ . '\\models\\credential\\CredentialVeeam',
		    'variant-of' => 'Credential',
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
	    ],
	],
	//CLD: 2026-11-20T12:00:00+02:00: Synology NAS (DSM 6.2 / 7.x): files, system, storage, users, Hyper Backup, any API; the NAS and notification triggers
	'synology' => [
	    'label' => 'Synology NAS',
	    'family' => [ 'prefix' => 'syno', 'capability' => 'synology', 'base' => models\node\NodeAppSynology::class ],
	    'objects' => [
		//a Synology NAS (the Synology steps and NAS trigger)
		'CredentialSynology' => [
		    'class' => __NAMESPACE__ . '\\models\\credential\\CredentialSynology',
		    'variant-of' => 'Credential',
		],
		//Synology (one step per area, NodeAppSynology their base; the NAS trigger, polling; the notification trigger, DSM's webhook)
		'NodeAppSynoFiles' => [
		    'class' => __NAMESPACE__ . '\\models\\node\\NodeAppSynoFiles',
		    'wfNode' => true,
		],
		'NodeAppSynoSystem' => [
		    'class' => __NAMESPACE__ . '\\models\\node\\NodeAppSynoSystem',
		    'wfNode' => true,
		],
		'NodeAppSynoStorage' => [
		    'class' => __NAMESPACE__ . '\\models\\node\\NodeAppSynoStorage',
		    'wfNode' => true,
		],
		'NodeAppSynoUsers' => [
		    'class' => __NAMESPACE__ . '\\models\\node\\NodeAppSynoUsers',
		    'wfNode' => true,
		],
		'NodeAppSynoBackup' => [
		    'class' => __NAMESPACE__ . '\\models\\node\\NodeAppSynoBackup',
		    'wfNode' => true,
		],
		'NodeAppSynoApi' => [
		    'class' => __NAMESPACE__ . '\\models\\node\\NodeAppSynoApi',
		    'wfNode' => true,
		],
		'NodeTriggerSynology' => [
		    'class' => __NAMESPACE__ . '\\models\\node\\NodeTriggerSynology',
		    'wfNode' => true,
		],
		'NodeTriggerSynologyNotification' => [
		    'class' => __NAMESPACE__ . '\\models\\node\\NodeTriggerSynologyNotification',
		    'wfNode' => true,
		],
	    ],
	],
	'checkmk' => [
	    'label' => 'Checkmk',
	    'family' => [ 'prefix' => 'cmk', 'capability' => 'checkmk', 'base' => models\node\NodeAppCheckmk::class ],
	    'objects' => [
		//a Checkmk site (the Checkmk steps and problem trigger)
		'CredentialCheckmk' => [
		    'class' => __NAMESPACE__ . '\\models\\credential\\CredentialCheckmk',
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
	    ],
	],
	'zabbix' => [
	    'label' => 'Zabbix',
	    'family' => [ 'prefix' => 'zbx', 'capability' => 'zabbix', 'base' => models\node\NodeAppZabbix::class ],
	    'objects' => [
		//a Zabbix server (the Zabbix steps and problem trigger)
		'CredentialZabbix' => [
		    'class' => __NAMESPACE__ . '\\models\\credential\\CredentialZabbix',
		    'variant-of' => 'Credential',
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
	    ],
	],
	//CLD: 2026-11-19T15:00:00+02:00: PatchMon (patchmon.net): hosts, packages, patch runs, the host trigger
	'patchmon' => [
	    'label' => 'PatchMon',
	    'family' => [ 'prefix' => 'pm', 'capability' => 'patchmon', 'base' => models\node\NodeAppPatchMon::class ],
	    'objects' => [
		//a PatchMon server (the PatchMon steps and host trigger)
		'CredentialPatchMon' => [
		    'class' => __NAMESPACE__ . '\\models\\credential\\CredentialPatchMon',
		    'variant-of' => 'Credential',
		],
		//PatchMon (one step per area, NodeAppPatchMon their base; the host trigger, polling)
		'NodeAppPmHosts' => [
		    'class' => __NAMESPACE__ . '\\models\\node\\NodeAppPmHosts',
		    'wfNode' => true,
		],
		'NodeAppPmPackages' => [
		    'class' => __NAMESPACE__ . '\\models\\node\\NodeAppPmPackages',
		    'wfNode' => true,
		],
		'NodeAppPmPatching' => [
		    'class' => __NAMESPACE__ . '\\models\\node\\NodeAppPmPatching',
		    'wfNode' => true,
		],
		'NodeAppPmApi' => [
		    'class' => __NAMESPACE__ . '\\models\\node\\NodeAppPmApi',
		    'wfNode' => true,
		],
		'NodeTriggerPatchMon' => [
		    'class' => __NAMESPACE__ . '\\models\\node\\NodeTriggerPatchMon',
		    'wfNode' => true,
		],
	    ],
	],
	'vmware' => [
	    'label' => 'VMware vCenter',
	    'family' => [ 'prefix' => 'vmware', 'capability' => 'vmware', 'base' => models\node\NodeAppVmware::class ],
	    'objects' => [
		//a VMware vCenter Server (the VMware steps and alarm trigger)
		'CredentialVmware' => [
		    'class' => __NAMESPACE__ . '\\models\\credential\\CredentialVmware',
		    'variant-of' => 'Credential',
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
	    ],
	],
	'proxmox' => [
	    'label' => 'Proxmox VE',
	    'family' => [ 'prefix' => 'proxmox', 'capability' => 'proxmox', 'base' => models\node\NodeAppProxmox::class ],
	    'objects' => [
		//a Proxmox VE cluster or server (the Proxmox steps and task trigger)
		'CredentialProxmox' => [
		    'class' => __NAMESPACE__ . '\\models\\credential\\CredentialProxmox',
		    'variant-of' => 'Credential',
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
	    ],
	],
	'hyperv' => [
	    'label' => 'Hyper-V',
	    'family' => [ 'prefix' => 'hyperv', 'capability' => 'hyperv', 'base' => models\node\NodeAppHyperV::class ],
	    'objects' => [
		//a Hyper-V host (the Hyper-V steps and VM state trigger; PowerShell over WinRM)
		'CredentialHyperV' => [
		    'class' => __NAMESPACE__ . '\\models\\credential\\CredentialHyperV',
		    'variant-of' => 'Credential',
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
	    ],
	],
	'glpi' => [
	    'label' => 'GLPI',
	    'family' => [ 'prefix' => 'glpi', 'capability' => 'glpi', 'base' => models\node\NodeAppGlpi::class ],
	    'objects' => [
		//a GLPI server (the GLPI steps and ticket trigger)
		'CredentialGlpi' => [
		    'class' => __NAMESPACE__ . '\\models\\credential\\CredentialGlpi',
		    'variant-of' => 'Credential',
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
	    ],
	],
	'intune' => [
	    'label' => 'Microsoft Intune',
	    'family' => [ 'prefix' => 'intune', 'capability' => 'intune', 'base' => models\node\NodeAppIntune::class ],
	    'objects' => [
		//a Microsoft Intune tenant's app (the Intune steps and device trigger)
		'CredentialIntune' => [
		    'class' => __NAMESPACE__ . '\\models\\credential\\CredentialIntune',
		    'variant-of' => 'Credential',
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
	    ],
	],
	'namecheap' => [
	    'label' => 'Namecheap',
	    'family' => [ 'prefix' => 'namecheap', 'capability' => 'namecheap', 'base' => models\node\NodeAppNamecheap::class ],
	    'objects' => [
		//a Namecheap account's API access (the Namecheap steps)
		'CredentialNamecheap' => [
		    'class' => __NAMESPACE__ . '\\models\\credential\\CredentialNamecheap',
		    'variant-of' => 'Credential',
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
	    ],
	],
    ];

    //the push triggers' endpoints: product => the execution log's action of a refused call and its label
    const ENDPOINTS = [
	//GravityZone's Event Push Service: a push refused, or events left out (GravityzoneController)
	'gravityzone' => [ 'gravityzone.refused', 'GravityZone event refused' ],
	//a FortiGate automation stitch's webhook: an event refused (FortigateController)
	'fortigate' => [ 'fortigate.refused', 'FortiGate event refused' ],
	//CLD: 2026-11-20T12:00:00+02:00: a DSM webhook's notification refused (SynologyController)
	'synology' => [ 'synology.refused', 'Synology notification refused' ],
    ];

    private static $products = null;

    //CLD: 2026-11-15T13:00:00+02:00: the chosen products (keys of PRODUCTS): plugins.json's `products`; none: all; unknown names are left out (logged)
    public static function products() {
	if (self::$products !== null)
	    return self::$products;
	$list = (\V::app()->config('plugins') ?? [])['ITOps']['products'] ?? null;
	if ($list === null)
	    return self::$products = array_keys(self::PRODUCTS);
	if (!is_array($list) || !array_is_list($list)) {
	    error_log('plugins.json: ITOps `products` is not a list of product names: all products registered');
	    return self::$products = array_keys(self::PRODUCTS);
	}
	$unknown = array_diff($list, array_keys(self::PRODUCTS));
	if ($unknown)
	    error_log('plugins.json: ITOps `products`: unknown ' . implode(', ', array_map('strval', $unknown)) . ' left out (products: ' . implode(', ', array_keys(self::PRODUCTS)) . ')');
	//in PRODUCTS' order
	return self::$products = array_values(array_intersect(array_keys(self::PRODUCTS), $list));
    }

    public function __construct() {
	$chosen = self::products();

	foreach($chosen as $p)
	    \V::app()->om->registerObjects(self::PRODUCTS[$p]['objects']);

	//the families of the AI steps' tools
	\V::app()->hooks->addHook('agent:families', function(\ArrayObject $families) use ($chosen) {
	    foreach($chosen as $p)
		$families[$p] = [ 'label' => self::PRODUCTS[$p]['label'] ] + self::PRODUCTS[$p]['family'];
	});

	//the refusals of the push triggers' endpoints, in the execution log
	foreach(array_intersect_key(self::ENDPOINTS, array_flip($chosen)) as [ $action, $label ])
	    \models\ExecutionLog::$pluginActions[$action] = $label;

	//this plugin's checks in the core's check suite (commands\CheckCommand, hook check:suites; checks.php): the chosen products'
	if (\V::app()->config('app.mode') != 'web') {
	    \V::app()->hooks->addHook('check:suites', function(\ArrayObject $suites) use ($chosen) {
		foreach(require(__DIR__ . '/checks.php') as $name => $fn)
		    if (in_array(strtolower($name), $chosen, true))
			$suites[$name] = $fn;
	    });
	    return;
	}

	//the push triggers' endpoints: `/<product>/<32 hex token>`; no session
	foreach(array_intersect(array_keys(self::ENDPOINTS), $chosen) as $c) {
	    \V::app()->session->noSessionFor("/{$c}/");
	    \V::app()->router->add_rule("{$c}/<id:[a-f0-9]{32}>", "ITOps/{$c}/receive", true);
	}
    }
}
