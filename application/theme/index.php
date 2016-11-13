<?php
/**
 * Theme file, responsible for outputting the generated content
 *
 * PHP version 5
 *
 * @category AGCMS
 * @package  AGCMS
 * @author   Anders Jenbo <anders@jenbo.dk>
 * @license  MIT http://opensource.org/licenses/MIT
 * @link     http://www.arms-gallery.dk/
 */

/**
 * Print price and offers
 *
 * @param float $price  Current price
 * @param float $before Past price
 * @param int   $from   What type is the curren price
 * @param int   $should What type is the past price
 *
 * @return null
 */
function echoPrice(float $price, float $before, int $from, int $should)
{
    if ($before) {
        if ($should == 1) {
            echo 'Retail price: <span>';
        } elseif ($should == 2) {
            echo 'Should cost: <span>';
        } else {
            echo 'Before: <span class="XPris">';
        }
        echo str_replace(',00', ',-', number_format($before, 2, ',', '.')) . '</span>';
    }

    if ($price) {
        if ($from == 1 && $before) {
            echo ' <span class="NyPris">New price from: ';
        } elseif ($from == 2 && $before) {
            echo ' <span class="NyPris">Used: ';
        } elseif ($from == 1) {
            echo ' Price from: <span class="Pris">';
        } elseif ($from == 2) {
            echo ' Used: <span class="Pris">';
        } elseif ($before) {
            echo ' <span class="NyPris">Now: ';
        } else {
            echo ' Price: <span class="Pris">';
        }
        echo str_replace(',00', ',-', number_format($price, 2, ',', '.')) . '</span>';
    }
}

/**
 * Print the menu
 *
 * @param array $menu Menu items as stored in generatedcontent
 *
 * @return null
 */
function echoMenu(array $menu)
{
    if ($menu) {
        echo '<ul>';
        foreach ($menu as $value) {
            echo '<li>';
            if ($value['id'] == @$GLOBALS['generatedcontent']['activmenu']) {
                echo '<h4 id="activmenu">';
            }
            echo '<a href="' . xhtmlEsc($value['link']) . '">' . $value['name'];
            if ($value['icon']) {
                echo ' <img src="' . xhtmlEsc($value['icon']) . '" alt="" />';
            }
            echo '</a>';

            if ($value['id'] == @$GLOBALS['generatedcontent']['activmenu']) {
                echo '</h4>';
            }
            if (!empty($value['subs'])) {
                echoMenu($value['subs']);
            }
            echo '</li>';
        }
        echo '</ul>';
    }
}

?><!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml"><head><title><?php
echo xhtmlEsc($GLOBALS['generatedcontent']['title']);
?></title>
<meta http-equiv="content-type" content="text/html; charset=utf-8" />
<link href="/theme/style.css" rel="stylesheet" type="text/css" />
<script src="/javascript/json2.stringify.js" type="text/javascript"></script>
<script src="/javascript/json_stringify.js" type="text/javascript"></script>
<script src="/javascript/json_parse_state.js" type="text/javascript"></script>
<script src="/javascript/sajax.js" type="text/javascript"></script>
<script src="/javascript/javascript.js" type="text/javascript"></script>
<link rel="alternate" type="application/rss+xml" title="News" href="/rss.php" />
<link title="Search" type="application/opensearchdescription+xml" rel="search" href="/sog.php" /><?php
if (!empty($GLOBALS['generatedcontent']['canonical'])) {
    echo '<link rel="canonical" href="' . xhtmlEsc($GLOBALS['generatedcontent']['canonical']) . '" />';
}
if (@$GLOBALS['generatedcontent']['keywords']) {
    echo '<meta name="Keywords" content="' . xhtmlEsc($GLOBALS['generatedcontent']['keywords']) . '" />';
}
?></head><body><div id="wrapper"><ul id="crumbs"><li><a href="/">Home</a><?php
if (@$GLOBALS['generatedcontent']['crumbs']) {
    foreach ($GLOBALS['generatedcontent']['crumbs'] as $value) {
        echo '<ul><li><b style="font-size:16px">-&gt;</b><a href="' . xhtmlEsc($value['link']) . '"> ' . xhtmlEsc($value['name']);
        if ($value['icon']) {
            echo '<img src="' . xhtmlEsc($value['icon']) . '" alt="" />';
        }
        echo '</a>';
    }
    foreach ($GLOBALS['generatedcontent']['crumbs'] as $value) {
        echo '</li></ul>';
    }
}

if (!empty($_SESSION['faktura']['quantities'])) {
    echo '<div class="bar" id="cart"><ul><li><a href="/bestilling/">Shopping basket</a></li></ul></div>';
}

?></li></ul></div><div id="text"><a name="top"></a><?php

if ($GLOBALS['generatedcontent']['contenttype'] === 'front') {
    echo $GLOBALS['generatedcontent']['text'];
} elseif ($GLOBALS['generatedcontent']['contenttype'] === 'page') {
    echo '<div id="innercontainer">';
    if ($GLOBALS['generatedcontent']['datetime']) {
        echo '<div id="date">' . date('d-m-Y H:i:s', $GLOBALS['generatedcontent']['datetime']) . '</div>';
    }
    echo '<h1>' . xhtmlEsc($GLOBALS['generatedcontent']['headline']) . '</h1>'
        . $GLOBALS['generatedcontent']['text'] . '</div>';
} elseif ($GLOBALS['generatedcontent']['contenttype'] === 'product') {
    echo '<div id="innercontainer"><div id="date">'
        . date('d-m-Y H:i:s', $GLOBALS['generatedcontent']['datetime'])
        . '</div><h1>' . xhtmlEsc($GLOBALS['generatedcontent']['headline']);
    if ($GLOBALS['generatedcontent']['serial']) {
        echo ' <span style="font-weight:normal; font-size:13px">SKU: '
            . xhtmlEsc($GLOBALS['generatedcontent']['serial']) . '</span>';
    }
    echo '</h1>' . $GLOBALS['generatedcontent']['text'];

    if (@$GLOBALS['generatedcontent']['requirement']['link']) {
        echo '<p><a href="' . xhtmlEsc($GLOBALS['generatedcontent']['requirement']['link']) . '" target="krav">'
            . xhtmlEsc($GLOBALS['generatedcontent']['requirement']['name']) . '</a></p>';
    }
    echo '<p style="text-align:center">';
    echoPrice(
        $GLOBALS['generatedcontent']['price']['now'],
        $GLOBALS['generatedcontent']['price']['before'],
        $GLOBALS['generatedcontent']['price']['from'],
        $GLOBALS['generatedcontent']['price']['market']
    );
    if ($GLOBALS['generatedcontent']['price']['now']) {
        echo ' <a href="/bestilling/?add=' . self::$activePage->getId() . '">+ Add to shopping cart</a> ';
    }
    echo '<br /></p></div>';

    if (@$GLOBALS['generatedcontent']['accessories']) {
        echo '<p align="center" style="clear:both">Accessories</p><table cellspacing="0" id="liste">';
        $i = 0;
        $nr = count($GLOBALS['generatedcontent']['accessories']) - 1;
        foreach ($GLOBALS['generatedcontent']['accessories'] as $value) {
            if ($i % 2 == 0) {
                echo '<tr>';
            }
            echo '<td><a href="' . xhtmlEsc($value['link']) . '">' . xhtmlEsc($value['name']);
            if ($value['icon']) {
                echo '<br /><img src="' . xhtmlEsc($value['icon']) . '" alt="' . xhtmlEsc($value['name']) . '" title="" />';
            }
            echo '</a></td>';
            if ($i % 2 || $i == $nr) {
                echo '</tr>';
            }
            $i++;
        }
        echo '</table>';
    }

    if (isset($GLOBALS['generatedcontent']['brands'])) {
        echo '<p align="center" style="clear:both">View other product from the same brand</p><table cellspacing="0" id="liste">';
        $i = 0;
        $nr = count($GLOBALS['generatedcontent']['brands']) - 1;
        foreach ($GLOBALS['generatedcontent']['brands'] as $value) {
            if ($i % 2 === 0) {
                echo '<tr>';
            }

            echo '<td><a href="' . xhtmlEsc($value['link']) . '">'. xhtmlEsc($value['name']);
            if ($value['icon']) {
                echo '<br /><img src="' . xhtmlEsc($value['icon']) . '" alt="' . xhtmlEsc($value['name']) . '" title="" />';
            }

            echo '</a></td>';
            if ($i % 2 || $i == $nr) {
                echo '</tr>';
            }
            $i++;
        }
        echo '</table>';
    }
} elseif ($GLOBALS['generatedcontent']['contenttype'] === 'tiles'
    || $GLOBALS['generatedcontent']['contenttype'] === 'list'
    || $GLOBALS['generatedcontent']['contenttype'] === 'brand'
) {
    if ($GLOBALS['generatedcontent']['contenttype'] === 'brand') {
        echo '<p align="center">';
        if ($GLOBALS['generatedcontent']['brand']['xlink']) {
            echo '<a rel="nofollow" target="_blank" href="'
                . xhtmlEsc($GLOBALS['generatedcontent']['brand']['xlink'])
                . '">Read more about ';
        }
        echo xhtmlEsc($GLOBALS['generatedcontent']['brand']['name']);
        if ($GLOBALS['generatedcontent']['brand']['icon']) {
            echo '<br /><img src="' . xhtmlEsc($GLOBALS['generatedcontent']['brand']['icon']) . '" alt="'
                . xhtmlEsc($GLOBALS['generatedcontent']['brand']['name']) . '" title="" />';
        }
        if ($GLOBALS['generatedcontent']['brand']['xlink']) {
            echo '</a>';
        }
        echo '</p>';
    }

    if (@$GLOBALS['generatedcontent']['list']) {
        echo '<p align="center" class="web">Click on the product for additional information</p>';

        if ($GLOBALS['generatedcontent']['contenttype'] === 'list') {
            echo '<div id="kat' . self::$activeCategory->getId()
                . '"><table class="tabel"><thead><tr><td><a href="#" onClick="x_get_kat("'
                . self::$activeCategory->getId()
                . ', \'navn\', inject_html);">Title</a></td><td><a href="#" onClick="x_get_kat(\''
                . self::$activeCategory->getId()
                . '\', \'for\', inject_html);">Previously</a></td><td><a href="#" onClick="x_get_kat(\''
                . self::$activeCategory->getId()
                . '\', \'pris\', inject_html);">Price</a></td><td><a href="#" onClick="x_get_kat(\''
                . self::$activeCategory->getId()
                . '\', \'varenr\', inject_html);">#</a></td></tr></thead><tbody>';
            $i = 0;
            foreach ($GLOBALS['generatedcontent']['list'] as $value) {
                echo '<tr';
                if ($i % 2) {
                    echo ' class="altrow"';
                }
                echo '><td><a href="' . xhtmlEsc($value['link']) . '">' . xhtmlEsc($value['name'])
                    . '</a></td><td class="XPris" align="right">';
                if ($value['price']['before']) {
                    echo number_format($value['price']['before'], 0, '', '.') . ',-';
                }
                echo '</td><td class="Pris" align="right">';
                if ($value['price']['now']) {
                    echo number_format($value['price']['now'], 0, '', '.') . ',-';
                }
                echo '</td><td align="right" style="font-size:11px">' . xhtmlEsc($value['serial']) . '</td></tr>';
                $i++;
            }
            echo '</tbody></table></div>';
        } else {
            echo '<table cellspacing="0" id="liste">';
            $i = 0;
            $nr = count($GLOBALS['generatedcontent']['list'])-1;
            foreach ($GLOBALS['generatedcontent']['list'] as $value) {
                if ($i % 2 == 0) {
                    echo '<tr>';
                }
                echo '<td><a href="' . xhtmlEsc($value['link']) . '">';
                if ($value['icon']) {
                    echo '<img src="' . xhtmlEsc($value['icon']) . '" alt="' . xhtmlEsc($value['name']) . '" title="" /><br />';
                }
                echo $value['name'] . '<br />';
                echoPrice(
                    $value['price']['now'],
                    $value['price']['before'],
                    $value['price']['from'],
                    $value['price']['market']
                );
                echo '</a></td>';

                if ($i % 2 || $i == $nr) {
                    echo '</tr>';
                }
                $i++;
            }
            echo '</table>';
        }
    } else {
        echo '<p align="center" class="web">The search did not return any results</p>';
    }
} elseif ($GLOBALS['generatedcontent']['contenttype'] == 'search') {
    echo '<div id="innercontainer"><h1>Search</h1>' . $GLOBALS['generatedcontent']['text'] . '</div>';
}

?></div><div id="menu"><?php

if (isset($GLOBALS['generatedcontent']['menu'])) {
    echoMenu($GLOBALS['generatedcontent']['menu']);
}

if (isset($GLOBALS['generatedcontent']['search_menu'])) {
    echoMenu($GLOBALS['generatedcontent']['search_menu']);
}

?></div></body></html>
