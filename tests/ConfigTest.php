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

    public function testWebpQuality(): void
    {
        $this->assertSame(80, (new Config([]))->webpQuality);
        $this->assertSame(65, (new Config(['WEBP_QUALITY' => '65']))->webpQuality);
        $this->assertSame(80, (new Config(['WEBP_QUALITY' => '0']))->webpQuality);
        $this->assertSame(80, (new Config(['WEBP_QUALITY' => '101']))->webpQuality);
    }

    public function testExcludeTemplates(): void
    {
        $this->assertSame(['demis_group', 'black_theme', 'black_theme_sidebar', 'black_theme_sidebar_white', 'seo_dark', 'seo_light', 'double_d'],
            (new Config())->excludeTemplates);
        $this->assertSame(['demis_group', 'seo_dark'], (new Config(['EXCLUDE_TEMPLATES' => "Demis_Group\r\n seo_dark "]))->excludeTemplates);
        $this->assertSame([], (new Config(['EXCLUDE_TEMPLATES' => '']))->excludeTemplates);
    }
}
