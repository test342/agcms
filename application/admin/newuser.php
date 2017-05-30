<?php

require_once __DIR__ . '/../inc/Bootstrap.php';

?><!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
<title><?php echo _('Create account'); ?></title>
<link type="text/css" rel="stylesheet" href="style/style.css" />
<script type="text/javascript"><!--
function validate()
{
    if (document.getElementById('fullname').value == '' || document.getElementById('name').value == '' || document.getElementById('password').value == '') {
        alert('<?php echo _('All fields must be filled.'); ?>');
        return false;
    }
    if (document.getElementById('password').value != document.getElementById('password2').value) {
        alert('<?php echo _('The passwords does not match.'); ?>');
        return false;
    }
}
--></script>
<style type="text/css"><!--
table, td, tr {
    border-collapse:separate;
}
--></style>
</head>
<body style="margin: 20px;"><?php


?><form action="" method="post" onsubmit="return validate();">
    <table style="background-color: #DDDDDD; border: 1px solid #AAAAAA; padding: 7px; margin: auto;">
        <tr>
            <td><?php echo _('Fullname:'); ?></td>
            <td><input id="fullname" name="fullname" /></td></tr>
        <tr>
            <td><?php echo _('Username:'); ?></td>
            <td><input id="name" name="name" /></td></tr>
        <tr>
            <td><?php echo _('Password:'); ?></td>
            <td><input id="password" name="password" type="password" /></td></tr>
        <tr>
            <td><?php echo _('Repeat password:'); ?></td>
            <td><input id="password2" name="password2" type="password" /></td></tr>
        <tr>
            <td colspan="2" align="center"><input type="submit" style="margin-top:6px; width:52; height:24;" value="<?php echo _('Create account'); ?>" /></td></tr>
    </table>
</form><?php

if ($_POST) {
    if (empty($_POST['fullname']) || empty($_POST['name']) || empty($_POST['password'])) {
        die('<p style="text-align: center; margin-top: 20px;">'._('All fields must be filled.').'</p></body></html>');
    }
    if ($_POST['password'] != $_POST['password2']) {
        die('<p style="text-align: center; margin-top: 20px;">'._('The passwords does not match.').'</p></body></html>');
    }

    if (db()->fetchArray('SELECT id FROM users WHERE name = \''.addcslashes($_POST['name'], "'").'\'')) {
        die('<p style="text-align: center; margin-top: 20px;">'._('Username already taken.').'</p></body></html>');
    }

    db()->query("INSERT INTO users SET name = '" . db()->esc($_POST['name']) . "', password = '" . db()->esc(@crypt($_POST['password'])) . "', fullname = '" . db()->esc($_POST['fullname']) . "'");

    $emailbody = '<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
<title>'._('New user').'</title></head>
<body><p>'.$_POST['fullname']._(' has created an account for the administrator page. An administrator needs to confirm the accound or reject it.').'</p>

<p>Sincerely the computer</p></body>
</html>';

    sendEmails(_('New user'), $emailbody);

    echo '<p style="text-align: center; margin-top: 20px;">'._('Your account has been created. An administrator will evaluate it shortly.').'</p>';
}
?>
</body>
</html>
