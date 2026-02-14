<?php

declare(strict_types=1);

namespace QDenka\QueryDoctor\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use QDenka\QueryDoctor\Application\AnalysisPipeline;
use QDenka\QueryDoctor\Domain\Analyzer\DuplicateQueryAnalyzer;
use QDenka\QueryDoctor\Domain\Analyzer\MissingIndexAnalyzer;
use QDenka\QueryDoctor\Domain\Analyzer\NPlusOneAnalyzer;
use QDenka\QueryDoctor\Domain\Analyzer\SelectStarAnalyzer;
use QDenka\QueryDoctor\Domain\Analyzer\SlowQueryAnalyzer;
use QDenka\QueryDoctor\Domain\Enums\CaptureContext;
use QDenka\QueryDoctor\Domain\Issue;
use QDenka\QueryDoctor\Domain\QueryEvent;

/**
 * Golden tests: fixed SQL trace fixtures produce deterministic issue snapshots.
 *
 * Run with UPDATE_SNAPSHOTS=1 to regenerate expected output files after
 * intentional changes to analyzer logic.
 */
final class GoldenTest extends TestCase
{
    private const string TRACES_DIR = __DIR__.'/../../Fixtures/traces';

    private const string EXPECTED_DIR = __DIR__.'/../../Fixtures/expected';

    /**
     * @return array<string, array{string}>
     */
    public static function traceProvider(): array
    {
        return [
            'n_plus_one_users_posts' => ['n_plus_one_users_posts'],
            'duplicate_config_queries' => ['duplicate_config_queries'],
            'slow_report_query' => ['slow_report_query'],
            'clean_no_issues' => ['clean_no_issues'],
            'mixed_issues' => ['mixed_issues'],
        ];
    }

    #[Test]
    #[DataProvider('traceProvider')]
    public function golden_snapshot_matches(string $traceName): void
    {
        $events = $this->loadTrace($traceName);
        $pipeline = $this->buildPipeline();
        $issues = $pipeline->analyze($events);

        $actual = $this->serializeIssues($issues);
        $expectedFile = self::EXPECTED_DIR.'/'.$traceName.'_issues.json';

        if (getenv('UPDATE_SNAPSHOTS') === '1') {
            $dir = dirname($expectedFile);
            if (! is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            file_put_contents($expectedFile, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
            $this->addToAssertionCount(1);

            return;
        }

        $this->assertFileExists($expectedFile, "Expected snapshot missing for '{$traceName}'. Run with UPDATE_SNAPSHOTS=1 to generate.");

        $expectedJson = file_get_contents($expectedFile);
        $this->assertNotFalse($expectedJson);

        /** @var array<int, array<string, mixed>> $expected */
        $expected = json_decode($expectedJson, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(
            count($expected),
            count($actual),
            "Issue count mismatch for '{$traceName}': expected ".count($expected).', got '.count($actual),
        );

        foreach ($expected as $i => $expectedIssue) {
            $actualIssue = $actual[$i];

            $this->assertSame($expectedIssue['type'], $actualIssue['type'], "Issue #{$i} type mismatch");
            $this->assertSame($expectedIssue['severity'], $actualIssue['severity'], "Issue #{$i} severity mismatch");
            $this->assertSame($expectedIssue['title'], $actualIssue['title'], "Issue #{$i} title mismatch");
            $this->assertSame($expectedIssue['fingerprint'], $actualIssue['fingerprint'], "Issue #{$i} fingerprint mismatch");
            $this->assertSame($expectedIssue['queryCount'], $actualIssue['queryCount'], "Issue #{$i} queryCount mismatch");

            // Confidence can vary slightly with float arithmetic, so use delta
            $this->assertEqualsWithDelta(
                $expectedIssue['confidence'],
                $actualIssue['confidence'],
                0.01,
                "Issue #{$i} confidence mismatch",
            );
        }
    }

    /**
     * Load a trace fixture and deserialize to QueryEvent[].
     *
     * @return QueryEvent[]
     */
    private function loadTrace(string $name): array
    {
        $path = self::TRACES_DIR.'/'.$name.'.json';
        $this->assertFileExists($path, "Trace fixture missing: {$path}");

        $json = file_get_contents($path);
        $this->assertNotFalse($json);

        /** @var array<int, array<string, mixed>> $data */
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return array_map(
            fn (array $row) => new QueryEvent(
                sql: (string) $row['sql'],
                bindings: (array) $row['bindings'],
                timeMs: (float) $row['timeMs'],
                connection: (string) $row['connection'],
                contextId: (string) $row['contextId'],
                context: CaptureContext::from((string) $row['context']),
                route: $row['route'] !== null ? (string) $row['route'] : null,
                controller: $row['controller'] !== null ? (string) $row['controller'] : null,
                stackExcerpt: (array) $row['stackExcerpt'],
                timestamp: new \DateTimeImmutable((string) $row['timestamp']),
            ),
            $data,
        );
    }

    /**
     * Build the standard pipeline with all analyzers at default thresholds.
     */
    private function buildPipeline(): AnalysisPipeline
    {
        return new AnalysisPipeline([
            new SlowQueryAnalyzer(thresholdMs: 100.0),
            new DuplicateQueryAnalyzer(minCount: 3),
            new NPlusOneAnalyzer(minRepetitions: 5, minTotalMs: 20.0),
            new MissingIndexAnalyzer(minOccurrences: 5, minAvgMs: 50.0),
            new SelectStarAnalyzer(minOccurrences: 3),
        ]);
    }

    /**
     * Serialize issues to a comparable array format (deterministic, no timestamps).
     *
     * @param  Issue[]  $issues
     * @return array<int, array<string, mixed>>
     */
    private function serializeIssues(array $issues): array
    {
        // Sort by type then title for deterministic order
        usort($issues, function (Issue $a, Issue $b): int {
            $typeCompare = strcmp($a->type->value, $b->type->value);

            return $typeCompare !== 0 ? $typeCompare : strcmp($a->title, $b->title);
        });

        return array_values(array_map(
            static fn (Issue $issue) => [
                'type' => $issue->type->value,
                'severity' => $issue->severity->value,
                'confidence' => round($issue->confidence, 4),
                'title' => $issue->title,
                'fingerprint' => $issue->evidence->fingerprint->value,
                'queryCount' => $issue->evidence->queryCount,
                'totalTimeMs' => round($issue->evidence->totalTimeMs, 1),
            ],
            $issues,
        ));
    }
}
