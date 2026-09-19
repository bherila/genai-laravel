<?php

use Bherila\GenAiLaravel\Mcp\Http\StandaloneMcpController;
use Bherila\McpLaravelBridge\Http\McpHttpSecurityMiddleware;
use Illuminate\Support\Facades\Route;

$hostMiddleware = array_values(config('genai.mcp.server.middleware', []));
Route::match(['POST', 'DELETE', 'OPTIONS'], trim((string) config('genai.mcp.server.path', 'genai/mcp'), '/'), StandaloneMcpController::class)
    ->name('genai.mcp.server')
    ->middleware([McpHttpSecurityMiddleware::class, ...$hostMiddleware, 'genai.mcp.auth', 'throttle:genai-mcp']);
