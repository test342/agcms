<?php

use AGCMS\Config;
use AGCMS\Entity\Brand;
use AGCMS\Entity\Category;
use AGCMS\Entity\Contact;
use AGCMS\Entity\CustomPage;
use AGCMS\Entity\File;
use AGCMS\Entity\Page;
use AGCMS\Entity\Requirement;
use AGCMS\Entity\RootCategory;
use AGCMS\ORM;
use AGCMS\Render;
use AJenbo\Image;
use Sajax\Sajax;

function checkUserLoggedIn(): void
{
    if (!empty($_SESSION['_user'])) {
        return;
    }

    if (empty($_POST['username'])) {
        sleep(1);
        header('HTTP/1.0 401 Unauthorized', true, 401);

        if (!empty($_GET['rs']) || !empty($_POST['rs'])) {
            exit(_('Your login has expired, please reload the page and login again.'));
        }

        Render::output('admin-login');
        exit;
    }

    $user = db()->fetchOne(
        "
        SELECT * FROM `users`
        WHERE `name` = '" . db()->esc($_POST['username']) . "'
        AND `access` >= 1
        "
    );
    if ($user && crypt($_POST['password'] ?? '', $user['password']) === $user['password']) {
        $_SESSION['_user'] = $user;
    }

    redirect($_SERVER['REQUEST_URI']);
}

/**
 * Optimize all tables.
 *
 * @return string Always empty
 */
function optimizeTables(): string
{
    $tables = db()->fetchArray("SHOW TABLE STATUS");
    foreach ($tables as $table) {
        db()->query("OPTIMIZE TABLE `" . $table['Name'] . "`");
    }

    return '';
}

/**
 * Remove newletter submissions that are missing vital information.
 *
 * @return string Always empty
 */
function removeBadSubmisions(): string
{
    db()->query(
        "
        DELETE FROM `email`
        WHERE `email` = ''
          AND `adresse` = ''
          AND `tlf1` = ''
          AND `tlf2` = '';
        "
    );

    return '';
}

/**
 * Delete bindings where either page or category is missing.
 *
 * @return string Always empty
 */
function removeBadBindings(): string
{
    db()->query(
        "
        DELETE FROM `bind`
        WHERE (kat != 0 AND kat != -1
             AND NOT EXISTS (SELECT id FROM kat   WHERE id = bind.kat)
            ) OR NOT EXISTS (SELECT id FROM sider WHERE id = bind.side);
        "
    );

    return '';
}

/**
 * Remove bad tilbehor bindings.
 *
 * @return string Always empty
 */
function removeBadAccessories(): string
{
    db()->query(
        "
        DELETE FROM `tilbehor`
        WHERE NOT EXISTS (SELECT id FROM sider WHERE tilbehor.side)
           OR NOT EXISTS (SELECT id FROM sider WHERE tilbehor.tilbehor);
        "
    );

    return '';
}

/**
 * Remove enteries for files that do no longer exist.
 *
 * @return string Always empty
 */
function removeNoneExistingFiles(): string
{
    $files = db()->fetchArray("SELECT id, path FROM `files`");

    $missing = [];
    foreach ($files as $files) {
        if (!is_file(_ROOT_ . $files['path'])) {
            $missing[] = (int) $files['id'];
        }
    }
    if ($missing) {
        db()->query("DELETE FROM `files` WHERE `id` IN(" . implode(",", $missing) . ")");
    }

    return '';
}

function sendDelayedEmail(): string
{
    //Get emails that needs sending
    $emails = db()->fetchArray("SELECT * FROM `emails`");
    $cronStatus = ORM::getOne(CustomPage::class, 0);
    if (!$emails) {
        $cronStatus->save();

        return '';
    }

    $emailsSendt = 0;
    $emailCount = count($emails);
    foreach ($emails as $email) {
        $email['from'] = explode('<', $email['from']);
        $email['from'][1] = mb_substr($email['from'][1], 0, -1);
        $email['to'] = explode('<', $email['to']);
        $email['to'][1] = mb_substr($email['to'][1], 0, -1);

        $success = sendEmails(
            $email['subject'],
            $email['body'],
            $email['from'][1],
            $email['from'][0],
            $email['to'][1],
            $email['to'][0],
            false
        );
        if (!$success) {
            continue;
        }

        ++$emailsSendt;

        db()->query("DELETE FROM `emails` WHERE `id` = " . (int) $email['id']);
    }

    $cronStatus->save();

    $msg = ngettext(
        '%d of %d e-mail was sent.',
        '%d of %d e-mails was sent.',
        $emailsSendt
    );

    return sprintf($msg, $emailsSendt, $emailCount);
}

/**
 * Convert PHP size string to bytes.
 *
 * @param string $val PHP size string (eg. '2M')
 *
 * @return int Byte size
 */
function returnBytes(string $val): int
{
    $last = mb_substr($val, -1);
    $last = mb_strtolower($last);
    $val = mb_substr($val, 0, -1);
    switch ($last) {
        case 'g':
            $val *= 1024;
            /*keep going*/
        case 'm':
            $val *= 1024;
            /*keep going*/
        case 'k':
            $val *= 1024;
    }

    return $val;
}

function get_mime_type(string $filepath): string
{
    $mime = '';
    if (function_exists('finfo_file')) {
        $mime = finfo_file($finfo = finfo_open(FILEINFO_MIME), $filepath);
        finfo_close($finfo);
    }
    if (!$mime && function_exists('mime_content_type')) {
        $mime = mime_content_type($filepath);
    }

    //Some types can't be trusted, and finding them via extension seams to give better resutls.
    $unknown = ['text/plain', 'application/msword', 'application/octet-stream'];
    if (!$mime || in_array($mime, $unknown, true)) {
        $mimes = [
            'doc'   => 'application/msword',
            'dot'   => 'application/msword',
            'eps'   => 'application/postscript',
            'hqx'   => 'application/mac-binhex40',
            'pdf'   => 'application/pdf',
            'ai'    => 'application/postscript',
            'ps'    => 'application/postscript',
            'pps'   => 'application/vnd.ms-powerpoint',
            'ppt'   => 'application/vnd.ms-powerpoint',
            'xlb'   => 'application/vnd.ms-excel',
            'xls'   => 'application/vnd.ms-excel',
            'xlt'   => 'application/vnd.ms-excel',
            'zip'   => 'application/zip',
            '7z'    => 'application/x-7z-compressed',
            'sit'   => 'application/x-stuffit',
            'swf'   => 'application/x-shockwave-flash',
            'swfl'  => 'application/x-shockwave-flash',
            'tar'   => 'application/x-tar',
            'taz'   => 'application/x-gtar',
            'tgz'   => 'application/x-gtar',
            'gtar'  => 'application/x-gtar',
            'gz'    => 'application/x-gzip',
            'kar'   => 'audio/midi',
            'mid'   => 'audio/midi',
            'midi'  => 'audio/midi',
            'm4a'   => 'audio/mpeg',
            'mp2'   => 'audio/mpeg',
            'mp3'   => 'audio/mpeg',
            'mpega' => 'audio/mpeg',
            'mpga'  => 'audio/mpeg',
            'wav'   => 'audio/x-wav',
            'wma'   => 'audio/x-ms-wma',
            'bmp'   => 'image/x-ms-bmp',
            'psd'   => 'image/x-photoshop',
            'tiff'  => 'image/tiff',
            'tif'   => 'image/tiff',
            'css'   => 'text/css',
            'asc'   => 'text/plain',
            'diff'  => 'text/plain',
            'pot'   => 'text/plain',
            'text'  => 'text/plain',
            'txt'   => 'text/plain',
            'html'  => 'text/html',
            'htm'   => 'text/html',
            'shtml' => 'text/html',
            'rtf'   => 'text/rtf',
            'asf'   => 'video/x-ms-asf',
            'asx'   => 'video/x-ms-asf',
            'avi'   => 'video/x-msvideo',
            'flv'   => 'video/x-flv',
            'mov'   => 'video/quicktime',
            'mpeg'  => 'video/mpeg',
            'mpe'   => 'video/mpeg',
            'mpg'   => 'video/mpeg',
            'qt'    => 'video/quicktime',
            'wm'    => 'video/x-ms-wm',
            'wmv'   => 'video/x-ms-wmv',
        ];
        $mime = 'application/octet-stream';
        $extension = mb_strtolower(pathinfo($filepath, PATHINFO_EXTENSION));
        if (isset($mimes[$extension])) {
            $mime = $mimes[$extension];
        }
    }

    $mime = explode(';', $mime);

    return array_shift($mime);
}

/**
 * @return array|true
 */
function sendEmail(int $id, string $from, string $interests, string $subject, string $text)
{
    if (!db()->fetchArray('SELECT `id` FROM `newsmails` WHERE `sendt` = 0')) {
        //Nyhedsbrevet er allerede afsendt!
        return ['error' => _('The newsletter has already been sent!')];
    }

    $text = purifyHTML($text);
    $text = htmlUrlDecode($text);

    saveEmail($id, $from, $interests, $subject, $text);

    //Colect interests
    if ($interests) {
        $interests = explode('<', $interests);
        $andwhere = '';
        foreach ($interests as $interest) {
            if ($andwhere) {
                $andwhere .= ' OR ';
            }
            $andwhere .= '`interests` LIKE \'';
            $andwhere .= $interest;
            $andwhere .= '\' OR `interests` LIKE \'';
            $andwhere .= $interest;
            $andwhere .= '<%\' OR `interests` LIKE \'%<';
            $andwhere .= $interest;
            $andwhere .= '\' OR `interests` LIKE \'%<';
            $andwhere .= $interest;
            $andwhere .= '<%\'';
        }
        $andwhere = ' AND (' . $andwhere;
        $andwhere .= ')';
    }

    $emails = db()->fetchArray(
        'SELECT navn, email
        FROM `email`
        WHERE `email` NOT LIKE \'\'
          AND `kartotek` = \'1\' ' . $andwhere . '
        GROUP BY `email`'
    );
    foreach ($emails as $x => $email) {
        $emailsGroup[floor($x / 99) + 1][] = $email;
    }

    $data = [
        'siteName' => Config::get('site_name'),
        'css' => file_get_contents(_ROOT_ . '/theme/' . Config::get('theme', 'default') . '/style/email.css'),
        'body' => str_replace(' href="/', ' href="' . Config::get('base_url') . '/', $text),
    ];

    $error = '';
    foreach ($emailsGroup as $of => $emails) {
        $success = sendEmails(
            $subject,
            Render::render('email-newsletter', $data),
            $from,
            '',
            '',
            '',
            true,
            $emails
        );

        if (!$success) {
            //TODO upload if send fails
            $error .= 'Email ' . $of . '/' . count($emails) . ' failed to be sent.' . "\n";
        }
    }

    if ($error) {
        return ['error' => trim($error)];
    }

    db()->query("UPDATE `newsmails` SET `sendt` = 1 WHERE `id` = " . (int) $id);

    return true;
}

function countEmailTo(array $interests): int
{
    //Colect interests
    $andwhere = '';
    if ($interests) {
        foreach ($interests as $interest) {
            if ($andwhere) {
                $andwhere .= ' OR ';
            }
            $andwhere .= '`interests` LIKE \'';
            $andwhere .= $interest;
            $andwhere .= '\' OR `interests` LIKE \'';
            $andwhere .= $interest;
            $andwhere .= '<%\' OR `interests` LIKE \'%<';
            $andwhere .= $interest;
            $andwhere .= '\' OR `interests` LIKE \'%<';
            $andwhere .= $interest;
            $andwhere .= '<%\'';
        }
        $andwhere = ' AND (' . $andwhere . ')';
    }

    $emails = db()->fetchOne(
        "
        SELECT count(DISTINCT email) as 'count'
        FROM `email`
        WHERE `email` NOT LIKE '' AND `kartotek` = '1'
        " . $andwhere
    );

    return $emails['count'];
}

function saveEmail(int $id, string $from, string $interests, string $subject, string $text): bool
{
    if (!$id) {
        db()->query(
            "
            INSERT INTO `newsmails` (`from`, `interests`, `subject`, `text`)
            VALUES (
                '" . db()->esc($from) . "',
                '" . db()->esc($interests) . "',
                '" . db()->esc($subject) . "',
                '" . db()->esc($text) . "'
            )
            "
        );

        return true;
    }

    db()->query(
        "UPDATE `newsmails`
        SET `from` = '" . db()->esc($from) . "',
        `interests` = '" . db()->esc($interests) . "',
        `subject` = '" . db()->esc($subject) . "',
        `text` = '" . db()->esc($text) . "'
        WHERE `id` = " . $id
    );

    return true;
}

function kattree(int $id): array
{
    $kat = db()->fetchOne("SELECT id, navn, bind FROM `kat` WHERE id = " . $id);

    $kattree = [];
    $id = null;
    if ($kat) {
        $id = $kat['bind'];
        $kattree[] = [
            'id' => $kat['id'],
            'navn' => $kat['navn'],
        ];

        while ($kat['bind'] > 0) {
            $kat = db()->fetchOne("SELECT id, navn, bind FROM `kat` WHERE id = " . $kat['bind']);
            $id = $kat['bind'];
            $kattree[]['id'] = $kat['id'];
            $kattree[count($kattree) - 1]['navn'] = $kat['navn'];
        }
    }

    $kattree[]['id'] = $id ? 1 : 0;
    $kattree[count($kattree) - 1]['navn'] = $id ? _('Inactive') : _('Frontpage');

    return array_reverse($kattree);
}

function katspath(int $id): array
{
    $html = _('Select location:') . ' ';
    foreach (kattree($id) as $kat) {
        $html .= '/' . trim($kat['navn']);
    }
    $html .= '/';

    return ['id' => 'katsheader', 'html' => $html];
}

function getOpenCategories(): array
{
    $activeCategoryId = max($_COOKIE['activekat'] ?? -1, -1);
    $openCategories = explode('<', $_COOKIE['openkat'] ?? '');
    $openCategories = array_map('intval', $openCategories);
    $openCategories = array_flip($openCategories);
    foreach (kattree($activeCategoryId) as $i => $value) {
        $openCategories[$value['id']] = true;
    }

    return $openCategories;
}

function getCategoryRootStructure(bool $includePages = false): array
{
    $openCategories = getOpenCategories();
    $categories = [];
    foreach ([-1 => _('Inactive'), 0 => _('Frontpage')] as $id => $name) {
        $pageSql = "";
        if ($includePages) {
            $pageSql = " UNION SELECT id FROM `bind` WHERE kat = " . $id;
        }

        $subs = [];
        $pages = [];
        if (isset($openCategories[$id])) {
            $subs = getCategoryStructure($id, $openCategories, $includePages);
            if ($includePages) {
                $pages = getCategoryStructurePages($id);
            }
        }

        $categories[] = [
            'id'         => $id,
            'hasContent' => (bool) db()->fetchOne("SELECT id FROM `kat` WHERE bind = " . $id . $pageSql),
            'subs'       => $subs,
            'pages'      => $pages,
            'icon'       => '',
            'title'      => $name,
        ];
    }

    return $categories;
}

function getCategoryStructure(int $id, array $openCategories = [], bool $includePages = false): array
{
    $pageSql = "'0'";
    if ($includePages) {
        $pageSql = "EXISTS(SELECT * FROM `bind` WHERE kat = kat.id)";
    }
    $categories = db()->fetchArray(
        "
        SELECT kat.id,
            kat.icon,
            kat.navn title,
            EXISTS(SELECT * FROM `kat` child WHERE kat.id = child.bind) or $pageSql hasContent
        FROM `kat` WHERE bind = " . $id . " ORDER BY `order`, `navn`
        "
    );

    foreach ($categories as $index => $category) {
        if (isset($openCategories[$category['id']])) {
            $categories[$index]['subs'] = getCategoryStructure($category['id'], $openCategories, $includePages);
            $categories[$index]['pages'] = $includePages ? getCategoryStructurePages($category['id']) : [];
        }
    }

    return $categories;
}

function getCategoryStructurePages(int $categoryId, string $orderBy = 'sider.navn'): array
{
    return db()->fetchArray(
        "
        SELECT sider.*, bind.id as bind
        FROM `bind` LEFT JOIN sider on bind.side = sider.id
        WHERE `kat` = " . $categoryId . " ORDER BY " . $orderBy
    );
}

function kat_expand(int $id, bool $includePages = false, string $input = ''): array
{
    $openCategories = getOpenCategories();
    $data = [
        'categories'        => getCategoryStructure($id, $openCategories, $includePages),
        'pages'             => $includePages ? getCategoryStructurePages($id) : [],
        'categoryBranchIds' => [],
        'openCategories'    => explode('<', $_COOKIE['openkat'] ?? ''),
        'input'             => $input,
        'includePages'      => $includePages,
    ];

    $html = Render::render('partial-admin-kat_expand', $data);

    return ['id' => $id, 'html' => $html];
}

/**
 * Check if file is in use.
 */
function isinuse(string $path): bool
{
    return (bool) db()->fetchOne(
        "
        (
            SELECT id FROM `sider`
            WHERE `text` LIKE '%$path%' OR `beskrivelse` LIKE '%$path%' OR `billed`
            LIKE '$path' LIMIT 1
        )
        UNION (
            SELECT id FROM `template`
            WHERE `text` LIKE '%$path%' OR `beskrivelse` LIKE '%$path%' OR `billed`
            LIKE '$path' LIMIT 1
        )
        UNION (SELECT id FROM `special` WHERE `text` LIKE '%$path%' LIMIT 1)
        UNION (SELECT id FROM `krav` WHERE `text` LIKE '%$path%' LIMIT 1)
        UNION (SELECT id FROM `maerke` WHERE `ico` LIKE '$path' LIMIT 1)
        UNION (SELECT id FROM `list_rows` WHERE `cells` LIKE '%$path%' LIMIT 1)
        UNION (SELECT id FROM `kat` WHERE `navn` LIKE '%$path%' OR `icon` LIKE '$path' LIMIT 1)
        "
    );
}

/**
 * Delete unused file.
 */
function deletefile(int $id, string $path): array
{
    if (isinuse($path)) {
        return ['error' => _('The file can not be deleted because it is used on a page.')];
    }
    $file = File::getByPath($path);
    if ($file && $file->delete()) {
        return ['id' => $id];
    }

    return ['error' => _('There was an error deleting the file, the file may be in use.')];
}

/**
 * Takes a string and changes it to comply with file name restrictions in windows, linux, mac and urls (UTF8)
 * .|"'´`:%=#&\/+?*<>{}-_.
 */
function genfilename(string $filename): string
{
    $search = ['/[.&?\/:*"\'´`<>{}|%\s-_=+#\\\\]+/u', '/^\s+|\s+$/u', '/\s+/u'];
    $replace = [' ', '', '-'];

    return mb_strtolower(preg_replace($search, $replace, $filename), 'UTF-8');
}

/**
 * return true for directorys and false for every thing else.
 */
function is_dirs(string $path): bool
{
    if (is_file(_ROOT_ . $path)
        || $path == '.'
        || $path == '..'
    ) {
        return false;
    }

    return true;
}

/**
 * return list of folders in a folder.
 */
function getSubDirs(string $path): array
{
    $dirs = [];
    $iterator = new DirectoryIterator(_ROOT_ . $path);
    foreach ($iterator as $fileinfo) {
        if ($fileinfo->isDot() || !$fileinfo->isDir()) {
            continue;
        }
        $dirs[] = $fileinfo->getFilename();
    }

    natcasesort($dirs);
    $dirs = array_values($dirs);

    foreach ($dirs as $index => $dir) {
        $dirs[$index] = formatDir($path . '/' . $dir, $dir);
    }

    return $dirs;
}

function hasSubsDirs(string $path): bool
{
    $iterator = new DirectoryIterator(_ROOT_ . $path);
    foreach ($iterator as $fileinfo) {
        if (!$fileinfo->isDot() && $fileinfo->isDir()) {
            return true;
        }
    }

    return false;
}

function getRootDirs(): array
{
    $dirs = [];
    foreach (['/images' => _('Images'), '/files' => _('Files')] as $path => $name) {
        $dirs[] = formatDir($path, $name);
    }

    return $dirs;
}

function formatDir(string $path, string $name): array
{
    $subs = [];
    $hassubs = false;
    if (empty($_COOKIE[$path])) {
        $hassubs = hasSubsDirs($path);
    } else {
        $subs = getSubDirs($path);
        $hassubs = (bool) $subs;
    }

    return [
        'id'      => preg_replace('#/#u', '.', $path),
        'path'    => $path,
        'name'    => $name,
        'hassubs' => $hassubs,
        'subs'    => $subs,
    ];
}

//TODO document type does not allow element "input" here; missing one of "p", "h1", "h2", "h3", "h4", "h5", "h6", "div", "pre", "address", "fieldset", "ins", "del" start-tag.
/**
 * Display a list of directorys for the explorer.
 */
function listdirs(string $path, bool $move = false): array
{
    $html = Render::render(
        'partial-admin-listDirs',
        [
            'dirs' => getSubDirs($path),
            'move' => $move,
        ]
    );

    return ['id' => $path, 'html' => $html];
}

/**
 * Update user.
 *
 * @param int   $id      User id
 * @param array $updates Array of values to change
 *                       'access' int 0 = no acces, 1 = admin, 3 = priviliged, 4 = clerk
 *                       'password' crypt(string)
 *                       'password_new' string
 *                       'fullname' string
 *                       'name' string
 *                       'lastlogin' MySQL time stamp
 *
 * @return array|true True on update, else ['error' => string]
 */
function updateuser(int $id, array $updates)
{
    if ($_SESSION['_user']['access'] != 1 && $_SESSION['_user']['id'] != $id) {
        return ['error' => _('You do not have the requred access level to change this user.')];
    }

    //Validate access lavel update
    if ($_SESSION['_user']['id'] == $id && $updates['access'] != $_SESSION['_user']['access']) {
        return ['error' => _('You can\'t change your own access level')];
    }

    //Validate password update
    if (!empty($updates['password_new'])) {
        if ($_SESSION['_user']['access'] == 1 && $_SESSION['_user']['id'] != $id) {
            $updates['password'] = crypt($updates['password_new']);
        } elseif ($_SESSION['_user']['id'] == $id) {
            $user = db()->fetchOne('SELECT `password` FROM `users` WHERE id = ' . $id);
            if (mb_substr($user['password'], 0, 13) !== mb_substr(crypt($updates['password'], $user['password']), 0, 13)) {
                return ['error' => _('Incorrect password.')];
            }
            $updates['password'] = crypt($updates['password_new']);
        } else {
            return [
                'error' => _('You do not have the requred access level to change the password for other users.'),
            ];
        }
    } else {
        unset($updates['password']);
    }
    unset($updates['password_new']);

    //Generate SQL command
    $sql = "UPDATE `users` SET";
    foreach ($updates as $key => $value) {
        $sql .= ' `' . addcslashes($key, '`\\') . "` = '" . db()->esc($value) . "',";
    }
    $sql = mb_substr($sql, 0, -1);
    $sql .= ' WHERE `id` = ' . (int) $id;

    //Run SQL
    db()->query($sql);

    return true;
}

function saveImage(
    string $path,
    int $cropX,
    int $cropY,
    int $cropW,
    int $cropH,
    int $maxW,
    int $maxH,
    int $flip,
    int $rotate,
    string $filename,
    bool $force
): array {
    $mimeType = get_mime_type(_ROOT_ . $path);

    $output = ['type' => 'png'];
    if ($mimeType === 'image/jpeg') {
        $output['type'] = 'jpg';
    }

    $output['filename'] = $filename;
    $output['force'] = $force;

    return generateImage(_ROOT_ . $path, $cropX, $cropY, $cropW, $cropH, $maxW, $maxH, $flip, $rotate, $output);
    //TODO close and update image in explorer
}

/**
 * Delete user.
 */
function deleteuser(int $id): bool
{
    if ($_SESSION['_user']['access'] != 1 || $_SESSION['_user']['id'] == $id) {
        return false;
    }

    db()->query("DELETE FROM `users` WHERE `id` = " . (int) $id);
    return true;
}

function fileExists(string $filename, string $type = ''): bool
{
    $pathinfo = pathinfo($filename);
    $filePath = _ROOT_ . ($_COOKIE['admin_dir'] ?? '') . '/' . genfilename($pathinfo['filename']);

    if ($type == 'image') {
        $filePath .= '.jpg';
    } elseif ($type == 'lineimage') {
        $filePath .= '.png';
    } else {
        $filePath .= '.' . $pathinfo['extension'];
    }

    return (bool) is_file($filePath);
}

function newfaktura(): int
{
    db()->query(
        "
        INSERT INTO `fakturas` (`date`, `clerk`)
        VALUES (
            NOW(),
            " . db()->eandq($_SESSION['_user']['fullname']) . "
        );
        "
    );

    return db()->insert_id;
}

function getPricelistRootStructure(string $sort, int $categoryId = null): array
{
    $categories = [];
    foreach ([-1 => _('Inactive'), 0 => _('Frontpage')] as $id => $name) {
        if ($categoryId !== null && $categoryId !== $id) {
            continue;
        }

        $categories[] = new RootCategory(['id' => $id, 'title' => $name]);
    }

    return $categories;
}

/**
 * Returns false for files that the users shoudn't see in the files view.
 */
function isVisableFile(string $fileName): bool
{
    global $dir;
    if (mb_substr($fileName, 0, 1) === '.' || is_dir(_ROOT_ . $dir . '/' . $fileName)) {
        return false;
    }

    return true;
}

/**
 * display a list of files in the selected folder.
 */
function showfiles(string $tempDir): array
{
    //temp_dir is needed to initialize dir as global
    //$dir needs to be global for other functions like isVisableFiles()
    global $dir;
    $dir = $tempDir;
    unset($tempDir);
    $html = '';
    $javascript = '';

    $files = [];
    if ($files = scandir(_ROOT_ . $dir)) {
        $files = array_filter($files, 'isVisableFile');
        natcasesort($files);
    }

    foreach ($files as $fileName) {
        $filePath = $dir . '/' . $fileName;
        $file = File::getByPath($filePath);
        if (!$file) {
            $file = File::fromPath($filePath)->save();
        }

        $html .= filehtml($file);
        //TODO reduce net to javascript
        $javascript .= filejavascript($file);
    }

    return ['id' => 'files', 'html' => $html, 'javascript' => $javascript];
}

function filejavascript(File $file): string
{
    $pathinfo = pathinfo($file->getPath());

    $javascript = '
    files[' . $file->getId() . '] = new file(' . $file->getId() . ', \'' . $file->getPath() . '\', \''
        . $pathinfo['filename'] . '\'';

    $javascript .= ', \'';
    switch ($file->getMime()) {
        case 'image/jpeg':
        case 'image/png':
        case 'image/gif':
            $javascript .= 'image';
            break;
        case 'video/x-flv':
            $javascript .= 'flv';
            break;
        case 'video/x-shockwave-flash':
        case 'application/x-shockwave-flash':
        case 'application/futuresplash':
            $javascript .= 'swf';
            break;
        case 'video/avi':
        case 'video/x-msvideo':
        case 'video/mpeg':
        case 'audio/mpeg':
        case 'video/quicktime':
        case 'video/x-ms-asf':
        case 'video/x-ms-wmv':
        case 'audio/x-wav':
        case 'audio/midi':
        case 'audio/x-ms-wma':
            $javascript .= 'video';
            break;
        default:
            $javascript .= 'unknown';
            break;
    }
    $javascript .= '\'';

    $javascript .= ', \'' . addcslashes(@$file->getDescription(), "\\'") . '\'';
    $javascript .= ', ' . ($file->getWidth() ?: '0') . '';
    $javascript .= ', ' . ($file->getHeight() ?: '0') . '';
    $javascript .= ');';

    return $javascript;
}

function filehtml(File $file): string
{
    $pathinfo = pathinfo($file->getPath());

    $html = '';

    switch ($file->getMime()) {
        case 'image/gif':
        case 'image/jpeg':
        case 'image/png':
            $html .= '<div id="tilebox' . $file->getId() . '" class="imagetile"><div class="image"';
            if ($_GET['return'] == 'rtef') {
                $html .= ' onclick="addimg(' . $file->getId() . ')"';
            } elseif ($_GET['return'] == 'thb') {
                if ($file->getWidth() <= Config::get('thumb_width')
                    && $file->getHeight() <= Config::get('thumb_height')
                ) {
                    $html .= ' onclick="insertThumbnail(' . $file->getId() . ')"';
                } else {
                    $html .= ' onclick="open_image_thumbnail(' . $file->getId() . ')"';
                }
            } else {
                $html .= ' onclick="files[' . $file->getId() . '].openfile();"';
            }
            break;
        case 'video/x-flv':
            $html .= '<div id="tilebox' . $file->getId() . '" class="flvtile"><div class="image"';
            if ($_GET['return'] == 'rtef') {
                if ($file->getAspect() == '4-3') {
                    $html .= ' onclick="addflv(' . $file->getId() . ', \'' . $file->getAspect() . '\', '
                        . max($file->getWidth(), $file->getHeight() / 3 * 4) . ', '
                        . ceil($file->getWidth() / 4 * 3 * 1.1975) . ')"';
                } elseif ($file->getAspect() == '16-9') {
                    $html .= ' onclick="addflv(' . $file->getId() . ', \'' . $file->getAspect() . '\', '
                        . max($file->getWidth(), $file->getHeight() / 9 * 16) . ', '
                        . ceil($file->getWidth() / 16 * 9 * 1.2) . ')"';
                }
            } else {
                $html .= ' onclick="files[' . $file->getId() . '].openfile();"';
            }
            break;
        case 'application/futuresplash':
        case 'application/x-shockwave-flash':
        case 'video/x-shockwave-flash':
            $html .= '<div id="tilebox' . $file->getId() . '" class="swftile"><div class="image"';
            if ($_GET['return'] == 'rtef') {
                $html .= ' onclick="addswf(' . $file->getId() . ', ' . $file->getWidth() . ', ' . $file->getHeight() . ')"';
            } else {
                $html .= ' onclick="files[' . $file->getId() . '].openfile();"';
            }
            break;
        case 'audio/midi':
        case 'audio/mpeg':
        case 'audio/x-ms-wma':
        case 'audio/x-wav':
        case 'video/avi':
        case 'video/mpeg':
        case 'video/quicktime':
        case 'video/x-ms-asf':
        case 'video/x-msvideo':
        case 'video/x-ms-wmv':
            $html .= '<div id="tilebox' . $file->getId() . '" class="videotile"><div class="image"';
            //TODO make the actual functions
            if ($_GET['return'] == 'rtef') {
                $html .= ' onclick="addmedia(' . $file->getId() . ')"';
            } else {
                $html .= ' onclick="files[' . $file->getId() . '].openfile();"';
            }
            break;
        default:
            $html .= '<div id="tilebox' . $file->getId() . '" class="filetile"><div class="image"';
            if ($_GET['return'] == 'rtef') {
                $html .= ' onclick="addfile(' . $file->getId() . ')"';
            } else {
                $html .= ' onclick="files[' . $file->getId() . '].openfile();"';
            }
            break;
    }

    $html .= '> <img src="';

    $type = 'bin';
    switch ($file->getMime()) {
        case 'image/gif':
        case 'image/jpeg':
        case 'image/png':
        case 'image/vnd.wap.wbmp':
            $type = 'image-native';
            break;
        case 'application/pdf':
            $type = 'pdf';
            break;
        case 'application/postscript':
            $type = 'image';
            break;
        case 'application/futuresplash':
        case 'application/vnd.ms-powerpoint':
        case 'application/vnd.rn-realmedia':
        case 'application/x-shockwave-flash':
            $type = 'video';
            break;
        case 'application/msword':
        case 'application/rtf':
        case 'application/vnd.ms-excel':
        case 'application/vnd.ms-works':
            $type = 'text';
            break;
        case 'text/css':
        case 'text/html':
            $type = 'sys';
            break;
        case 'application/mac-binhex40':
        case 'application/x-7z-compressed':
        case 'application/x-bzip2':
        case 'application/x-compressed': //missing
        case 'application/x-compress': //missing
        case 'application/x-gtar':
        case 'application/x-gzip':
        case 'application/x-rar':
        case 'application/x-rar-compressed':
        case 'application/x-stuffit':
        case 'application/x-stuffitx':
        case 'application/x-tar':
        case 'application/x-zip':
        case 'application/zip':
            $type = 'zip';
            break;
        default:
            $type = explode('/', $file->getMime());
            $type = array_shift($type);
            break;
    }

    switch ($type) {
        case 'image-native':
            $html .= 'image.php?path=' . rawurlencode($pathinfo['dirname'] . '/' . $pathinfo['basename'])
                . '&amp;maxW=128&amp;maxH=96';
            break;
        case 'pdf':
        case 'image':
        case 'video':
        case 'audio':
        case 'text':
        case 'sys':
        case 'zip':
            $html .= 'images/file-' . $type . '.gif';
            break;
        default:
            $html .= 'images/file-bin.gif';
            break;
    }

    $html .= '" alt="" title="" /> </div><div ondblclick="showfilename(' . $file->getId() . ')" class="navn" id="navn'
        . $file->getId() . 'div" title="' . $pathinfo['filename'] . '"> ' . $pathinfo['filename']
        . '</div><form action="" method="get" onsubmit="document.getElementById(\'files\').focus();return false;" style="display:none" id="navn'
        . $file->getId() . 'form"><p><input onblur="renamefile(\'' . $file->getId() . '\');" maxlength="'
        . (251 - mb_strlen($pathinfo['dirname'], 'UTF-8')) . '" value="' . $pathinfo['filename']
        . '" name="" /></p></form></div>';

    return $html;
}

function makedir(string $name): array
{
    $name = genfilename($name);
    $adminDir = $_COOKIE['admin_dir'] ?? '';
    if (is_dir(_ROOT_ . $adminDir . '/' . $name)) {
        return ['error' => _('A file or folder with the same name already exists.')];
    }

    if (!is_dir(_ROOT_ . $adminDir)
        || !mkdir(_ROOT_ . $adminDir . '/' . $name, 0771)
    ) {
        return ['error' => _('Could not create folder, you may not have sufficient rights to this folder.')];
    }

    return ['error' => false];
}

//TODO if force, refresh folder or we might have duplicates displaying in the folder.
//TODO Error out if the files is being moved to it self
//TODO moving two files to the same dire with no reload inbetwean = file exists?????????????
/**
 * Rename or relocate a file/directory.
 */
function renamefile(int $id, string $path, string $dir, string $filename, bool $force = false): array
{
    $pathinfo = pathinfo($path);
    if ($pathinfo['dirname'] == '/') {
        $pathinfo['dirname'] == '';
    }

    if (!$dir) {
        $dir = $pathinfo['dirname'];
    } elseif ($dir == '/') {
        $dir == '';
    }

    $pathinfo['extension'] = '';
    if (!is_dir(_ROOT_ . $path)) {
        $mime = get_mime_type(_ROOT_ . $path);
        if ($mime == 'image/jpeg') {
            $pathinfo['extension'] = 'jpg';
        } elseif ($mime == 'image/png') {
            $pathinfo['extension'] = 'png';
        } elseif ($mime == 'image/gif') {
            $pathinfo['extension'] = 'gif';
        } elseif ($mime == 'application/pdf') {
            $pathinfo['extension'] = 'pdf';
        } elseif ($mime == 'video/x-flv') {
            $pathinfo['extension'] = 'flv';
        } elseif ($mime == 'image/vnd.wap.wbmp') {
            $pathinfo['extension'] = 'wbmp';
        }
    } else {
        //a folder with a . will mistakingly be seen as a file with extension
        $pathinfo['filename'] .= '-' . @$pathinfo['extension'];
    }

    if (!$filename) {
        $filename = $pathinfo['filename'];
    }

    $filename = genfilename($filename);

    if (!$filename) {
        return ['error' => _('The name is invalid.'), 'id' => $id];
    }

    //Destination folder doesn't exist
    if (!is_dir(_ROOT_ . $dir . '/')) {
        return [
            'error' => _('The file could not be moved because the destination folder does not exist.'),
            'id' => $id,
        ];
    }
    if ($pathinfo['extension']) {
        //No changes was requested.
        $newPath = $dir . '/' . $filename . '.' . $pathinfo['extension'];
        if ($path === $newPath) {
            return ['id' => $id, 'filename' => $filename, 'path' => $path];
        }

        //if file path more then 255 erturn error
        if (mb_strlen($newPath, 'UTF-8') > 255) {
            return ['error' => _('The filename is too long.'), 'id' => $id];
        }

        //File already exists, but are we trying to force a overwrite?
        if (is_file(_ROOT_ . $newPath) && !$force) {
            return ['yesno' => _('A file with the same name already exists.
Would you like to replace the existing file?'), 'id' => $id];
        }

        //Rename/move or give an error
        if (@rename(_ROOT_ . $path, _ROOT_ . $newPath)) {
            if ($force) {
                db()->query("DELETE FROM files WHERE `path` = '" . db()->esc($newPath) . "'");
            }

            db()->query(
                "UPDATE files SET path = '" . db()->esc($newPath) . "' WHERE `path` = '" . db()->esc($path) . "'"
            );
            replacePaths($path, $newPath);

            return ['id' => $id, 'filename' => $filename, 'path' => $newPath];
        }

        return ['error' => _('An error occurred with the file operations.'), 'id' => $id];
    }

    //Dir or file with no extension
    //TODO ajax rename folder
    $newPath = $dir . '/' . $filename . '.' . $pathinfo['extension'];
    //No changes was requested.
    if ($path == $newPath) {
        return ['id' => $id, 'filename' => $filename, 'path' => $path];
    }

    //folder already exists
    if (is_dir(_ROOT_ . $dir . '/' . $filename)) {
        return ['error' => _('A folder with the same name already exists.'), 'id' => $id];
    }

    //if file path more then 255 erturn error
    if (mb_strlen($newPath, 'UTF-8') > 255) {
        return ['error' => _('The filename is too long.'), 'id' => $id];
    }

    //File already exists, but are we trying to force a overwrite?
    if (is_file(_ROOT_ . $path) && !$force) {
        return ['yesno' => _('A file with the same name already exists.
Would you like to replace the existing file?'), 'id' => $id];
    }

    //Rename/move or give an error
    //TODO prepared query
    if (rename(_ROOT_ . $path, _ROOT_ . $dir . '/' . $filename)) {
        if ($force) {
            db()->query("DELETE FROM files WHERE `path` = '" . db()->esc($newPath) . "%'");
            //TODO insert new file data (width, alt, height, aspect)
        }

        db()->query("UPDATE files SET path = REPLACE(path, '" . db()->esc($path) . "', '" . db()->esc($newPath) . "')");
        replacePaths($path, $newPath);

        if (is_dir(_ROOT_ . $dir . '/' . $filename)) {
            if (!empty($_COOKIE[$_COOKIE['admin_dir']])) {
                setcookie($dir . '/' . $filename, ($_COOKIE[$_COOKIE['admin_dir']]) ?? '');
            }
            setcookie($_COOKIE['admin_dir'] ?? '', false);
            setcookie('admin_dir', $dir . '/' . $filename);
        }

        return ['id' => $id, 'filename' => $filename, 'path' => $dir . '/' . $filename];
    }

    return ['error' => _('An error occurred with the file operations.'), 'id' => $id];
}

function replacePaths($path, $newPath): void
{
    $newPathEsc = db()->esc($newPath);
    $pathEsc = db()->esc($path);
    db()->query("UPDATE sider     SET navn  = REPLACE(navn, '" . $pathEsc . "', '" . $newPathEsc . "'), text = REPLACE(text, '" . $pathEsc . "', '" . $newPathEsc . "'), beskrivelse = REPLACE(beskrivelse, '" . $pathEsc . "', '" . $newPathEsc . "'), billed = REPLACE(billed, '" . $pathEsc . "', '" . $newPathEsc . "')");
    db()->query("UPDATE template  SET navn  = REPLACE(navn, '" . $pathEsc . "', '" . $newPathEsc . "'), text = REPLACE(text, '" . $pathEsc . "', '" . $newPathEsc . "'), beskrivelse = REPLACE(beskrivelse, '" . $pathEsc . "', '" . $newPathEsc . "'), billed = REPLACE(billed, '" . $pathEsc . "', '" . $newPathEsc . "')");
    db()->query("UPDATE special   SET text  = REPLACE(text, '" . $pathEsc . "', '" . $newPathEsc . "')");
    db()->query("UPDATE krav      SET text  = REPLACE(text, '" . $pathEsc . "', '" . $newPathEsc . "')");
    db()->query("UPDATE maerke    SET ico   = REPLACE(ico, '" . $pathEsc . "', '" . $newPathEsc . "')");
    db()->query("UPDATE list_rows SET cells = REPLACE(cells, '" . $pathEsc . "', '" . $newPathEsc . "')");
    db()->query("UPDATE kat       SET navn  = REPLACE(navn, '" . $pathEsc . "', '" . $newPathEsc . "'), icon = REPLACE(icon, '" . $pathEsc . "', '" . $newPathEsc . "')");
}

/**
 * Rename or relocate a file/directory.
 *
 * return void|array
 */
function deltree(string $dir)
{
    $dirlists = scandir(_ROOT_ . $dir);
    foreach ($dirlists as $dirlist) {
        if ($dirlist != '.' && $dirlist != '..') {
            if (is_dir(_ROOT_ . $dir . '/' . $dirlist)) {
                $deltree = deltree($dir . '/' . $dirlist);
                if ($deltree) {
                    return $deltree;
                }
                @rmdir(_ROOT_ . $dir . '/' . $dirlist);
                @setcookie($dir . '/' . $dirlist, false);
                continue;
            }

            if (db()->fetchOne("SELECT id FROM `sider` WHERE `navn` LIKE '%" . $dir . '/' . $dirlist . "%' OR `text` LIKE '%" . $dir . '/' . $dirlist . "%' OR `beskrivelse` LIKE '%" . $dir . '/' . $dirlist . "%' OR `billed` LIKE '%" . $dir . '/' . $dirlist . "%'")
                || db()->fetchOne("SELECT id FROM `template` WHERE `navn` LIKE '%" . $dir . '/' . $dirlist . "%' OR `text` LIKE '%" . $dir . '/' . $dirlist . "%' OR `beskrivelse` LIKE '%" . $dir . '/' . $dirlist . "%' OR `billed` LIKE '%" . $dir . '/' . $dirlist . "%'")
                || db()->fetchOne("SELECT id FROM `special` WHERE `text` LIKE '%" . $dir . '/' . $dirlist . "%'")
                || db()->fetchOne("SELECT id FROM `krav` WHERE `text` LIKE '%" . $dir . '/' . $dirlist . "%'")
                || db()->fetchOne("SELECT id FROM `maerke` WHERE `ico` LIKE '%" . $dir . '/' . $dirlist . "%'")
                || db()->fetchOne("SELECT id FROM `list_rows` WHERE `cells` LIKE '%" . $dir . '/' . $dirlist . "%'")
                || db()->fetchOne("SELECT id FROM `kat` WHERE `navn` LIKE '%" . $dir . '/' . $dirlist . "%' OR `icon` LIKE '%" . $dir . '/' . $dirlist . "%'")
            ) {
                return ['error' => _('A file could not be deleted because it is used on a site.')];
            }

            @unlink(_ROOT_ . $dir . '/' . $dirlist);
        }
    }
}

/**
 * @return array|true
 */
function deletefolder()
{
    $deltree = deltree($_COOKIE['admin_dir'] ?? '');
    if ($deltree) {
        return $deltree;
    }
    if (@rmdir(_ROOT_ . ($_COOKIE['admin_dir'] ?? ''))) {
        @setcookie($_COOKIE['admin_dir'] ?? '', false);

        return true;
    }

    return ['error' => _('The folder could not be deleted, you may not have sufficient rights to this folder.')];
}

function searchfiles(string $qpath, string $qalt, string $qmime): array
{
    $qpath = db()->escapeWildcards(db()->esc($qpath));
    $qalt = db()->escapeWildcards(db()->esc($qalt));

    $sqlMime = '';
    switch ($qmime) {
        case 'image':
            $sqlMime = "(mime = 'image/jpeg' OR mime = 'image/png' OR mime = 'image/gif' OR mime = 'image/vnd.wap.wbmp')";
            break;
        case 'imagefile':
            $sqlMime = "(mime = 'application/postscript' OR mime = 'image/x-ms-bmp' OR mime = 'image/x-psd' OR mime = 'image/x-photoshop' OR mime = 'image/tiff' OR mime = 'image/x-eps' OR mime = 'image/bmp')";
            break;
        case 'video':
            $sqlMime = "(mime = 'video/avi' OR mime = 'video/x-msvideo' OR mime = 'video/mpeg' OR mime = 'video/quicktime' OR mime = 'video/x-shockwave-flash' OR mime = 'application/futuresplash' OR mime = 'application/x-shockwave-flash' OR mime = 'video/x-flv' OR mime = 'video/x-ms-asf' OR mime = 'video/x-ms-wmv' OR mime = 'application/vnd.ms-powerpoint' OR mime = 'video/vnd.rn-realvideo' OR mime = 'application/vnd.rn-realmedia')";
            break;
        case 'audio':
            $sqlMime = "(mime = 'audio/vnd.rn-realaudio' OR mime = 'audio/x-wav' OR mime = 'audio/mpeg' OR mime = 'audio/midi' OR mime = 'audio/x-ms-wma')";
            break;
        case 'text':
            $sqlMime = "(mime = 'application/pdf' OR mime = 'text/plain' OR mime = 'application/rtf' OR mime = 'text/rtf' OR mime = 'application/msword' OR mime = 'application/vnd.ms-works' OR mime = 'application/vnd.ms-excel')";
            break;
        case 'sysfile':
            $sqlMime = "(mime = 'text/html' OR mime = 'text/css')";
            break;
        case 'compressed':
            $sqlMime = "(mime = 'application/x-gzip' OR mime = 'application/x-gtar' OR mime = 'application/x-tar' OR mime = 'application/x-stuffit' OR mime = 'application/x-stuffitx' OR mime = 'application/zip' OR mime = 'application/x-zip' OR mime = 'application/x-compressed' OR mime = 'application/x-compress' OR mime = 'application/mac-binhex40' OR mime = 'application/x-rar-compressed' OR mime = 'application/x-rar' OR mime = 'application/x-bzip2' OR mime = 'application/x-7z-compressed')";
            break;
    }

    //Generate search query
    $sql = " FROM `files`";
    if ($qpath || $qalt || $sqlMime) {
        $sql .= " WHERE ";
        if ($qpath || $qalt) {
            $sql .= "(";
        }
        if ($qpath) {
            $sql .= "MATCH(path) AGAINST('" . $qpath . "')>0";
        }
        if ($qpath && $qalt) {
            $sql .= " OR ";
        }
        if ($qalt) {
            $sql .= "MATCH(alt) AGAINST('" . $qalt . "')>0";
        }
        if ($qpath) {
            $sql .= " OR `path` LIKE '%" . $qpath . "%' ";
        }
        if ($qalt) {
            $sql .= " OR `alt` LIKE '%" . $qalt . "%'";
        }
        if ($qpath || $qalt) {
            $sql .= ")";
        }
        if (($qpath || $qalt) && !empty($sqlMime)) {
            $sql .= " AND ";
        }
        if (!empty($sqlMime)) {
            $sql .= $sqlMime;
        }
    }

    $sqlSelect = '';
    if ($qpath || $qalt) {
        $sqlSelect .= ', ';
        if ($qpath && $qalt) {
            $sqlSelect .= '(';
        }
        if ($qpath) {
            $sqlSelect .= 'MATCH(path) AGAINST(\'' . $qpath . '\')';
        }
        if ($qpath && $qalt) {
            $sqlSelect .= ' + ';
        }
        if ($qalt) {
            $sqlSelect .= 'MATCH(alt) AGAINST(\'' . $qalt . '\')';
        }
        if ($qpath && $qalt) {
            $sqlSelect .= ')';
        }
        $sqlSelect .= ' AS score';
        $sql = $sqlSelect . $sql;
        $sql .= ' ORDER BY `score` DESC';
    }

    $html = '';
    $javascript = '';
    foreach (ORM::getByQuery(File::class, "SELECT *" . $sql) as $file) {
        if ($qmime !== 'unused' || !isinuse($file->getPath())) {
            $html .= filehtml($file);
            $javascript .= filejavascript($file);
        }
    }

    return ['id' => 'files', 'html' => $html, 'javascript' => $javascript];
}

function edit_alt(int $id, string $description): array
{
    $file = ORM::getOne(File::class, $id);
    $file->setDescription($description)->save();

    //Update html with new alt...
    $pages = ORM::getByQuery(
        Page::class,
        "SELECT * FROM `sider` WHERE `text` LIKE '%" . db()->esc($file->getPath()) . "%'"
    );
    foreach ($pages as $page) {
        //TODO move this to db fixer to test for missing alt="" in img
        /*preg_match_all('/<img[^>]+/?>/ui', $value, $matches);*/
        $html = $page->getHtml();
        $html = preg_replace(
            '/(<img[^>]+src="' . addcslashes(str_replace('.', '[.]', $file->getPath()), '/')
                . '"[^>]+alt=)"[^"]*"([^>]*>)/iu',
            '\1"' . xhtmlEsc($description) . '"\2',
            $html
        );
        $html = preg_replace(
            '/(<img[^>]+alt=)"[^"]*"([^>]+src="' . addcslashes(str_replace(
                '.',
                '[.]',
                $file->getPath()
            ), '/') . '"[^>]*>)/iu',
            '\1"' . xhtmlEsc($description) . '"\2',
            $html
        );
        $page->setHtml($html)->save();
    }

    return ['id' => $id, 'alt' => $description];
}

/**
 * Use HTMLPurifier to clean HTML-code, preserves youtube videos.
 *
 * @param string $string Sting to clean
 *
 * @return string Cleaned stirng
 **/
function purifyHTML(string $string): string
{
    $config = HTMLPurifier_Config::createDefault();
    $config->set('HTML.SafeIframe', true);
    $config->set('URI.SafeIframeRegexp', '%^http://www.youtube.com/embed/%u');
    $config->set('HTML.SafeObject', true);
    $config->set('Output.FlashCompat', true);
    $config->set('HTML.Doctype', 'XHTML 1.0 Transitional');
    $config->set('Cache.SerializerPath', _ROOT_ . '/theme/cache/HTMLPurifier');
    $purifier = new HTMLPurifier($config);

    return $purifier->purify($string);
}

function search(string $text): array
{
    if (!$text) {
        return ['error' => _('You must enter a search word.')];
    }

    //fulltext search dosn't catch things like 3 letter words and some other combos
    $simpleq = preg_replace(
        ['/\s+/u', "/'/u", '/´/u', '/`/u'],
        ['%', '_', '_', '_'],
        $text
    );

    $pages = ORM::getByQuery(
        Page::class,
        "
        SELECT * FROM sider
        WHERE MATCH (navn, text, beskrivelse) AGAINST('" . $text . "') > 0
            OR `navn` LIKE '%" . $simpleq . "%'
            OR `text` LIKE '%" . $simpleq . "%'
            OR `beskrivelse` LIKE '%" . $simpleq . "%'
        ORDER BY MATCH (navn, text, beskrivelse) AGAINST('" . $text . "') DESC
        "
    );

    $html = Render::render('partial-admin-search', ['text' => $text, 'pages' => $pages]);

    return ['id' => 'canvas', 'html' => $html];
}

function listRemoveRow(int $listid, int $rowId): array
{
    db()->query("DELETE FROM `list_rows` WHERE `id` = " . $rowId);

    return ['listid' => $listid, 'rowid' => $rowId];
}

function listSavetRow(int $listid, string $cells, string $link, int $rowId): array
{
    if (!$rowId) {
        db()->query(
            '
            INSERT INTO `list_rows`(`list_id`, `cells`, `link`)
            VALUES (' . $listid . ', \'' . db()->esc($cells) . '\', \'' . db()->esc($link) . '\')
            '
        );
        $rowId = db()->insert_id;
    } else {
        db()->query(
            '
            UPDATE `list_rows`
                SET
                    `list_id` = ' . $listid . ',
                    `cells` = \'' . db()->esc($cells) . '\',
                    `link` = \'' . db()->esc($link) . '\'
            WHERE id = ' . $rowId
        );
    }

    return ['listid' => $listid, 'rowid' => $rowId];
}

function updateContact(
    int $id,
    string $navn,
    string $email,
    string $adresse,
    string $land,
    string $post,
    string $city,
    string $tlf1,
    string $tlf2,
    bool $kartotek,
    string $interests
): bool {
    if (!$id) {
        $contact = new Contact([
            'timestamp'  => time(),
            'name'       => $navn,
            'email'      => $email,
            'address'    => $adresse,
            'country'    => $land,
            'postcode'   => $post,
            'city'       => $city,
            'phone1'     => $tlf1,
            'phone2'     => $tlf2,
            'newsletter' => $kartotek,
            'interests'  => $interests,
            'ip'         => $_SERVER['REMOTE_ADDR'],
        ]);
        $contact->save();

        return true;
    }

    $contact = ORM::getOne(Contact::class, $id);
    $contact->setName($navn)
        ->setEmail($email)
        ->setAddress($adresse)
        ->setCountry($land)
        ->setPostcode($post)
        ->setCity($city)
        ->setPhone1($tlf1)
        ->setPhone2($tlf2)
        ->setNewsletter($kartotek)
        ->setInterests($interests)
        ->setIp($_SERVER['REMOTE_ADDR'])
        ->save();

    return true;
}

function deleteContact(int $id): string
{
    db()->query('DELETE FROM `email` WHERE `id` = ' . $id);

    return 'contact' . $id;
}

function makeNewList(string $navn): array
{
    db()->query('INSERT INTO `tablesort` (`navn`) VALUES (\'' . db()->esc($navn) . '\')');

    return ['id' => db()->insert_id, 'name' => $navn];
}

function saveListOrder(int $id, string $navn, string $text): bool
{
    db()->query(
        'UPDATE `tablesort` SET navn = \'' . db()->esc($navn) . '\', text = \'' . db()->esc($text) . '\'
        WHERE id = ' . $id
    );

    return true;
}

function get_subscriptions_with_bad_emails(): string
{
    $contacts = ORM::getByQuery(Contact::class, "SELECT * FROM `email` WHERE `email` != ''");
    foreach ($contacts as $key => $contact) {
        if (!$contact->isEmailValide()) {
            unset($contacts[$key]);
        }
    }

    return Render::render('partial-admin-subscriptions_with_bad_emails', ['contacts' => $contacts]);
}

function get_orphan_rows(): string
{
    $html = '';
    $error = db()->fetchArray('SELECT * FROM `list_rows` WHERE list_id NOT IN (SELECT id FROM lists);');
    if ($error) {
        $html .= '<br /><b>' . _('The following rows have no lists:') . '</b><br />';
        foreach ($error as $value) {
            $html .= $value['id'] . ': ' . $value['cells'] . ' ' . $value['link'] . '<br />';
        }
    }
    if ($html) {
        $html = '<b>' . _('The following pages have no binding') . '</b><br />' . $html;
    }

    return $html;
}

function get_orphan_cats(): string
{
    $html = '';
    $error = db()->fetchArray(
        'SELECT `id`, `navn` FROM `kat` WHERE `bind` != 0 AND `bind` != -1 AND `bind` NOT IN (SELECT `id` FROM `kat`);'
    );
    if ($error) {
        $html .= '<br /><b>' . _('The following categories are orphans:') . '</b><br />';
        foreach ($error as $value) {
            $html .= '<a href="?side=redigerkat&id=' . $value['id'] . '">' . $value['id'] . ': ' . $value['navn']
                . '</a><br />';
        }
    }
    if ($html) {
        $html = '<b>' . _('The following categories have no binding') . '</b><br />' . $html;
    }

    return $html;
}

function get_looping_cats(): string
{
    $error = db()->fetchArray("SELECT id, bind, navn FROM `kat` WHERE bind != 0 AND bind != -1");

    $html = '';
    $tempHtml = '';
    foreach ($error as $kat) {
        $bindtree = kattree($kat['bind']);
        foreach ($bindtree as $bindbranch) {
            if ($kat['id'] == $bindbranch['id']) {
                $tempHtml .= '<a href="?side=redigerkat&id=' . $kat['id'] . '">' . $kat['id'] . ': ' . $kat['navn']
                    . '</a><br />';
                continue;
            }
        }
    }
    if ($tempHtml) {
        $html .= '<br /><b>' . _('The following categories are tied in itself:') . '</b><br />' . $tempHtml;
    }
    if ($html) {
        $html = '<b>' . _('The following categories are tied in itself:') . '</b><br />' . $html;
    }

    return $html;
}

function check_file_names(): string
{
    $html = '';
    $error = db()->fetchArray(
        '
        SELECT path FROM `files`
        WHERE `path` COLLATE UTF8_bin REGEXP \'[A-Z|_"\\\'`:%=#&+?*<>{}\\]+[^/]+$\'
        ORDER BY `path` ASC
        '
    );
    if ($error) {
        if (db()->affected_rows > 1) {
            $html .= '<br /><b>'
                . sprintf(_('The following %d files must be renamed:'), db()->affected_rows)
                . '</b><br /><a onclick="explorer(\'\',\'\');">';
        } else {
            $html .= '<br /><br /><a onclick="explorer(\'\',\'\');">';
        }
        foreach ($error as $value) {
            $html .= $value['path'] . '<br />';
        }
        $html .= '</a>';
    }
    if ($html) {
        $html = '<b>' . _('The following files must be renamed') . '</b><br />' . $html;
    }

    return $html;
}

function check_file_paths(): string
{
    $html = '';
    $error = db()->fetchArray(
        '
        SELECT path FROM `files`
        WHERE `path` COLLATE UTF8_bin REGEXP \'[A-Z|_"\\\'`:%=#&+?*<>{}\\]+.*[/]+\'
        ORDER BY `path` ASC
        '
    );
    if ($error) {
        if (db()->affected_rows > 1) {
            $html .= '<br /><b>'
                . sprintf(_('The following %d files are in a folder that needs to be renamed:'), db()->affected_rows)
                . '</b><br /><a onclick="explorer(\'\',\'\');">';
        } else {
            $html .= '<br /><br /><a onclick="explorer(\'\',\'\');">';
        }
        //TODO only repport one error per folder
        foreach ($error as $value) {
            $html .= $value['path'] . '<br />';
        }
        $html .= '</a>';
    }
    if ($html) {
        $html = '<b>' . _('The following folders must be renamed') . '</b><br />' . $html;
    }

    return $html;
}

function get_size_of_files(): int
{
    $files = db()->fetchOne("SELECT sum(`size`) / 1024 / 1024 AS `filesize` FROM `files`");

    return $files['filesize'] ?? 0;
}

function get_mail_size(): int
{
    $size = 0;

    foreach (Config::get('emails', []) as $email) {
        $imap = new AJenbo\Imap(
            $email['address'],
            $email['password'],
            $email['imapHost'],
            $email['imapPort']
        );

        foreach ($imap->listMailboxes() as $mailbox) {
            try {
                $mailboxStatus = $imap->select($mailbox['name'], true);
                if (!$mailboxStatus['exists']) {
                    continue;
                }

                $mails = $imap->fetch('1:*', 'RFC822.SIZE');
                preg_match_all('/RFC822.SIZE\s([0-9]+)/', $mails['data'], $mailSizes);
                $size += array_sum($mailSizes[1]);
            } catch (Exception $e) {
            }
        }
    }

    return $size;
}

//todo remove missing maerke from sider->maerke
/*
TODO test for missing alt="" in img under sider
preg_match_all('/<img[^>]+/?>/ui', $value, $matches);
*/

function get_orphan_lists(): string
{
    $error = db()->fetchArray('SELECT id FROM `lists` WHERE page_id NOT IN (SELECT id FROM sider);');
    $html = '';
    if ($error) {
        $html .= '<br /><b>' . _('The following lists are orphans:') . '</b><br />';
        foreach ($error as $value) {
            $html .= $value['id'] . ': ' . $value['navn'] . ' ' . $value['cell1'] . ' ' . $value['cell2'] . ' '
                . $value['cell3'] . ' ' . $value['cell4'] . ' ' . $value['cell5'] . ' ' . $value['cell6'] . ' '
                . $value['cell7'] . ' ' . $value['cell8'] . ' ' . $value['cell9'] . ' ' . $value['img'] . ' '
                . $value['link'] . '<br />';
        }
    }
    if ($html) {
        $html = '<b>' . _('The following lists are not tied to any page') . '</b><br />' . $html;
    }

    return $html;
}

function get_db_size(): float
{
    $tabels = db()->fetchArray("SHOW TABLE STATUS");
    $dbsize = 0;
    foreach ($tabels as $tabel) {
        $dbsize += $tabel['Data_length'];
        $dbsize += $tabel['Index_length'];
    }

    return $dbsize / 1024 / 1024;
}

function get_orphan_pages(): string
{
    $html = '';
    $sider = db()->fetchArray(
        'SELECT `id`, `navn`, `varenr` FROM `sider` WHERE `id` NOT IN(SELECT `side` FROM `bind`);'
    );
    foreach ($sider as $side) {
        $html .= '<a href="?side=redigerside&amp;id=' . $side['id'] . '">' . $side['id'] . ': ' . $side['navn']
            . '</a><br />';
    }

    if ($html) {
        $html = '<b>' . _('The following pages have no binding') . '</b><br />' . $html;
    }

    return $html;
}

function get_pages_with_mismatch_bindings(): string
{
    $html = '';

    // Map out active / inactive
    $categoryActiveMaps = [[0], [-1]];
    $categories = ORM::getByQuery(Category::class, "SELECT * FROM `kat`");
    foreach ($categories as $category) {
        $categoryActiveMaps[(int) $category->isInactive()][] = $category->getId();
    }

    $pages = ORM::getByQuery(
        Page::class,
        "
        SELECT * FROM `sider`
        WHERE EXISTS (
            SELECT * FROM bind
            WHERE side = sider.id
            AND kat IN (" . implode(",", $categoryActiveMaps[0]) . ")
        )
        AND EXISTS (
            SELECT * FROM bind
            WHERE side = sider.id
            AND kat IN (" . implode(",", $categoryActiveMaps[1]) . ")
        )
        ORDER BY id
        "
    );
    if ($pages) {
        $html .= '<b>' . _('The following pages are both active and inactive') . '</b><br />';
        foreach ($pages as $page) {
            $html .= '<a href="?side=redigerside&amp;id=' . $page->getId() . '">' . $page->getId() . ': '
                . $page->getTitle() . '</a><br />';
        }
    }

    //Add active pages that has a list that links to this page
    $pages = db()->fetchArray(
        '
        SELECT `sider`.*, `lists`.`page_id`
        FROM `list_rows`
        JOIN `lists` ON `list_rows`.`list_id` = `lists`.`id`
        JOIN `sider` ON `list_rows`.`link` = `sider`.id
        WHERE EXISTS (
            SELECT * FROM bind
            WHERE side = `lists`.`page_id`
            AND kat IN (' . implode(',', $categoryActiveMaps[0]) . ')
        )
        AND EXISTS (
            SELECT * FROM bind
            WHERE side = sider.id
            AND kat IN (' . implode(',', $categoryActiveMaps[1]) . ')
        )
        ORDER BY `lists`.`page_id`
        '
    );
    if ($pages) {
        $html .= '<b>' . _('The following inactive pages appears in list on active pages') . '</b><br />';
        foreach ($pages as $page) {
            $listPage = ORM::getOne(Page::class, $page['page_id']);
            $page = new Page(Page::mapFromDB($page));
            $html .= '<a href="?side=redigerside&amp;id=' . $listPage->getId() . '">' . $listPage->getId() . ': '
                . $listPage->getTitle() . '</a> -&gt; <a href="?side=redigerside&amp;id=' . $page->getId() . '">'
                . $page->getId() . ': ' . $page->getTitle() . '</a><br />';
        }
    }

    return $html;
}

function getRequirementOptions(): array
{
    $options = [0 => 'None'];
    $requirements = db()->fetchArray('SELECT id, navn FROM `krav` ORDER BY navn');
    foreach ($requirements as $requirement) {
        $options[$requirement['id']] = $requirement['navn'];
    }

    return $options;
}

function getBrandOptions(): array
{
    $options = [0 => 'All others'];
    $brands = db()->fetchArray('SELECT id, navn FROM `maerke` ORDER BY navn');
    foreach ($brands as $brand) {
        $options[$brand['id']] = $brand['navn'];
    }

    return $options;
}

/**
 * @return array|true
 */
function save_ny_kat(string $navn, string $kat, string $icon, string $vis, string $email)
{
    if ($navn != '' && $kat != '') {
        $category = new Category([
            'title'             => $navn,
            'parent_id'         => $kat,
            'icon_path'         => $icon,
            'render_mode'       => $vis,
            'email'             => $email,
            'weighted_children' => 0,
            'weight'            => 0,
        ]);
        $category->save();
        return true;
    }

    return ['error' => _('You must enter a name and choose a location for the new category.')];
}

function savekrav(int $id, string $navn, string $text): array
{
    $text = purifyHTML($text);
    $text = htmlUrlDecode($text);

    if ($navn != '' && $text != '') {
        if (!$id) {
            $requirement = new Requirement([
                'title' => $navn,
                'html'  => $text,
            ]);
            $id = $requirement->getId();
        } else {
            $requirement = ORM::getOne(Requirement::class, $id);
            $requirement->setTitle($navn)->setHtml($text);
        }
        $requirement->save();

        return ['id' => $id];
    }

    return ['error' => _('You must enter a name and a text of the requirement.')];
}

function sogogerstat(string $sog, string $erstat): int
{
    db()->query('UPDATE sider SET text = REPLACE(text,\'' . db()->esc($sog) . '\',\'' . db()->esc($erstat) . '\')');

    return db()->affected_rows;
}

function updatemaerke(int $id = null, string $navn = '', string $link = '', string $ico = ''): array
{
    if ($navn) {
        if ($id === null) {
            (new Brand(['title' => $navn, 'link' => $link, 'icon_path' => $ico]))->save();
        } else {
            $brand = ORM::getOne(Brand::class, $id)->setTitle($navn)->setLink($link)->setIconPath($ico)->save();
            $id = $brand->getId();
        }

        return ['id' => $id];
    }

    return ['error' => _('You must enter a name.')];
}

function sletmaerke(int $id): array
{
    db()->query("DELETE FROM `maerke` WHERE `id` = " . $id);

    return ['node' => 'maerke' . $id];
}

function sletkrav(int $id): array
{
    db()->query("DELETE FROM `krav` WHERE `id` = " . $id);

    return ['id' => 'krav' . $id];
}

function removeAccessory(int $pageId, int $accessoryId): array
{
    $accessory = ORM::getOne(Page::class, $accessoryId);
    ORM::getOne(Page::class, $pageId)->removeAccessory($accessory);

    return ['id' => 'accessory' . $accessory->getId()];
}

function addAccessory(int $pageId, int $accessoryId): array
{
    $accessory = ORM::getOne(Page::class, $accessoryId);
    $page = ORM::getOne(Page::class, $pageId);
    $page->addAccessory($accessory);

    return ['pageId' => $page->getId(), 'accessoryId' => $accessory->getId(), 'title' => $accessory->getTitle()];
}

function sletkat(int $id): array
{
    db()->query('DELETE FROM `kat` WHERE `id` = ' . $id);
    if ($kats = db()->fetchArray('SELECT id FROM `kat` WHERE `bind` = ' . $id)) {
        foreach ($kats as $kat) {
            sletkat($kat['id']);
        }
    }
    if ($bind = db()->fetchArray('SELECT side FROM `bind` WHERE `kat` = ' . $id)) {
        db()->query('DELETE FROM `bind` WHERE `kat` = ' . $id);
        foreach ($bind as $side) {
            if (!db()->fetchOne("SELECT id FROM `bind` WHERE `side` = " . $side['side'])) {
                sletSide($side['side']);
            }
        }
    }

    return ['id' => 'kat' . $id];
}

/**
 * @return array|false
 */
function movekat(int $id, int $toId)
{
    db()->query("UPDATE `kat` SET `bind` = " . $toId . " WHERE `id` = " . $id);

    if (db()->affected_rows) {
        return ['id' => 'kat' . $id, 'update' => $toId];
    }

    return false;
}

function renamekat(int $id, string $name): array
{
    db()->query("UPDATE `kat` SET `navn` = '" . db()->esc($name) . "' WHERE `id` = " . $id);

    return ['id' => 'kat' . $id, 'name' => $name];
}

function sletbind(string $id): array
{
    if (!$bind = db()->fetchOne("SELECT side FROM `bind` WHERE `id` = " . $id)) {
        return ['error' => _('The binding does not exist.')];
    }
    db()->query("DELETE FROM `bind` WHERE `id` = " . $id);
    $delete[0]['id'] = $id;
    $added = false;
    if (!db()->fetchOne('SELECT id FROM `bind` WHERE `side` = ' . $bind['side'])) {
        db()->query('INSERT INTO `bind` (`side`, `kat`) VALUES (\'' . $bind['side'] . '\', \'-1\')');

        $added = [
            'id' => db()->insert_id,
            'path' => '/' . _('Inactive') . '/',
            'kat' => -1,
            'side' => $bind['side'],
        ];
    }

    return ['deleted' => $delete, 'added' => $added];
}

function bind(int $id, int $kat): array
{
    if (db()->fetchOne("SELECT id FROM `bind` WHERE `side` = " . $id . " AND `kat` = " . $kat)) {
        return ['error' => _('The binding already exists.')];
    }

    $katRoot = $kat;
    while ($katRoot > 0) {
        $katRoot = db()->fetchOne("SELECT bind FROM `kat` WHERE id = '" . $katRoot . "'");
        $katRoot = $katRoot['bind'];
    }

    //Delete any binding not under $katRoot
    $delete = [];
    $binds = db()->fetchArray('SELECT id, kat FROM `bind` WHERE `side` = ' . $id);
    foreach ($binds as $bind) {
        $bindRoot = $bind['kat'];
        while ($bindRoot > 0) {
            $bindRoot = db()->fetchOne("SELECT bind FROM `kat` WHERE id = '" . $bindRoot . "'");
            $bindRoot = $bindRoot['bind'];
        }
        if ($bindRoot != $katRoot) {
            db()->query("DELETE FROM `bind` WHERE `id` = " . $bind['id']);
            $delete[] = $bind['id'];
        }
    }

    db()->query('INSERT INTO `bind` (`side`, `kat`) VALUES (' . $id . ', ' . $kat . ')');

    $added = [
        'id' => db()->insert_id,
        'kat' => $kat,
        'side' => $id,
        'path' => '',
    ];

    foreach (kattree($kat) as $kat) {
        $added['path'] .= '/' . trim($kat['navn']);
    }
    $added['path'] .= '/';

    return ['deleted' => $delete, 'added' => $added];
}

function htmlUrlDecode(string $text): string
{
    $text = trim($text);

    //Double encode importand encodings, to survive next step and remove white space
    $text = preg_replace(
        ['/&lt;/u',  '/&gt;/u',  '/&amp;/u', '/\s+/u'],
        ['&amp;lt;', '&amp;gt;', '&amp;amp;', ' '],
        $text
    );

    //Decode IE style urls
    $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');

    //Decode Firefox style urls
    $text = rawurldecode($text);

    //atempt to make relative paths (generated by Firefox when copy pasting) in to absolute
    $text = preg_replace('/="[.]{2}\//iu', '="/', $text);

    return $text;
}

function updateSide(
    int $id,
    string $navn,
    string $keywords,
    int $pris,
    string $billed,
    string $beskrivelse,
    int $for,
    string $text,
    string $varenr,
    int $burde,
    int $fra,
    int $krav,
    int $maerke
): bool {
    $beskrivelse = purifyHTML($beskrivelse);
    $beskrivelse = htmlUrlDecode($beskrivelse);
    $text = purifyHTML($text);
    $text = htmlUrlDecode($text);

    ORM::getOne(Page::class, $id)
        ->setTitle($navn)
        ->setKeywords($keywords)
        ->setPrice($pris)
        ->setHtml($text)
        ->setSku($varenr)
        ->setOldPrice($for)
        ->setExcerpt($beskrivelse)
        ->setRequirementId($krav)
        ->setBrandId($maerke)
        ->setImagePath($billed)
        ->setPriceType($fra)
        ->setOldPriceType($burde)
        ->save();

    return true;
}

function updateKat(
    int $id,
    string $navn,
    string $bind,
    string $icon,
    string $vis,
    string $email,
    string $customSortSubs,
    string $subsorder
): bool {
    $bindtree = kattree($bind);
    foreach ($bindtree as $bindbranch) {
        if ($id == $bindbranch['id']) {
            return ['error' => _('The category can not be placed under itself.')];
        }
    }

    //Set the order of the subs
    if ($customSortSubs) {
        updateKatOrder($subsorder);
    }

    //Update kat
    ORM::getOne(Category::class, $id)
        ->setTitle($navn)
        ->setParentId($bind)
        ->setIconPath($icon)
        ->setRenderMode($vis)
        ->setEmail($email)
        ->setWeightedChildren($customSortSubs)
        ->save();

    return true;
}

function updateKatOrder(string $subsorder)
{
    $orderquery = db()->prepare('UPDATE `kat` SET `order` = ? WHERE `id` = ?');
    $orderquery->bind_param('ii', $key, $value);

    $subsorder = explode(',', $subsorder);

    foreach ($subsorder as $key => $value) {
        $orderquery->execute();
    }

    $orderquery->close();
}

function updateSpecial(int $id, string $html): bool
{
    $html = purifyHTML($html);
    $html = htmlUrlDecode($html);
    ORM::getOne(CustomPage::class, $id)->setHtml($html)->save();

    return true;
}

function opretSide(
    int $kat,
    string $navn,
    string $keywords,
    int $pris,
    string $billed,
    string $beskrivelse,
    int $for,
    string $text,
    string $varenr,
    int $burde,
    int $fra,
    int $krav,
    int $maerke
): array {
    $beskrivelse = purifyHTML($beskrivelse);
    $beskrivelse = htmlUrlDecode($beskrivelse);
    $text = purifyHTML($text);
    $text = htmlUrlDecode($text);

    $page = new Page([
        'title'          => $navn,
        'keywords'       => $keywords,
        'excerpt'        => $beskrivelse,
        'html'           => $text,
        'sku'            => $varenr,
        'image_path'     => $billed,
        'requirement_id' => $krav,
        'brand_id'       => $maerke,
        'price'          => $pris,
        'old_price'      => $for,
        'price_type'     => $fra,
        'old_price_type' => $burde,
    ]);
    $page->save();

    db()->query('INSERT INTO `bind` (`side`, `kat` ) VALUES (' . $page->getId() . ', ' . $kat . ')');

    return ['id' => $page->getId()];
}

//Delete a page and all it's relations from the database
function sletSide(int $sideId): array
{
    $lists = db()->fetchArray('SELECT id FROM `lists` WHERE `page_id` = ' . $sideId);
    if ($lists) {
        $listIds = [];
        foreach ($lists as $list) {
            $listIds[] = $list['id'];
        }

        db()->query('DELETE FROM `list_rows` WHERE list_id IN(' . implode('', $listIds) . ')');
        db()->query('DELETE FROM `lists` WHERE `page_id` = ' . $sideId);
    }
    db()->query('DELETE FROM `list_rows` WHERE `link` = ' . $sideId);
    db()->query('DELETE FROM `bind` WHERE side = ' . $sideId);
    db()->query('DELETE FROM `tilbehor` WHERE side = ' . $sideId . ' OR tilbehor =' . $sideId);
    db()->query('DELETE FROM `sider` WHERE id = ' . $sideId);

    return ['class' => 'side' . $sideId];
}

function copytonew(int $id): int
{
    $faktura = db()->fetchOne('SELECT * FROM `fakturas` WHERE `id` = ' . $id);

    unset(
        $faktura['id'],
        $faktura['status'],
        $faktura['date'],
        $faktura['paydate'],
        $faktura['sendt'],
        $faktura['transferred']
    );
    $faktura['clerk'] = $_SESSION['_user']['fullname'];

    $sql = "INSERT INTO `fakturas` SET";
    foreach ($faktura as $key => $value) {
        $sql .= ' `' . addcslashes($key, '`\\') . "` = '" . db()->esc($value) . "',";
    }
    $sql .= " `date` = NOW();";

    db()->query($sql);

    return db()->insert_id;
}

function save(int $id, string $type, array $updates): array
{
    if (empty($updates['department'])) {
        $email = first(Config::get('emails'))['address'];
        $updates['department'] = $email;
    }

    if (!empty($updates['date'])) {
        $date = "STR_TO_DATE('" . $updates['date'] . "', '%d/%m/%Y')";
        unset($updates['date']);
    }

    if (!empty($updates['paydate']) && ($type == 'giro' || $type == 'cash')) {
        $paydate = "STR_TO_DATE('" . $updates['paydate'] . "', '%d/%m/%Y')";
    } elseif ($type == 'lock' || $type == 'cancel') {
        $paydate = 'NOW()';
    }
    unset($updates['paydate']);

    $faktura = db()->fetchOne('SELECT `status`, `note` FROM `fakturas` WHERE `id` = ' . $id);

    if (in_array($faktura['status'], ['locked', 'pbsok', 'rejected'])) {
        $updates = [
            'note' => $updates['note'] ? trim($faktura['note'] . "\n" . $updates['note']) : $faktura['note'],
            'clerk' => $updates['clerk'] ?? '',
            'department' => $updates['department'],
        ];
        if ($faktura['status'] != 'pbsok') {
            if ($type == 'giro') {
                $updates['status'] = 'giro';
            }
            if ($type == 'cash') {
                $updates['status'] = 'cash';
            }
        }
    } elseif (in_array($faktura['status'], ['accepted', 'giro', 'cash', 'canceled'])) {
        if ($updates['note']) {
            $updates = ['note' => $faktura['note'] . "\n" . $updates['note']];
        } else {
            $updates = [];
        }
    } elseif ($faktura['status'] == 'new') {
        unset($updates['id'], $updates['status']);

        if ($type == 'lock') {
            $updates['status'] = 'locked';
        } elseif ($type == 'giro') {
            $updates['status'] = 'giro';
        } elseif ($type == 'cash') {
            $updates['status'] = 'cash';
        }
    }

    if ($type == 'cancel'
        && !in_array($faktura['status'], ['pbsok', 'accepted', 'giro', 'cash'])
    ) {
        $updates['status'] = 'canceled';
    }

    if ($_SESSION['_user']['access'] != 1) {
        unset($updates['clerk']);
    }

    if (count($updates) || !empty($date) || !empty($paydate)) {
        $sql = "UPDATE `fakturas` SET";
        foreach ($updates as $key => $value) {
            $sql .= ' `' . addcslashes($key, '`\\') . "` = '" . addcslashes($value, "'\\") . "',";
        }
        $sql = mb_substr($sql, 0, -1);

        if (!empty($date)) {
            $sql .= ', `date` = ' . $date;
        }
        if (!empty($paydate)) {
            $sql .= ', `paydate` = ' . $paydate;
        }

        $sql .= ' WHERE `id` = ' . $id;

        db()->query($sql);
    }

    $faktura = db()->fetchOne('SELECT * FROM `fakturas` WHERE `id` = ' . $id);

    if (empty($faktura['clerk'])) {
        db()->query(
            "UPDATE `fakturas` SET `clerk` = '"
                . db()->esc($_SESSION['_user']['fullname']) . "' WHERE `id` = " . $faktura['id']
        );
        $faktura['clerk'] = $_SESSION['_user']['fullname'];
    }

    if ($type == 'email') {
        if (!valideMail($faktura['email'])) {
            return ['error' => _('E-mail address is not valid!')];
        }
        if (!$faktura['department'] && count(Config::get('emails')) > 1) {
            return ['error' => _('You have not selected a sender!')];
        } elseif (!$faktura['department']) {
            $email = first(Config::get('emails'))['address'];
            $updates['department'] = $email;
        }
        if ($faktura['amount'] < 1) {
            return ['error' => _('The invoice must be of at at least 1 krone!')];
        }

        $data = [
            'siteName' => Config::get('site_name'),
            'invoiceId' => $faktura['id'],
            'clerk' => $faktura['clerk'],
            'address' => Config::get('address'),
            'postcode' => Config::get('postcode'),
            'city' => Config::get('city'),
            'phone' => Config::get('phone'),
            'link' => Config::get('base_url')
                . '/betaling/?id=' . $faktura['id'] . '&checkid=' . getCheckid($faktura['id']),
        ];

        $success = sendEmails(
            _('Online payment for ') . Config::get('site_name'),
            Render::render('email-invoice', $data),
            $faktura['department'],
            '',
            $faktura['email'],
            $faktura['navn'],
            false
        );
        if (!$success) {
            return ['error' => _('Unable to sendt e-mail!') . "\n"];
        }
        db()->query("UPDATE `fakturas` SET `status` = 'locked' WHERE `status` = 'new' && `id` = " . $faktura['id']);
        db()->query(
            "UPDATE `fakturas` SET `sendt` = 1, `department` = '"
                . db()->esc($faktura['department']) . "' WHERE `id` = " . $faktura['id']
        );

        //Forece reload
        $faktura['status'] = 'sendt';
    }

    return ['type' => $type, 'status' => $faktura['status']];
}

function sendReminder(int $id): array
{
    $error = '';

    $faktura = db()->fetchOne('SELECT * FROM `fakturas` WHERE `id` = ' . $id);

    if (!$faktura['status']) {
        return ['error' => _('You can not send a reminder until the invoice is sent!')];
    }

    if (!valideMail($faktura['email'])) {
        return ['error' => _('E-mail address is not valid!')];
    }

    if (empty($faktura['department'])) {
        $email = first(Config::get('emails'))['address'];
        $faktura['department'] = $email;
    }

    $data = [
        'siteName' => Config::get('site_name'),
        'addresse' => Config::get('address'),
        'postcode' => Config::get('postcode'),
        'city' => Config::get('city'),
        'phone' => Config::get('phone'),
        'fax' => Config::get('fax'),
        'department' => Config::get('department'),
        'invoiceId' => $faktura['id'],
        'link' => Config::get('base_url') . '/betaling/?id=' . $faktura['id']
            . '&checkid=' . getCheckid($faktura['id']),
    ];

    $success = sendEmails(
        'Elektronisk faktura vedr. ordre',
        Render::render('email-invoice-reminder', $data),
        $faktura['department'],
        '',
        $faktura['email'],
        $faktura['navn'],
        false
    );

    if (!$success) {
        return ['error' => 'Mailen kunde ikke sendes!' . "\n"];
    }
    $error .= "\n\n" . _('A Reminder was sent to the customer.');

    return ['error' => trim($error)];
}

/**
 * @return array|true
 */
function pbsconfirm(int $id)
{
    global $epayment;

    try {
        $success = $epayment->confirm();
    } catch (SoapFault $e) {
        return ['error' => $e->faultstring];
    }

    if (!$epayment->hasError() || !$success) {
        db()->query(
            "
            UPDATE `fakturas`
            SET `status` = 'accepted', `paydate` = NOW()
            WHERE `id` = " . $id
        );

        return true;
    }

    return ['error' => _('An error occurred')];
}

/**
 * @return array|true
 */
function annul(int $id)
{
    global $epayment;

    try {
        $success = $epayment->annul();
    } catch (SoapFault $e) {
        return ['error' => $e->faultstring];
    }

    if (!$epayment->hasError() || !$success) {
        db()->query(
            "
            UPDATE `fakturas`
            SET `status`  = 'rejected',
                `paydate` = NOW()
            WHERE `id` = 'pbsok'
              AND `id` = " . $id
        );

        return true;
    }

    return ['error' => _('An error occurred')];
}

function generateImage(
    string $path,
    int $cropX,
    int $cropY,
    int $cropW,
    int $cropH,
    int $maxW,
    int $maxH,
    int $flip,
    int $rotate,
    array $output = []
): array {
    $outputPath = $path;
    if (!empty($output['type']) && empty($output['overwrite'])) {
        $pathinfo = pathinfo($path);
        if (empty($output['filename'])) {
            $output['filename'] = $pathinfo['filename'];
        }

        $outputPath = $pathinfo['dirname'] . '/' . $output['filename'];
        $outputPath .= !empty($output['type']) && $output['type'] === 'png' ? '.png' : '.jpg';

        if (!empty($output['type']) && empty($output['force']) && file_exists($outputPath)) {
            return [
                'yesno' => _(
                    'A file with the same name already exists.' . "\n"
                    . 'Would you like to replace the existing file?'
                ),
                'filename' => $output['filename'],
            ];
        }
    }

    $image = new AJenbo\Image($path);
    $orginalWidth = $image->getWidth();
    $orginalHeight = $image->getHeight();

    // Crop image
    $cropW = $cropW ?: $image->getWidth();
    $cropH = $cropH ?: $image->getHeight();
    $cropW = min($image->getWidth(), $cropW);
    $cropH = min($image->getHeight(), $cropH);
    $cropX = $cropW !== $image->getWidth() ? $cropX : 0;
    $cropY = $cropH !== $image->getHeight() ? $cropY : 0;
    $image->crop($cropX, $cropY, $cropW, $cropH);

    // Trim image whitespace
    $imageContent = $image->findContent(0);

    $maxW = min($maxW, $imageContent['width']);
    $maxH = min($maxH, $imageContent['height']);

    if (empty($output['type'])
        && !$flip
        && !$rotate
        && $maxW === $orginalWidth
        && $maxH === $orginalHeight
        && mb_strpos($path, _ROOT_) === 0
    ) {
        redirect(mb_substr($path, mb_strlen(_ROOT_)), 301);
    }

    $image->crop(
        $imageContent['x'],
        $imageContent['y'],
        $imageContent['width'],
        $imageContent['height']
    );

    // Resize
    $image->resize($maxW, $maxH);

    // Flip / mirror
    if ($flip) {
        $image->flip($flip === 1 ? 'x' : 'y');
    }

    $image->rotate($rotate);

    // Output image or save
    $mimeType = 'image/jpeg';
    $type = 'jpeg';
    if (empty($output['type'])) {
        $mimeType = get_mime_type($path);
        if ($mimeType !== 'image/png') {
            $mimeType = 'image/jpeg';
        }
        header('Content-Type: ' . $mimeType);
        $image->save(null, $mimeType === 'image/png' ? 'png' : 'jpeg');
        die();
    } elseif ($output['type'] === 'png') {
        $mimeType = 'image/png';
        $type = 'png';
    }
    $image->save($outputPath, $type);

    $width = $image->getWidth();
    $height = $image->getHeight();
    unset($image);

    $file = null;
    $localFile = $outputPath;
    if (mb_strpos($outputPath, _ROOT_) === 0) {
        $localFile = mb_substr($outputPath, mb_strlen(_ROOT_));
        $file = File::getByPath($localFile);
        if ($file && $output['filename'] === $pathinfo['filename'] && $outputPath !== $path) {
            $file->delete();
            $file = null;
        }
        if (!$file) {
            $file = File::fromPath($localFile);
        }

        $file->setMime($mimeType)
            ->setWidth($width)
            ->setHeight($height)
            ->setSize(filesize($outputPath))
            ->save();
    }

    return ['id' => $file ? $file->getId() : null, 'path' => $localFile, 'width' => $width, 'height' => $height];
}

function getBasicAdminTemplateData(): array
{
    return [
        'title'           => 'Administrator menu',
        'javascript'      => Sajax::showJavascript(true),
        'hide'            => [
            'activity'    => $_COOKIE['hideActivity'] ?? false,
            'binding'     => $_COOKIE['hidebinding'] ?? false,
            'categories'  => $_COOKIE['hidekats'] ?? false,
            'description' => $_COOKIE['hidebeskrivelsebox'] ?? false,
            'indhold'     => $_COOKIE['hideIndhold'] ?? false,
            'listbox'     => $_COOKIE['hidelistbox'] ?? false,
            'misc'        => $_COOKIE['hidemiscbox'] ?? false,
            'prices'      => $_COOKIE['hidepriser'] ?? false,
            'suplemanger' => $_COOKIE['hideSuplemanger'] ?? false,
            'tilbehor'    => $_COOKIE['hidetilbehor'] ?? false,
            'tools'       => $_COOKIE['hideTools'] ?? false,
            'theme'       => Config::get('theme', 'default'),
        ],
    ];
}
