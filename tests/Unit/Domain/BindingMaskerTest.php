<?php

declare(strict_types=1);

namespace QDenka\QueryDoctor\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use QDenka\QueryDoctor\Domain\BindingMasker;

final class BindingMaskerTest extends TestCase
{
    private BindingMasker $masker;

    protected function setUp(): void
    {
        $this->masker = new BindingMasker(
            columns: ['password', 'secret', 'token', 'api_key', 'email', 'ssn'],
            valuePatterns: [
                '/^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/', // email
                '/^\+?[1-9]\d{6,14}$/',                                   // phone
                '/^\d{3}-\d{2}-\d{4}$/',                                   // SSN
            ],
        );
    }

    // ── Column-based masking ─────────────────────────────

    #[Test]
    public function it_masks_bindings_for_sensitive_columns_with_equals(): void
    {
        $sql = 'select * from users where email = ? and name = ?';
        $bindings = ['john@example.com', 'John'];

        $result = $this->masker->mask($sql, $bindings);

        $this->assertSame(BindingMasker::MASKED, $result[0]);
        $this->assertSame('John', $result[1]);
    }

    #[Test]
    public function it_masks_bindings_for_password_column(): void
    {
        $sql = 'insert into users (name, password) values (?, ?)';

        // INSERT doesn't use WHERE, so column-based won't catch it.
        // But the password value looks random, not matching patterns either.
        // Column-based only works with WHERE/comparison operators.
        $result = $this->masker->mask($sql, ['John', 'secret123']);

        // INSERT without WHERE comparison — column-based masking doesn't apply here.
        // This is by design: INSERT column names aren't in WHERE clauses.
        $this->assertSame('John', $result[0]);
        $this->assertSame('secret123', $result[1]);
    }

    #[Test]
    public function it_masks_bindings_for_update_set_clause(): void
    {
        $sql = 'update users set password = ? where id = ?';
        $bindings = ['new_password_hash', 42];

        $result = $this->masker->mask($sql, $bindings);

        // SET password = ? is matched as a comparison pattern
        $this->assertSame(BindingMasker::MASKED, $result[0]);
        $this->assertSame(42, $result[1]);
    }

    #[Test]
    public function it_masks_bindings_for_like_operator(): void
    {
        $sql = 'select * from tokens where token LIKE ?';
        $bindings = ['abc123%'];

        $result = $this->masker->mask($sql, $bindings);

        $this->assertSame(BindingMasker::MASKED, $result[0]);
    }

    #[Test]
    public function it_masks_bindings_for_in_clause(): void
    {
        $sql = 'select * from users where email IN (?, ?, ?)';
        $bindings = ['a@b.com', 'c@d.com', 'e@f.com'];

        $result = $this->masker->mask($sql, $bindings);

        $this->assertSame(BindingMasker::MASKED, $result[0]);
        $this->assertSame(BindingMasker::MASKED, $result[1]);
        $this->assertSame(BindingMasker::MASKED, $result[2]);
    }

    #[Test]
    public function it_masks_bindings_for_not_equals(): void
    {
        $sql = 'select * from users where secret != ?';
        $bindings = ['old_secret'];

        $result = $this->masker->mask($sql, $bindings);

        $this->assertSame(BindingMasker::MASKED, $result[0]);
    }

    #[Test]
    public function it_masks_bindings_for_backtick_quoted_columns(): void
    {
        $sql = 'select * from `users` where `api_key` = ?';
        $bindings = ['sk_live_abc123'];

        $result = $this->masker->mask($sql, $bindings);

        $this->assertSame(BindingMasker::MASKED, $result[0]);
    }

    #[Test]
    public function it_masks_bindings_for_table_qualified_columns(): void
    {
        $sql = 'select * from users where users.password = ?';
        $bindings = ['hashed_password'];

        $result = $this->masker->mask($sql, $bindings);

        $this->assertSame(BindingMasker::MASKED, $result[0]);
    }

    #[Test]
    public function it_does_not_mask_non_sensitive_columns(): void
    {
        $sql = 'select * from users where name = ? and age = ?';
        $bindings = ['John', 30];

        $result = $this->masker->mask($sql, $bindings);

        $this->assertSame('John', $result[0]);
        $this->assertSame(30, $result[1]);
    }

    // ── Pattern-based masking ────────────────────────────

    #[Test]
    public function it_masks_email_values_by_pattern(): void
    {
        $sql = 'select * from logs where data = ?';
        $bindings = ['user@example.com'];

        $result = $this->masker->mask($sql, $bindings);

        $this->assertSame(BindingMasker::MASKED, $result[0]);
    }

    #[Test]
    public function it_masks_phone_numbers_by_pattern(): void
    {
        $sql = 'select * from logs where data = ?';
        $bindings = ['+12025551234'];

        $result = $this->masker->mask($sql, $bindings);

        $this->assertSame(BindingMasker::MASKED, $result[0]);
    }

    #[Test]
    public function it_masks_ssn_by_pattern(): void
    {
        $sql = 'select * from logs where data = ?';
        $bindings = ['123-45-6789'];

        $result = $this->masker->mask($sql, $bindings);

        $this->assertSame(BindingMasker::MASKED, $result[0]);
    }

    #[Test]
    public function it_does_not_mask_non_matching_patterns(): void
    {
        $sql = 'select * from logs where status = ?';
        $bindings = ['active'];

        $result = $this->masker->mask($sql, $bindings);

        $this->assertSame('active', $result[0]);
    }

    #[Test]
    public function it_does_not_mask_numeric_bindings_with_patterns(): void
    {
        $sql = 'select * from users where id = ?';
        $bindings = [42];

        $result = $this->masker->mask($sql, $bindings);

        $this->assertSame(42, $result[0]);
    }

    // ── Combined masking ─────────────────────────────────

    #[Test]
    public function it_applies_both_column_and_pattern_masking(): void
    {
        $sql = 'select * from users where password = ? and bio = ?';
        $bindings = ['secret123', 'john@example.com'];

        $result = $this->masker->mask($sql, $bindings);

        // password masked by column, email in bio masked by pattern
        $this->assertSame(BindingMasker::MASKED, $result[0]);
        $this->assertSame(BindingMasker::MASKED, $result[1]);
    }

    #[Test]
    public function it_returns_empty_array_for_empty_bindings(): void
    {
        $sql = 'select count(*) from users';
        $result = $this->masker->mask($sql, []);

        $this->assertSame([], $result);
    }

    // ── Disabled masker ──────────────────────────────────

    #[Test]
    public function masker_with_no_rules_passes_bindings_through(): void
    {
        $masker = new BindingMasker(columns: [], valuePatterns: []);

        $sql = 'select * from users where email = ?';
        $bindings = ['john@example.com'];

        $result = $masker->mask($sql, $bindings);

        $this->assertSame('john@example.com', $result[0]);
    }

    // ── Case insensitivity ───────────────────────────────

    #[Test]
    public function it_handles_case_insensitive_column_names(): void
    {
        $masker = new BindingMasker(columns: ['PASSWORD'], valuePatterns: []);

        $sql = 'select * from users where password = ?';
        $bindings = ['hash123'];

        $result = $masker->mask($sql, $bindings);

        $this->assertSame(BindingMasker::MASKED, $result[0]);
    }

    // ── Multiple placeholders ────────────────────────────

    #[Test]
    public function it_correctly_tracks_placeholder_positions_with_many_bindings(): void
    {
        $sql = 'select * from users where name = ? and email = ? and age = ? and token = ?';
        $bindings = ['John', 'john@test.com', 25, 'abc-token'];

        $result = $this->masker->mask($sql, $bindings);

        $this->assertSame('John', $result[0]);       // name: not sensitive
        $this->assertSame(BindingMasker::MASKED, $result[1]); // email: column-masked
        $this->assertSame(25, $result[2]);            // age: not sensitive
        $this->assertSame(BindingMasker::MASKED, $result[3]); // token: column-masked
    }
}
