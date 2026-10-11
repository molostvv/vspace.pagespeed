<?php

namespace Vspace\PageSpeed\Tests\Images;

use PHPUnit\Framework\TestCase;
use Vspace\PageSpeed\Images\InlineBackground;

final class InlineBackgroundTest extends TestCase
{
    public function testFirstUrl(): void
    {
        $this->assertSame('/img/hero.jpg', InlineBackground::firstUrl('height:300px; background-image: url("/img/hero.jpg")'));
        $this->assertSame('/img/a b.jpg', InlineBackground::firstUrl("background:#000 url('/img/a b.jpg') center/cover no-repeat"));
        $this->assertNull(InlineBackground::firstUrl('color:red'));
        $this->assertNull(InlineBackground::firstUrl('mask:url(/m.svg)'));
        $this->assertNull(InlineBackground::firstUrl('background-image:url(data:image/png;base64,AAA)'));
    }

    public function testDeferSimpleBackgrounds(): void
    {
        $this->assertSame(
            ['height:300px;background-image:none', 'url("/img/a.jpg")'],
            InlineBackground::defer('height:300px; background-image: url("/img/a.jpg");')
        );
        $this->assertSame(
            ['background:#000 none center/cover no-repeat;color:#fff', 'url(/img/b.jpg)'],
            InlineBackground::defer('background:#000 url(/img/b.jpg) center/cover no-repeat; color:#fff')
        );
    }

    public function testRiskyBackgroundsStayAsIs(): void
    {
        foreach ([
            'color:red',
            'background-image:url(/a.jpg), url(/b.jpg)',
            'background:linear-gradient(#000,#fff), url(/a.jpg)',
            'background-image:url(/a.jpg) !important',
            'background-image:image-set(url(/a.webp) type("image/webp"))',
            'background-image:url(data:image/png;base64,AAA)',
            'background:url(/a.jpg);background-image:url(/b.jpg)',
        ] as $style) {
            $this->assertNull(InlineBackground::defer($style), $style);
        }
    }
}
