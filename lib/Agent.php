<?php

namespace Vspace\PageSpeed;

use Bitrix\Main\Application;

/**
 * Агент раз в сутки удаляет из кеша модуля файлы, которые не использовались дольше CACHE_TTL_DAYS
 * (старые версии индексов CSS после правок шаблона, critical для исчезнувших структур страниц).
 */
final class Agent
{
    public static function cleanup(): string
    {
        try {
            $root = rtrim(str_replace('\\', '/', Application::getDocumentRoot()), '/');
            Optimizer::storage($root)->cleanup(Options::config()->cacheTtlDays * 86400);
        } catch (\Throwable $e) {
            try {
                Application::getInstance()->getExceptionHandler()->writeToLog($e);
            } catch (\Throwable) {
            }
        }
        return '\\' . __METHOD__ . '();';
    }
}
