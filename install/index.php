<?php

use Bitrix\Main\Config\Option;
use Bitrix\Main\EventManager;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ModuleManager;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

Loc::loadMessages(__FILE__);

class vspace_pagespeed extends CModule
{
    public $MODULE_ID = 'vspace.pagespeed';
    public $MODULE_VERSION;
    public $MODULE_VERSION_DATE;
    public $MODULE_NAME;
    public $MODULE_DESCRIPTION;
    public $PARTNER_NAME = 'vspace';

    /**
     * Обработчики main: [событие, метод, сортировка]. OnEndBufferContent — после ammina.optimizer (1000),
     * чтобы работать с её итоговым HTML; OnBeforeEndBufferContent — до неё (выключение её CSS).
     */
    private const EVENTS = [
        ['OnBeforeEndBufferContent', 'onBeforeEndBufferContent', 100],
        ['OnEndBufferContent', 'onEndBufferContent', 1100],
    ];
    private const HANDLER_CLASS = '\\Vspace\\PageSpeed\\EventHandler';
    private const AGENT = '\\Vspace\\PageSpeed\\Agent::cleanup();';
    /** Заглушки страниц админки (install/admin/ -> /bitrix/admin/): инструкция модуля. */
    private const ADMIN_FILES = ['vspace_pagespeed_help.php'];

    public function __construct()
    {
        $arModuleVersion = [];
        include __DIR__ . '/version.php';
        $this->MODULE_VERSION = $arModuleVersion['VERSION'];
        $this->MODULE_VERSION_DATE = $arModuleVersion['VERSION_DATE'];

        $this->MODULE_NAME = Loc::getMessage('VPS_MODULE_NAME');
        $this->MODULE_DESCRIPTION = Loc::getMessage('VPS_MODULE_DESC');
    }

    public function InstallEvents()
    {
        $eventManager = EventManager::getInstance();
        foreach (self::EVENTS as [$event, $method, $sort]) {
            $eventManager->registerEventHandler('main', $event, $this->MODULE_ID, self::HANDLER_CLASS, $method, $sort);
        }
        CAgent::AddAgent(
            self::AGENT,
            $this->MODULE_ID,
            'N',
            86400,
            '',
            'Y',
            \Bitrix\Main\Type\DateTime::createFromTimestamp(time() + 3600)->toString()
        );
        return true;
    }

    public function UnInstallEvents()
    {
        $eventManager = EventManager::getInstance();
        foreach (self::EVENTS as [$event, $method]) {
            $eventManager->unRegisterEventHandler('main', $event, $this->MODULE_ID, self::HANDLER_CLASS, $method);
        }
        CAgent::RemoveModuleAgents($this->MODULE_ID);
        return true;
    }

    public function InstallFiles()
    {
        CopyDirFiles(__DIR__ . '/admin', $_SERVER['DOCUMENT_ROOT'] . '/bitrix/admin', true, true);
        return true;
    }

    public function UnInstallFiles()
    {
        foreach (self::ADMIN_FILES as $file) {
            $path = $_SERVER['DOCUMENT_ROOT'] . '/bitrix/admin/' . $file;
            if (is_file($path)) {
                unlink($path);
            }
        }
        return true;
    }

    /**
     * Модуль ставится выключенным (ACTIVE=N): оптимизация начинается только после включения в настройках.
     * Классы lib/ подключает автозагрузчик ядра (пространство имён Vspace\PageSpeed -> lib/).
     */
    public function DoInstall()
    {
        ModuleManager::registerModule($this->MODULE_ID);
        $this->InstallEvents();
        $this->InstallFiles();
        return true;
    }

    public function DoUninstall()
    {
        if (Loader::includeModule($this->MODULE_ID)) {
            \Vspace\PageSpeed\Optimizer::storage(rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT']), '/'))->clear();
        }
        $this->UnInstallEvents();
        $this->UnInstallFiles();
        Option::delete($this->MODULE_ID);
        ModuleManager::unRegisterModule($this->MODULE_ID);
        return true;
    }
}
