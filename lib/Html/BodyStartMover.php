<?php

namespace Vspace\PageSpeed\Html;

use Vspace\PageSpeed\Context;
use Vspace\PageSpeed\Processor\ProcessorInterface;

/**
 * Переносит элементы с заданными id в начало <body>, сразу после открывающего тега.
 *
 * Для закреплённых блоков (position: fixed), которые в разметке стоят в конце страницы, — плашка cookie и т. п.
 * На вид место в HTML не влияет, но с critical CSS первая отрисовка случается раньше, чем браузер дочитает конец
 * большой страницы: такой блок появлялся на 1–2 с позже остального и на телефоне становился LCP (самым крупным
 * элементом экрана). Из начала body он рисуется вместе с первым экраном.
 *
 * Переносится исходная разметка элемента: правки других процессоров внутри него не применяются (Edits пропускает
 * пересекающиеся правки) — для блоков без картинок и скриптов это ничего не меняет. Элемент с id внутри уже
 * перенесённого переезжает вместе с ним.
 */
final class BodyStartMover implements ProcessorInterface
{
    /** @param list<string> $ids */
    public function __construct(private readonly array $ids)
    {
    }

    public function name(): string
    {
        return 'move';
    }

    public function process(Document $doc, Edits $edits, Context $ctx): void
    {
        if (!$this->ids || $ctx->fragment) {
            return;
        }
        $wanted = array_flip($this->ids);
        $tags = $doc->tags();
        $count = count($tags);
        $first = $doc->firstTagIndexAt($doc->bodyOpenEnd);
        $moved = 0;
        for ($k = $first; $k < $count; $k++) {
            $tag = $tags[$k];
            if ($tag['pos'] >= $doc->bodyEnd) {
                break;
            }
            if ($tag['close'] || stripos($tag['attrs'], 'id') === false) {
                continue;
            }
            $id = Tag::attrFromString($tag['attrs'], 'id');
            if ($id === null || !isset($wanted[$id])) {
                continue;
            }
            unset($wanted[$id]);
            $endIndex = $doc->elementEndIndex($k);
            $start = $tag['pos'];
            $end = $tags[$endIndex]['end'];
            // уже первый в body (до него только пробелы) — переносить незачем
            if ($k === $first && trim(substr($doc->html, $doc->bodyOpenEnd, $start - $doc->bodyOpenEnd)) === '') {
                $k = $endIndex;
                continue;
            }
            $edits->insert($doc->bodyOpenEnd, substr($doc->html, $start, $end - $start));
            $edits->replace($start, $end, '');
            $moved++;
            $k = $endIndex;
            if (!$wanted) {
                break;
            }
        }
        if ($moved) {
            $ctx->stats['moved'] = $moved;
        }
    }
}
