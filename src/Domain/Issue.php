<?php

declare(strict_types=1);

namespace QDenka\QueryDoctor\Domain;

use QDenka\QueryDoctor\Domain\Enums\IssueType;
use QDenka\QueryDoctor\Domain\Enums\Severity;

final readonly class Issue
{
    /**
     * @param  string  $id  Deterministic ID: hash of type + fingerprint + contextId
     * @param  IssueType  $type  Which analyzer detected this
     * @param  Severity  $severity  How severe the issue is
     * @param  float  $confidence  0.0 to 1.0, how certain the analyzer is
     * @param  string  $title  One-line summary
     * @param  string  $description  Full explanation
     * @param  Evidence  $evidence  Supporting data
     * @param  Recommendation  $recommendation  What to do about it
     * @param  SourceContext|null  $sourceContext  Where in the app the issue originates
     * @param  \DateTimeImmutable  $createdAt  When first detected
     */
    public function __construct(
        public string $id,
        public IssueType $type,
        public Severity $severity,
        public float $confidence,
        public string $title,
        public string $description,
        public Evidence $evidence,
        public Recommendation $recommendation,
        public ?SourceContext $sourceContext,
        public \DateTimeImmutable $createdAt,
    ) {}

    /**
     * Generate a deterministic issue ID from its key attributes.
     * Same problem in the same context always produces the same ID.
     */
    public static function generateId(IssueType $type, QueryFingerprint $fingerprint, string $contextId): string
    {
        return hash('sha256', $type->value.':'.$fingerprint->hash.':'.$contextId);
    }
}
