<?php

namespace Vspace\PageSpeed\Tests\Css;

use PHPUnit\Framework\TestCase;
use Vspace\PageSpeed\Css\Parser;
use Vspace\PageSpeed\Css\StylesheetIndexer;
use Vspace\PageSpeed\Css\UrlRebaser;

final class StylesheetIndexerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/vps-indexer-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/tpl/css/parts', 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->root);
    }

    public function testRebasesUrlsInlinesImportsAndCollectsAtoms(): void
    {
        file_put_contents($this->root . '/tpl/css/main.css', '@charset "utf-8";@import "parts/a.css" screen;@import url(//cdn.example/x.css);'
            . ".hero{background:url('../img/bg.jpg')}\n.hero .title , #top{ color : red ; }");
        file_put_contents($this->root . '/tpl/css/parts/a.css', '.part{background:url(i.png)}');

        $index = (new StylesheetIndexer($this->root))->indexFile('/tpl/css/main.css');

        $this->assertEqualsCanonicalizing(['hero', 'title', 'part'], array_keys($index['atoms']['c']));
        $this->assertSame(['top' => true], $index['atoms']['i']);
        $this->assertArrayHasKey('/tpl/css/parts/a.css', $index['deps']);

        [$import, $hero, $title] = $index['nodes'];
        $this->assertSame([Parser::GROUP, 'media', 'screen'], array_slice($import, 0, 3));
        $this->assertSame('background:url(/tpl/css/parts/i.png)', $import[3][0][1]);
        $this->assertSame("background:url('/tpl/img/bg.jpg')", $hero[1]);
        $this->assertSame('color:red', $title[1]);
        $this->assertSame('.hero .title', $title[2][0][0]);
        $this->assertSame('#top', $title[2][1][0]);
    }

    public function testCyclicImportsStop(): void
    {
        file_put_contents($this->root . '/tpl/css/a.css', '@import "b.css";.a{x:1}');
        file_put_contents($this->root . '/tpl/css/b.css', '@import "a.css";.b{x:1}');
        $index = (new StylesheetIndexer($this->root))->indexFile('/tpl/css/a.css');
        $this->assertEqualsCanonicalizing(['a', 'b'], array_keys($index['atoms']['c']));
    }

    public function testMissingFile(): void
    {
        $this->assertNull((new StylesheetIndexer($this->root))->indexFile('/nope.css'));
    }

    public function testUrlRebaser(): void
    {
        $css = 'a{b:url(x.png);c:url("../y.svg#i");d:url(\'/abs.png\');e:url(data:image/png;base64,AA==);f:url(https://e.x/z.png);g:url(./q/../w.gif?v=2)}';
        $this->assertSame(
            'a{b:url(/s/css/x.png);c:url("/s/y.svg#i");d:url(\'/abs.png\');e:url(data:image/png;base64,AA==);f:url(https://e.x/z.png);g:url(/s/css/w.gif?v=2)}',
            UrlRebaser::rebase($css, '/s/css/')
        );
        $this->assertSame('/a/c', UrlRebaser::normalize('/a/b/../c'));
        $this->assertSame('/', UrlRebaser::normalize('/a/../../'));
    }

    public function testLongDataUriDoesNotBreakRebasing(): void
    {
        $css = '.a{background:url("data:image/svg+xml,' . str_repeat('%3Cpath d=\'M0 0\'/%3E', 20000) . '")}.b{background:url(b.png)}';
        $this->assertStringEndsWith('.b{background:url(/c/b.png)}', UrlRebaser::rebase($css, '/c/'));
    }
}
