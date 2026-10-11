<?php

namespace Vspace\PageSpeed;

use Bitrix\Main\Config\Option;

/**
 * Настройки модуля из \Bitrix\Main\Config\Option (b_option) -> Config. Значения общие для всего сайта
 * (без привязки к SITE_ID). Список ключей и значения по умолчанию — Config::DEFAULTS.
 */
final class Options
{
    public const MODULE_ID = 'vspace.pagespeed';

    private static ?Config $config = null;

    public static function config(): Config
    {
        if (self::$config === null) {
            $values = [];
            foreach (Config::DEFAULTS as $key => $default) {
                $values[$key] = Option::get(self::MODULE_ID, $key, (string)$default, '');
            }
            self::$config = new Config($values);
        }
        return self::$config;
    }

    public static function set(string $key, string $value): void
    {
        Option::set(self::MODULE_ID, $key, $value, '');
        self::$config = null;
    }
}
