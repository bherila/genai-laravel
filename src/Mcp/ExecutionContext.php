<?php

namespace Bherila\GenAiLaravel\Mcp;

final readonly class ExecutionContext
{
    /**
     * @param  list<string>  $mailboxIds
     * @param  list<string>  $scopes
     */
    public function __construct(
        public string $principalKey,
        public array $mailboxIds,
        public array $scopes = [],
    ) {}

    public function can(string $scope): bool
    {
        return in_array('*', $this->scopes, true) || in_array($scope, $this->scopes, true);
    }
}
