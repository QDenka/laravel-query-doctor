@php
    $severityColors = [
        'critical' => 'border-red-400 bg-red-50',
        'high' => 'border-orange-400 bg-orange-50',
        'medium' => 'border-yellow-400 bg-yellow-50',
        'low' => 'border-blue-400 bg-blue-50',
    ];
    $severityBadgeColors = [
        'critical' => 'bg-red-100 text-red-800',
        'high' => 'bg-orange-100 text-orange-800',
        'medium' => 'bg-yellow-100 text-yellow-800',
        'low' => 'bg-blue-100 text-blue-800',
    ];
    $typeBadgeColors = [
        'n_plus_one' => 'bg-purple-100 text-purple-800',
        'duplicate' => 'bg-pink-100 text-pink-800',
        'slow' => 'bg-red-100 text-red-800',
        'missing_index' => 'bg-amber-100 text-amber-800',
        'select_star' => 'bg-cyan-100 text-cyan-800',
    ];
    $sevValue = $issue->severity->value;
    $typeValue = $issue->type->value;
    $isBaselined = in_array($issue->id, $baselinedIds, true);
@endphp

<div x-data="{ open: false }" class="bg-white rounded-lg border-l-4 {{ $severityColors[$sevValue] ?? 'border-gray-300' }} shadow-sm">
    <div class="p-4 cursor-pointer flex items-start gap-3" @click="open = !open">
        <div class="flex-1 min-w-0">
            <div class="flex items-center gap-2 mb-1 flex-wrap">
                <span class="text-xs font-medium px-2 py-0.5 rounded {{ $severityBadgeColors[$sevValue] ?? '' }}">
                    {{ ucfirst($sevValue) }}
                </span>
                <span class="text-xs font-medium px-2 py-0.5 rounded {{ $typeBadgeColors[$typeValue] ?? 'bg-gray-100 text-gray-800' }}">
                    {{ $issue->type->label() }}
                </span>
                @if($isBaselined)
                    <span class="text-xs font-medium px-2 py-0.5 rounded bg-gray-100 text-gray-500">Baselined</span>
                @endif
                <span class="text-xs text-gray-400">
                    {{ number_format($issue->confidence * 100, 0) }}% confidence
                </span>
            </div>
            <div class="text-sm font-medium text-gray-900 truncate">{{ $issue->title }}</div>
            @if($issue->sourceContext?->route)
                <div class="text-xs text-gray-500 mt-0.5">{{ $issue->sourceContext->route }}</div>
            @endif
        </div>
        <div class="text-right shrink-0">
            <div class="text-sm font-mono text-gray-700">{{ number_format($issue->evidence->totalTimeMs, 1) }}ms</div>
            <div class="text-xs text-gray-400">{{ $issue->evidence->queryCount }} queries</div>
        </div>
        <svg class="w-5 h-5 text-gray-400 transition-transform" :class="{ 'rotate-180': open }" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
        </svg>
    </div>

    <div x-show="open" x-transition class="border-t border-gray-100 px-4 py-3 text-sm">
        <div class="mb-3">
            <div class="font-medium text-gray-700 mb-1">Description</div>
            <div class="text-gray-600">{{ $issue->description }}</div>
        </div>

        <div class="mb-3">
            <div class="font-medium text-gray-700 mb-1">Recommendation</div>
            <div class="text-gray-600">{{ $issue->recommendation->action }}</div>
            @if($issue->recommendation->code)
                <pre class="mt-1 bg-gray-800 text-green-300 text-xs p-3 rounded overflow-x-auto">{{ $issue->recommendation->code }}</pre>
            @endif
            @if($issue->recommendation->docsUrl)
                <a href="{{ $issue->recommendation->docsUrl }}" target="_blank" rel="noopener" class="text-blue-600 hover:underline text-xs mt-1 inline-block">Documentation</a>
            @endif
        </div>

        @if($issue->sourceContext)
            <div class="mb-3">
                <div class="font-medium text-gray-700 mb-1">Source</div>
                <div class="text-xs text-gray-500 font-mono">
                    @if($issue->sourceContext->file)
                        {{ $issue->sourceContext->file }}{{ $issue->sourceContext->line ? ':' . $issue->sourceContext->line : '' }}
                    @endif
                    @if($issue->sourceContext->controller)
                        <span class="text-gray-400 ml-2">{{ $issue->sourceContext->controller }}</span>
                    @endif
                </div>
            </div>
        @endif

        <div class="mb-3">
            <div class="font-medium text-gray-700 mb-1">Query Pattern</div>
            <pre class="bg-gray-100 text-gray-800 text-xs p-2 rounded overflow-x-auto">{{ $issue->evidence->fingerprint->value }}</pre>
        </div>

        <div class="flex gap-2 mt-3">
            <button @click="$root.__x.$data.ignoreIssue('{{ $issue->id }}')" class="text-xs bg-gray-100 hover:bg-gray-200 text-gray-700 px-3 py-1 rounded">
                Ignore
            </button>
        </div>
    </div>
</div>
