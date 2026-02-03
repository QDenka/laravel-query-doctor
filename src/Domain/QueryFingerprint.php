<?php

declare(strict_types=1);

namespace QDenka\QueryDoctor\Domain;

final readonly class QueryFingerprint
{
    public string $hash;

    public function __construct(
        public string $value,
    ) {
        $this->hash = hash('sha256', $this->value);
    }

    /**
     * Create a fingerprint by normalizing raw SQL.
     *
     * Normalization steps:
     * 1. Strip SQL comments (-- and block comments)
     * 2. Replace string literals with ?
     * 3. Replace numeric literals with ?
     * 4. Collapse IN (?, ?, ...) to IN (?)
     * 5. Lowercase SQL keywords
     * 6. Collapse whitespace
     */
    public static function fromSql(string $sql): self
    {
        $normalized = $sql;

        // Strip block comments /* ... */
        $normalized = (string) preg_replace('/\/\*.*?\*\//s', '', $normalized);

        // Strip line comments -- ...
        $normalized = (string) preg_replace('/--[^\n]*/', '', $normalized);

        // Replace string literals 'value' and "value" with ?
        // Handles escaped quotes inside strings
        $normalized = (string) preg_replace("/(?<!')('(?:[^'\\\\]|\\\\.)*')(?!')/", '?', $normalized);
        $normalized = (string) preg_replace('/(?<!")("(?:[^"\\\\]|\\\\.)*")(?!")/', '?', $normalized);

        // Replace numeric literals with ?
        // Match standalone numbers, not inside identifiers
        $normalized = (string) preg_replace('/\b\d+\.?\d*\b/', '?', $normalized);

        // Collapse IN (?, ?, ..., ?) to IN (?)
        $normalized = (string) preg_replace('/\bIN\s*\(\s*\?(?:\s*,\s*\?)*\s*\)/i', 'IN (?)', $normalized);

        // Lowercase everything (SQL is case-insensitive for keywords)
        $normalized = strtolower($normalized);

        // Collapse whitespace
        $normalized = (string) preg_replace('/\s+/', ' ', $normalized);

        // Trim
        $normalized = trim($normalized);

        return new self($normalized);
    }

    public function __toString(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->hash === $other->hash;
    }
}
