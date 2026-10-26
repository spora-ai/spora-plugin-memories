<?php

declare(strict_types=1);

namespace Spora\Plugins\Memories\Services;

/**
 * Backward-compatible constants shim for the v2 service split.
 *
 * The implementations moved to {@see MemoryQueryService} and
 * {@see MemoryCommandService}, the domain enums and error codes to
 * {@see MemoryTypes}. This class carries no methods and exists so external
 * code that still references `MemoryService::TYPE_NOT_ALLOWED_CODE` keeps
 * compiling.
 *
 * Kept deliberately, without a new removal date: it costs the plugin nothing
 * to ship, and dropping it would break the external code that still compiles
 * against the old constants. The removal version last promised here — 0.4.0 —
 * came and went with the shim still shipping, so a new date would be a second
 * promise the plugin has no plan to keep.
 *
 * @deprecated since 0.4.0 — use {@see MemoryTypes} for constants,
 *             {@see MemoryQueryService} / {@see MemoryCommandService}
 *             for the implementations.
 */
final class MemoryService
{
    /** @var list<string> */
    public const DOCUMENT_TYPES         = MemoryTypes::DOCUMENT_TYPES;
    public const TYPE_NOT_ALLOWED_CODE  = MemoryTypes::TYPE_NOT_ALLOWED_CODE;
    public const REPLACE_NOT_FOUND_CODE = MemoryTypes::REPLACE_NOT_FOUND_CODE;
    public const REPLACE_NOT_UNIQUE_CODE = MemoryTypes::REPLACE_NOT_UNIQUE_CODE;
}
