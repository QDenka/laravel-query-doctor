<?php

declare(strict_types=1);

namespace QDenka\QueryDoctor\Domain\Enums;

enum CaptureContext: string
{
    case Http = 'http';
    case Queue = 'queue';
    case Cli = 'cli';
}
