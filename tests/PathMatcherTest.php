<?php

namespace Vspace\PageSpeed\Tests;

use PHPUnit\Framework\TestCase;
use Vspace\PageSpeed\Config;
use Vspace\PageSpeed\PathMatcher;

final class PathMatcherTest extends TestCase
{
    public function testPaths(): void
    {
        $rules = ['/personal/', '/services/*-redesign/', '~^/test/\d+/$'];
        $this->assertTrue(PathMatcher::matchPath('/personal/orders/', $rules));
        $this->assertTrue(PathMatcher::matchPath('/services/seo-redesign/', $rules));
        $this->assertFalse(PathMatcher::matchPath('/services/seo-redesign/prices/', $rules));
        $this->assertTrue(PathMatcher::matchPath('/test/15/', $rules));
        $this->assertFalse(PathMatcher::matchPath('/services/seo/', $rules));
        $this->assertFalse(PathMatcher::matchPath('/x/', ['~[broken']));
    }

    public function testHosts(): void
    {
        $rules = ['design-demis.ru', 'crm.', '*.double-d.ru'];
        $this->assertTrue(PathMatcher::matchHost('design-demis.ru', $rules));
        $this->assertTrue(PathMatcher::matchHost('WWW.design-demis.ru:443', $rules));
        $this->assertTrue(PathMatcher::matchHost('crm.demis.ru', $rules));
        $this->assertTrue(PathMatcher::matchHost('a.double-d.ru', $rules));
        $this->assertFalse(PathMatcher::matchHost('demis.ru', $rules));
        $this->assertFalse(PathMatcher::matchHost('seo.demis.ru', $rules));
    }

    public function testConfigParsing(): void
    {
        $config = new Config([
            'ACTIVE' => 'Y',
            'EXCLUDE_URLS' => " /a/ \r\n\r\n/b/ ",
            'FOLD_BLOCK_CLASSES' => "panel, section\npanel",
            'FOLD_BLOCKS' => '-3',
            'FONT_FACE' => 'weird',
        ]);
        $this->assertTrue($config->active);
        $this->assertSame(['/a/', '/b/'], $config->excludeUrls);
        $this->assertSame(['panel', 'section'], $config->foldBlockClasses);
        $this->assertSame(0, $config->foldBlocks);
        $this->assertSame('used', $config->fontFace);
        $this->assertNotSame((new Config(['FONT_FACE' => 'all']))->criticalHash(), $config->criticalHash());
    }
}
