<?php

set_include_path(get_include_path() . PATH_SEPARATOR . '../lib');
include_once("config.inc.php");

require_once('Archive/Tar.php');

include_once("aur.inc.php");         # access AUR common functions
include_once("pkgfuncs.inc.php");    # package functions

set_lang();                 # this sets up the visitor's language
check_sid();                # see if they're still logged in

$cwd = getcwd();

if ($_COOKIE["AURSID"]) {
	$uid = uid_from_sid($_COOKIE['AURSID']);
}
else {
	$uid = NULL;
}

if ($uid):

	# Track upload errors
	$error = "";
	$ignore_missing_aurinfo = 0;

	if (isset($_REQUEST['pkgsubmit'])) {

		# Make sure authenticated user submitted the package themselves
		if (!check_token()) {
			$error = __("Invalid token for user action.");
		}

		# Before processing, make sure we even have a file
		switch($_FILES['pfile']['error']) {
			case UPLOAD_ERR_INI_SIZE:
				$maxsize =  ini_get('upload_max_filesize');
				$error = __("Error - Uploaded file larger than maximum allowed size (%s)", $maxsize);
				break;
			case UPLOAD_ERR_PARTIAL:
				$error = __("Error - File partially uploaded");
				break;
			case UPLOAD_ERR_NO_FILE:
				$error = __("Error - No file uploaded");
				break;
			case UPLOAD_ERR_NO_TMP_DIR:
				$error = __("Error - Could not locate temporary upload folder");
				break;
			case UPLOAD_ERR_CANT_WRITE:
				$error = __("Error - File could not be written");
				break;
		}

		# Check whether the file is gzip'ed
		if (!$error) {
			$fh = fopen($_FILES['pfile']['tmp_name'], 'rb');
			fseek($fh, 0, SEEK_SET);
			list(, $magic) = unpack('v', fread($fh, 2));

			if ($magic != 0x8b1f) {
				$error = __("Error - unsupported file format (please submit gzip'ed tarballs generated by makepkg(8) only).");
			}
		}

		# Check uncompressed file size (ZIP bomb protection)
		if (!$error && $MAX_FILESIZE_UNCOMPRESSED) {
			fseek($fh, -4, SEEK_END);
			list(, $filesize_uncompressed) = unpack('V', fread($fh, 4));

			if ($filesize_uncompressed > $MAX_FILESIZE_UNCOMPRESSED) {
				$error = __("Error - uncompressed file size too large.");
			}
		}

		# Close file handle before extracting stuff
		if (isset($fh) && is_resource($fh)) {
			fclose($fh);
		}

		if (!$error) {
			$tar = new Archive_Tar($_FILES['pfile']['tmp_name']);

			/* Extract PKGBUILD and .AURINFO into a string. */
			$pkgbuild_raw = $srcinfo_raw = '';
			$dircount = 0;
			foreach ($tar->listContent() as $tar_file) {
				if ($tar_file['typeflag'] == 0) {
					if (strchr($tar_file['filename'], '/') === false) {
						$error = __("Error - source tarball may not contain files outside a directory.");
						break;
					}
					elseif (substr($tar_file['filename'], -9) == '/PKGBUILD') {
						$pkgbuild_raw = $tar->extractInString($tar_file['filename']);
					}
					elseif (substr($tar_file['filename'], -9) == '/.AURINFO') {
						$srcinfo_raw = $tar->extractInString($tar_file['filename']);
					}
				}
				elseif ($tar_file['typeflag'] == 5) {
					if (substr_count($tar_file['filename'], "/") > 1) {
						$error = __("Error - source tarball may not contain nested subdirectories.");
						break;
					}
					elseif (++$dircount > 1) {
						$error = __("Error - source tarball may not contain more than one directory.");
						break;
					}
				}
			}
		}

		if (!$error && $dircount !== 1) {
			$error = __("Error - source tarball may not contain files outside a directory.");
		}

		if (empty($pkgbuild_raw)) {
			$pkgbuild_raw = '';
			if (!$error) {
				$error = __("Error trying to unpack upload - PKGBUILD does not exist.");
			}
		}

		if (empty($srcinfo_raw)) {
			$srcinfo_raw = '';
			if (!$error && (!isset($_POST['ignore_missing_aurinfo']) || $_POST['ignore_missing_aurinfo'] != 1)) {
				$ignore_missing_aurinfo = 1;
				$error = __("The source package does not contain any meta data. Please use `mkaurball` to create AUR source packages. Support for source packages without .AURINFO entries will be removed in an upcoming release! You can resubmit the package if you want to proceed anyway.");
			}
		}

		include('pkgbuild-parser.inc.php');

		/* Parse .AURINFO and extract meta data. */
		$pkgbase_info = array();
		$pkginfo = array();
		$section_info = array();
		foreach (explode("\n", $srcinfo_raw) as $line) {
			$line = trim($line);
			if (empty($line) || $line[0] == '#') {
				continue;
			}
			list($key, $value) = explode(' = ', $line, 2);
			switch ($key) {
			case 'pkgbase':
			case 'pkgname':
				if (!empty($section_info)) {
					if (isset($section_info['pkgbase'])) {
						$pkgbase_info = $section_info;
					} elseif (isset($section_info['pkgname'])) {
						$pkginfo[] = array_merge($pkgbase_info, $section_info);
					}
				}
				$section_info = array('depends' => array(), 'source' => array());
				/* Fall-through case. */
			case 'epoch':
			case 'pkgdesc':
			case 'pkgver':
			case 'pkgrel':
			case 'url':
			case 'license':
				$section_info[$key] = $value;
				break;
			case 'source':
			case 'depends':
				$section_info[$key][] = $value;
				break;
			}
		}

		if (!empty($section_info)) {
			if (isset($section_info['pkgbase'])) {
				$pkgbase_info = $section_info;
			} elseif (isset($section_info['pkgname'])) {
				$pkginfo[] = array_merge($pkgbase_info, $section_info);
			}
		} else {
			/* Use data from the PKGBUILD parser (deprecated!) */
			$pkgbase_info = $new_pkgbuild;
			if (!isset($pkgbase_info['pkgbase'])) {
				$pkgbase_info['pkgbase'] = $pkgbase_info['pkgname'];
			}
			if (empty($pkgbase_info['depends'])) {
				$pkgbase_info['depends'] = array();
			} else {
				$pkgbase_info['depends'] = explode(" ", $pkgbase_info['depends']);
			}
			if (empty($pkgbase_info['source'])) {
				$pkgbase_info['source'] = array();
			} else {
				$pkgbase_info['source'] = explode(" ", $pkgbase_info['source']);
			}
			$pkginfo[] = $pkgbase_info;
		}

		/* Validate package base name. */
		if (!$error) {
			$pkgbase_name = $pkgbase_info['pkgbase'];
			if (!preg_match("/^[a-z0-9][a-z0-9\.+_-]*$/", $pkgbase_name)) {
				$error = __("Invalid name: only lowercase letters are allowed.");
			}

			/* Check whether the package base already exists. */
			$base_id = pkgbase_from_name($pkgbase_name);
		}

		foreach ($pkginfo as $key => $pi) {
			/* Bail out early if an error has occurred. */
			if ($error) {
				break;
			}

			/* Validate package names. */
			$pkg_name = $pi['pkgname'];
			if (!preg_match("/^[a-z0-9][a-z0-9\.+_-]*$/", $pkg_name)) {
				$error = __("Invalid name: only lowercase letters are allowed.");
				break;
			}

			/* Determine the full package versions with epoch. */
			if (isset($pi['epoch']) && (int)$pi['epoch'] > 0) {
				$pkginfo[$key]['full-version'] = sprintf('%d:%s-%s', $pi['epoch'], $pi['pkgver'], $pi['pkgrel']);
			} else {
				$pkginfo[$key]['full-version'] = sprintf('%s-%s', $pi['pkgver'], $pi['pkgrel']);
			}

			/* Check for http:// or other protocols in the URL. */
			$parsed_url = parse_url($pi['url']);
			if (!$parsed_url['scheme']) {
				$error = __("Package URL is missing a protocol (ie. http:// ,ftp://)");
				break;
			}

			/*
			 * The DB schema imposes limitations on number of
			 * allowed characters. Print error message when these
			 * limitations are exceeded.
			 */
			if (strlen($pi['pkgname']) > 64) {
				$error = __("Error - Package name cannot be greater than %d characters", 64);
				break;
			}
			if (strlen($pi['url']) > 255) {
				$error = __("Error - Package URL cannot be greater than %d characters", 255);
				break;
			}
			if (strlen($pi['pkgdesc']) > 255) {
				$error = __("Error - Package description cannot be greater than %d characters", 255);
				break;
			}
			if (strlen($pi['license']) > 40) {
				$error = __("Error - Package license cannot be greater than %d characters", 40);
				break;
			}
			if (strlen($pkginfo[$key]['full-version']) > 32) {
				$error = __("Error - Package version cannot be greater than %d characters", 32);
				break;
			}

			/* Check if package name is blacklisted. */
			if (!$base_id && pkgname_is_blacklisted($pi['pkgname']) && !canSubmitBlacklisted(account_from_sid($_COOKIE["AURSID"]))) {
				$error = __( "%s is on the package blacklist, please check if it's available in the official repos.", $pi['pkgname']);
				break;
			}
		}

		if (isset($pkgbase_name)) {
			$incoming_pkgdir = INCOMING_DIR . substr($pkgbase_name, 0, 2) . "/" . $pkgbase_name;
		}

		/* Upload PKGBUILD and tarball. */
		if (!$error && !can_submit_pkgbase($pkgbase_name, $_COOKIE["AURSID"])) {
			$error = __( "You are not allowed to overwrite the %s%s%s package.", "<strong>", $pkgbase_name, "</strong>");
		}

		if (!$error) {
			foreach ($pkginfo as $pi) {
				if (!can_submit_pkg($pi['pkgname'], $base_id)) {
					$error = __( "You are not allowed to overwrite the %s%s%s package.", "<strong>", $pi['pkgname'], "</strong>");
					break;
				}
			}
		}

		if (!$error) {
			/*
			 * Blow away the existing directory and its contents.
			 */
			if (file_exists($incoming_pkgdir)) {
				rm_tree($incoming_pkgdir);
			}

			/*
			 * The mode is masked by the current umask, so not as
			 * scary as it looks.
			 */
			if (!mkdir($incoming_pkgdir, 0777, true)) {
				$error = __( "Could not create directory %s.", $incoming_pkgdir);
			}

			if (!chdir($incoming_pkgdir)) {
				$error = __("Could not change directory to %s.", $incoming_pkgdir);
			}

			file_put_contents('PKGBUILD', $pkgbuild_raw);
			move_uploaded_file($_FILES['pfile']['tmp_name'], $pkgbase_name . '.tar.gz');
		}

		/* Update the backend database. */
		if (!$error) {
			begin_atomic_commit();

			/*
			 * Check the category to use, "1" meaning "none" (or
			 * "keep category" for existing packages).
			 */
			if (isset($_POST['category'])) {
				$category_id = max(1, intval($_POST['category']));
			} else {
				$category_id = 1;
			}

			if ($base_id) {
				/*
				 * This is an overwrite of an existing package
				 * base, the database ID needs to be preserved
				 * so that any votes are retained.
				 */
				$was_orphan = (pkgbase_maintainer_uid($base_id) === NULL);

				update_pkgbase($base_id, $pkgbase_info['pkgbase'], $uid);

				if ($category_id > 1) {
					update_pkgbase_category($base_id, $category_id);
				}

				pkgbase_delete_packages($base_id);
			} else {
				/* This is a brand new package. */
				$was_orphan = true;
				$base_id = create_pkgbase($pkgbase_name, $category_id, $uid);
			}

			foreach ($pkginfo as $pi) {
				$pkgid = create_pkg($base_id, $pi['pkgname'], $pi['license'], $pi['full-version'], $pi['pkgdesc'], $pi['url']);

				foreach ($pi['depends'] as $dep) {
					$deppkgname = preg_replace("/(<|=|>).*/", "", $dep);
					$depcondition = str_replace($deppkgname, "", $dep);
					add_pkg_dep($pkgid, $deppkgname, $depcondition);
				}

				foreach ($pi['source'] as $src) {
					add_pkg_src($pkgid, $src);
				}
			}

			/*
			 * If we just created this package, or it was an orphan
			 * and we auto-adopted, add submitting user to the
			 * notification list.
			 */
			if ($was_orphan) {
				pkg_notify(account_from_sid($_COOKIE["AURSID"]), array($base_id), true);
			}

			end_atomic_commit();

			header('Location: ' . get_pkg_uri($pi[0]['pkgname']));
		}

		chdir($cwd);
	}

html_header("Submit");

?>

<div class="box">
	<h2><?= __("Submit"); ?></h2>
	<p><?= __("Upload your source packages here. Create source packages with `mkaurball`.") ?></p>

<?php
	if (empty($_REQUEST['pkgsubmit']) || $error):
		# User is not uploading, or there were errors uploading - then
		# give the visitor the default upload form
		if (ini_get("file_uploads")):

			$pkg_categories = pkgCategories();
?>

<?php if ($error): ?>
	<ul class="errorlist"><li><?= $error ?></li></ul>
<?php endif; ?>

<form action="<?= get_uri('/submit/'); ?>" method="post" enctype="multipart/form-data">
	<fieldset>
		<div>
			<input type="hidden" name="pkgsubmit" value="1" />
			<input type="hidden" name="ignore_missing_aurinfo" value="<?= $ignore_missing_aurinfo ?>" />
			<input type="hidden" name="token" value="<?= htmlspecialchars($_COOKIE['AURSID']) ?>" />
		</div>
		<p>
			<label for="id_category"><?= __("Package Category"); ?>:</label>
			<select id="id_category" name="category">
				<option value="1"><?= __("Select Category"); ?></option>
				<?php
					foreach ($pkg_categories as $num => $cat):
						print '<option value="' . $num . '"';
						if (isset($_POST['category']) && $_POST['category'] == $cat):
							print ' selected="selected"';
						endif;
						print '>' . $cat . '</option>';
					endforeach;
				?>
			</select>
		</p>
		<p>
			<label for="id_file"><?= __("Upload package file"); ?>:</label>
			<input id="id_file" type="file" name="pfile" size='30' />
		</p>
		<p>
			<label></label>
			<input class="button" type="submit" value="<?= __("Upload"); ?>" />
		</p>
	</fieldset>
</form>
</div>
<?php
		else:
			print __("Sorry, uploads are not permitted by this server.");
?>

<br />
</div>
<?php
		endif;
	endif;
else:
	# Visitor is not logged in
	html_header("Submit");
	print __("You must create an account before you can upload packages.");
?>

<br />

<?php
endif;
?>



<?php
html_footer(AUR_VERSION);

