<?php

declare(strict_types=1);

namespace QDenka\QueryDoctor\Domain;

final readonly class Recommendation
{
    /**
     * @param  string  $action  What to do (e.g. "Add eager loading")
     * @param  string|null  $code  Suggested code snippet (e.g. "->with('posts')")
     * @param  string|null  $docsUrl  Link to relevant documentation
     */
    public function __construct(
        public string $action,
        public ?string $code = null,
        public ?string $docsUrl = null,
    ) {}
}
