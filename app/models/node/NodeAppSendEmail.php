<?php

/*
    Send email - an email made of blocks (models\EmailBlocks,
	edited with the block designer app.emaildesigner.js) sent to the recipients of the step.
	The step fills the {{ }} with the run's variables and renders the email
	    (util\EmailHtml: responsive, inline styles, Outlook-safe, a text version, the images embedded),
	    then queues it: the daemon sends it (util\EmailSend::deliver); the run goes on.
	CLD: 2026-11-16T23:30:00+02:00: Attachments: project files (ids, a list, {{ file }} of Download File / Spreadsheet file), checked here (the project's, MAX_ATTACHMENTS in all);
	    the job keeps their ids, deliver() reads them (a file deleted before is left out)

    From: the app's address (config mail.from), the step's sender name and Reply-To
    CLD: 2026-11-28T18:00:00+02:00: Send email (SMTP) (NodeAppSmtpEmail) without its server settings: the installation's SMTP server (app.env: util\EmailSend::installationServer,
	127.0.0.1 without sign-in on a standard install), no credential, From, timeout, result variable or On error; its settings, checks and message are the parent's
*/
namespace models\node;

class NodeAppSendEmail extends NodeAppSmtpEmail {
    public static $name = 'send-email';
    public static $label = 'Send email';
    public static $icon = 'bi-envelope-paper';
    public static $audience = 'basic';
    public static $outPorts = null;
    protected static $ownServer = false;

    public function execute($edgeId = null) {
	$vars = $this->exec->expressionVariables();
	try {
	    [ $email, $attachments ] = $this->email($vars);
	} catch (\RuntimeException $e) {
	    throw new \ErrorException(static::$label . ': ' . $e->getMessage());
	}
	$job = \models\Job::submit('\util\EmailSend::deliver', $email, [ 'aid' => $this->exec->aid, 'project' => $this->exec->project, 'delete_on_done' => true ]);
	$this->exec->log($this->wfid, 'email "' . mb_substr($email['subject'], 0, 80) . '" queued ' . self::logOf($email, $attachments) . " (job {$job})");
	return [ 0 ];
    }
}
