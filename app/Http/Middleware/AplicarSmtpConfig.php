<?php

namespace App\Http\Middleware;

use App\Services\SmtpConfigService;
use Closure;
use Illuminate\Http\Request;

class AplicarSmtpConfig
{
    public function handle(Request $request, Closure $next): mixed
    {
        try {
            SmtpConfigService::aplicar();
        } catch (\Throwable $e) {
            \Log::warning('AplicarSmtpConfig falló: ' . $e->getMessage());
        }
        return $next($request);
    }
}
