<?php

namespace Vspace\PageSpeed\Tests\Css;

use PHPUnit\Framework\TestCase;
use Vspace\PageSpeed\Css\SelectorAnalyzer;

final class SelectorAnalyzerTest extends TestCase
{
    /** @return array{0:string,1:list<string>,2:list<string>,3:list<string>,4:bool} */
    private function analyze(string $selector): array
    {
        return (new SelectorAnalyzer())->analyze($selector);
    }

    public function testCompoundAndCombinators(): void
    {
        [$text, $classes, $ids, $tags, $interaction] = $this->analyze('ul.menu > li.item + a#top ~ span');
        $this->assertSame('ul.menu > li.item + a#top ~ span', $text);
        $this->assertSame(['menu', 'item'], $classes);
        $this->assertSame(['top'], $ids);
        $this->assertSame(['ul', 'li', 'a', 'span'], $tags);
        $this->assertFalse($interaction);
    }

    public function testFunctionalPseudoClassesAndAttributesAreDropped(): void
    {
        [, $classes, , $tags] = $this->analyze('.card:not(.hidden) .title:nth-child(2n+1)[data-x="a b]"]');
        $this->assertSame(['card', 'title'], $classes);
        $this->assertSame([], $tags);

        [, $classes] = $this->analyze(':is(.a, .b) .c:has(> .d)');
        $this->assertSame(['c'], $classes);
    }

    public function testPseudoElementsAndRoot(): void
    {
        [, $classes, , $tags] = $this->analyze('.btn::before');
        $this->assertSame(['btn'], $classes);
        $this->assertSame([], $tags);

        [, $classes, $ids, $tags] = $this->analyze(':root');
        $this->assertSame([[], [], []], [$classes, $ids, $tags]);

        [, , , $tags] = $this->analyze('input::-webkit-input-placeholder');
        $this->assertSame(['input'], $tags);
    }

    public function testInteractionStates(): void
    {
        $this->assertTrue($this->analyze('a:hover')[4]);
        $this->assertTrue($this->analyze('.btn:focus-visible .icon')[4]);
        $this->assertTrue($this->analyze('a:visited')[4]);
        $this->assertFalse($this->analyze('.btn:not(:hover)')[4]);
        $this->assertFalse($this->analyze('input:checked')[4]);
        $this->assertFalse($this->analyze('a:hover-like')[4]);
    }

    public function testEscapedClassNames(): void
    {
        $this->assertSame(['uk-width-1-2@m'], $this->analyze('.uk-width-1-2\@m')[1]);
        $this->assertSame(['md:flex'], $this->analyze('.md\:flex:hover')[1]);
        $this->assertSame(['w-[10px]'], $this->analyze('.w-\[10px\]')[1]);
        $this->assertSame(['10'], $this->analyze('.\31 0')[1]);
        $this->assertSame(['кнопка'], $this->analyze('.кнопка')[1]);
    }

    public function testImplicitTagsAreNotRequired(): void
    {
        [, , , $tags] = $this->analyze('html body table tbody tr td');
        $this->assertSame(['table', 'tr', 'td'], $tags);
    }

    public function testNamespacePrefixIsNotATag(): void
    {
        $this->assertSame(['circle'], $this->analyze('svg|circle')[3]);
    }

    public function testSplitListRespectsParenthesesAndStrings(): void
    {
        $analyzer = new SelectorAnalyzer();
        $this->assertSame(
            ['.a', ':is(.b, .c) .d', '[title="x,y"]'],
            $analyzer->splitList('.a, :is(.b, .c) .d ,[title="x,y"]')
        );
        $this->assertCount(2, $analyzer->analyzeList('.a,.b'));
    }
}
