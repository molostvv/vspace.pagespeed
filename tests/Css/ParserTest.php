<?php

namespace Vspace\PageSpeed\Tests\Css;

use PHPUnit\Framework\TestCase;
use Vspace\PageSpeed\Css\Parser;

final class ParserTest extends TestCase
{
    private function parse(string $css): array
    {
        return (new Parser())->parse($css);
    }

    public function testRulesAndGroups(): void
    {
        $nodes = $this->parse('a{color:red} @media (min-width:768px){.b,.c{margin:0}} .d{}');
        $this->assertSame([Parser::RULE, 'a', 'color:red'], $nodes[0]);
        $this->assertSame(Parser::GROUP, $nodes[1][0]);
        $this->assertSame('media', $nodes[1][1]);
        $this->assertSame('(min-width:768px)', $nodes[1][2]);
        $this->assertSame([[Parser::RULE, '.b,.c', 'margin:0']], $nodes[1][3]);
        $this->assertSame([Parser::RULE, '.d', ''], $nodes[2]);
    }

    public function testBracesAndSemicolonsInsideStringsAndUrls(): void
    {
        $css = '.a{content:"}{;"}'
            . '.b{background:url(data:image/svg+xml;charset=utf8,%3Csvg%3E)}'
            . ".c{font-family:'x;y'}";
        $nodes = $this->parse($css);
        $this->assertCount(3, $nodes);
        $this->assertSame('content:"}{;"', $nodes[0][2]);
        $this->assertSame('background:url(data:image/svg+xml;charset=utf8,%3Csvg%3E)', $nodes[1][2]);
        $this->assertSame(".c", $nodes[2][1]);
    }

    public function testCommentsAndWhitespaceInSelectors(): void
    {
        $nodes = $this->parse("/* {x} */ .a /* c */ ,\n  .b { color: red } /* tail");
        $this->assertSame('.a , .b', $nodes[0][1]);
        $this->assertSame(' color: red ', $nodes[0][2]);
    }

    public function testAtRules(): void
    {
        $nodes = $this->parse('@charset "utf-8";@import url("x.css") screen;'
            . '@font-face{font-family:A;src:url(a.woff2)}'
            . '@keyframes spin{0%{transform:rotate(0)}to{transform:rotate(360deg)}}'
            . '@layer base, theme;@supports (display:grid){@media screen{.g{display:grid}}}');
        $this->assertSame([Parser::STATEMENT, 'charset', '"utf-8"'], $nodes[0]);
        $this->assertSame([Parser::STATEMENT, 'import', 'url("x.css") screen'], $nodes[1]);
        $this->assertSame([Parser::AT, 'font-face', '', 'font-family:A;src:url(a.woff2)'], $nodes[2]);
        $this->assertSame([Parser::AT, 'keyframes', 'spin', '0%{transform:rotate(0)}to{transform:rotate(360deg)}'], $nodes[3]);
        $this->assertSame([Parser::STATEMENT, 'layer', 'base, theme'], $nodes[4]);
        $this->assertSame('supports', $nodes[5][1]);
        $this->assertSame('media', $nodes[5][3][0][1]);
        $this->assertSame([Parser::RULE, '.g', 'display:grid'], $nodes[5][3][0][3][0]);
    }

    public function testEscapedCharactersInSelector(): void
    {
        $nodes = $this->parse('.w-1\/2{width:50%}.a\{b{x:y}');
        $this->assertSame('.w-1\/2', $nodes[0][1]);
        $this->assertSame('.a\{b', $nodes[1][1]);
    }

    public function testBrokenCssRecoversLikeBrowser(): void
    {
        $nodes = $this->parse('garbage; .a{color:red} } .b{color:blue');
        $this->assertSame('.a', $nodes[0][1]);
        $this->assertSame([Parser::RULE, '.b', 'color:blue'], $nodes[1]);
    }

    public function testBomIsIgnored(): void
    {
        $nodes = $this->parse("\xEF\xBB\xBF.a{b:c}");
        $this->assertSame('.a', $nodes[0][1]);
    }
}
