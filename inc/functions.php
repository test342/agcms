<?php
/**
 * Declare common functions
 *
 * PHP version 5
 *
 * @category AGCMS
 * @package  AGCMS
 * @author   Anders Jenbo <anders@jenbo.dk>
 * @license  GPLv2 http://www.gnu.org/licenses/gpl-2.0.html
 * @link     http://www.arms-gallery.dk/
 */

/**
 * Checks if email an address looks valid and that an mx server is responding
 *
 * @param string $email The email address to check
 *
 * @return bool
 */
function validemail(string $email): bool
{
    //_An-._E-mail@test-domain.test.dk
    if ($email
        && preg_match('/^[[:word:]0-9-_.]+@([[:lower:]0-9-]+\.)+[[:lower:]0-9-]+$/u', $email)
        && !preg_match('/@\S[.]{2}/u', $email)
        && getmxrr(preg_replace('/.+?@(.?)/u', '$1', $email), $dummy)
    ) {
        return true;
    } else {
        return false;
    }
}

/**
 * Get last update time for table
 *
 * @param string $table Table name
 *
 * @return null
 */
function getUpdateTime(string $table)
{
    global $mysqli;
    if (!@$GLOBALS['cache']['updatetime'][$table]) {
        $updatetime = $mysqli->fetchArray("SHOW TABLE STATUS LIKE '".$table."'");
        $GLOBALS['cache']['updatetime'][$table] = strtotime($updatetime[0]['Update_time']);
    }
}

/**
 * Check if there are pages connected to a category
 *
 * @param int $id Category id
 *
 * @return bool
 */
function skriv(int $id): bool
{
    global $mysqli;

    if (@$GLOBALS['cache']['kats'][$id]['skriv']) {
        return true;
    } elseif (@$GLOBALS['cache']['kats'][$id]['skriv'] === false) {
        return false;
    }

    //er der en side på denne kattegori
    if ($sider = $mysqli->fetchArray('SELECT id FROM bind WHERE kat = '.$id)) {
        getUpdateTime('bind');
        $GLOBALS['cache']['kats'][$id]['skriv'] = true;
        return true;
    }

    //ellers kig om der er en under kattegori med en side
    $kat = $mysqli->fetchArray(
        "
        SELECT kat.id, bind.id as skriv
        FROM kat JOIN bind ON bind.kat = kat.id
        WHERE kat.bind = $id
        GROUP BY kat.id
        "
    );

    getUpdateTime('kat');

    //cache all results
    foreach ($kat as $value) {
        if ($value['skriv']) {
            $GLOBALS['cache']['kats'][$value['id']]['skriv'] = true;
            $return = true;
            //Load full result in to cache and return true if there was a hit
        }
    }

    if ($return = false) {
        $GLOBALS['cache']['kats'][$id]['skriv'] = true;
        return true;
    }

    //Search deeper if a result wasn't found yet
    foreach ($kat as $value) {
        if (skriv($value['id'])) {
            $GLOBALS['cache']['kats'][$value['id']]['skriv'] = true;
            return true;
        } else {
            //This category is empty or only contains empty categorys
            $GLOBALS['cache']['kats'][$value['id']]['skriv'] = false;
            return false;
        }
    }
}

/**
 * Test if category contain categories with content
 *
 * @param int $kat Category id
 *
 * @return bool
 */
function subs(int $kat): bool
{
    global $mysqli;

    $sub = $mysqli->fetchArray(
        "
        SELECT id
        FROM kat
        WHERE bind = $kat
        ORDER BY navn
        "
    );

    getUpdateTime('kat');

    foreach ($sub as $value) {
        //er der sider bundet til katagorien
        if (skriv($value['id'])) {
            return true;
        }
    }

    return false;
}

/**
 * Generate safe file name
 *
 * @param string $name String to clean
 *
 * @return string
 */
function clearFileName(string $name): string
{
    $search = array(
        '/[&?\/:*"<>|%\s-_#\\\\]+/u',
        '/^\s+|\s+$/u',
        '/\s+/u'
    );
    $replace = array(' ', '', '-');
    return preg_replace($search, $replace, $name);
}

/**
 * Natsort an array
 *
 * @param array  $aryData     Array to sort
 * @param string $strIndex    Key of unique id
 * @param string $strSortBy   Key to sort by
 * @param string $strSortType Revers sorting
 *
 * @return array
 */
function arrayNatsort(array $aryData, string $strIndex, string $strSortBy, string $strSortType = 'asc'): array
{
    //Make sure the sort by is a string
    $strSortBy .= '';
    //Make sure the index is a string
    $strIndex .= '';

    //if the parameters are invalid
    if (!is_array($aryData) || $strIndex === '' || $strSortBy === '') {
        return $aryData;
    }

    //ignore
    $match = array();
    $replace = '';

    //create our temporary arrays
    $arySort = $aryResult = array();
    //print_r($aryData);

    //loop through the array
    foreach ($aryData as $aryRow) {
        //set up the value in the array
        $arySort[$aryRow[$strIndex]] = str_replace(
            $match,
            $replace,
            $aryRow[$strSortBy]
        );
    }

    //apply the natural sort
    natcasesort($arySort);

    //if the sort type is descending
    if ($strSortType == 'desc' || $strSortType == '-') {
        //reverse the array
        arsort($arySort);
    }

    //loop through the sorted and original data
    foreach ($arySort as $arySortKey => $arySorted) {
        foreach ($aryData as $aryOriginal) {
            //if the key matches
            if ($aryOriginal[$strIndex]==$arySortKey) {
                //add it to the output array
                array_push($aryResult, $aryOriginal);
                break;
            }
        }
    }

    //return the result
    return $aryResult;
}

/**
 * Sort a 2D array based on a custome sort order an array
 *
 * @param array  $aryData         Array to sort
 * @param string $strIndex        Key of unique id
 * @param string $strSortBy       Key to sort by
 * @param int    $intSortingOrder Custome sorting to use
 * @param string $strSortType     Revers sorting
 *
 * @return array
 */
function arrayListsort(array $aryData, string $strIndex, string $strSortBy, int $intSortingOrder, string $strSortType = 'asc'): array
{
    global $mysqli;

    //Open database
    if (!isset($mysqli)) {
        $mysqli = new Simple_Mysqli(
            $GLOBALS['_config']['mysql_server'],
            $GLOBALS['_config']['mysql_user'],
            $GLOBALS['_config']['mysql_password'],
            $GLOBALS['_config']['mysql_database']
        );
    }

    if (!is_array($aryData) || !$strIndex || !$strSortBy) {
        return $aryData;
    }

    $kaliber = $mysqli->fetchArray(
        "
        SELECT text
        FROM `tablesort`
        WHERE id = " . $intSortingOrder
    );
    if ($kaliber) {
        $kaliber = explode('<', $kaliber[0]['text']);
    }

    getUpdateTime('tablesort');

    $arySort = $aryResult = array();

    foreach ($aryData as $aryRow) {
        $arySort[$aryRow[$strIndex]] = -1;
        foreach ($kaliber as $kalKey => $kalSort) {
            if ($aryRow[$strSortBy]==$kalSort) {
                $arySort[$aryRow[$strIndex]] = $kalKey;
                    break;
            }
        }
    }

    natcasesort($arySort);

    if ($strSortType=="desc" || $strSortType=="-") {
        arsort($arySort);
    }

    foreach ($arySort as $arySortKey => $arySorted) {
        foreach ($aryData as $aryOriginal) {
            if ($aryOriginal[$strIndex]==$arySortKey) {
                array_push($aryResult, $aryOriginal);
                break;
            }
        }
    }

    return $aryResult;
}

/**
 * Apply trim to a multi dimentional array
 *
 * @param array $totrim Array to trim
 *
 * @return array
 */
function trimArray(array $totrim): array
{
    if (is_array($totrim)) {
        $totrim = array_map("trimArray", $totrim);
    } else {
        $totrim = trim($totrim);
    }
    return $totrim;
}

/**
 * Return html for a sorted list
 *
 * @param int $listid      Id of list
 * @param int $bycell      What cell to sort by
 * @param int $current_kat Id of current category
 *
 * @return string
 */
function getTable(int $listid, int $bycell, int $current_kat): string
{
    global $mysqli;

    $html = '';

    getUpdateTime('lists');
    $lists = $mysqli->fetchArray("SELECT * FROM `lists` WHERE id = " . $listid);

    getUpdateTime('list_rows');
    $rows = $mysqli->fetchArray(
        "
        SELECT *
        FROM `list_rows`
        WHERE `list_id` = " . $listid
    );
    if ($rows) {
        //Explode sorts
        $lists[0]['sorts'] = explode('<', $lists[0]['sorts']);
        $lists[0]['cells'] = explode('<', $lists[0]['cells']);
        $lists[0]['cell_names'] = explode('<', $lists[0]['cell_names']);

        if (!$bycell && $bycell !== '0') {
            $bycell = $lists[0]['sort'];
        }

        //Explode cells
        foreach ($rows as $row) {
            $cells = explode('<', $row['cells']);
            $cells['id'] = $row['id'];
            $cells['link'] = $row['link'];
            $rows_cells[] = $cells;
        }
        $rows = $rows_cells;
        unset($row);
        unset($cells);
        unset($rows_cells);

        //Sort rows
        if ($lists[0]['sorts'][$bycell] < 1) {
            $rows = arrayNatsort($rows, 'id', $bycell);
        } else {
            $rows = arrayListsort(
                $rows,
                'id',
                $bycell,
                $lists[0]['sorts'][$bycell]
            );
        }

        //unset temp holder for rows

        $html .= '<table class="tabel">';
        if ($lists[0]['title']) {
            $html .= '<caption>'.$lists[0]['title'].'</caption>';
        }
        $html .= '<thead><tr>';
        foreach ($lists[0]['cell_names'] as $key => $cell_name) {
            $html .= '<td><a href="" onclick="x_getTable(\'' . $lists[0]['id']
            . '\', \'' . $key . '\', ' . $current_kat
            . ', inject_html);return false;">' . $cell_name . '</a></td>';
        }
        $html .= '</tr></thead><tbody>';
        foreach ($rows as $i => $row) {
            $html .= '<tr';
            if ($i % 2) {
                $html .= ' class="altrow"';
            }
            $html .= '>';
            if ($row['link']) {
                getUpdateTime('sider');
                getUpdateTime('kat');
                $sider = $mysqli->fetchArray(
                    "
                    SELECT `sider`.`navn`, `kat`.`navn` AS `kat_navn`
                    FROM `sider` JOIN `kat` ON `kat`.`id` = " . $current_kat . "
                    WHERE `sider`.`id` = " . $row['link'] . "
                    LIMIT 1
                    "
                );
                $row['link'] = '<a href="/kat' . $current_kat . '-'
                . clearFileName($sider[0]['kat_navn']) . '/side' . $row['link']
                . '-' . clearFileName($sider[0]['navn']) . '.html">';
            }
            foreach ($lists[0]['cells'] as $key => $type) {
                if (empty($row[$key])) {
                    $row[$key] = '';
                }

                switch ($type) {
                    case 0:
                        //Plain text
                        $html .= '<td>';
                        if ($row['link']) {
                            $html .= $row['link'];
                        }
                        $html .= $row[$key];
                        if ($row['link']) {
                            $html .= '</a>';
                        }
                        $html .= '</td>';
                        break;
                    case 1:
                        //number
                        $html .= '<td style="text-align:right;">';
                        if ($row['link']) {
                            $html .= $row['link'];
                        }
                        $html .= $row[$key];
                        if ($row['link']) {
                            $html .= '</a>';
                        }
                        $html .= '</td>';
                        break;
                    case 2:
                        //price
                        $html .= '<td style="text-align:right;" class="Pris">';
                        if ($row['link']) {
                            $html .= $row['link'];
                        }
                        if (is_numeric(@$row[$key])) {
                            $html .= str_replace(
                                ',00',
                                ',-',
                                number_format($row[$key], 2, ',', '.')
                            );
                        } else {
                            $html .= @$row[$key];
                        }
                        if ($row['link']) {
                            $html .= '</a>';
                        }
                            $html .= '</td>';
                            $GLOBALS['generatedcontent']['has_product_table'] = true;
                        break;
                    case 3:
                        //new price
                        $html .= '<td style="text-align:right;" class="NyPris">';
                        if ($row['link']) {
                            $html .= $row['link'];
                        }
                        if (is_numeric(@$row[$key])) {
                            $html .= str_replace(
                                ',00',
                                ',-',
                                number_format($row[$key], 2, ',', '.')
                            );
                        } else {
                            $html .= @$row[$key];
                        }
                        if ($row['link']) {
                            $html .= '</a>';
                        }
                            $html .= '</td>';
                            $GLOBALS['generatedcontent']['has_product_table'] = true;
                        break;
                    case 4:
                        //pold price
                        $html .= '<td style="text-align:right;" class="XPris">';
                        if ($row['link']) {
                            $html .= $row['link'];
                        }
                        if (is_numeric(@$row[$key])) {
                            $html .= str_replace(
                                ',00',
                                ',-',
                                number_format($row[$key], 2, ',', '.')
                            );
                        }
                        if ($row['link']) {
                            $html .= '</a>';
                        }
                        $html .= '</td>';
                        break;
                    case 5:
                        //image
                        $html .= '<td>';
                        $files = $mysqli->fetchArray(
                            "
                        SELECT *
                        FROM `files`
                        WHERE path = " . $row[$key] . "
                        LIMIT 1
                        "
                        );

                        getUpdateTime('files');

                        //TODO make image tag
                        if ($row['link']) {
                            $html .= $row['link'];
                        }
                        $html .= '<img src="' . $row[$key] . '" alt="'
                        . $files[0]['alt'] . '" title="" width="' . $files[0]['width']
                        . '" height="' . $files[0]['height'] . '" />';
                        if ($row['link']) {
                            $html .= '</a>';
                        }
                        $html .= '</td>';
                        break;
                }
            }
            if (@$GLOBALS['generatedcontent']['has_product_table']) {
                $html .= '<td class="addtocart"><a href="/bestilling/?add_list_item='
                . $row['id'] . '"><img src="/theme/images/cart_add.png" title="'
                . _('Add to shopping cart') . '" alt="+" /></a></td>';
            }
            $html .= '</tr>';
        }

        $html .= '</tbody></table>';
    }


    $updatetime = 0;
    $included_files = get_included_files();
    foreach ($included_files as $filename) {
        $GLOBALS['cache']['updatetime']['filemtime'] = max(
            $GLOBALS['cache']['updatetime']['filemtime'],
            filemtime($filename)
        );
    }
    foreach ($GLOBALS['cache']['updatetime'] as $time) {
        $updatetime = max($updatetime, $time);
    }
    if ($updatetime < 1) {
        $updatetime = time();
    }

    doConditionalGet($updatetime);

    return array('id' => 'table'.$listid, 'html' => $html);
}

/**
 * Generate html code for lists associated with a page
 *
 * @param int $sideid Id of page
 *
 * @return string
 */
function echoTable(int $sideid): string
{
    global $mysqli;

    $tablesort = $mysqli->fetchArray(
        "
        SELECT `navn`, `text`
        FROM `tablesort`
        ORDER BY `id`"
    );

    getUpdateTime('tablesort');

    foreach ($tablesort as $value) {
        $GLOBALS['tablesort_navn'][] = $value['navn'];
        $GLOBALS['tablesort'][] = trimArray(explode(',', $value['text']));
    }
    //----------------------------------

    $lists = $mysqli->fetchArray(
        "
        SELECT id
        FROM `lists`
        WHERE `page_id` = " . $sideid
    );

    getUpdateTime('lists');

    foreach ($lists as $list) {
        $html = '<div id="table'.$list['id'].'">';

        $table_html = getTable(
            $list['id'],
            null,
            $GLOBALS['generatedcontent']['activmenu']
        );
        $html .= $table_html['html'];
        $html .= '</div>';
    }

    if (!isset($html)) {
        $html = '';
    }

    return $html;
}

/**
 * Get alle gategories leading up a given one
 *
 * @param int $id Id of the end category
 *
 * @return array Ids of all the categories leading up to $id
 */
function kats(int $id): array
{
    global $mysqli;

    $kat = $mysqli->fetchOne(
        "
        SELECT bind
        FROM kat
        WHERE id = " . (int) $id . "
        LIMIT 1
        "
    );

    getUpdateTime('kat');

    if ($kat) {
        $data =  kats($kat['bind']);
        $nr = count($data);
        $kats[0] = $id;
        foreach ($data as $value) {
            $kats[] = $value;
        }
    }

    if (!isset($kats)) {
        $kats = array();
    }

    return $kats;
}

/**
 * Search for root.
 *
 * @param int $bind Kategory id
 *
 * @return int Kategory id of the root branch where $bind belongs to
 */
function binding(int $bind): int
{
    global $mysqli;

    if ($bind > 0) {
        $sog_kat = $mysqli->fetchOne(
            "
            SELECT `bind`
            FROM `kat`
            WHERE id = '" . $bind . "'
            LIMIT 1
            "
        );

        getUpdateTime('kat');

        return binding($sog_kat['bind']);
    } else {
        return $bind;
    }
}

/**
 * Used with array_filter() to make a 2d array uniqe
 *
 * @param array $array Row with key id to make unique
 *
 * @return bool False if id is already seen
 */
function uniquecol(array $array): bool
{
    static $idlist = array();

    if (in_array($array['id'], $idlist)) {
        return false;
    }

    $idlist[] = $array['id'];

    return true;
}
