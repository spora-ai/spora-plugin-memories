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
 * @deprecated use {@see MemoryTypes} for constants,
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
