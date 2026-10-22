<?php

declare(strict_types=1);

namespace Spora\Plugins\Memories\Services;

/**
 * Combined contract for memory persistence (principal-scoped global +
 * agent-scoped). Spans {@see MemoryQueryInterface} (reads) and
 * {@see MemoryCommandInterface} (writes), so a caller can depend on the
 * narrower interface for the half it uses.
 *
 * Ownership is anchored on `principalId` for global memories (the
 * post-0067 principal model) and on `agentId` for agent memories (the
 * agent travels with its memories through owner transfers).
 */
interface MemoryServiceInterface extends MemoryQueryInterface, MemoryCommandInterface {}
