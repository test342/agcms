<?php

require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/logon.php';

$file = db()->fetchOne(
    "
    SELECT aspect, width, height
    FROM `files`
    WHERE `path` = '" . $_GET['url'] . "'"
);
?><!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
<title><?php
echo $_GET['url'];
?></title>
<style type="text/css"><!--
* {
    margin:0;
}
--></style>
</head>

<body><embed src="<?php echo $_GET['url']; ?>" width="<?php echo $file['width']; ?>" height="<?php echo $file['height']; ?>" /></body>
</html>