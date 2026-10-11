<?php

namespace Vspace\PageSpeed\Tests\Html;

use PHPUnit\Framework\TestCase;
use Vspace\PageSpeed\Html\AtomCollector;
use Vspace\PageSpeed\Html\Document;
use Vspace\PageSpeed\Html\FoldDetector;

final class FoldTest extends TestCase
{
    private function page(string $body): Document
    {
        return Document::fromHtml('<!DOCTYPE html><html class="no-js"><head></head><body class="page">' . $body . '</body></html>');
    }

    private const HEADER = '<header class="site-header"><nav class="navbar"><div class="navbar-dropdown"><a class="deep-link">x</a></div></nav></header>';

    public function testCountsTopLevelBlocksAfterContentStart(): void
    {
        $doc = $this->page(self::HEADER . '<section class="page__content">'
            . '<div class="panel panel--hero"><div class="panel inner"></div></div>'
            . '<div class="panel panel--two"></div>'
            . '<div class="panel panel--three"></div></section>');
        $fold = (new FoldDetector(['page__content'], ['panel'], 2, 0))->detect($doc);
        $this->assertSame('blocks', $fold['reason']);
        $this->assertSame(strpos($doc->html, '<div class="panel panel--three">'), $fold['boundary']);
    }

    public function testMarkerWins(): void
    {
        $doc = $this->page('<div class="panel"></div><!--vps-fold--><div class="panel"></div>');
        $fold = (new FoldDetector([], ['panel'], 5, 0))->detect($doc);
        $this->assertSame(['marker', strpos($doc->html, '<!--vps-fold-->')], [$fold['reason'], $fold['boundary']]);
    }

    public function testLimitCountsFromFirstBlockWithoutContentStart(): void
    {
        $doc = $this->page('<header>' . str_repeat('<i></i>', 100) . '</header><div class="section">' . str_repeat('x', 1000) . '</div>');
        $fold = (new FoldDetector(['page__content'], ['section'], 2, 100))->detect($doc);
        $this->assertSame('limit', $fold['reason']);
        $this->assertSame(strpos($doc->html, '<div class="section">') + 100, $fold['boundary']);
    }

    public function testNoBlocksMeansWholeBody(): void
    {
        $doc = $this->page('<p>text</p>');
        $fold = (new FoldDetector([], ['panel'], 2, 0))->detect($doc);
        $this->assertSame(['end', $doc->bodyEnd], [$fold['reason'], $fold['boundary']]);
    }

    public function testAtomsWithHiddenSubtreesAndExtraElements(): void
    {
        $doc = $this->page(self::HEADER . '<div id="main" class="panel"></div><!--vps-fold-->'
            . '<div class="below"></div><div class="cookie"><button class="cookie-btn">ok</button></div>');
        $fold = (new FoldDetector([], ['panel'], 1, 0))->detect($doc);
        $collector = new AtomCollector(['navbar-dropdown']);
        $atoms = $collector->collect($doc, $fold['start'], $fold['boundary']);

        $this->assertArrayHasKey('navbar-dropdown', $atoms->classes); // сам скрытый элемент учитывается
        $this->assertArrayNotHasKey('deep-link', $atoms->classes);   // его содержимое — нет
        $this->assertArrayHasKey('no-js', $atoms->classes);          // классы <html>
        $this->assertArrayHasKey('page', $atoms->classes);           // классы <body>
        $this->assertArrayHasKey('main', $atoms->ids);
        $this->assertArrayHasKey('header', $atoms->tags);
        $this->assertArrayNotHasKey('below', $atoms->classes);

        $collector->collectElements($doc, ['cookie'], $atoms);
        $this->assertArrayHasKey('cookie-btn', $atoms->classes);
        $this->assertArrayHasKey('button', $atoms->tags);
        $this->assertArrayNotHasKey('below', $atoms->classes);
    }
}
