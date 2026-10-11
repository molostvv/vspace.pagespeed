<?php

namespace Vspace\PageSpeed\Tests\Css;

use PHPUnit\Framework\TestCase;
use Vspace\PageSpeed\Css\CriticalExtractor;
use Vspace\PageSpeed\Css\StylesheetIndexer;
use Vspace\PageSpeed\Html\AtomSet;

final class CriticalExtractorTest extends TestCase
{
    private function atoms(array $classes = [], array $ids = [], array $tags = []): AtomSet
    {
        $set = new AtomSet();
        array_map([$set, 'addClass'], $classes);
        array_map([$set, 'addId'], $ids);
        array_map([$set, 'addTag'], $tags);
        return $set;
    }

    private function extract(string $css, AtomSet $atoms, ?CriticalExtractor $extractor = null, ?string $media = null): string
    {
        $index = (new StylesheetIndexer(sys_get_temp_dir()))->indexCss($css, '/css/');
        return ($extractor ?? new CriticalExtractor())->extract([['nodes' => $index['nodes'], 'media' => $media]], $atoms);
    }

    public function testKeepsOnlyMatchingSelectorsOfARule(): void
    {
        $css = '.a,.b{color:red}.c{color:blue}div p{margin:0}*{box-sizing:border-box}';
        $this->assertSame(
            '.a{color:red}div p{margin:0}*{box-sizing:border-box}',
            $this->extract($css, $this->atoms(['a'], [], ['div', 'p']))
        );
    }

    public function testMediaGroupsArePrunedAndPrintIsDropped(): void
    {
        $css = '@media (max-width:767px){.a{x:1}.z{x:2}}@media (min-width:1px){.z{x:3}}@media print{.a{x:4}}';
        $this->assertSame('@media (max-width:767px){.a{x:1}}', $this->extract($css, $this->atoms(['a'])));
    }

    public function testInteractionStatesAreDroppedByDefault(): void
    {
        $css = '.a{x:1}.a:hover{x:2}.a:not(:hover){x:3}';
        $this->assertSame('.a{x:1}.a:not(:hover){x:3}', $this->extract($css, $this->atoms(['a'])));
        $this->assertSame(
            '.a{x:1}.a:hover{x:2}.a:not(:hover){x:3}',
            $this->extract($css, $this->atoms(['a']), new CriticalExtractor(false))
        );
    }

    public function testFontFacesOnlyForUsedFamiliesWithSwap(): void
    {
        $css = '@font-face{font-family:"Manrope";src:url(m.woff2)}'
            . '@font-face{font-family:Other;src:url(o.woff2)}'
            . '@font-face{font-family:Mono;src:url(x.woff2);font-display:block}'
            . '.a{font:700 16px/1.2 "Manrope",sans-serif}.b{font-family:Mono}';
        $this->assertSame(
            '@font-face{font-family:"Manrope";src:url(/css/m.woff2);font-display:swap}'
            . '.a{font:700 16px/1.2 "Manrope",sans-serif}',
            $this->extract($css, $this->atoms(['a']))
        );
        $result = $this->extract($css, $this->atoms(['a']), new CriticalExtractor(true, 'all', false));
        $this->assertStringContainsString('font-family:Other', $result);
        $this->assertStringNotContainsString('font-display:swap', $result);
    }

    public function testFontFamilyViaCustomProperty(): void
    {
        $css = '@font-face{font-family:Inter;src:url(i.woff2)}:root{--ff:Inter,sans-serif}.a{font-family:var(--ff)}';
        $this->assertStringContainsString('@font-face{font-family:Inter', $this->extract($css, $this->atoms(['a'])));
    }

    public function testOnlyReferencedKeyframes(): void
    {
        $css = '@keyframes spin{to{transform:rotate(360deg)}}@-webkit-keyframes fade{to{opacity:0}}.a{animation:spin 1s infinite}';
        $this->assertSame(
            '.a{animation:spin 1s infinite}@keyframes spin{to{transform:rotate(360deg)}}',
            $this->extract($css, $this->atoms(['a']))
        );
    }

    public function testStylesheetMediaWrapsResult(): void
    {
        $this->assertSame('@media (min-width:768px){.a{x:1}}', $this->extract('.a{x:1}', $this->atoms(['a']), null, '(min-width:768px)'));
        $this->assertSame('.a{x:1}', $this->extract('.a{x:1}', $this->atoms(['a']), null, 'all'));
    }

    public function testDuplicateRulesKeepLastOccurrence(): void
    {
        $indexer = new StylesheetIndexer(sys_get_temp_dir());
        $main = $indexer->indexCss('.a{x:1}.b{y:1}@media (min-width:768px){.a{x:2}}.c{z:1}', '/css/');
        $page = $indexer->indexCss('.a{x:1}.b{y:2}@media (min-width:768px){.a{x:2}.c{z:1}}', '/css/');
        $result = (new CriticalExtractor())->extract([
            ['nodes' => $main['nodes'], 'media' => null],
            ['nodes' => $page['nodes'], 'media' => null],
        ], $this->atoms(['a', 'b', 'c']));
        // .a{x:1} и @media{.a{x:2}} из первого файла повторены во втором — остаются только поздние копии;
        // .b с другим телом и .c в другом контексте — разные правила; пустая обёртка @media не выводится
        $this->assertSame('.b{y:1}.c{z:1}.a{x:1}.b{y:2}@media (min-width:768px){.a{x:2}.c{z:1}}', $result);
    }

    public function testNothingMatchedGivesEmptyString(): void
    {
        $this->assertSame('', $this->extract('@font-face{font-family:A}.a{x:1}', $this->atoms(['zzz'])));
    }

    public function testPrintOnlyDetection(): void
    {
        $this->assertTrue(CriticalExtractor::isPrintOnly('print'));
        $this->assertTrue(CriticalExtractor::isPrintOnly('only print and (orientation:landscape)'));
        $this->assertFalse(CriticalExtractor::isPrintOnly('screen, print'));
        $this->assertFalse(CriticalExtractor::isPrintOnly('not print'));
    }
}
