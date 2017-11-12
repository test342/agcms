<?php namespace AGCMS\Service;

use AGCMS\Config;
use AGCMS\Entity\File;
use AGCMS\Exception\InvalidInput;
use AGCMS\ORM;
use Exception;

class FileService
{
    /**
     * Create new folder.
     *
     * @param string $newPath
     *
     * @throws Exception
     */
    public function createFolder(string $path): void
    {
        $this->checkPermittedTargetPath($path);

        if (file_exists(_ROOT_ . $path)) {
            throw new InvalidInput(_('A file or folder with the same name already exists.'));
        }

        if (!@mkdir(_ROOT_ . $path, 0771)) {
            throw new Exception(
                _('Could not create folder, you may not have sufficient rights to this folder.')
            );
        }
    }

    /**
     * Delete folder.
     *
     * @param string $path
     *
     * @throws InvalidInput
     */
    public function deleteFolder(string $path): void
    {
        $this->checkPermittedPath($path);

        $files = ORM::getByQuery(
            File::class,
            'SELECT * FROM `' . File::TABLE_NAME . "` WHERE path LIKE '" . db()->esc($path) . "/%'"
        );
        foreach ($files as $file) {
            if ($file->isInUse()) {
                throw new InvalidInput(sprintf(_('"%s" is still in use.'), $file->getPath()));
            }

            $file->delete();
        }

        if (!$this->deltree(_ROOT_ . $path)) {
            throw new InvalidInput(_('A file could not be deleted because it is used on a site.'));
        }
    }

    /**
     * Takes a string and changes it to comply with file name restrictions in windows, linux, mac and urls (UTF8)
     * .|"'´`:%=#&\/+?*<>{}-_.
     *
     * @param string $filename
     *
     * @return string
     */
    public function cleanFileName(string $filename): string
    {
        $search = ['/[.&?\/:*"\'´`<>{}|%\s-_=+#\\\\]+/u', '/^\s+|\s+$/u', '/\s+/u'];
        $replace = [' ', '', '-'];

        return mb_strtolower(preg_replace($search, $replace, $filename), 'UTF-8');
    }

    /**
     * Check that given path is within the permittede datafolders.
     *
     * @param string $path
     *
     * @throws InvalidInput
     */
    public function checkPermittedPath(string $path): void
    {
        if (realpath(_ROOT_ . $path) !== _ROOT_ . $path) {
            throw new InvalidInput(_('Path must be absolute.'));
        }

        if (0 !== mb_strpos($path . '/', '/files/') && 0 !== mb_strpos($path . '/', '/images/')) {
            throw new InvalidInput(_('Path is outside of permitted folders.'));
        }
    }

    /**
     * Check that the path is a valid save to taget.
     *
     * @param string $path
     *
     * @throws InvalidInput
     */
    public function checkPermittedTargetPath(string $path): void
    {
        $dirname = pathinfo($path, PATHINFO_DIRNAME);
        $this->checkPermittedPath($dirname);

        if (mb_strlen($path, 'UTF-8') > 255) {
            throw new InvalidInput(_('The name is too long.'));
        }

        if (!is_dir(_ROOT_ . $dirname . '/')) {
            throw new InvalidInput(_('Target is not a folder.'));
        }
    }

    /**
     * Convert PHP size string to bytes.
     *
     * @param string $val PHP size string (eg. '2M')
     *
     * @return int Byte size
     */
    public function returnBytes(string $val): int
    {
        $last = mb_substr($val, -1);
        $last = mb_strtolower($last);
        $val = (int) mb_substr($val, 0, -1);
        switch ($last) {
            case 'g':
                $val *= 1024;
                // no break
            case 'm':
                $val *= 1024;
                // no break
            case 'k':
                $val *= 1024;
        }

        return $val;
    }

    /**
     * Replace file paths in the html of pages, templates and requirements.
     *
     * @param string $path
     * @param string $newPath
     */
    public function replaceFolderPaths(string $path, string $newPath): void
    {
        $newPathEsc = db()->esc($newPath);
        $pathEsc = db()->esc($path);
        db()->query("UPDATE sider    SET text = REPLACE(text, '=\"" . $pathEsc . "', '=\"" . $newPathEsc . "')");
        db()->query("UPDATE template SET text = REPLACE(text, '=\"" . $pathEsc . "', '=\"" . $newPathEsc . "')");
        db()->query("UPDATE special  SET text = REPLACE(text, '=\"" . $pathEsc . "', '=\"" . $newPathEsc . "')");
        db()->query("UPDATE krav     SET text = REPLACE(text, '=\"" . $pathEsc . "', '=\"" . $newPathEsc . "')");
        db()->query(
            "
            UPDATE files
            SET path = REPLACE(path, '" . $pathEsc . "', '" . $newPathEsc . "')
            WHERE path LIKE '$pathEsc%'
            "
        );
    }

    /**
     * Delete a folder structure.
     *
     * Alle files must be deleted seperatly
     *
     * return bool
     */
    private function deltree(string $path): bool
    {
        $success = true;

        $nodes = scandir($path);
        foreach ($nodes as $node) {
            if ('.' === $node || '..' === $node) {
                continue;
            }

            if (!is_dir($path . '/' . $node)) {
                return false;
            }

            $success = $success && $this->deltree($path . '/' . $node);
        }
        rmdir($path);

        return $success;
    }

    /**
     * Generate javascript for setting up file objects in Explorer.
     *
     * @param File $file
     *
     * @return string
     */
    public function filejavascript(File $file): string
    {
        $data = [
            'id' => $file->getId(),
            'path' => $file->getPath(),
            'mime' => $file->getMime(),
            'name' => pathinfo($file->getPath(), PATHINFO_FILENAME),
            'width' => $file->getWidth(),
            'height' => $file->getHeight(),
            'description' => $file->getDescription(),
        ];

        return 'files[' . $file->getId() . '] = new file(' . json_encode($data) . ');';
    }

    /**
     * Generate display HTML for file objects in Explorer.
     *
     * @param File   $file
     * @param string $returnType
     *
     * @return string
     */
    public function filehtml(File $file, string $returnType = ''): string
    {
        $html = '';

        $menuType = 'filetile';
        $type = explode('/', $file->getMime());
        $type = array_shift($type);
        if (in_array($file->getMime(), ['image/gif', 'image/jpeg', 'image/png'], true)) {
            $menuType = 'imagetile';
        }
        $html .= '<div id="tilebox' . $file->getId() . '" class="' . $menuType . '"><div class="image"';

        if ('ckeditor' === $returnType) {
            $html .= ' onclick="files[' . $file->getId() . '].addToEditor()"';
        } elseif ('thb' === $returnType && in_array($file->getMime(), ['image/gif', 'image/jpeg', 'image/png'], true)) {
            if ($file->getWidth() <= Config::get('thumb_width')
                && $file->getHeight() <= Config::get('thumb_height')
            ) {
                $html .= ' onclick="insertThumbnail(' . $file->getId() . ')"';
            } else {
                $html .= ' onclick="openImageThumbnail(' . $file->getId() . ')"';
            }
        } else {
            $html .= ' onclick="files[' . $file->getId() . '].openfile();"';
        }

        $html .= '> <img src="';

        $type = explode('/', $file->getMime());
        $type = array_shift($type);
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
            case 'application/msword':
            case 'application/vnd.ms-excel':
            case 'application/vnd.ms-works':
            case 'application/vnd.oasis.opendocument.graphics':
            case 'application/vnd.openxmlformats-officedocument.spreadsheetml.shee':
                $type = 'text';
                break;
            case 'application/zip':
                $type = 'zip';
                break;
        }

        switch ($type) {
            case 'image-native':
                $html .= '/admin/image.php?path=' . rawurlencode($file->getPath()) . '&amp;maxW=128&amp;maxH=96';
                break;
            case 'pdf':
            case 'image':
            case 'video':
            case 'audio':
            case 'text':
            case 'zip':
                $html .= '/theme/default/images/admin/file-' . $type . '.gif';
                break;
            default:
                $html .= '/theme/default/images/admin/file-bin.gif';
                break;
        }

        $pathinfo = pathinfo($file->getPath());
        $html .= '" alt="" title="" /> </div><div ondblclick="showfilename(' . $file->getId()
            . ')" class="navn" id="navn' . $file->getId() . 'div" title="' . $pathinfo['filename'] . '"> '
            . $pathinfo['filename'] . '</div><form action="" method="get" onsubmit="document.getElementById(\'rename'
            . $file->getId() . '\').blur();return false" style="display:none" id="navn' . $file->getId()
            . 'form"><p><input id="rename' . $file->getId() . '" onblur="renamefile(\'' . $file->getId()
            . '\');" maxlength="' . (251 - mb_strlen($pathinfo['dirname'], 'UTF-8')) . '" value="'
            . $pathinfo['filename'] . '" /></p></form></div>';

        return $html;
    }

    /**
     * Get root of folder tree.
     *
     * @param string $currentDir
     *
     * @return array[]
     */
    public function getRootDirs(string $currentDir): array
    {
        $dirs = [];
        foreach (['/images' => _('Images'), '/files' => _('Files')] as $path => $name) {
            $dirs[] = $this->formatDir($path, $name, $currentDir);
        }

        return $dirs;
    }

    /**
     * Get metadata for a folder.
     *
     * @param string $path
     * @param string $name
     * @param string $currentDir
     *
     * @return array
     */
    private function formatDir(string $path, string $name, string $currentDir): array
    {
        $subs = [];
        if (0 === mb_strpos($currentDir, $path)) {
            $subs = $this->getSubDirs($path, $currentDir);
            $hassubs = (bool) $subs;
        } else {
            $hassubs = $this->hasSubsDirs($path);
        }

        return [
            'id' => preg_replace('#/#u', '.', $path),
            'path' => $path,
            'name' => $name,
            'hassubs' => $hassubs,
            'subs' => $subs,
        ];
    }

    /**
     * Return list of folders in a folder.
     *
     * @param string $path
     * @param string $currentDir
     *
     * @return array[]
     */
    public function getSubDirs(string $path, string $currentDir): array
    {
        $folders = glob(_ROOT_ . $path . '/*/');
        natcasesort($folders);

        $dirs = [];
        foreach ($folders as $folder) {
            $name = pathinfo($folder, PATHINFO_BASENAME);
            $dirs[] = $this->formatDir($path . '/' . $name, $name, $currentDir);
        }

        return $dirs;
    }

    /**
     * Check if folder has subfolders.
     *
     * @param string $path
     *
     * @return bool
     */
    private function hasSubsDirs(string $path): bool
    {
        return (bool) glob(_ROOT_ . $path . '/*/');
    }
}
