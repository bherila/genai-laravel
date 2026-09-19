<?php

use Bherila\GenAiLaravel\Mcp\Http\AttachmentController;
use Bherila\GenAiLaravel\Mcp\Http\McpApiController;
use Illuminate\Support\Facades\Route;

$prefix = trim((string) config('genai.mcp.rest.prefix', 'genai/mcp/v1'), '/');
$hostMiddleware = array_values(config('genai.mcp.rest.middleware', []));
Route::prefix($prefix)->middleware(['genai.mcp.no_store', ...$hostMiddleware, 'genai.mcp.auth', 'throttle:genai-mcp'])->group(function (): void {
    Route::get('/queue/status', [McpApiController::class, 'status'])->name('genai.mcp.queue.status');
    Route::post('/claims', [McpApiController::class, 'claim'])->name('genai.mcp.claims.store');
    Route::get('/requests/{requestId}', [McpApiController::class, 'show'])->name('genai.mcp.requests.show');
    Route::post('/requests/{requestId}/lease', [McpApiController::class, 'renew'])->name('genai.mcp.requests.lease');
    Route::post('/requests/{requestId}/complete', [McpApiController::class, 'complete'])->name('genai.mcp.requests.complete');
    Route::post('/requests/{requestId}/fail', [McpApiController::class, 'fail'])->name('genai.mcp.requests.fail');
    Route::match(['GET', 'HEAD'], '/requests/{requestId}/attachments/{attachmentId}', AttachmentController::class)->name('genai.mcp.attachments.show');
});
