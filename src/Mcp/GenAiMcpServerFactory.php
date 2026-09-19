<?php

namespace Bherila\GenAiLaravel\Mcp;

use Bherila\GenAiLaravel\Mcp\Tools\GenAiMcpTools;
use Bherila\McpLaravelBridge\Mcp\CredentialSessionNamespace;
use Bherila\McpLaravelBridge\Mcp\ReflectedInputSchemaFactory;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Http\Request;
use Mcp\Capability\Registry;
use Mcp\Capability\Registry\ReferenceHandler;
use Mcp\Schema\ToolAnnotations;
use Mcp\Server;
use Mcp\Server\Session\Psr16SessionStore;
use Psr\Log\NullLogger;

final readonly class GenAiMcpServerFactory
{
    public function __construct(
        private CacheRepository $cache,
        private GenAiMcpToolCatalog $catalog,
        private GenAiMcpTools $tools,
        private ReflectedInputSchemaFactory $schemas,
    ) {}

    public function make(Request $request): Server
    {
        $logger = new NullLogger;
        $builder = Server::builder()->setServerInfo('GenAI subscription execution mailbox', '1.0.0', 'Process private queued GenAI requests with a user-owned model subscription.')
            ->setInstructions($this->catalog->instructions())->setPaginationLimit(25)
            ->setSession(new Psr16SessionStore($this->cache, CredentialSessionNamespace::prefix($request, 'genai_mcp_'), (int) config('genai.mcp.server.session_ttl_seconds', 1800)))
            ->setLogger($logger)->setContainer(app())->setRegistry(new Registry(logger: $logger))->setReferenceHandler(new ReferenceHandler(app()))->setLazyLoading(false);
        // Register only what this principal may call, so a tool it lacks the
        // scope for is unknown rather than argument-validated and then refused.
        $context = $request->attributes->get(ExecutionContext::class);
        foreach ($this->catalog->definitions($this->tools) as $definition) {
            if (! $context instanceof ExecutionContext || ! $context->can($this->catalog->requiredScope($definition))) {
                continue;
            }
            $builder->addTool(
                handler: $definition->handler, name: $definition->name, title: $definition->title,
                description: $definition->description,
                annotations: new ToolAnnotations(readOnlyHint: $definition->readOnly, destructiveHint: $definition->destructive, idempotentHint: $definition->idempotent, openWorldHint: false),
                inputSchema: $this->schemas->for($definition->handler),
                outputSchema: $this->catalog->outputSchema($definition->name),
            );
        }

        return $builder->build();
    }
}
