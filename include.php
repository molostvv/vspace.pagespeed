<?php
if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

// Классы lib/ подключаются автоматически: Loader::includeModule() регистрирует для партнёрского модуля
// пространство имён Vspace\PageSpeed -> lib/. Точки входа — lib/EventHandler.php (события main) и lib/Agent.php.
