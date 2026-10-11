<?php
/**
 * Инструкция модуля в админке: README.md (или docs/*.md по ссылке из него) в виде HTML. Открывается ссылкой
 * со страницы настроек; в /bitrix/admin/ её подключает заглушка install/admin/vspace_pagespeed_help.php.
 *
 * @global CMain $APPLICATION
 * @global CUser $USER
 */

use Bitrix\Main\Loader;
use Vspace\PageSpeed\Admin\Markdown;

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';

IncludeModuleLangFile(__FILE__);

if (!$USER->IsAdmin() || !Loader::includeModule('vspace.pagespeed')) {
    $APPLICATION->AuthForm(GetMessage('ACCESS_DENIED'));
}

$vpsIsDoc = static fn(string $doc): bool => $doc === 'README.md' || preg_match('~^docs/[a-z0-9_-]+\.md$~', $doc) === 1;
$vpsDoc = (string)($_GET['doc'] ?? 'README.md');
if (!$vpsIsDoc($vpsDoc)) {
    $vpsDoc = 'README.md';
}
$vpsHelpUrl = static fn(string $doc): string => $APPLICATION->GetCurPage() . '?lang=' . LANGUAGE_ID
    . ($doc === 'README.md' ? '' : '&doc=' . urlencode($doc));
$vpsSettingsUrl = '/bitrix/admin/settings.php?lang=' . LANGUAGE_ID . '&mid=vspace.pagespeed&mid_menu=1';

$vpsPath = dirname(__DIR__) . '/' . $vpsDoc;
$vpsText = is_file($vpsPath) ? (string)file_get_contents($vpsPath) : null;

// заголовок файла — в заголовок страницы админки
$vpsTitle = GetMessage('VPS_HELP_TITLE');
if ($vpsText !== null && preg_match('/^#[ \t]+(.+?)[ \t]*\r?\n/', $vpsText, $vpsMatch)) {
    $vpsTitle = $vpsMatch[1];
    $vpsText = substr($vpsText, strlen($vpsMatch[0]));
}
$APPLICATION->SetTitle($vpsTitle);

// относительные ссылки на другие .md модуля ведут на эту же страницу
$vpsMarkdown = new Markdown(static function (string $url) use ($vpsDoc, $vpsIsDoc, $vpsHelpUrl): string {
    if (preg_match('~^(?:[a-z][a-z0-9+.-]*:|/|#)~i', $url)) {
        return $url;
    }
    $parts = [];
    $dir = dirname($vpsDoc);
    foreach (explode('/', ($dir === '.' ? '' : $dir . '/') . strtok($url, '#')) as $part) {
        if ($part === '..') {
            array_pop($parts);
        } elseif ($part !== '' && $part !== '.') {
            $parts[] = $part;
        }
    }
    $target = implode('/', $parts);
    return $vpsIsDoc($target) ? $vpsHelpUrl($target) : $url;
});

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';
?>
<style>
    .vps-help{max-width:960px;padding:8px 28px 20px;background:#fff;border-radius:4px;font-size:14px;line-height:1.55;color:#333}
    .vps-help-nav{margin:0 0 14px}
    .vps-help h1{font-size:22px;margin:22px 0 12px}
    .vps-help h2{font-size:18px;margin:26px 0 10px;padding-bottom:5px;border-bottom:1px solid #dce1e4}
    .vps-help h3{font-size:15px;margin:20px 0 8px}
    .vps-help p,.vps-help ul,.vps-help ol{margin:0 0 12px}
    .vps-help li{margin:4px 0}
    .vps-help code{padding:1px 4px;background:#eef2f4;border-radius:3px;font:13px/1.4 Consolas,"Courier New",monospace}
    .vps-help pre{margin:0 0 14px;padding:10px 14px;background:#f5f7f8;border:1px solid #dce1e4;border-radius:4px;overflow:auto}
    .vps-help pre code{padding:0;background:none}
    .vps-help table{margin:0 0 14px;border-collapse:collapse}
    .vps-help th,.vps-help td{padding:6px 10px;border:1px solid #dce1e4;text-align:left;vertical-align:top}
    .vps-help th{background:#eef2f4}
</style>
<div class="vps-help">
    <p class="vps-help-nav">
        <a href="<?= htmlspecialcharsbx($vpsSettingsUrl) ?>"><?= GetMessage('VPS_HELP_SETTINGS') ?></a>
        <?php if ($vpsDoc !== 'README.md'): ?>
            · <a href="<?= htmlspecialcharsbx($vpsHelpUrl('README.md')) ?>"><?= GetMessage('VPS_HELP_README') ?></a>
        <?php endif; ?>
    </p>
    <?php if ($vpsText === null): ?>
        <?php CAdminMessage::ShowMessage(GetMessage('VPS_HELP_NOT_FOUND', ['#FILE#' => $vpsDoc])); ?>
    <?php else: ?>
        <?= $vpsMarkdown->toHtml($vpsText) ?>
    <?php endif; ?>
</div>
<?php
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
