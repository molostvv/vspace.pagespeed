<?php

namespace Vspace\PageSpeed\Tests;

use PHPUnit\Framework\TestCase;
use Vspace\PageSpeed\Config;

final class ConfigTest extends TestCase
{
    public function testListsSplitOnlyOnLineBreaks(): void
    {
        // «х» в UTF-8 — D1 85; \R без флага u резал по 0x85, и из адреса получалось правило «/» на весь сайт
        $config = new Config(['EXCLUDE_URLS' => "/статьи/о-нас/\r\n\n  /услуги/хостинг/  \r/api/"]);
        $this->assertSame(['/статьи/о-нас/', '/услуги/хостинг/', '/api/'], $config->excludeUrls);
    }

    public function testTokens(): void
    {
        $config = new Config(['FOLD_BLOCK_CLASSES' => "panel, section\npanel  hero"]);
        $this->assertSame(['panel', 'section', 'hero'], $config->foldBlockClasses);
    }
}
