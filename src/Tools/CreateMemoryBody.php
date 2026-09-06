<?php

declare(strict_types=1);

namespace Spora\Plugins\Memories\Tools;

/**
 * Body fields for {@see AbstractMemoryTool::createMemory()} — packaged so
 * the creator takes a single value object instead of three loose args
 * (summary / content / order) and Sonar's per-method parameter count
 * stays under the 7-parameter ceiling.
 */
final readonly class CreateMemoryBody
{
    public function __construct(
        public ?string $summary,
        public string $content,
        public int $order,
    ) {}
}
