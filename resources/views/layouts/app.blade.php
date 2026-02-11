<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Query Doctor</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <style>
        [x-cloak] { display: none !important; }
    </style>
</head>
<body class="bg-gray-50 text-gray-900 min-h-screen">
    <nav class="bg-white border-b border-gray-200 px-6 py-3">
        <div class="max-w-7xl mx-auto flex items-center justify-between">
            <div class="flex items-center gap-3">
                <span class="text-lg font-semibold text-gray-800">Query Doctor</span>
                <span class="text-xs bg-gray-100 text-gray-500 px-2 py-0.5 rounded">v0.1</span>
            </div>
            <div class="text-sm text-gray-500">
                {{ config('app.name', 'Laravel') }} &mdash; {{ config('app.env') }}
            </div>
        </div>
    </nav>

    <main class="max-w-7xl mx-auto px-6 py-6">
        @yield('content')
    </main>
</body>
</html>
