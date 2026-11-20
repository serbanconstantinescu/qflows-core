<?php
//members can be managed (Project::update): add a user of the account or invite an email address, with a role, change a member's role (the select saves it), remove a member
//(ProjectController::member_add / member_role / member_remove); read-only without the permission; a personal project says why it has no members
if ($project->personal) {
    echo "<div class='alert alert-info mt-4 mb-0'><i class='bi bi-person-lock me-2'></i>This is your personal project: it has no other members. "
	. "To work with others, create a project (<i class='bi bi-plus-lg'></i> at the top of the sidebar, <b>New project</b>) and add them in its Members tab.</div>";
    return;
}

$canManage = \V::app()->user->can('Project::update', [ 'object' => $project ]);
$roles = [];
foreach(\V::app()->om->find('Role', [ 'aid' => $project->aid ], [ 'sort' => [ 'name' => 1 ] ]) as $r)
    $roles[(string)$r->_id] = (string)$r;
$members = (array)$project->members;
//the role select (`role`): one option per role of the account
$roleSelectTpl = '<select name="role" class="form-select form-select-sm" {:attrs}>{:options}</select>';
$roleOptionTpl = '<option value="{:value}"{:selected}>{:label}</option>';

echo '<div class="w-100 mt-4"></div>';

if ($canManage) {
    //CLD: 2026-11-20T15:00:00+02:00: one form adds people (ProjectController::member_add): a user of the account, picked by name or email, is added now; a new email
    //address typed in (app.SelectPlus create, canCreate: a valid address, not a user or member) is invited (a link to join, needs User::create); the button follows (Add / Invite)
    $candidates = [];
    $memberEmails = [];
    foreach(\V::app()->om->find('User', [ 'aid' => $project->aid ], [ 'sort' => [ 'firstname' => 1 ] ]) as $u) {
	if (!isset($members[(string)$u->_id]))
	    $candidates[(string)$u->_id] = (string)$u . ($u->email ? ' <' . $u->email . '>' : '');
	elseif ($u->email)
	    $memberEmails[] = strtolower((string)$u->email);
    }
    $canInvite = \V::app()->user->can('User::create');
    $memberRole = (string)(\V::app()->om->findOne('Role', [ 'aid' => $project->aid, 'iname' => 'member' ])->_id ?? '');
    //the role of who is added or invited: Member at first
    $roleOptions = '';
    foreach($roles as $id => $label)
	$roleOptions .= \util\VString::insert($roleOptionTpl, [ 'value' => \V::app()->html->escape($id), 'selected' => $id === $memberRole ? ' selected' : '', 'label' => \V::app()->html->escape($label) ]);
    $addTpl = '
<form method="post" action="{:action}" class="d-flex flex-wrap gap-2 align-items-end mb-1">
    <div style="min-width: 22rem">
	<label class="form-label small mb-1" for="member-add-user">Add people</label>
	<select id="member-add-user" name="user" class="form-select form-select-sm"{:disabled}>{:options}</select>
    </div>
    <div><label class="form-label small mb-1">Role</label>{:role}</div>
    <button type="submit" class="btn btn-sm btn-primary member-add-btn"{:disabled}><i class="bi bi-person-plus me-1"></i>Add</button>
</form>
<div class="form-text mb-3">{:help}</div>
';
    $closed = !$candidates && !$canInvite;
    $options = \util\VString::insert('<option value="">{:label}</option>', [ 'label' => $closed ? 'All the account\'s users are members' : 'Type a name or an email address&hellip;' ]);
    foreach($candidates as $id => $label)
	$options .= \util\VString::insert('<option value="{:value}">{:label}</option>', [
	    'value' => \V::app()->html->escape($id),
	    'label' => \V::app()->html->escape($label),
	]);
    echo \util\VString::insert($addTpl, [
	'action' => \V::app()->createUrl('/project/member_add', [ 'id' => $project->_id ]),
	'disabled' => $closed ? ' disabled' : '',
	'options' => $options,
	'role' => \util\VString::insert($roleSelectTpl, [ 'attrs' => '', 'options' => $roleOptions ]),
	'help' => $canInvite ? 'Users of the account are added now; anyone else gets an email with a link to join, valid 7 days.'
	    : 'Users of the account are added now; only who may add users can invite new people.',
    ]);

    $membersJs = json_encode($memberEmails);
    $canInviteJs = $canInvite ? 'true' : 'false';
    \V::app()->cs->addPageScript(<<<JS
(function() {
    const select = document.getElementById('member-add-user');
    if (!select || select.disabled || !window.app.SelectPlus)
	return;
    const members = {$membersJs};
    const users = new Set([...select.options].map((o) => o.value).filter(Boolean));
    const isEmail = (t) => /^[^\\s@]+@[^\\s@]+\\.[^\\s@]+$/.test(t);
    new app.SelectPlus(select, {
	searchPlaceholder: 'A name or an email address',
	create: true,
	createLabel: (t) => 'Invite ' + t + ' by email',
	//an email address: not when it is a listed user's (pick them); a member's or (without User::create) a new one: greyed out, why
	canCreate: (t, sp) => {
	    t = t.toLowerCase();
	    if (!isEmail(t) || sp.items.some((i) => i.text.toLowerCase().includes('<' + t + '>')))
		return false;
	    if (members.includes(t))
		return t + ' is a member already';
	    return {$canInviteJs} || (t + ' is not a user of the account: only who may add users can invite new people');
	},
    });
    //the button: disabled until someone is chosen (the select is hidden, so not required), Invite for a typed address
    const btn = select.form.querySelector('.member-add-btn');
    const follow = () => {
	const invite = select.value !== '' && !users.has(select.value);
	btn.disabled = select.value === '';
	btn.innerHTML = invite ? '<i class="bi bi-envelope-plus me-1"></i>Invite' : '<i class="bi bi-person-plus me-1"></i>Add';
    };
    select.addEventListener('change', follow);
    follow();
})();
JS);
}

//the members as a table, as the invitations below
echo "<div class='table-responsive'><table class='table table-sm align-middle'><thead><tr><th>User</th><th style='width: 16rem'>Role</th><th style='width: 8rem'></th></tr></thead><tbody>";
$memberTpl = '<tr><td>{:name}</td><td>{:role}</td><td class="text-end">{:remove}</td></tr>';
$roleFormTpl = '<form method="post" action="{:action}" class="m-0" style="max-width: 14rem">{:select}</form>';
$removeTpl = '<a class="btn btn-sm btn-outline-danger" href="{:href}" data-method="post" data-confirm="{:confirm}"><i class="bi bi-person-dash me-1"></i>Remove</a>';
foreach($members as $key => $item) {
    $name = (string)\V::app()->om->findById('User', $key);
    $q = [ 'id' => (string)$project->_id, 'user' => (string)$key ];
    $roleOptions = '';
    foreach($roles as $id => $label)
	$roleOptions .= \util\VString::insert($roleOptionTpl, [ 'value' => \V::app()->html->escape($id), 'selected' => $id === (string)($item['role'] ?? '') ? ' selected' : '', 'label' => \V::app()->html->escape($label) ]);
    echo \util\VString::insert($memberTpl, [
	'name' => \V::app()->html->escape($name),
	'role' => $canManage
	    ? \util\VString::insert($roleFormTpl, [
		'action' => \V::app()->createUrl('/project/member_role', $q),
		'select' => \util\VString::insert($roleSelectTpl, [ 'attrs' => "onchange='this.form.requestSubmit()' aria-label='Role'", 'options' => $roleOptions ]),
	    ])
	    : \V::app()->html->escape($roles[(string)($item['role'] ?? '')] ?? ''),
	'remove' => $canManage
	    ? \util\VString::insert($removeTpl, [
		'href' => \V::app()->createUrl('/project/member_remove', $q),
		//the confirmation is shown as HTML after the attribute is read: escaped for both
		'confirm' => \V::app()->html->escape('Remove ' . \V::app()->html->escape($name) . ' from the project?'),
	    ])
	    : '',
    ]);
}
echo '</tbody></table></div>';

//the pending invitations (not joined yet): send again / cancel
$invites = $canManage ? iterator_to_array(\models\user\ProjectInvite::pending($project), false) : [];
if ($invites) {
    //in .table-responsive (scrolls sideways on phones; its fixed-width columns did not fit)
    echo "<h6 class='mt-4 mb-2'>Invited, not joined yet</h6><div class='table-responsive'><table class='table table-sm align-middle'><tbody>";
    $inviteRowTpl = '
<tr>
    <td><i class="bi bi-envelope me-2 text-body-secondary"></i>{:email}</td>
    <td style="width: 16rem">{:role}</td>
    <td class="small text-body-secondary">{:by}until {:until}</td>
    <td class="text-end text-nowrap" style="width: 14rem">
	<a class="btn btn-sm btn-outline-secondary me-1" href="{:resend}" data-method="post"><i class="bi bi-arrow-repeat me-1"></i>Resend</a>
	<a class="btn btn-sm btn-outline-danger" href="{:cancel}" data-method="post" data-confirm="{:confirm}"><i class="bi bi-x-lg me-1"></i>Cancel</a>
    </td>
</tr>
';
    foreach($invites as $inv) {
	$by = $inv->invited_by ? \V::app()->om->findById('User', $inv->invited_by) : null;
	$q = [ 'id' => (string)$project->_id, 'invite' => (string)$inv->_id ];
	//the invitation's end in the account's datetime format (util\Format)
	echo \util\VString::insert($inviteRowTpl, [
	    'email' => \V::app()->html->escape($inv->email),
	    'role' => \V::app()->html->escape($roles[(string)$inv->role] ?? ''),
	    'by' => $by ? 'by ' . \V::app()->html->escape($by) . ', ' : '',
	    'until' => \V::app()->html->escape(\util\Format::datetime($inv->expires_at)),
	    'resend' => \V::app()->createUrl('/project/member_invite_resend', $q),
	    'cancel' => \V::app()->createUrl('/project/member_invite_cancel', $q),
	    'confirm' => \V::app()->html->escape('Cancel the invitation of ' . $inv->email . '?'),
	]);
    }
    echo '</tbody></table></div>';
}
