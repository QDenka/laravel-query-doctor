<?php

declare(strict_types=1);

namespace QDenka\QueryDoctor\Infrastructure\Storage;

use QDenka\QueryDoctor\Domain\Contracts\StorageInterface;
use QDenka\QueryDoctor\Domain\Enums\CaptureContext;
use QDenka\QueryDoctor\Domain\Enums\IssueType;
use QDenka\QueryDoctor\Domain\Enums\Severity;
use QDenka\QueryDoctor\Domain\Evidence;
use QDenka\QueryDoctor\Domain\Issue;
use QDenka\QueryDoctor\Domain\QueryEvent;
use QDenka\QueryDoctor\Domain\QueryFingerprint;
use QDenka\QueryDoctor\Domain\Recommendation;
use QDenka\QueryDoctor\Domain\SourceContext;

final class SqliteStore implements StorageInterface
{
    private ?\PDO $pdo = null;

    private bool $schemaEnsured = false;

    private int $writeCount = 0;

    public function __construct(
        private readonly string $path,
        private readonly int $retentionDays = 14,
        private readonly int $cleanupEvery = 500,
    ) {}

    public function storeEvent(QueryEvent $event): void
    {
        $pdo = $this->connection();
        $fingerprint = QueryFingerprint::fromSql($event->sql);

        // Upsert the run
        $stmt = $pdo->prepare(
            'INSERT OR IGNORE INTO doctor_runs (id, context, route, controller, query_count, total_ms, created_at) '
            .'VALUES (:id, :context, :route, :controller, 0, 0, :created_at)',
        );
        $stmt->execute([
            'id' => $event->contextId,
            'context' => $event->context->value,
            'route' => $event->route,
            'controller' => $event->controller,
            'created_at' => $event->timestamp->format('c'),
        ]);

        // Update run aggregates
        $stmt = $pdo->prepare(
            'UPDATE doctor_runs SET query_count = query_count + 1, total_ms = total_ms + :ms WHERE id = :id',
        );
        $stmt->execute(['ms' => $event->timeMs, 'id' => $event->contextId]);

        // Insert event
        $bindingsHash = hash('sha256', serialize($event->bindings));
        $stmt = $pdo->prepare(
            'INSERT INTO doctor_query_events (run_id, sql, bindings_hash, time_ms, connection, fingerprint, fingerprint_hash, stack_excerpt, created_at) '
            .'VALUES (:run_id, :sql, :bh, :time, :conn, :fp, :fph, :stack, :created)',
        );
        $stmt->execute([
            'run_id' => $event->contextId,
            'sql' => $event->sql,
            'bh' => $bindingsHash,
            'time' => $event->timeMs,
            'conn' => $event->connection,
            'fp' => $fingerprint->value,
            'fph' => $fingerprint->hash,
            'stack' => json_encode($event->stackExcerpt, JSON_THROW_ON_ERROR),
            'created' => $event->timestamp->format('c'),
        ]);

        $this->maybeCleanup();
    }

    public function storeEvents(array $events): void
    {
        if ($events === []) {
            return;
        }

        $pdo = $this->connection();
        $pdo->beginTransaction();

        try {
            foreach ($events as $event) {
                $this->storeEvent($event);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public function getEvents(array $filters = []): array
    {
        $pdo = $this->connection();

        $where = [];
        $params = [];

        if (isset($filters['context_id'])) {
            $where[] = 'run_id = :context_id';
            $params['context_id'] = $filters['context_id'];
        }

        if (isset($filters['fingerprint_hash'])) {
            $where[] = 'fingerprint_hash = :fph';
            $params['fph'] = $filters['fingerprint_hash'];
        }

        if (isset($filters['min_time'])) {
            $where[] = 'time_ms >= :min_time';
            $params['min_time'] = (float) $filters['min_time'];
        }

        if (isset($filters['connection'])) {
            $where[] = 'connection = :conn';
            $params['conn'] = $filters['connection'];
        }

        if (isset($filters['period'])) {
            $where[] = "e.created_at >= datetime('now', :period)";
            $params['period'] = '-'.$this->periodToSqliteInterval($filters['period']);
        }

        $sql = 'SELECT e.*, r.context as ctx, r.route, r.controller FROM doctor_query_events e '
            .'JOIN doctor_runs r ON e.run_id = r.id';

        if ($where !== []) {
            $sql .= ' WHERE '.implode(' AND ', $where);
        }

        $sql .= ' ORDER BY e.created_at DESC LIMIT 10000';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return array_map(fn (array $row) => $this->rowToEvent($row), $rows);
    }

    public function storeIssue(Issue $issue): void
    {
        $pdo = $this->connection();

        $stmt = $pdo->prepare(
            'INSERT INTO doctor_issues (id, type, severity, confidence, title, description, fingerprint_hash, '
            .'evidence_json, recommendation_json, source_route, source_file, source_line, source_controller, '
            .'first_seen_at, last_seen_at, occurrences) '
            .'VALUES (:id, :type, :sev, :conf, :title, :desc, :fph, :evidence, :rec, :route, :file, :line, :ctrl, :first, :last, 1) '
            .'ON CONFLICT(id) DO UPDATE SET last_seen_at = :last2, occurrences = occurrences + 1, '
            .'severity = :sev2, confidence = :conf2',
        );

        $now = $issue->createdAt->format('c');
        $stmt->execute([
            'id' => $issue->id,
            'type' => $issue->type->value,
            'sev' => $issue->severity->value,
            'conf' => $issue->confidence,
            'title' => $issue->title,
            'desc' => $issue->description,
            'fph' => $issue->evidence->fingerprint->hash,
            'evidence' => json_encode([
                'query_count' => $issue->evidence->queryCount,
                'total_time_ms' => $issue->evidence->totalTimeMs,
                'sample_sql' => $issue->evidence->queries[0]->sql ?? null,
            ], JSON_THROW_ON_ERROR),
            'rec' => json_encode([
                'action' => $issue->recommendation->action,
                'code' => $issue->recommendation->code,
                'docs_url' => $issue->recommendation->docsUrl,
            ], JSON_THROW_ON_ERROR),
            'route' => $issue->sourceContext?->route,
            'file' => $issue->sourceContext?->file,
            'line' => $issue->sourceContext?->line,
            'ctrl' => $issue->sourceContext?->controller,
            'first' => $now,
            'last' => $now,
            'last2' => $now,
            'sev2' => $issue->severity->value,
            'conf2' => $issue->confidence,
        ]);
    }

    public function getIssues(array $filters = []): array
    {
        $pdo = $this->connection();

        $where = ['is_ignored = 0'];
        $params = [];

        if (isset($filters['severity'])) {
            $where[] = 'severity = :severity';
            $params['severity'] = $filters['severity'];
        }

        if (isset($filters['type'])) {
            $where[] = 'type = :type';
            $params['type'] = $filters['type'];
        }

        if (isset($filters['route'])) {
            $where[] = 'source_route LIKE :route';
            $params['route'] = str_replace('*', '%', $filters['route']);
        }

        if (isset($filters['period'])) {
            $where[] = "last_seen_at >= datetime('now', :period)";
            $params['period'] = '-'.$this->periodToSqliteInterval($filters['period']);
        }

        $sql = 'SELECT * FROM doctor_issues WHERE '.implode(' AND ', $where)
            .' ORDER BY CASE severity '
            ."WHEN 'critical' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 WHEN 'low' THEN 4 END";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return array_map(fn (array $row) => $this->rowToIssue($row), $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    public function ignoreIssue(string $issueId): void
    {
        $pdo = $this->connection();
        $stmt = $pdo->prepare('UPDATE doctor_issues SET is_ignored = 1 WHERE id = :id');
        $stmt->execute(['id' => $issueId]);
    }

    public function createBaseline(): int
    {
        $pdo = $this->connection();

        $pdo->exec('DELETE FROM doctor_baselines');

        $stmt = $pdo->prepare(
            'INSERT INTO doctor_baselines (issue_id, fingerprint_hash, type, created_at) '
            ."SELECT id, fingerprint_hash, type, datetime('now') FROM doctor_issues WHERE is_ignored = 0",
        );
        $stmt->execute();

        return $stmt->rowCount();
    }

    public function clearBaseline(): void
    {
        $this->connection()->exec('DELETE FROM doctor_baselines');
    }

    public function getBaselinedIssueIds(): array
    {
        $stmt = $this->connection()->query('SELECT issue_id FROM doctor_baselines');

        return $stmt ? $stmt->fetchAll(\PDO::FETCH_COLUMN) : [];
    }

    public function cleanup(): void
    {
        $pdo = $this->connection();
        $interval = "-{$this->retentionDays} days";

        $pdo->exec("DELETE FROM doctor_query_events WHERE created_at < datetime('now', '{$interval}')");
        $pdo->exec("DELETE FROM doctor_runs WHERE created_at < datetime('now', '{$interval}')");
        $pdo->exec("DELETE FROM doctor_issues WHERE last_seen_at < datetime('now', '{$interval}') AND is_ignored = 0");
    }

    private function connection(): \PDO
    {
        if ($this->pdo !== null) {
            return $this->pdo;
        }

        $pdo = new \PDO("sqlite:{$this->path}", null, null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);

        // WAL mode for concurrent read/write safety
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA busy_timeout = 5000');
        $pdo->exec('PRAGMA synchronous = NORMAL');
        $pdo->exec('PRAGMA cache_size = -2000');
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA temp_store = MEMORY');

        $this->ensureSchema($pdo);
        $this->pdo = $pdo;

        return $pdo;
    }

    private function ensureSchema(\PDO $pdo): void
    {
        if ($this->schemaEnsured) {
            return;
        }

        // Check if tables already exist
        $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='doctor_runs'");
        $exists = $stmt && $stmt->fetchColumn() !== false;

        if (! $exists) {
            $this->createSchema($pdo);
        }

        $this->schemaEnsured = true;
    }

    private function createSchema(\PDO $pdo): void
    {
        $pdo->exec('
            CREATE TABLE IF NOT EXISTS doctor_runs (
                id TEXT PRIMARY KEY,
                context TEXT NOT NULL,
                route TEXT,
                controller TEXT,
                job_class TEXT,
                command TEXT,
                query_count INTEGER NOT NULL DEFAULT 0,
                total_ms REAL NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL
            )
        ');

        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_runs_context ON doctor_runs(context)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_runs_created_at ON doctor_runs(created_at)');

        $pdo->exec('
            CREATE TABLE IF NOT EXISTS doctor_query_events (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                run_id TEXT NOT NULL,
                sql TEXT NOT NULL,
                bindings_hash TEXT,
                time_ms REAL NOT NULL,
                connection TEXT NOT NULL,
                fingerprint TEXT NOT NULL,
                fingerprint_hash TEXT NOT NULL,
                stack_excerpt TEXT,
                created_at TEXT NOT NULL,
                FOREIGN KEY (run_id) REFERENCES doctor_runs(id) ON DELETE CASCADE
            )
        ');

        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_events_run_id ON doctor_query_events(run_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_events_fingerprint_hash ON doctor_query_events(fingerprint_hash)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_events_time_ms ON doctor_query_events(time_ms)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_events_created_at ON doctor_query_events(created_at)');

        $pdo->exec('
            CREATE TABLE IF NOT EXISTS doctor_issues (
                id TEXT PRIMARY KEY,
                type TEXT NOT NULL,
                severity TEXT NOT NULL,
                confidence REAL NOT NULL,
                title TEXT NOT NULL,
                description TEXT NOT NULL,
                fingerprint_hash TEXT NOT NULL,
                evidence_json TEXT NOT NULL,
                recommendation_json TEXT NOT NULL,
                source_route TEXT,
                source_file TEXT,
                source_line INTEGER,
                source_controller TEXT,
                is_ignored INTEGER NOT NULL DEFAULT 0,
                first_seen_at TEXT NOT NULL,
                last_seen_at TEXT NOT NULL,
                occurrences INTEGER NOT NULL DEFAULT 1
            )
        ');

        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_issues_type ON doctor_issues(type)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_issues_severity ON doctor_issues(severity)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_issues_fingerprint ON doctor_issues(fingerprint_hash)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_issues_last_seen ON doctor_issues(last_seen_at)');

        $pdo->exec('
            CREATE TABLE IF NOT EXISTS doctor_baselines (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                issue_id TEXT NOT NULL,
                fingerprint_hash TEXT NOT NULL,
                type TEXT NOT NULL,
                created_at TEXT NOT NULL
            )
        ');

        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_baselines_issue ON doctor_baselines(issue_id)');

        $pdo->exec('
            CREATE TABLE IF NOT EXISTS doctor_meta (
                key TEXT PRIMARY KEY,
                value TEXT NOT NULL
            )
        ');

        $pdo->exec("INSERT OR IGNORE INTO doctor_meta (key, value) VALUES ('schema_version', '1')");
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function rowToEvent(array $row): QueryEvent
    {
        return new QueryEvent(
            sql: (string) $row['sql'],
            bindings: [], // not stored for PII reasons
            timeMs: (float) $row['time_ms'],
            connection: (string) $row['connection'],
            contextId: (string) $row['run_id'],
            context: CaptureContext::tryFrom((string) ($row['ctx'] ?? 'http')) ?? CaptureContext::Http,
            route: $row['route'] ?? null,
            controller: $row['controller'] ?? null,
            stackExcerpt: json_decode((string) ($row['stack_excerpt'] ?? '[]'), true) ?: [],
            timestamp: new \DateTimeImmutable((string) $row['created_at']),
        );
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function rowToIssue(array $row): Issue
    {
        /** @var array{action?: string, code?: string, docs_url?: string} $rec */
        $rec = json_decode((string) $row['recommendation_json'], true) ?: [];

        /** @var array{query_count?: int, total_time_ms?: float, sample_sql?: string} $evidence */
        $evidence = json_decode((string) $row['evidence_json'], true) ?: [];

        $fingerprint = new QueryFingerprint($evidence['sample_sql'] ?? '');

        return new Issue(
            id: (string) $row['id'],
            type: IssueType::from((string) $row['type']),
            severity: Severity::from((string) $row['severity']),
            confidence: (float) $row['confidence'],
            title: (string) $row['title'],
            description: (string) $row['description'],
            evidence: new Evidence(
                queries: [],
                queryCount: (int) ($evidence['query_count'] ?? 0),
                totalTimeMs: (float) ($evidence['total_time_ms'] ?? 0),
                fingerprint: $fingerprint,
            ),
            recommendation: new Recommendation(
                action: $rec['action'] ?? '',
                code: $rec['code'] ?? null,
                docsUrl: $rec['docs_url'] ?? null,
            ),
            sourceContext: new SourceContext(
                route: $row['source_route'] ?? null,
                file: $row['source_file'] ?? null,
                line: isset($row['source_line']) ? (int) $row['source_line'] : null,
                controller: $row['source_controller'] ?? null,
            ),
            createdAt: new \DateTimeImmutable((string) $row['first_seen_at']),
        );
    }

    private function periodToSqliteInterval(string $period): string
    {
        return match ($period) {
            '1h' => '1 hours',
            '6h' => '6 hours',
            '24h' => '24 hours',
            '7d' => '7 days',
            '30d' => '30 days',
            default => '24 hours',
        };
    }

    private function maybeCleanup(): void
    {
        if ($this->cleanupEvery <= 0) {
            return;
        }

        $this->writeCount++;

        if ($this->writeCount >= $this->cleanupEvery) {
            $this->cleanup();
            $this->writeCount = 0;
        }
    }
}
