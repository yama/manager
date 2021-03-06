<?php
if (IN_MANAGER_MODE != "true") {
	die("<b>INCLUDE_ORDERING_ERROR</b><br /><br />Please use the MODX Content Manager instead of accessing this file directly.");
}
if (!$modx->hasPermission('save_user')) {
	$e->setError(3);
	$e->dumpError();
}

$tbl_manager_users   = $modx->getFullTableName('manager_users');
$tbl_user_attributes = $modx->getFullTableName('user_attributes');
$tbl_member_groups   = $modx->getFullTableName('member_groups');

$input = $_POST;
foreach($input as $k=>$v) {
    if($k!=='comment') {
        $v = sanitize($v);
    }
    $input[$k] = $v;
}

$id                   = intval($input['id']);
$oldusername          = $input['oldusername'];
$newusername          = !empty ($input['newusername']) ? trim($input['newusername']) : "New User";
$esc_newusername      = $modx->db->escape($newusername);
$fullname             = $input['fullname'];
$genpassword          = $input['newpassword'];
$passwordgenmethod    = $input['passwordgenmethod'];
$passwordnotifymethod = $input['passwordnotifymethod'];
$specifiedpassword    = $input['specifiedpassword'];
$email                = $input['email'];
$esc_email            = $modx->db->escape($email);
$oldemail             = $input['oldemail'];
$phone                = $input['phone'];
$mobilephone          = $input['mobilephone'];
$fax                  = $input['fax'];
$dob                  = !empty ($input['dob']) ? ConvertDate($input['dob']) : 0;
$country              = $input['country'];
$street               = $input['street'];
$city                 = $input['city'];
$state                = $input['state'];
$zip                  = $input['zip'];
$gender               = !empty($input['gender']) ? $input['gender'] : 0;
$photo                = $input['photo'];
$comment              = $input['comment'];
$role                 = !empty($input['role']) ? $input['role'] : 0;
$failedlogincount     = !empty($input['failedlogincount']) ? $input['failedlogincount'] : 0;
$blocked              = !empty($input['blocked']) ? $input['blocked'] : 0;
$blockeduntil         = !empty($input['blockeduntil']) ? ConvertDate($input['blockeduntil']) : 0;
$blockedafter         = !empty($input['blockedafter']) ? ConvertDate($input['blockedafter']) : 0;
$user_groups          = $input['user_groups'];

// verify password
if ($passwordgenmethod == "spec" && $input['specifiedpassword'] != $input['confirmpassword']) {
	webAlert("Password typed is mismatched");
	exit;
}

// verify email
if ($email == '' || !preg_match("/^[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,6}$/i", $email)) {
	webAlert("E-mail address doesn't seem to be valid!");
	exit;
}

// verify admin security
if ($_SESSION['mgrRole'] != 1) {
	// Check to see if user tried to spoof a "1" (admin) role
	if ($role == 1) {
		webAlert("Illegal attempt to create/modify administrator by non-administrator!");
		exit;
	}
	// Verify that the user being edited wasn't an admin and the user ID got spoofed
	$rs = $modx->db->select('role', $tbl_user_attributes, "internalKey='{$id}'");
	if ($rs) {
		$limit = $modx->db->getRecordCount($rs);
		if ($limit > 0) {
			// There should only be one if there is one
			$row = $modx->db->getRow($rs);
			if ($row['role'] == 1) {
				webAlert("You cannot alter an administrative user.");
				exit;
			}
		}
	}

}

switch ($input['mode']) {
	case '11' : // new user
		// check if this user name already exist
		$rs = $modx->db->select('id', $tbl_manager_users, "username='{$esc_newusername}'");
		$limit = $modx->db->getRecordCount($rs);
		if ($limit > 0) {
			webAlert("User name is already in use!");
			exit;
		}

		// check if the email address already exist
		$rs = $modx->db->select('id', $tbl_user_attributes, "email='{$esc_email}'");
		$limit = $modx->db->getRecordCount($rs);
		if ($limit > 0) {
			$row = $modx->db->getRow($rs);
			if ($row['id'] != $id) {
				webAlert("Email is already in use!");
				exit;
			}
		}

		// generate a new password for this user
		if ($specifiedpassword != "" && $passwordgenmethod == "spec") {
			if (strlen($specifiedpassword) < 6) {
				webAlert("Password is too short!");
				exit;
			} else {
				$newpassword = $specifiedpassword;
			}
		}
		elseif ($specifiedpassword == "" && $passwordgenmethod == "spec") {
			webAlert("You didn't specify a password for this user!");
			exit;
		}
		elseif ($passwordgenmethod == 'g') {
			$newpassword = generate_password(8);
		} else {
			webAlert("No password generation method specified!");
			exit;
		}

		// invoke OnBeforeUserFormSave event
		$modx->invokeEvent("OnBeforeUserFormSave", array (
			"mode" => "new",
		));

		// create the user account
		$field = array();
		$field['username'] = $newusername;
		$field = $modx->db->escape($field);
		$internalKey = $modx->db->insert($field, $tbl_manager_users);
		if (!$internalKey) {
			webAlert("An error occurred while attempting to save the user.");
			exit;
		}

		$field['password'] = $modx->manager->genHash($newpassword, $internalKey);
		$modx->db->update($field, $tbl_manager_users, "id='{$internalKey}'");
		
		$field = compact('internalKey','fullname','role','email','phone','mobilephone','fax','zip','street','city','state','country','gender','dob','photo','comment','blocked','blockeduntil','blockedafter');
        $f = $modx->db->escape($f);
		$rs = $modx->db->insert($field, $tbl_user_attributes);
		if (!$rs) {
			webAlert("An error occurred while attempting to save the user's attributes.");
			exit;
		}

		// Save user settings
		saveUserSettings($internalKey);

		// invoke OnManagerSaveUser event
		$modx->invokeEvent("OnManagerSaveUser", array (
			"mode" => "new",
			"userid" => $internalKey,
			"username" => $newusername,
			"userpassword" => $newpassword,
			"useremail" => $email,
			"userfullname" => $fullname,
			"userroleid" => $role
		));

		// invoke OnUserFormSave event
		$modx->invokeEvent("OnUserFormSave", array (
			"mode" => "new",
			"id" => $internalKey
		));

		// Set the item name for logger
		$_SESSION['itemname'] = $newusername;

		/*******************************************************************************/
		// put the user in the user_groups he/ she should be in
		// first, check that up_perms are switched on!
		if ($use_udperms == 1) {
			if (count($user_groups) > 0) {
				for ($i = 0; $i < count($user_groups); $i++) {
					$f = array();
					$f['user_group'] = intval($user_groups[$i]);
					$f['member']     = $internalKey;
					$f = $modx->db->escape($f);
					$rs = $modx->db->insert($f, $tbl_member_groups);
					if (!$rs) {
						webAlert("An error occurred while attempting to add the user to a user_group.");
						exit;
					}
				}
			}
		}
		// end of user_groups stuff!

		if ($passwordnotifymethod == 'e') {
			sendMailMessage($email, $newusername, $newpassword, $fullname);
			if ($input['stay'] != '') {
				$a = ($input['stay'] == '2') ? "12&id={$id}" : "11";
				$header = "Location: index.php?a={$a}&r=2&stay=" . $input['stay'];
				header($header);
			} else {
				$header = "Location: index.php?a=75&r=2";
				header($header);
			}
		} else {
			if ($input['stay'] != '') {
				$a = ($input['stay'] == '2') ? "12&id={$internalKey}" : "11";
				$stayUrl = "index.php?a={$a}&r=2&stay=" . $input['stay'];
			} else {
				$stayUrl = "index.php?a=75&r=2";
			}
			
			include_once "header.inc.php";
?>
			<h1><?php echo $_lang['user_title']; ?></h1>

			<div id="actions">
			<ul class="actionButtons">
				<li><a href="<?php echo $stayUrl ?>"><img src="<?php echo $_style["icons_save"] ?>" /> <?php echo $_lang['close']; ?></a></li>
			</ul>
			</div>
            <div class="section">
			<div class="sectionHeader"><?php echo $_lang['user_title']; ?></div>
			<div class="sectionBody">
			<div id="disp">
			<p>
			<?php echo sprintf($_lang["password_msg"], $newusername, $newpassword); ?>
			</p>
			</div>
			</div>
            </div>
		<?php

			include_once "footer.inc.php";
		}
		break;

	case '12' : // edit user
		// generate a new password for this user
		if ($genpassword == 1) {
			if ($specifiedpassword != "" && $passwordgenmethod == "spec") {
				if (strlen($specifiedpassword) < 6) {
					webAlert("Password is too short!");
					exit;
				} else {
					$newpassword = $specifiedpassword;
				}
			}
			elseif ($specifiedpassword == "" && $passwordgenmethod == "spec") {
				webAlert("You didn't specify a password for this user!");
				exit;
			}
			elseif ($passwordgenmethod == 'g') {
				$newpassword = generate_password(8);
			} else {
				webAlert("No password generation method specified!");
				exit;
			}
		}
		if ($passwordnotifymethod == 'e') {
			sendMailMessage($email, $newusername, $newpassword, $fullname);
		}

		// check if the username already exist
		$rs = $modx->db->select('id', $tbl_manager_users, "username='{$esc_newusername}'");
		$limit = $modx->db->getRecordCount($rs);
		if ($limit > 0) {
			$row = $modx->db->getRow($rs);
			if ($row['id'] != $id) {
				webAlert("User name is already in use!");
				exit;
			}
		}

		// check if the email address already exists
		$rs = $modx->db->select('internalKey', $tbl_user_attributes, "email='{$esc_email}'");
		$limit = $modx->db->getRecordCount($rs);
		if ($limit > 0) {
			$row = $modx->db->getRow($rs);
			if ($row['internalKey'] != $id) {
				webAlert("Email is already in use!");
				exit;
			}
		}

		// invoke OnBeforeUserFormSave event
		$modx->invokeEvent("OnBeforeUserFormSave", array (
			"mode" => "upd",
			"id" => $id
		));

		// update user name and password
		$field = array();
		$field['username'] = $newusername;
		$field = $modx->db->escape($field);
		if($genpassword == 1) {
		    $field['password'] = $modx->manager->genHash($newpassword, $id);
		}
		$rs = $modx->db->update($field, $tbl_manager_users, "id='{$id}'");
		if (!$rs) {
			webAlert("An error occurred while attempting to update the user's data.");
			exit;
		}
		$field = array();
		$field = compact('fullname','role','email','phone','mobilephone','fax','zip','street','city','state','country','gender','dob','photo','comment','failedlogincount','blocked','blockeduntil','blockedafter');
		$field = $modx->db->escape($field);
		$rs = $modx->db->update($field, $tbl_user_attributes, "internalKey='{$id}'");
		if (!$rs) {
			webAlert("An error occurred while attempting to update the user's attributes.");
			exit;
		}

		// Save user settings
		saveUserSettings($id);

		// Set the item name for logger
		$_SESSION['itemname'] = $newusername;

		// invoke OnManagerSaveUser event
		$modx->invokeEvent("OnManagerSaveUser", array (
			"mode" => "upd",
			"userid" => $id,
			"username" => $newusername,
			"userpassword" => $newpassword,
			"useremail" => $email,
			"userfullname" => $fullname,
			"userroleid" => $role,
			"oldusername" => (($oldusername != $newusername
		) ? $oldusername : ""), "olduseremail" => (($oldemail != $email) ? $oldemail : "")));

		// invoke OnManagerChangePassword event
		if ($genpassword == 1) {
			$modx->invokeEvent("OnManagerChangePassword", array(
				"userid" => $id,
				"username" => $newusername,
				"userpassword" => $newpassword
			));
		}

		// invoke OnUserFormSave event
		$modx->invokeEvent("OnUserFormSave", array (
			"mode" => "upd",
			"id" => $id
		));

		/*******************************************************************************/
		// put the user in the user_groups he/ she should be in
		// first, check that up_perms are switched on!
		if ($use_udperms == 1) {
			// as this is an existing user, delete his/ her entries in the groups before saving the new groups
			$rs = $modx->db->delete($tbl_member_groups, "member='{$id}'");
			if (!$rs) {
				webAlert("An error occurred while attempting to delete previous user_groups entries.");
				exit;
			}
			if (count($user_groups) > 0) {
				for ($i = 0; $i < count($user_groups); $i++) {
					$f = array();
					$f['user_group'] = intval($user_groups[$i]);
					$f['member']  = $id;
					$f = $modx->db->escape($f);
					$rs = $modx->db->insert($f, $tbl_member_groups);
					if (!$rs) {
						webAlert("An error occurred while attempting to add the user to a user_group.<br />$sql;");
						exit;
					}
				}
			}
		}
		// end of user_groups stuff!
		/*******************************************************************************/
		if ($id == $modx->getLoginUserID() && ($genpassword !==1 && $passwordnotifymethod !='s')) {
?>
			<body bgcolor='#efefef'>
			<script language="JavaScript">
			alert("<?php echo $_lang["user_changeddata"]; ?>");
			top.location.href='index.php?a=8';
			</script>
			</body>
		<?php

			exit;
		}
		if ($genpassword == 1 && $passwordnotifymethod == 's') {
			if ($input['stay'] != '') {
				$a = ($input['stay'] == '2') ? "12&id={$id}" : "11";
				$stayUrl = "index.php?a={$a}&r=2&stay=" . $input['stay'];
			} else {
				$stayUrl = "index.php?a=75&r=2";
			}
			
			include_once "header.inc.php";
?>
			<h1><?php echo $_lang['user_title']; ?></h1>

			<div id="actions">
			<ul class="actionButtons">
				<li><a href="<?php echo ($id == $modx->getLoginUserID()) ? 'index.php?a=8' : $stayUrl; ?>"><img src="<?php echo $_style["icons_save"] ?>" /> <?php echo ($id == $modx->getLoginUserID()) ? $_lang['logout'] : $_lang['close']; ?></a></li>
			</ul>
			</div>
            <div class="section">
			<div class="sectionHeader"><?php echo $_lang['user_title']; ?></div>
			<div class="sectionBody">
			<div id="disp">
				<p><?php echo sprintf($_lang["password_msg"], $newusername, $newpassword).(($id == $modx->getLoginUserID()) ? ' '.$_lang['user_changeddata'] : ''); ?></p>
			</div>
			</div>
            </div>
		<?php

			include_once "footer.inc.php";
		} else {
			if ($input['stay'] != '') {
				$a = ($input['stay'] == '2') ? "12&id={$id}" : "11";
				$header = "Location: index.php?a={$a}&r=2&stay=" . $input['stay'];
				header($header);
			} else {
				$header = "Location: index.php?a=75&r=2";
				header($header);
			}
		}
		break;
	default :
		webAlert("Unauthorized access");
		exit;
}

// in case any plugins include a quoted_printable function
function save_user_quoted_printable($string) {
	$crlf = "\n" ;
	$string = preg_replace('!(\r\n|\r|\n)!', $crlf, $string) . $crlf ;
	$f[] = '/([\000-\010\013\014\016-\037\075\177-\377])/e' ;
	$r[] = "'=' . sprintf('%02X', ord('\\1'))" ; $f[] = '/([\011\040])' . $crlf . '/e' ;
	$r[] = "'=' . sprintf('%02X', ord('\\1')) . '" . $crlf . "'" ;
	$string = preg_replace($f, $r, $string) ;
	return trim(wordwrap($string, 70, ' =' . $crlf)) ;
}

// Send an email to the user
function sendMailMessage($email, $uid, $pwd, $ufn) {
	global $modx,$_lang,$signupemail_message;
	global $emailsubject, $emailsender;
	global $site_name, $site_start, $site_url;
	$message = sprintf($signupemail_message, $uid, $pwd); // use old method
	// replace placeholders
	$message = str_replace("[+uid+]", $uid, $message);
	$message = str_replace("[+pwd+]", $pwd, $message);
	$message = str_replace("[+ufn+]", $ufn, $message);
	$message = str_replace("[+sname+]", $site_name, $message);
	$message = str_replace("[+saddr+]", $emailsender, $message);
	$message = str_replace("[+semail+]", $emailsender, $message);
	$message = str_replace("[+surl+]", BOLMER_MANAGER_URL, $message);

	$param = array();
	$param['from']    = "{$site_name}<{$emailsender}>";
	$param['subject'] = $emailsubject;
	$param['body']    = $message;
	$param['to']      = $email;
	$rs = $modx->sendmail($param);
	if (!$rs) {
		webAlert("{$email} - {$_lang['error_sending_email']}");
		exit;
	}
}

// Save User Settings
function saveUserSettings($id) {
	global $modx;
    $tbl_user_settings = $modx->getFullTableName('user_settings');

	$ignore = array(
		'id',
		'oldusername',
		'oldemail',
		'newusername',
		'fullname',
		'newpassword',
		'newpasswordcheck',
		'passwordgenmethod',
		'passwordnotifymethod',
		'specifiedpassword',
		'confirmpassword',
		'email',
		'phone',
		'mobilephone',
		'fax',
		'dob',
		'country',
		'street',
		'city',
		'state',
		'zip',
		'gender',
		'photo',
		'comment',
		'role',
		'failedlogincount',
		'blocked',
		'blockeduntil',
		'blockedafter',
		'user_groups',
		'mode',
		'blockedmode',
		'stay',
		'save',
		'theme_refresher'
	);

	// determine which settings can be saved blank (based on 'default_{settingname}' POST checkbox values)
	$defaults = array(
		'upload_images',
		'upload_media',
		'upload_flash',
		'upload_files'
	);

	// get user setting field names
	$settings= array ();
	foreach ($_POST as $n => $v) {
		if (in_array($n, $ignore) || (!in_array($n, $defaults) && trim($v) == '')) continue; // ignore blacklist and empties
		$settings[$n] = $v; // this value should be saved
	}

	foreach ($defaults as $k) {
		if (isset($settings['default_'.$k]) && $settings['default_'.$k] == '1') {
			unset($settings[$k]);
		}
		unset($settings['default_'.$k]);
	}

    $esc_id = $modx->db->escape($id);
	$modx->db->delete($tbl_user_settings,"user='{$esc_id}'");

	foreach ($settings as $n => $vl) {
		if (is_array($vl)) {
			$vl = implode(",", $vl);
		}
		if ($vl != '') {
		    $f = array();
		    $f['user']          = $id;
		    $f['setting_name']  = $n;
		    $f['setting_value'] = $vl;
		    $f = $modx->db->escape($f);
		    $rs = $modx->db->insert($f, $tbl_user_settings);
			if (!$rs) {
				webAlert("Failed to update user setting!<br />User: $id, Setting: '$n', Value: '$v'");
				exit;
			}
		}
	}
}

// converts date format dd-mm-yyyy to php date
function ConvertDate($date) {
	global $modx;
	if ($date == "") {return "0";}
	else {}          {return $modx->toTimeStamp($date);}
}

// Web alert -  sends an alert to web browser
function webAlert($msg) {
	global $id, $modx;
	global $dbase, $table_prefix;
	$mode = intval($_POST['mode']);
	$url = "index.php?a={$mode}" . ($mode == '12' ? "&id={$id}" : '');
	$modx->manager->saveFormValues($mode);
	include_once "header.inc.php";
	$modx->webAlert($msg, $url);
	include_once "footer.inc.php";
}

// Generate password
function generate_password($length = 10) {
	$allowable_characters = "abcdefghjkmnpqrstuvxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789";
	$ps_len = strlen($allowable_characters);
	mt_srand((double) microtime() * 1000000);
	$pass = "";
	for ($i = 0; $i < $length; $i++) {
		$pass .= $allowable_characters[mt_rand(0, $ps_len -1)];
	}
	return $pass;
}

function sanitize($str='',$safecount=0) {
	global $modx;
	$safecount++;
	if (1000 < $safecount) {
		exit("error too many loops '{$safecount}'");
	}
	if(is_array($str)) {
		foreach($str as $i=>$v) {
			$str[$i] = sanitize($v,$safecount);
		}
	}
	else {
		$str = strip_tags($str);
		$str = htmlspecialchars($str, ENT_NOQUOTES, $modx->config['modx_charset']);
	}
	return $str;
}
