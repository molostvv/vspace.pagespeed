<?php

namespace Vspace\PageSpeed\Tests\Admin;

use PHPUnit\Framework\TestCase;
use Vspace\PageSpeed\Admin\Markdown;

final class MarkdownTest extends TestCase
{
    public function testBlocks(): void
    {
        $html = (new Markdown())->toHtml(implode("\n", [
            '## Заголовок',
            '',
            'Первая строка абзаца',
            'вторая строка.',
            '- пункт `a|b`',
            '  продолжение',
            '- второй',
            '',
            '1. раз',
            '2. два',
            '',
            '```',
            '<b> & **не жирный**',
            '```',
            '---',
        ]));
        $this->assertSame(implode("\n", [
            '<h2>Заголовок</h2>',
            '<p>Первая строка абзаца вторая строка.</p>',
            '<ul><li>пункт <code>a|b</code> продолжение</li><li>второй</li></ul>',
            '<ol><li>раз</li><li>два</li></ol>',
            '<pre><code>&lt;b&gt; &amp; **не жирный**</code></pre>',
            '<hr>',
        ]), $html);
    }

    public function testHeadingAnchor(): void
    {
        $this->assertSame('<h3 id="webp">WebP <code>x</code></h3>', (new Markdown())->toHtml('### WebP `x` {#webp}'));
    }

    public function testTable(): void
    {
        // «х» — байт 0x85 в UTF-8, не перевод строки
        $html = (new Markdown())->toHtml("| A | B |\n|---|---|\n| `x|y` | 1 \\| 2 |\n| **хорошо** |  |");
        $this->assertSame(
            '<table><thead><tr><th>A</th><th>B</th></tr></thead><tbody>'
            . '<tr><td><code>x|y</code></td><td>1 | 2</td></tr><tr><td><strong>хорошо</strong></td><td></td></tr>'
            . '</tbody></table>',
            $html
        );
    }

    public function testInline(): void
    {
        $markdown = new Markdown(static fn(string $url): string => str_ends_with($url, '.md') ? 'help.php?doc=' . $url : $url);
        $this->assertSame(
            '<p><strong>жирный</strong>, <em>курсив</em>, * TTFB*, &lt;script&gt;, <code>*код*</code>, '
            . '<a href="help.php?doc=docs/a.md">анализ</a>, '
            . '<a href="https://example.com/?a=1&amp;b=2" target="_blank" rel="noopener">сайт</a>, плохая</p>',
            $markdown->toHtml('**жирный**, *курсив*, \* TTFB*, <script>, `*код*`, [анализ](docs/a.md), '
                . '[сайт](https://example.com/?a=1&b=2), [плохая](javascript:void)')
        );
    }

    public function testModuleDocsRender(): void
    {
        $markdown = new Markdown();
        foreach (['README.md', 'docs/ammina-analysis.md'] as $file) {
            $html = $markdown->toHtml(file_get_contents(dirname(__DIR__, 2) . '/' . $file));
            $this->assertStringNotContainsString('**', $html, $file);
            $this->assertStringNotContainsString('|---', $html, $file);
            $this->assertStringContainsString('<table>', $html, $file);
        }
    }
}
