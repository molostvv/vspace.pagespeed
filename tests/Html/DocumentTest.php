<?php

namespace Vspace\PageSpeed\Tests\Html;

use PHPUnit\Framework\TestCase;
use Vspace\PageSpeed\Html\Document;
use Vspace\PageSpeed\Html\Edits;
use Vspace\PageSpeed\Html\Tag;

final class DocumentTest extends TestCase
{
    private const HTML = '<!DOCTYPE html><html lang="ru"><head><title>a<b></title>'
        . '<script>if (a<b) { s = "<link rel=stylesheet href=x.css>"; }</script>'
        . '<!-- <link rel="stylesheet" href="commented.css"> -->'
        . '<link rel="stylesheet" href="/real.css"></head>'
        . '<body class="page"><div class="a"><div class="b"></div><img src="i.png"></div><p>t</p></body></html>';

    public function testSectionsAndProtectedRanges(): void
    {
        $doc = Document::fromHtml(self::HTML);
        $this->assertNotNull($doc);
        $links = array_values(array_filter($doc->tags(), static fn(array $t): bool => $t['name'] === 'link'));
        $this->assertCount(1, $links);
        $this->assertStringContainsString('/real.css', $links[0]['attrs']);
        $this->assertSame('<body class="page">', substr(self::HTML, $doc->bodyStart, $doc->bodyOpenEnd - $doc->bodyStart));
        $this->assertSame('</head>', substr(self::HTML, $doc->headEnd, 7));
        $this->assertSame('</body>', substr(self::HTML, $doc->bodyEnd, 7));
    }

    public function testElementEnd(): void
    {
        $doc = Document::fromHtml(self::HTML);
        $tags = $doc->tags();
        $outer = $doc->firstTagIndexAt($doc->bodyOpenEnd);
        $this->assertSame('div', $tags[$outer]['name']);
        $end = $doc->elementEndIndex($outer);
        $this->assertTrue($tags[$end]['close']);
        $this->assertSame('div', $tags[$end]['name']);
        $this->assertSame('p', $tags[$end + 1]['name']);
        $img = $outer + 3;
        $this->assertSame('img', $tags[$img]['name']);
        $this->assertSame($img, $doc->elementEndIndex($img));
    }

    public function testRawElementAndNonHtml(): void
    {
        $html = '<html><head><style id="s">.a{b:c}</style></head><body></body></html>';
        $doc = Document::fromHtml($html);
        $style = strpos($html, '<style');
        $raw = $doc->rawElement($style);
        $this->assertSame('.a{b:c}', substr($html, $raw['contentStart'], $raw['contentEnd'] - $raw['contentStart']));
        $this->assertSame(strpos($html, '</head>'), $raw['end']);
        $this->assertNull(Document::fromHtml('{"json":true}'));
        $this->assertNull(Document::fromHtml('<div>fragment</div>'));
    }

    public function testTagParseAndBuild(): void
    {
        $tag = Tag::parse("<link href='/a.css?v=1&amp;x=2' rel=stylesheet data-template-style=\"true\" disabled>");
        $this->assertSame('/a.css?v=1&x=2', $tag->get('href'));
        $this->assertSame('stylesheet', $tag->get('rel'));
        $this->assertSame('', $tag->get('disabled'));
        $this->assertNull($tag->get('media'));
        $tag->set('media', 'print')->set('onload', "this.media='all'")->remove('disabled');
        $this->assertSame(
            "<link href='/a.css?v=1&amp;x=2' rel=stylesheet data-template-style=\"true\" media=\"print\" onload=\"this.media='all'\">",
            $tag->toHtml()
        );
        $this->assertSame('a  b', Tag::attrFromString(' data-class="x" class="a  b"', 'class'));
        $this->assertSame(['a', 'b'], Tag::splitClasses(' a  b '));
    }

    public function testEditsApplyInOrder(): void
    {
        $edits = new Edits();
        $edits->replace(2, 4, 'XY');
        $edits->insert(2, '[1]');
        $edits->insert(2, '[2]');
        $edits->replace(3, 5, 'skipped'); // пересекается с первой заменой
        $edits->insert(6, '!');
        $this->assertSame('ab[1][2]XYef!gh', $edits->apply('abcdefgh'));
    }
}
