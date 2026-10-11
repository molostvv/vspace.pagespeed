<?php

namespace Vspace\PageSpeed\Processor;

use Vspace\PageSpeed\Context;
use Vspace\PageSpeed\Html\Document;
use Vspace\PageSpeed\Html\Edits;

/**
 * Шаг оптимизации страницы. Процессор читает неизменный Document и складывает правки в свой Edits;
 * исключение отменяет только его правки (см. Optimizer).
 */
interface ProcessorInterface
{
    /** Короткое имя для статистики и отладочного вывода. */
    public function name(): string;

    public function process(Document $doc, Edits $edits, Context $ctx): void;
}
