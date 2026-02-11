@extends('query-doctor::layouts.app')

@section('content')
<div x-data="dashboard()" x-cloak>

    {{-- Stats bar --}}
    <div class="grid grid-cols-2 sm:grid-cols-5 gap-4 mb-6">
        <div class="bg-white rounded-lg border border-gray-200 p-4">
            <div class="text-sm text-gray-500">Total Issues</div>
            <div class="text-2xl font-bold text-gray-900">{{ $stats['total'] }}</div>
        </div>
        <div class="bg-white rounded-lg border border-red-200 p-4">
            <div class="text-sm text-red-600">Critical</div>
            <div class="text-2xl font-bold text-red-700">{{ $stats['critical'] }}</div>
        </div>
        <div class="bg-white rounded-lg border border-orange-200 p-4">
            <div class="text-sm text-orange-600">High</div>
            <div class="text-2xl font-bold text-orange-700">{{ $stats['high'] }}</div>
        </div>
        <div class="bg-white rounded-lg border border-yellow-200 p-4">
            <div class="text-sm text-yellow-600">Medium</div>
            <div class="text-2xl font-bold text-yellow-700">{{ $stats['medium'] }}</div>
        </div>
        <div class="bg-white rounded-lg border border-blue-200 p-4">
            <div class="text-sm text-blue-600">Low</div>
            <div class="text-2xl font-bold text-blue-700">{{ $stats['low'] }}</div>
        </div>
    </div>

    {{-- Filters --}}
    <div class="bg-white rounded-lg border border-gray-200 p-4 mb-6">
        <form method="GET" action="{{ route('query-doctor.dashboard') }}" class="flex flex-wrap gap-4 items-end">
            <div>
                <label class="block text-xs text-gray-500 mb-1">Period</label>
                <select name="period" class="border border-gray-300 rounded px-3 py-1.5 text-sm">
                    @foreach($periods as $p)
                        <option value="{{ $p }}" @selected($filters['period'] === $p)>{{ $p }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs text-gray-500 mb-1">Severity</label>
                <select name="severity" class="border border-gray-300 rounded px-3 py-1.5 text-sm">
                    <option value="">All</option>
                    @foreach($severities as $s)
                        <option value="{{ $s }}" @selected($filters['severity'] === $s)>{{ ucfirst($s) }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs text-gray-500 mb-1">Type</label>
                <select name="type" class="border border-gray-300 rounded px-3 py-1.5 text-sm">
                    <option value="">All</option>
                    @foreach($types as $t)
                        <option value="{{ $t }}" @selected($filters['type'] === $t)>{{ str_replace('_', ' ', ucfirst($t)) }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <button type="submit" class="bg-gray-800 text-white px-4 py-1.5 rounded text-sm hover:bg-gray-700">
                    Filter
                </button>
            </div>
            <div class="ml-auto">
                <button type="button" @click="createBaseline()" class="bg-blue-600 text-white px-4 py-1.5 rounded text-sm hover:bg-blue-500">
                    Create Baseline
                </button>
            </div>
        </form>
    </div>

    {{-- Baseline notification --}}
    <div x-show="baselineMessage" x-transition class="bg-blue-50 border border-blue-200 text-blue-700 px-4 py-2 rounded mb-4 text-sm">
        <span x-text="baselineMessage"></span>
    </div>

    {{-- Issues list --}}
    @if(count($issues) === 0)
        <div class="bg-white rounded-lg border border-gray-200 p-12 text-center">
            <div class="text-gray-400 text-lg mb-2">No issues found</div>
            <div class="text-gray-400 text-sm">Try changing the filters or waiting for more queries to be captured.</div>
        </div>
    @else
        <div class="space-y-3">
            @foreach($issues as $issue)
                @include('query-doctor::dashboard.partials.issue-card', ['issue' => $issue, 'baselinedIds' => $baselinedIds])
            @endforeach
        </div>
    @endif
</div>

<script>
function dashboard() {
    return {
        baselineMessage: '',

        async createBaseline() {
            try {
                const res = await fetch('{{ route("query-doctor.api.baseline") }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                });
                const data = await res.json();
                this.baselineMessage = `Baseline created: ${data.issues_baselined} issues baselined.`;
                setTimeout(() => this.baselineMessage = '', 5000);
            } catch (e) {
                this.baselineMessage = 'Failed to create baseline.';
            }
        },

        async ignoreIssue(issueId) {
            try {
                await fetch('{{ route("query-doctor.api.ignore") }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                    body: JSON.stringify({ issue_id: issueId }),
                });
                location.reload();
            } catch (e) {
                alert('Failed to ignore issue.');
            }
        }
    }
}
</script>
@endsection
