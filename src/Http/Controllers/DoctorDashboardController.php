<?php

declare(strict_types=1);

namespace QDenka\QueryDoctor\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use QDenka\QueryDoctor\Application\BaselineService;
use QDenka\QueryDoctor\Domain\Contracts\StorageInterface;
use QDenka\QueryDoctor\Domain\Enums\IssueType;
use QDenka\QueryDoctor\Domain\Enums\Severity;
use QDenka\QueryDoctor\Domain\Issue;

final class DoctorDashboardController extends Controller
{
    public function __construct(
        private readonly StorageInterface $storage,
        private readonly BaselineService $baselineService,
    ) {}

    /**
     * Dashboard index page.
     */
    public function index(Request $request): View
    {
        $period = $request->query('period', '24h');
        $severity = $request->query('severity');
        $type = $request->query('type');

        $filters = ['period' => $period];
        if (is_string($severity) && $severity !== '') {
            $filters['severity'] = $severity;
        }
        if (is_string($type) && $type !== '') {
            $filters['type'] = $type;
        }

        $issues = $this->storage->getIssues($filters);
        $baselinedIds = $this->storage->getBaselinedIssueIds();

        $stats = $this->buildStats($issues);

        /** @var \Illuminate\View\View $view */
        $view = view('query-doctor::dashboard.index', [ // @phpstan-ignore argument.type
            'issues' => $issues,
            'baselinedIds' => $baselinedIds,
            'stats' => $stats,
            'filters' => [
                'period' => $period,
                'severity' => $severity,
                'type' => $type,
            ],
            'periods' => ['1h', '6h', '24h', '7d', '30d'],
            'severities' => array_map(static fn (Severity $s) => $s->value, Severity::cases()),
            'types' => array_map(static fn (IssueType $t) => $t->value, IssueType::cases()),
        ]);

        return $view;
    }

    /**
     * API: List issues with pagination and filtering.
     */
    public function apiIssues(Request $request): JsonResponse
    {
        $page = max(1, (int) $request->query('page', '1'));
        $perPage = min(100, max(1, (int) $request->query('per_page', '25')));

        $filters = $this->buildFilters($request);
        $issues = $this->storage->getIssues($filters);
        $baselinedIds = $this->storage->getBaselinedIssueIds();

        $total = count($issues);
        $lastPage = max(1, (int) ceil($total / $perPage));
        $offset = ($page - 1) * $perPage;
        $paged = array_slice($issues, $offset, $perPage);

        return new JsonResponse([
            'data' => array_map(fn (Issue $i) => $this->serializeIssue($i, $baselinedIds), $paged),
            'meta' => [
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage,
                'last_page' => $lastPage,
            ],
            'filters' => $filters,
        ]);
    }

    /**
     * API: List captured query events.
     */
    public function apiQueries(Request $request): JsonResponse
    {
        $page = max(1, (int) $request->query('page', '1'));
        $perPage = min(200, max(1, (int) $request->query('per_page', '50')));

        $filters = [];
        if (is_string($request->query('fingerprint')) && $request->query('fingerprint') !== '') {
            $filters['fingerprint_hash'] = $request->query('fingerprint');
        }
        if (is_string($request->query('context_id')) && $request->query('context_id') !== '') {
            $filters['context_id'] = $request->query('context_id');
        }
        if (is_string($request->query('min_time')) && $request->query('min_time') !== '') {
            $filters['min_time'] = (float) $request->query('min_time');
        }
        if (is_string($request->query('connection')) && $request->query('connection') !== '') {
            $filters['connection'] = $request->query('connection');
        }
        $filters['period'] = is_string($request->query('period')) ? $request->query('period') : '24h';

        $events = $this->storage->getEvents($filters);

        $total = count($events);
        $lastPage = max(1, (int) ceil($total / $perPage));
        $offset = ($page - 1) * $perPage;
        $paged = array_slice($events, $offset, $perPage);

        return new JsonResponse([
            'data' => array_map(static fn ($e) => [
                'sql' => $e->sql,
                'time_ms' => $e->timeMs,
                'connection' => $e->connection,
                'context_id' => $e->contextId,
                'context' => $e->context->value,
                'route' => $e->route,
                'timestamp' => $e->timestamp->format('c'),
            ], $paged),
            'meta' => [
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage,
                'last_page' => $lastPage,
            ],
        ]);
    }

    /**
     * API: Create a baseline from current issues.
     */
    public function apiCreateBaseline(): JsonResponse
    {
        $count = $this->baselineService->create();

        return new JsonResponse([
            'message' => 'Baseline created',
            'issues_baselined' => $count,
            'created_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * API: Ignore a specific issue.
     */
    public function apiIgnoreIssue(Request $request): JsonResponse
    {
        /** @var string $issueId */
        $issueId = $request->input('issue_id', '');

        if ($issueId === '') {
            return new JsonResponse(['error' => 'issue_id is required'], 422);
        }

        $this->storage->ignoreIssue($issueId);

        return new JsonResponse([
            'message' => 'Issue ignored',
            'issue_id' => $issueId,
        ]);
    }

    /**
     * @param  Issue[]  $issues
     * @return array{total: int, critical: int, high: int, medium: int, low: int, by_type: array<string, int>}
     */
    private function buildStats(array $issues): array
    {
        $stats = [
            'total' => count($issues),
            'critical' => 0,
            'high' => 0,
            'medium' => 0,
            'low' => 0,
            'by_type' => [],
        ];

        foreach ($issues as $issue) {
            $stats[$issue->severity->value]++;
            $typeKey = $issue->type->value;
            $stats['by_type'][$typeKey] = ($stats['by_type'][$typeKey] ?? 0) + 1;
        }

        return $stats;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildFilters(Request $request): array
    {
        $filters = [];

        $period = $request->query('period');
        $filters['period'] = is_string($period) && $period !== '' ? $period : '24h';

        $severity = $request->query('severity');
        if (is_string($severity) && $severity !== '') {
            $filters['severity'] = $severity;
        }

        $type = $request->query('type');
        if (is_string($type) && $type !== '') {
            $filters['type'] = $type;
        }

        $route = $request->query('route');
        if (is_string($route) && $route !== '') {
            $filters['route'] = $route;
        }

        return $filters;
    }

    /**
     * @param  string[]  $baselinedIds
     * @return array<string, mixed>
     */
    private function serializeIssue(Issue $issue, array $baselinedIds): array
    {
        return [
            'id' => $issue->id,
            'type' => $issue->type->value,
            'severity' => $issue->severity->value,
            'confidence' => $issue->confidence,
            'title' => $issue->title,
            'description' => $issue->description,
            'evidence' => [
                'query_count' => $issue->evidence->queryCount,
                'total_time_ms' => $issue->evidence->totalTimeMs,
                'fingerprint' => $issue->evidence->fingerprint->value,
            ],
            'recommendation' => [
                'action' => $issue->recommendation->action,
                'code' => $issue->recommendation->code,
                'docs_url' => $issue->recommendation->docsUrl,
            ],
            'source_context' => $issue->sourceContext !== null ? [
                'route' => $issue->sourceContext->route,
                'file' => $issue->sourceContext->file,
                'line' => $issue->sourceContext->line,
                'controller' => $issue->sourceContext->controller,
            ] : null,
            'is_baselined' => in_array($issue->id, $baselinedIds, true),
            'created_at' => $issue->createdAt->format('c'),
        ];
    }
}
