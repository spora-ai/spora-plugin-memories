<?php

declare(strict_types=1);

namespace Spora\Plugins\Memories\Tools;

use Spora\Plugins\Memories\Models\Memory;
use Spora\Plugins\Memories\Services\Exceptions\MemoryValidationException;
use Spora\Plugins\Memories\Services\MemoryTypes;
use Spora\Services\PrincipalContext;
use Spora\Services\Text\Utf8Sanitizer;
use Spora\Tools\AbstractTool;
use Spora\Tools\Attributes\ToolParameter;
use Spora\Tools\ValueObjects\ToolResult;

/**
 * Abstract base for memory tools that provide list, get, save, delete, and
 * replace operations. Subclasses define the scope (agent or global) via
 * getScope().
 *
 * Extending AbstractTool gives the subclasses (AgentMemoryTool, GlobalMemoryTool)
 * the auto-generated parameter schema for free — they only need their own
 * #[Tool] declaration and an implementation of getScope().
 *
 * Every memory written by this tool is attributed to the principal id on
 * `PrincipalContext` (resolved upstream by the host). For global scope that
 * is the user-principal; for agent scope it is the agent's owning principal.
 * Agents that travel between principals (0067 transfer flow) keep their
 * agent memories because the agent FK never changes.
 */
#[ToolParameter(name: 'name', type: 'string', description: 'Unique name for the memory (e.g. "user_preferences", "project_context").', required: ['get', 'save', 'delete', 'replace'])]
#[ToolParameter(name: 'type', type: 'string', description: "Document type: 'plan', 'documentation', 'examples', or 'context'.", required: ['save', 'replace', 'get'], enum: ['plan', 'documentation', 'examples', 'context'])]
#[ToolParameter(name: 'content', type: 'string', description: 'Memory content in markdown. Required for save action.', required: ['save'])]
#[ToolParameter(name: 'summary', type: 'string', description: 'Brief one-line summary for list view. Auto-derived from content if omitted.', required: false)]
#[ToolParameter(name: 'order', type: 'integer', description: 'Sort order for listing. Defaults to 0.', required: false)]
#[ToolParameter(name: 'find', type: 'string', description: 'Exact substring to replace in the memory content. Must be unique within the content.', required: ['replace'])]
#[ToolParameter(name: 'new_text', type: 'string', description: 'Replacement text for the `find` substring.', required: ['replace'])]
abstract class AbstractMemoryTool extends AbstractTool
{
    abstract protected function getScope(): string;

    public function execute(
        array $arguments,
        int $agentId,
        ?int $userId = null,
        ?int $taskId = null,
        ?PrincipalContext $context = null,
    ): ToolResult {
        $operation = $this->getOperationName($arguments);
        $scope = $this->getScope();
        $principalId = $context !== null ? $context->principalId : ($userId ?? throw new MemoryValidationException(
            'Cannot resolve principal id for memory tool execution: no PrincipalContext and no legacy userId fallback.',
        ));

        return match ($operation) {
            'list'    => $this->list($scope, $agentId, $principalId, $arguments),
            'get'     => $this->runGet($arguments, $scope, $agentId, $principalId),
            'save'    => $this->runSave($arguments, $scope, $agentId, $principalId),
            'replace' => $this->runReplace($arguments, $scope, $agentId, $principalId),
            'delete'  => $this->runDelete($arguments, $scope, $agentId, $principalId),
            default   => new ToolResult(false, 'Invalid action. Must be list, get, save, replace, or delete.'),
        };
    }

    public function describeAction(array $arguments): string
    {
        $op = (string) ($arguments['action'] ?? $this->getOperationName($arguments));
        $name = (string) ($arguments['name'] ?? '');
        return "Memory {$op}: {$name}";
    }

    public function list(string $scope, int $agentId, int $principalId, array $arguments = []): ToolResult
    {
        $type = isset($arguments['type']) ? (string) $arguments['type'] : null;

        if ($scope === 'global') {
            $query = Memory::forPrincipal($principalId);
        } else {
            $query = Memory::forAgent($agentId);
        }
        if ($type !== null && $type !== '') {
            $query->ofType($type);
        }
        $memories = $query->orderBy('order')->orderBy('name')->get();

        if ($memories->isEmpty()) {
            return new ToolResult(true, "No memories found in {$scope} scope.");
        }

        $lines = ["Found {$memories->count()} memory(ies) in {$scope} scope:"];
        foreach ($memories as $m) {
            $summary = $m->summary !== null ? " — {$m->summary}" : '';
            $typeTag = " [{$m->type}]";
            $lines[] = "- [{$m->name}]{$typeTag}{$summary}";
        }

        return new ToolResult(true, implode("\n", $lines));
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function runGet(array $arguments, string $scope, int $agentId, int $principalId): ToolResult
    {
        $validationError = $this->requireNameAndType($arguments, 'get');
        if ($validationError !== null) {
            return $validationError;
        }

        $name = trim((string) ($arguments['name'] ?? ''));
        $type = (string) ($arguments['type'] ?? '');

        $memory = $this->findMemory($name, $type, $scope, $agentId, $principalId);
        if ($memory === null) {
            return new ToolResult(false, "Memory [{$name}] (type={$type}) not found in {$scope} scope.");
        }

        $header = "# {$memory->name} ({$memory->type})";
        if ($memory->summary !== null) {
            $header .= "\n*Summary: {$memory->summary}*";
        }
        $header .= "\n\n";

        return new ToolResult(true, $header . ($memory->content ?? ''));
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function runSave(array $arguments, string $scope, int $agentId, int $principalId): ToolResult
    {
        $validationError = $this->requireNameAndType($arguments, 'save');
        if ($validationError !== null) {
            return $validationError;
        }

        $name = trim((string) ($arguments['name'] ?? ''));
        $type = (string) ($arguments['type'] ?? '');
        $content = (string) ($arguments['content'] ?? '');
        $summary = isset($arguments['summary']) ? trim((string) $arguments['summary']) : null;
        $order = isset($arguments['order']) ? (int) $arguments['order'] : 0;

        if (!in_array($type, MemoryTypes::DOCUMENT_TYPES, true)) {
            return new ToolResult(
                false,
                sprintf("Error: type '%s' is not one of: %s", $type, implode(', ', MemoryTypes::DOCUMENT_TYPES)),
            );
        }

        $memory = $this->findMemory($name, $type, $scope, $agentId, $principalId);
        if ($memory !== null) {
            $this->updateMemoryFields($memory, $content, $summary, $order);
            return new ToolResult(true, "Updated memory [{$name}] (type={$type}) in {$scope} scope.");
        }

        $summary ??= $content !== '' ? mb_substr(strip_tags($content), 0, 200) : null;
        $this->createMemory($scope, $agentId, $principalId, $name, $type, new CreateMemoryBody($summary, $content, $order));

        return new ToolResult(true, "Created memory [{$name}] (type={$type}) in {$scope} scope.");
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function runReplace(array $arguments, string $scope, int $agentId, int $principalId): ToolResult
    {
        $name = trim((string) ($arguments['name'] ?? ''));
        $type = (string) ($arguments['type'] ?? '');
        $find = (string) ($arguments['find'] ?? '');
        $newText = (string) ($arguments['new_text'] ?? '');

        foreach (['name' => $name, 'type' => $type, 'find' => $find] as $field => $value) {
            if ($value === '') {
                return new ToolResult(false, "Error: {$field} is required for replace action.");
            }
        }

        $memory = $this->findMemory($name, $type, $scope, $agentId, $principalId);
        if ($memory === null) {
            return new ToolResult(false, "Memory [{$name}] (type={$type}) not found in {$scope} scope.");
        }

        try {
            $memory->content = $this->replaceInMemoryContent((string) ($memory->content ?? ''), $find, $newText);
        } catch (MemoryValidationException $e) {
            return new ToolResult(false, $e->getMessage());
        }
        $memory->save();
        \Illuminate\Database\Capsule\Manager::table('memories')
            ->where('id', $memory->id)
            ->update(['updated_at' => gmdate('Y-m-d H:i:s')]);

        return new ToolResult(true, "Replaced 1 occurrence in [{$name}] (type={$type}).");
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function runDelete(array $arguments, string $scope, int $agentId, int $principalId): ToolResult
    {
        $validationError = $this->requireNameAndType($arguments, 'delete');
        if ($validationError !== null) {
            return $validationError;
        }

        $name = trim((string) ($arguments['name'] ?? ''));
        $type = (string) ($arguments['type'] ?? '');

        $memory = $this->findMemory($name, $type, $scope, $agentId, $principalId);
        if ($memory === null) {
            return new ToolResult(false, "Memory [{$name}] (type={$type}) not found in {$scope} scope.");
        }
        $memory->delete();

        return new ToolResult(true, "Deleted memory [{$name}] (type={$type}) from {$scope} scope.");
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function requireNameAndType(array $arguments, string $action): ?ToolResult
    {
        $name = trim((string) ($arguments['name'] ?? ''));
        if ($name === '') {
            return new ToolResult(false, "Error: name is required for {$action} action.");
        }
        $type = (string) ($arguments['type'] ?? '');
        if ($type === '') {
            return new ToolResult(false, "Error: type is required for {$action} action.");
        }

        return null;
    }

    private function updateMemoryFields(Memory $memory, string $content, ?string $summary, int $order): void
    {
        $memory->content = Utf8Sanitizer::scrubString($content);
        if ($summary !== null) {
            $memory->summary = Utf8Sanitizer::scrubString($summary);
        }
        $memory->order = $order;
        $memory->save();
    }

    private function createMemory(string $scope, int $agentId, int $principalId, string $name, string $type, CreateMemoryBody $body): void
    {
        $memory = new Memory();
        if ($scope === 'global') {
            $memory->principal_id = $principalId;
            $memory->scope = 'global';
        } else {
            $memory->agent_id = $agentId;
            $memory->scope = 'agent';
        }
        $memory->type    = $type;
        $memory->name    = $name;
        $memory->summary = $body->summary !== null ? Utf8Sanitizer::scrubString($body->summary) : null;
        $memory->content = Utf8Sanitizer::scrubString($body->content);
        $memory->order   = $body->order;
        $memory->save();
    }

    private function findMemory(string $name, string $type, string $scope, int $agentId, int $principalId): ?Memory
    {
        if ($scope === 'global') {
            $query = Memory::forPrincipal($principalId);
        } else {
            $query = Memory::forAgent($agentId);
        }
        return $query->where('name', $name)->where('type', $type)->first();
    }

    private function replaceInMemoryContent(string $current, string $find, string $newText): string
    {
        $count = mb_substr_count($current, $find);
        if ($count === 0) {
            throw new MemoryValidationException("find matches 0 occurrences.");
        }
        if ($count > 1) {
            throw new MemoryValidationException(
                "find matches {$count} > 1 occurrences; provide a unique substring.",
            );
        }

        return Utf8Sanitizer::scrubString(str_replace($find, $newText, $current));
    }
}
