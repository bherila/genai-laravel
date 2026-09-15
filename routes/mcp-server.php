<?php

use Bherila\GenAiLaravel\Mcp\Http\StandaloneMcpController;
use Illuminate\Support\Facades\Route;

Route::match(['POST', 'DELETE', 'OPTIONS'], trim((string) config('genai.mcp.server.path', 'genai/mcp'), '/'), StandaloneMcpController::class)
    ->middleware(['genai.mcp.no_store', 'genai.mcp.auth', 'throttle:'.config('genai.mcp.rest.throttle', '60,1')]);
