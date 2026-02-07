<?php

declare(strict_types=1);

namespace QDenka\QueryDoctor\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class QueryDoctorMiddleware
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        /** @var string[] $allowed */
        $allowed = config('query-doctor.allowed_environments', ['local', 'staging']);

        if (! in_array(app()->environment(), $allowed, true)) {
            abort(403, 'Query Doctor is not available in this environment.');
        }

        return $next($request);
    }
}
