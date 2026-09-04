<?php

declare(strict_types=1);

namespace Spora\Plugins\Memories\Tools;

use Spora\Tools\Attributes\Tool;
use Spora\Tools\Attributes\ToolOperation;

/**
 * Stores and retrieves persistent memories shared across all agents for
 * the calling principal. Memories here are principal-scoped post-0067;
 * each principal (user-principal or group-principal) has its own namespace.
 */
#[Tool(
    name: 'global_memory',
    description: 'Store and retrieve persistent memories shared across all agents for this principal. Each memory has a document type (plan, documentation, examples, context).',
    displayName: 'Global Memory',
    category: 'productivity',
    icon: 'brain',
)]
#[ToolOperation(name: 'list', description: 'List all memories with summaries. Optional `type` filter.', enabledByDefault: true, requiresApprovalByDefault: false)]
#[ToolOperation(name: 'get', description: 'Get a single memory by name and type.', enabledByDefault: true, requiresApprovalByDefault: false)]
#[ToolOperation(name: 'save', description: 'Create or update a memory. Requires `name`, `type`, `content`.', enabledByDefault: true, requiresApprovalByDefault: false)]
#[ToolOperation(name: 'replace', description: 'Replace a single substring inside a memory content body. Errors on zero or multiple matches.', enabledByDefault: true, requiresApprovalByDefault: true)]
#[ToolOperation(name: 'delete', description: 'Delete a memory by name and type.', enabledByDefault: true, requiresApprovalByDefault: true)]
final class GlobalMemoryTool extends AbstractMemoryTool
{
    protected function getScope(): string
    {
        return 'global';
    }
}
