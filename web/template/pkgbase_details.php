<?php

$cgit_uri = config_get('options', 'cgit_uri');

$uid = uid_from_sid($SID);

$base_id = intval($row['ID']);

$catarr = pkgbase_categories();

$submitter = username_from_id($row["SubmitterUID"]);
$maintainer = username_from_id($row["MaintainerUID"]);
$packager = username_from_id($row["PackagerUID"]);

$votes = $row['NumVotes'];

# In case of wanting to put a custom message
$msg = __('unknown');

# Print the timestamps for last updates
$updated_time = ($row["ModifiedTS"] == 0) ? $msg : gmdate("Y-m-d H:i", intval($row["ModifiedTS"]));
$submitted_time = ($row["SubmittedTS"] == 0) ? $msg : gmdate("Y-m-d H:i", intval($row["SubmittedTS"]));
$out_of_date_time = ($row["OutOfDateTS"] == 0) ? $msg : gmdate("Y-m-d", intval($row["OutOfDateTS"]));

$pkgs = pkgbase_get_pkgnames($base_id);
?>
<div id="pkgdetails" class="box">
	<h2><?= __('Package Base Details') . ': ' . htmlspecialchars($row['Name']) ?></h2>
	<div id="detailslinks" class="listing">
		<div id="actionlist">
			<h4><?= __('Package Actions') ?></h4>
			<ul class="small">
				<li><a href="<?= $cgit_uri . $row['Name'] . '.git' ?>/tree/PKGBUILD"><?= __('View PKGBUILD') ?></a></li>
				<li><a href="<?= $cgit_uri . $row['Name'] . '.git' ?>/snapshot/master.tar.gz"><?= __('Download snapshot') ?></a></li>
				<li><a href="https://wiki.archlinux.org/index.php/Special:Search?search=<?= urlencode($row['Name']) ?>"><?= __('Search wiki') ?></a></li>
				<li><span class="flagged"><?php if ($row["OutOfDateTS"] !== NULL) { echo __('Flagged out-of-date')." (${out_of_date_time})"; } ?></span></li>
				<?php if ($uid): ?>
				<?php if ($row["OutOfDateTS"] === NULL): ?>
				<li>
					<form action="<?= get_pkgbase_uri($row['Name']) . 'flag/'; ?>" method="post">
						<input type="hidden" name="token" value="<?= htmlspecialchars($_COOKIE['AURSID']) ?>" />
						<input type="submit" class="button text-button" name="do_Flag" value="<?= __('Flag package out-of-date') ?>" />
					</form>
				</li>
				<?php elseif (($row["OutOfDateTS"] !== NULL) && has_credential(CRED_PKGBASE_UNFLAG, array($row["MaintainerUID"]))): ?>
				<li>
					<form action="<?= get_pkgbase_uri($row['Name']) . 'unflag/'; ?>" method="post">
						<input type="hidden" name="token" value="<?= htmlspecialchars($_COOKIE['AURSID']) ?>" />
						<input type="submit" class="button text-button" name="do_UnFlag" value="<?= __('Unflag package') ?>" />
					</form>
				</li>
				<?php endif; ?>
				<?php if (pkgbase_user_voted($uid, $base_id)): ?>
				<li>
					<form action="<?= get_pkgbase_uri($row['Name']) . 'unvote/'; ?>" method="post">
						<input type="hidden" name="token" value="<?= htmlspecialchars($_COOKIE['AURSID']) ?>" />
						<input type="submit" class="button text-button" name="do_UnVote" value="<?= __('Remove vote') ?>" />
					</form>
				</li>
				<?php else: ?>
				<li>
					<form action="<?= get_pkgbase_uri($row['Name']) . 'vote/'; ?>" method="post">
						<input type="hidden" name="token" value="<?= htmlspecialchars($_COOKIE['AURSID']) ?>" />
						<input type="submit" class="button text-button" name="do_Vote" value="<?= __('Vote for this package') ?>" />
					</form>
				</li>
				<?php endif; ?>
				<?php if (pkgbase_user_notify($uid, $base_id)): ?>
				<li>
					<form action="<?= get_pkgbase_uri($row['Name']) . 'unnotify/'; ?>" method="post">
						<input type="hidden" name="token" value="<?= htmlspecialchars($_COOKIE['AURSID']) ?>" />
						<input type="submit" class="button text-button" name="do_UnNotify" value="<?= __('Disable notifications') ?>" />
					</form>
				</li>
				<?php else: ?>
				<li>
					<form action="<?= get_pkgbase_uri($row['Name']) . 'notify/'; ?>" method="post">
						<input type="hidden" name="token" value="<?= htmlspecialchars($_COOKIE['AURSID']) ?>" />
						<input type="submit" class="button text-button" name="do_Notify" value="<?= __('Notify of new comments') ?>" />
					</form>
				</li>
				<?php endif; ?>
				<li><span class="flagged"><?php if ($row["RequestCount"] > 0) { echo _n('%d pending request', '%d pending requests', $row["RequestCount"]); } ?></span></li>
				<li><a href="<?= get_pkgbase_uri($row['Name']) . 'request/'; ?>"><?= __('File Request'); ?></a></li>
				<?php if (has_credential(CRED_PKGBASE_DELETE)): ?>
				<li><a href="<?= get_pkgbase_uri($row['Name']) . 'delete/'; ?>"><?= __('Delete Package'); ?></a></li>
				<li><a href="<?= get_pkgbase_uri($row['Name']) . 'merge/'; ?>"><?= __('Merge Package'); ?></a></li>
				<?php endif; ?>
				<?php endif; ?>

				<?php if ($uid && $row["MaintainerUID"] === NULL): ?>
				<li>
					<form action="<?= get_pkgbase_uri($row['Name']) . 'adopt/'; ?>" method="post">
						<input type="hidden" name="token" value="<?= htmlspecialchars($_COOKIE['AURSID']) ?>" />
						<input type="submit" class="button text-button" name="do_Adopt" value="<?= __('Adopt Package') ?>" />
					</form>
				</li>
				<?php elseif (has_credential(CRED_PKGBASE_DISOWN, array($row["MaintainerUID"]))): ?>
				<li>
					<form action="<?= get_pkgbase_uri($row['Name']) . 'disown/'; ?>" method="post">
						<input type="hidden" name="token" value="<?= htmlspecialchars($_COOKIE['AURSID']) ?>" />
						<input type="submit" class="button text-button" name="do_Disown" value="<?= __('Disown Package') ?>" />
					</form>
				</li>
				<?php endif; ?>
			</ul>
		</div>
	</div>

	<table id="pkginfo">
		<tr>
			<th><?= __('Category') . ': ' ?></th>
<?php
if (has_credential(CRED_PKGBASE_CHANGE_CATEGORY, array($row["MaintainerUID"]))):
?>
			<td>
				<form method="post" action="<?= htmlspecialchars(get_pkgbase_uri($row['Name']), ENT_QUOTES); ?>">
					<div>
						<input type="hidden" name="action" value="do_ChangeCategory" />
						<?php if ($SID): ?>
						<input type="hidden" name="token" value="<?= htmlspecialchars($_COOKIE['AURSID']) ?>" />
						<?php endif; ?>
						<select name="category_id">
<?php
	foreach ($catarr as $cid => $catname):
?>
							<option value="<?= $cid ?>"<?php if ($cid == $row["CategoryID"]) { ?> selected="selected" <?php } ?>><?= $catname ?></option>
	<?php endforeach; ?>
						</select>
						<input type="submit" value="<?= __('Change category') ?>"/>
					</div>
				</form>
<?php else: ?>
			<td>
				<a href="<?= get_uri('/packages/'); ?>?C=<?= $row['CategoryID'] ?>"><?= $row['Category'] ?></a>
<?php endif; ?>
			</td>
		</tr>
		<tr>
			<th><?= __('Submitter') .': ' ?></th>
			<?php if ($row["SubmitterUID"] && $SID): ?>
			<td><a href="<?= get_uri('/account/') . html_format_username($submitter, ENT_QUOTES) ?>" title="<?= __('View account information for %s', html_format_username($submitter)) ?>"><?= html_format_username($submitter) ?></a></td>
			<?php elseif ($row["SubmitterUID"] && !$SID): ?>
			<td><?= html_format_username($submitter) ?></td>
			<?php else: ?>
			<td><?= __('None') ?></td>
			<?php endif; ?>
		</tr>
		<tr>
			<th><?= __('Maintainer') .': ' ?></th>
			<?php if ($row["MaintainerUID"] && $SID): ?>
			<td><a href="<?= get_uri('/account/') . html_format_username($maintainer) ?>" title="<?= __('View account information for %s', html_format_username($maintainer)) ?>"><?= html_format_username($maintainer) ?></a></td>
			<?php elseif ($row["MaintainerUID"] && !$SID): ?>
			<td><?= html_format_username($maintainer) ?></td>
			<?php else: ?>
			<td><?= __('None') ?></td>
			<?php endif; ?>
		</tr>
		<tr>
			<th><?= __('Last Packager') .': ' ?></th>
			<?php if ($row["PackagerUID"] && $SID): ?>
			<td><a href="<?= get_uri('/account/') . html_format_username($packager) ?>" title="<?= __('View account information for %s', html_format_username($packager)) ?>"><?= html_format_username($packager) ?></a></td>
			<?php elseif ($row["PackagerUID"] && !$SID): ?>
			<td><?= html_format_username($packager) ?></td>
			<?php else: ?>
			<td><?= __('None') ?></td>
			<?php endif; ?>
		</tr>
		<tr>
			<th><?= __('Votes') . ': ' ?></th>
			<?php if (has_credential(CRED_PKGBASE_LIST_VOTERS)): ?>
			<td><a href="<?= get_pkgbase_uri($row['Name']); ?>voters/"><?= $votes ?></a></td>
			<?php else: ?>
			<td><?= $votes ?></td>
			<?php endif; ?>
		</tr>
		<tr>
			<th><?= __('First Submitted') . ': ' ?></th>
			<td><?= $submitted_time ?></td>
		</tr>
		<tr>
			<th><?= __('Last Updated') . ': ' ?></th>
			<td><?= $updated_time ?></td>
		</tr>
	</table>

	<div id="metadata">
		<div id="pkgs" class="listing">
			<h3><?= __('Packages') . " (" . count($pkgs) . ")"?></h3>
<?php if (count($pkgs) > 0): ?>
			<ul>
<?php
	while (list($k, $pkg) = each($pkgs)):
?>
	<li><a href="<?= htmlspecialchars(get_pkg_uri($pkg), ENT_QUOTES); ?>" title="<?= __('View packages details for').' '. htmlspecialchars($pkg) ?>"><?= htmlspecialchars($pkg) ?></a></li>
	<?php endwhile; ?>
			</ul>
<?php endif; ?>
		</div>
	</div>
</div>
