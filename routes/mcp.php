<?php

use Bherila\GenAiLaravel\Mcp\Http\AttachmentController;
use Bherila\GenAiLaravel\Mcp\Http\McpApiController;
use Illuminate\Support\Facades\Route;

$prefix = trim((string) config('genai.mcp.rest.prefix', 'genai/mcp/v1'), '/');
Route::prefix($prefix)->middleware(['genai.mcp.no_store', 'genai.mcp.auth', 'throttle:'.config('genai.mcp.rest.throttle', '60,1')])->group(function (): void {
    Route::get('/queue/status', [McpApiController::class, 'status']);
    Route::post('/claims', [McpApiController::class, 'claim']);
    Route::get('/requests/{requestId}', [McpApiController::class, 'show']);
    Route::post('/requests/{requestId}/lease', [McpApiController::class, 'renew']);
    Route::post('/requests/{requestId}/complete', [McpApiController::class, 'complete']);
    Route::post('/requests/{requestId}/fail', [McpApiController::class, 'fail']);
    Route::match(['GET', 'HEAD'], '/requests/{requestId}/attachments/{attachmentId}', AttachmentController::class)->name('genai.mcp.attachments.show');
});
