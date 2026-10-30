<?php

namespace Vspace\PageSpeed\Tests\Html;

use PHPUnit\Framework\TestCase;
use Vspace\PageSpeed\Config;
use Vspace\PageSpeed\Context;
use Vspace\PageSpeed\Html\BodyStartMover;
use Vspace\PageSpeed\Html\Document;
use Vspace\PageSpeed\Html\Edits;
use Vspace\PageSpeed\Optimizer;

final class BodyStartMoverTest extends TestCase
{
    private const BANNER = '<div class="policy-cookie" id="policy-cookie-note"><div class="policy-cookie-container">'
        . '<div class="policy-cookie-entry">Мы используем <a href="/company/cookie/">Cookies</a></div>'
        . '<div class="policy-cookie-action"><button type="button">Согласен</button></div></div></div>';

    private function move(string $html, array $ids, ?Context &$ctx = null): string
    {
        $doc = Document::fromHtml($html);
        $edits = new Edits();
        $ctx = new Context(new Config(), sys_get_temp_dir());
        (new BodyStartMover($ids))->process($doc, $edits, $ctx);
        return $edits->apply($html);
    }

    public function testMovesFooterBannerRightAfterBody(): void
    {
        $html = '<!DOCTYPE html><html><head></head><body class="page"><header>h</header><main>m</main>'
            . '<footer>f</footer>' . "\n    " . self::BANNER . "\n" . '<a href="#mm-0" class="anchor">up</a></body></html>';
        $out = $this->move($html, ['policy-cookie-note'], $ctx);

        $this->assertSame('<!DOCTYPE html><html><head></head><body class="page">' . self::BANNER
            . '<header>h</header><main>m</main><footer>f</footer>' . "\n    \n" . '<a href="#mm-0" class="anchor">up</a></body></html>', $out);
        $this->assertSame(1, substr_count($out, 'id="policy-cookie-note"'));
        $this->assertSame(1, $ctx->stats['moved']);
    }

    public function testKeepsOrderOfSeveralIdsAndSkipsMissing(): void
    {
        $html = '<html><head></head><body><p>text</p><div id="b">B</div><div id="a">A <div>inner</div></div></body></html>';
        $out = $this->move($html, ['a', 'b', 'missing'], $ctx);
        $this->assertSame('<html><head></head><body><div id="b">B</div><div id="a">A <div>inner</div></div><p>text</p></body></html>', $out);
        $this->assertSame(2, $ctx->stats['moved']);
    }

    public function testAlreadyFirstOrAbsentLeavesPageAsIs(): void
    {
        $first = '<html><head></head><body>' . "\n" . self::BANNER . '<p>x</p></body></html>';
        $this->assertSame($first, $this->move($first, ['policy-cookie-note'], $ctx));
        $this->assertArrayNotHasKey('moved', $ctx->stats);

        $none = '<html><head></head><body><p>x</p></body></html>';
        $this->assertSame($none, $this->move($none, ['policy-cookie-note']));
        $this->assertSame($none, $this->move($none, []));
    }

    public function testIdInsideScriptOrCommentIsNotAnElement(): void
    {
        $html = '<html><head></head><body><p>x</p><script>var s = \'<div id="policy-cookie-note">\';</script></body></html>';
        $this->assertSame($html, $this->move($html, ['policy-cookie-note']));
    }

    /** В конвейере перенос идёт вместе с critical CSS: без него первая отрисовка ждёт весь CSS, и переносить незачем. */
    public function testOptimizerDoesNotMoveWithoutCritical(): void
    {
        $html = '<!DOCTYPE html><html><head></head><body><p>x</p>' . self::BANNER . '</body></html>';
        $root = sys_get_temp_dir();
        $config = new Config(['ACTIVE' => 'Y', 'CRITICAL_ACTIVE' => 'N', 'IMAGES_ACTIVE' => 'N', 'WEBP_ACTIVE' => 'N', 'JS_ACTIVE' => 'N']);
        $this->assertSame($html, Optimizer::create($config, $root)->optimize($html, new Context($config, $root)));
    }
}
