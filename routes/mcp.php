<?php

use Bherila\GenAiLaravel\Mcp\Http\AttachmentController;
use Bherila\GenAiLaravel\Mcp\Http\McpApiController;
use Illuminate\Support\Facades\Route;

$prefix = trim((string) config('genai.mcp.rest.prefix', 'genai/mcp/v1'), '/');
$hostMiddleware = array_values(config('genai.mcp.rest.middleware', []));
Route::prefix($prefix)->middleware(['genai.mcp.no_store', 'throttle:genai-mcp-preauth', 'genai.mcp.body_limit', ...$hostMiddleware, 'genai.mcp.auth', 'throttle:genai-mcp'])->group(function (): void {
    Route::get('/queue/status', [McpApiController::class, 'status']);
    Route::post('/claims', [McpApiController::class, 'claim']);
    Route::get('/requests/{requestId}', [McpApiController::class, 'show']);
    Route::post('/requests/{requestId}/lease', [McpApiController::class, 'renew']);
    Route::post('/requests/{requestId}/complete', [McpApiController::class, 'complete']);
    Route::post('/requests/{requestId}/fail', [McpApiController::class, 'fail']);
    Route::match(['GET', 'HEAD'], '/requests/{requestId}/attachments/{attachmentId}', AttachmentController::class)->name('genai.mcp.attachments.show');
});
