<?php

declare(strict_types=1);

namespace Spora\Plugins\Memories\Tools;

use Spora\Tools\Attributes\Tool;
use Spora\Tools\Attributes\ToolOperation;

/**
 * Stores and retrieves persistent memories scoped to the current agent.
 * Each agent has its own isolated memory namespace keyed by `agent_id`.
 * Memories here travel with the agent across principal transfers (0067)
 * because the agent FK never changes.
 */
#[Tool(
    name: 'memory',
    description: 'Store and retrieve persistent memories scoped to this agent. Each memory has a document type (plan, documentation, examples, context).',
    displayName: 'Agent Memory',
    category: 'productivity',
    icon: 'brain',
)]
#[ToolOperation(name: 'list', description: 'List all memories with summaries. Optional `type` filter.', enabledByDefault: true, requiresApprovalByDefault: false)]
#[ToolOperation(name: 'get', description: 'Get a single memory by name and type.', enabledByDefault: true, requiresApprovalByDefault: false)]
#[ToolOperation(name: 'save', description: 'Create or update a memory. Requires `name`, `type`, `content`.', enabledByDefault: true, requiresApprovalByDefault: false)]
#[ToolOperation(name: 'replace', description: 'Replace a single substring inside a memory content body. Errors on zero or multiple matches.', enabledByDefault: true, requiresApprovalByDefault: true)]
#[ToolOperation(name: 'delete', description: 'Delete a memory by name and type.', enabledByDefault: true, requiresApprovalByDefault: true)]
final class AgentMemoryTool extends AbstractMemoryTool
{
    protected function getScope(): string
    {
        return 'agent';
    }
}
