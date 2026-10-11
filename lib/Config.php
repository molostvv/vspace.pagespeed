<?php

namespace Vspace\PageSpeed;

/**
 * Настройки модуля одним неизменяемым объектом. Настройки общие для всего сайта (без профилей по сайтам и
 * страницам): отключение — списками страниц (EXCLUDE_URLS), доменов (EXCLUDE_HOSTS) и шаблонов (EXCLUDE_TEMPLATES).
 *
 * Ядро (Css, Html, Critical) получает только этот объект и не обращается к Битриксу — поэтому его можно
 * тестировать без ядра. Из \Bitrix\Main\Config\Option объект собирает Options::config().
 */
final class Config
{
    /** Значения по умолчанию; они же — список всех ключей (options.php выводит поля по этому списку). */
    public const DEFAULTS = [
        'ACTIVE' => 'N',
        'EXCLUDE_URLS' => "/personal/\n/api/",
        // те же домены, что исключены у ammina.optimizer
        'EXCLUDE_HOSTS' => "crm.\ndouble-d.\ndesign-demis.ru",
        // demis_group — redesign: пока не решено, будет ли он развиваться; black_theme*, seo_dark, seo_light —
        // старые SEO-лендинги (/services/seo-prodvizhenie-2/ и т. п.), double_d — /double_d/: модуль их не трогает
        'EXCLUDE_TEMPLATES' => "demis_group\nblack_theme\nblack_theme_sidebar\nblack_theme_sidebar_white\nseo_dark\nseo_light\ndouble_d",
        'DEBUG_PARAMS' => 'N',
        'AMMINA_DISABLE' => 'Y',

        'CRITICAL_ACTIVE' => 'Y',
        // redesign (шаблон demis_group), если убрать его из EXCLUDE_TEMPLATES: шрифт Manrope (TTF) приходит
        // после ранней отрисовки, и его подмена сдвигает hero — CLS 0.13 против 0.01 без critical
        'CRITICAL_EXCLUDE_URLS' => "*-redesign/*\n/redesign/",
        'FOLD_START_CLASSES' => 'page__content',
        // блоки контента: panel* — шаблон demis_edges, section — demis_group (redesign), page__shares__row и
        // list__news__item — ряд плиток и карточка списков статей (/articles/ и разделы): там нет панелей
        'FOLD_BLOCK_CLASSES' => "panel\npanel-hero\npage-header\nsection\npage__shares__row\nlist__news__item",
        // 3, а не 2: на телефоне блоки короче, и под hero виден верх ещё одного блока (главная, 390 px)
        'FOLD_BLOCKS' => 3,
        'FOLD_MAX_BYTES' => 40000,
        // скрытые при загрузке: меню, попап города, поиск (demis_edges) и .dropdown (demis_group)
        'FOLD_SKIP_CLASSES' => "navbar-dropdown\ncity-popup\nsite-header-search\ndropdown",
        // фиксированные внизу cookie-баннер и панель кнопок redesign на телефоне (в коде — в футере); классы,
        // которые добавляют скрипты: select от jQuery FormStyler, Owl Carousel (без owl-loaded карусель скрыта —
        // слайдер под hero на главной)
        'ADD_CLASSES' => "policy-cookie\npolicy-cookie-container\nonline-helper\njq-selectbox\njqselect\njq-selectbox__select\njq-selectbox__select-text\njq-selectbox__trigger\njq-selectbox__trigger-arrow\nowl-loaded\nowl-stage-outer\nowl-stage\nowl-item\nowl-nav\nowl-prev\nowl-next\nowl-dots\nowl-dot",
        'ADD_IDS' => '',
        'EXCLUDE_FILES' => '',
        'INLINE_STYLES' => 'Y',
        'INLINE_STYLES_MIN' => 8192,
        'DROP_INTERACTION' => 'Y',
        'FONT_FACE' => 'used',
        'FONT_DISPLAY_SWAP' => 'Y',
        'ASYNC_MODE' => 'preload',
        'CRITICAL_MAX_SIZE' => 204800,

        // картинки: без lazy в первом экране, preload фона hero, lazy ниже (img/iframe — нативно, фоны — скриптом)
        'IMAGES_ACTIVE' => 'Y',
        // WebP-копии JPEG/PNG (конвертирует агент из cron) для браузеров с WebP
        'WEBP_ACTIVE' => 'Y',

        // скрипты — после window.onload (как type="deferjs" у Ammina); исключения — те же, что у неё
        'JS_ACTIVE' => 'Y',
        'JS_EXCLUDE_FILES' => "/local/templates/demis_edges/scripts/import/HeaderJilin/\n/local/templates/demis_edges/js/inp.min.js",

        'CACHE_TTL_DAYS' => 30,

        // ключ Google PageSpeed Insights API для tools/psi.php (на работу модуля не влияет)
        'PSI_API_KEY' => '',
    ];

    public readonly bool $active;
    /** @var list<string> */
    public readonly array $excludeUrls;
    /** @var list<string> */
    public readonly array $excludeHosts;
    /** @var list<string> ID шаблонов сайта в нижнем регистре */
    public readonly array $excludeTemplates;
    public readonly bool $debugParams;
    public readonly bool $amminaDisable;

    public readonly bool $criticalActive;
    /** @var list<string> */
    public readonly array $criticalExcludeUrls;
    /** @var list<string> */
    public readonly array $foldStartClasses;
    /** @var list<string> */
    public readonly array $foldBlockClasses;
    public readonly int $foldBlocks;
    public readonly int $foldMaxBytes;
    /** @var list<string> */
    public readonly array $foldSkipClasses;
    /** @var list<string> */
    public readonly array $addClasses;
    /** @var list<string> */
    public readonly array $addIds;
    /** @var list<string> */
    public readonly array $excludeFiles;
    public readonly bool $inlineStyles;
    public readonly int $inlineStylesMin;
    public readonly bool $dropInteraction;
    /** used | all | none */
    public readonly string $fontFace;
    public readonly bool $fontDisplaySwap;
    /** preload | print */
    public readonly string $asyncMode;
    public readonly int $criticalMaxSize;

    public readonly bool $imagesActive;
    public readonly bool $webpActive;

    public readonly bool $jsActive;
    /** @var list<string> */
    public readonly array $jsExcludeFiles;

    public readonly int $cacheTtlDays;

    public readonly string $psiApiKey;

    /** @param array<string, mixed> $values ключи из DEFAULTS; отсутствующие берутся по умолчанию */
    public function __construct(array $values = [])
    {
        $v = $values + self::DEFAULTS;

        $this->active = self::flag($v['ACTIVE']);
        $this->excludeUrls = self::lines($v['EXCLUDE_URLS']);
        $this->excludeHosts = self::lines($v['EXCLUDE_HOSTS']);
        $this->excludeTemplates = self::tokens(strtolower((string)$v['EXCLUDE_TEMPLATES']));
        $this->debugParams = self::flag($v['DEBUG_PARAMS']);
        $this->amminaDisable = self::flag($v['AMMINA_DISABLE']);

        $this->criticalActive = self::flag($v['CRITICAL_ACTIVE']);
        $this->criticalExcludeUrls = self::lines($v['CRITICAL_EXCLUDE_URLS']);
        $this->foldStartClasses = self::tokens($v['FOLD_START_CLASSES']);
        $this->foldBlockClasses = self::tokens($v['FOLD_BLOCK_CLASSES']);
        $this->foldBlocks = max(0, (int)$v['FOLD_BLOCKS']);
        $this->foldMaxBytes = max(0, (int)$v['FOLD_MAX_BYTES']);
        $this->foldSkipClasses = self::tokens($v['FOLD_SKIP_CLASSES']);
        $this->addClasses = self::tokens($v['ADD_CLASSES']);
        $this->addIds = self::tokens($v['ADD_IDS']);
        $this->excludeFiles = self::lines($v['EXCLUDE_FILES']);
        $this->inlineStyles = self::flag($v['INLINE_STYLES']);
        $this->inlineStylesMin = max(0, (int)$v['INLINE_STYLES_MIN']);
        $this->dropInteraction = self::flag($v['DROP_INTERACTION']);
        $this->fontFace = in_array($v['FONT_FACE'], ['used', 'all', 'none'], true) ? $v['FONT_FACE'] : 'used';
        $this->fontDisplaySwap = self::flag($v['FONT_DISPLAY_SWAP']);
        $this->asyncMode = $v['ASYNC_MODE'] === 'print' ? 'print' : 'preload';
        $this->criticalMaxSize = max(0, (int)$v['CRITICAL_MAX_SIZE']);

        $this->imagesActive = self::flag($v['IMAGES_ACTIVE']);
        $this->webpActive = self::flag($v['WEBP_ACTIVE']);

        $this->jsActive = self::flag($v['JS_ACTIVE']);
        $this->jsExcludeFiles = self::lines($v['JS_EXCLUDE_FILES']);

        $this->cacheTtlDays = max(1, (int)$v['CACHE_TTL_DAYS']);

        $this->psiApiKey = trim((string)$v['PSI_API_KEY']);
    }

    /** Critical CSS включён и применяется на странице $path (не в CRITICAL_EXCLUDE_URLS). */
    public function criticalFor(string $path): bool
    {
        return $this->criticalActive && !PathMatcher::matchPath($path, $this->criticalExcludeUrls);
    }

    /**
     * Хеш настроек, от которых зависит текст critical CSS (входит в ключ кеша): при их изменении critical
     * пересобирается сам, без ручной очистки кеша. Списки классов сюда не входят — они меняют набор атомов
     * страницы, который и так часть ключа.
     */
    public function criticalHash(): string
    {
        return md5(implode('|', [
            (int)$this->dropInteraction,
            $this->fontFace,
            (int)$this->fontDisplaySwap,
        ]));
    }

    private static function flag(mixed $value): bool
    {
        return $value === true || $value === 'Y';
    }

    /**
     * Многострочное значение -> непустые строки без пробелов по краям. Не \R: без флага u он режет строку
     * по байту 0x85 — второму байту буквы «х» в UTF-8 (адрес с «х» превращался в правило «/» на весь сайт).
     */
    private static function lines(mixed $value): array
    {
        $result = [];
        foreach (preg_split('/\r\n|\r|\n/', (string)$value) as $line) {
            $line = trim($line);
            if ($line !== '') {
                $result[] = $line;
            }
        }
        return $result;
    }

    /** Список имён через пробелы, запятые или переводы строк -> уникальные токены. */
    private static function tokens(mixed $value): array
    {
        $parts = preg_split('/[\s,]+/', (string)$value, -1, PREG_SPLIT_NO_EMPTY);
        return array_values(array_unique($parts));
    }
}
