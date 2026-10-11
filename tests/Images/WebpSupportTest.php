<?php

namespace Vspace\PageSpeed\Tests\Images;

use PHPUnit\Framework\TestCase;
use Vspace\PageSpeed\Images\WebpSupport;

final class WebpSupportTest extends TestCase
{
    private const PAGE_ACCEPT = 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8';

    /** @return array<string, array{0:string, 1:string, 2:bool}> */
    public static function browsers(): array
    {
        return [
            'Chrome по Accept' => ['text/html,application/xhtml+xml,image/avif,image/webp,image/apng,*/*;q=0.8', '', true],
            'Chrome по UA' => [self::PAGE_ACCEPT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36', true],
            'Яндекс Браузер' => [self::PAGE_ACCEPT, 'Mozilla/5.0 (Linux; Android 13) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 YaBrowser/24.4 Mobile Safari/537.36', true],
            'Firefox без image/webp в Accept' => [self::PAGE_ACCEPT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:130.0) Gecko/20100101 Firefox/130.0', true],
            'Firefox 60' => [self::PAGE_ACCEPT, 'Mozilla/5.0 (Windows NT 10.0; rv:60.0) Gecko/20100101 Firefox/60.0', false],
            'Safari 17 macOS' => [self::PAGE_ACCEPT, 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Safari/605.1.15', true],
            'Safari 15 macOS (может быть 10.15)' => [self::PAGE_ACCEPT, 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/15.6 Safari/605.1.15', false],
            'iPhone iOS 17' => [self::PAGE_ACCEPT, 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Mobile/15E148 Safari/604.1', true],
            'Telegram на iOS 15 (без Version/)' => [self::PAGE_ACCEPT, 'Mozilla/5.0 (iPhone; CPU iPhone OS 15_8 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148', true],
            'iOS 13' => [self::PAGE_ACCEPT, 'Mozilla/5.0 (iPhone; CPU iPhone OS 13_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/13.1.2 Mobile/15E148 Safari/604.1', false],
            'Chrome на iOS 13' => [self::PAGE_ACCEPT, 'Mozilla/5.0 (iPhone; CPU iPhone OS 13_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/87.0 Mobile/15E148 Safari/604.1', false],
            'старый Edge 17' => [self::PAGE_ACCEPT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/64.0.3282.140 Safari/537.36 Edge/17.17134', false],
            'IE 11' => [self::PAGE_ACCEPT, 'Mozilla/5.0 (Windows NT 10.0; Trident/7.0; rv:11.0) like Gecko', false],
            'YandexBot' => [self::PAGE_ACCEPT, 'Mozilla/5.0 (compatible; YandexBot/3.0; +http://yandex.com/bots)', false],
            'пусто' => ['', '', false],
        ];
    }

    /** @dataProvider browsers */
    public function testBrowser(string $accept, string $userAgent, bool $expected): void
    {
        $this->assertSame($expected, WebpSupport::browser($accept, $userAgent));
    }
}
