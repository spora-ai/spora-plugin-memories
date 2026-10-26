<?php

declare(strict_types=1);

namespace Spora\Plugins\Memories\Services;

/**
 * Canonical home for the memory-domain enums and error codes.
 *
 * Its own file so controllers and the HTTP layer can import a single
 * namespace without dragging in the service class — the v2 split moved the
 * constants here, leaving `MemoryService` with only a deprecated shim.
 */
final class MemoryTypes
{
    /** @var list<string> */
    public const DOCUMENT_TYPES = ['plan', 'documentation', 'examples', 'context'];

    public const TYPE_NOT_ALLOWED_CODE   = 'TYPE_NOT_ALLOWED';
    public const REPLACE_NOT_FOUND_CODE  = 'REPLACE_NOT_FOUND';
    public const REPLACE_NOT_UNIQUE_CODE = 'REPLACE_NOT_UNIQUE';
}
