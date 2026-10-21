<?php

declare(strict_types=1);

namespace Spora\Plugins\Memories\Tools;

/**
 * Body fields for {@see AbstractMemoryTool::createMemory()} — one value
 * object instead of three loose arguments, so the creator stays under
 * Sonar's 7-parameter ceiling.
 */
final readonly class CreateMemoryBody
{
    public function __construct(
        public ?string $summary,
        public string $content,
        public int $order,
    ) {}
}
