<?php

class Config
{
    private static $config = [];

    public static function get(string $key, $default = null)
    {
        if (!self::$config) {
            include_once _ROOT_ . '/inc/config.php';
            self::$config = $GLOBALS['_config'] ?? [];
            unset($GLOBALS['_config']);
        }

        return isset(self::$config[$key]) ? self::$config[$key] : $default;
    }
}
