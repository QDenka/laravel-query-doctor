<?php

declare(strict_types=1);

namespace QDenka\QueryDoctor\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use QDenka\QueryDoctor\Domain\QueryFingerprint;

final class QueryFingerprintTest extends TestCase
{
    #[Test]
    public function it_replaces_numeric_literals(): void
    {
        $fp = QueryFingerprint::fromSql('SELECT * FROM users WHERE id = 42');

        $this->assertSame('select * from users where id = ?', $fp->value);
    }

    #[Test]
    public function it_replaces_string_literals(): void
    {
        $fp = QueryFingerprint::fromSql("SELECT * FROM users WHERE name = 'John'");

        $this->assertSame('select * from users where name = ?', $fp->value);
    }

    #[Test]
    public function it_collapses_in_lists(): void
    {
        $fp = QueryFingerprint::fromSql('SELECT * FROM posts WHERE user_id IN (1, 2, 3, 4, 5)');

        $this->assertSame('select * from posts where user_id in (?)', $fp->value);
    }

    #[Test]
    public function it_collapses_in_lists_with_placeholders(): void
    {
        $fp = QueryFingerprint::fromSql('SELECT * FROM posts WHERE user_id IN (?, ?, ?)');

        $this->assertSame('select * from posts where user_id in (?)', $fp->value);
    }

    #[Test]
    public function it_lowercases_keywords(): void
    {
        $fp = QueryFingerprint::fromSql('SELECT * FROM Users WHERE Id = 1');

        $this->assertSame('select * from users where id = ?', $fp->value);
    }

    #[Test]
    public function it_collapses_whitespace(): void
    {
        $fp = QueryFingerprint::fromSql("SELECT  *  FROM\n  users\tWHERE  id = 1");

        $this->assertSame('select * from users where id = ?', $fp->value);
    }

    #[Test]
    public function it_strips_line_comments(): void
    {
        $fp = QueryFingerprint::fromSql("SELECT * FROM users -- fetch all\nWHERE id = 1");

        $this->assertSame('select * from users where id = ?', $fp->value);
    }

    #[Test]
    public function it_strips_block_comments(): void
    {
        $fp = QueryFingerprint::fromSql('SELECT /* columns */ * FROM users WHERE id = 1');

        $this->assertSame('select * from users where id = ?', $fp->value);
    }

    #[Test]
    public function same_structure_different_values_produce_same_fingerprint(): void
    {
        $fp1 = QueryFingerprint::fromSql('SELECT * FROM users WHERE id = 1');
        $fp2 = QueryFingerprint::fromSql('SELECT * FROM users WHERE id = 99');

        $this->assertTrue($fp1->equals($fp2));
        $this->assertSame($fp1->hash, $fp2->hash);
    }

    #[Test]
    public function different_structure_produces_different_fingerprint(): void
    {
        $fp1 = QueryFingerprint::fromSql('SELECT * FROM users WHERE id = 1');
        $fp2 = QueryFingerprint::fromSql('SELECT * FROM posts WHERE id = 1');

        $this->assertFalse($fp1->equals($fp2));
    }

    #[Test]
    public function it_handles_float_literals(): void
    {
        $fp = QueryFingerprint::fromSql('SELECT * FROM products WHERE price > 19.99');

        // 19.99 is replaced as two numeric tokens (19 and 99) separated by a dot
        // This is acceptable — the fingerprint still normalizes consistently
        $this->assertSame('select * from products where price > ?', $fp->value);
    }

    #[Test]
    public function it_preserves_table_aliases(): void
    {
        $fp = QueryFingerprint::fromSql('SELECT u.* FROM users u WHERE u.id = 1');

        $this->assertSame('select u.* from users u where u.id = ?', $fp->value);
    }

    #[Test]
    public function it_handles_insert_statements(): void
    {
        $fp = QueryFingerprint::fromSql("INSERT INTO users (name, email) VALUES ('John', 'john@example.com')");

        $this->assertSame('insert into users (name, email) values (?, ?)', $fp->value);
    }

    #[Test]
    public function it_handles_update_statements(): void
    {
        $fp = QueryFingerprint::fromSql("UPDATE users SET name = 'Jane' WHERE id = 5");

        $this->assertSame('update users set name = ? where id = ?', $fp->value);
    }

    #[Test]
    public function hash_is_sha256(): void
    {
        $fp = QueryFingerprint::fromSql('SELECT 1');

        $this->assertSame(64, strlen($fp->hash));
        $this->assertSame(hash('sha256', $fp->value), $fp->hash);
    }

    #[Test]
    public function to_string_returns_value(): void
    {
        $fp = QueryFingerprint::fromSql('SELECT * FROM users WHERE id = 1');

        $this->assertSame($fp->value, (string) $fp);
    }
}
