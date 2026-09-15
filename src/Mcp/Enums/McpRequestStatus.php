<?php

namespace Bherila\GenAiLaravel\Mcp\Enums;

enum McpRequestStatus: string
{
    case Pending = 'pending';
    case Leased = 'leased';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
}
