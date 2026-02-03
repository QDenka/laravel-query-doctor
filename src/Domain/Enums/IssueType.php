<?php

declare(strict_types=1);

namespace QDenka\QueryDoctor\Domain\Enums;

enum IssueType: string
{
    case NPlusOne = 'n_plus_one';
    case Duplicate = 'duplicate';
    case Slow = 'slow';
    case MissingIndex = 'missing_index';
    case SelectStar = 'select_star';

    public function label(): string
    {
        return match ($this) {
            self::NPlusOne => 'N+1 Query',
            self::Duplicate => 'Duplicate Query',
            self::Slow => 'Slow Query',
            self::MissingIndex => 'Missing Index',
            self::SelectStar => 'SELECT *',
        };
    }
}
