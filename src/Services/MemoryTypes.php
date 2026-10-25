<?php

declare(strict_types=1);

namespace Spora\Plugins\Memories\Services;

/**
 * Canonical home for the memory-domain enums and error codes.
 *
 * Lives in its own file so controllers and the HTTP layer can import a
 * single namespace without dragging in the service class — and so the
 * service class the v2 split shrank does not carry the domain constants
 * as well.
 */
final class MemoryTypes
{
    /** @var list<string> */
    public const DOCUMENT_TYPES = ['plan', 'documentation', 'examples', 'context'];

    public const TYPE_NOT_ALLOWED_CODE   = 'TYPE_NOT_ALLOWED';
    public const REPLACE_NOT_FOUND_CODE  = 'REPLACE_NOT_FOUND';
    public const REPLACE_NOT_UNIQUE_CODE = 'REPLACE_NOT_UNIQUE';
}
