<?php

declare(strict_types=1);

namespace Spora\Plugins\Memories\Exceptions;

use RuntimeException;

/**
 * Thrown when the controller layer can't find a logged-in user behind
 * the request. Distinct from {@see \Spora\Services\Exceptions\PrincipalMaterialisationException}
 * (which fires deeper, when the principal row write fails) — the
 * HTTP layer wants to translate this one into a 401 before any
 * principal-lookup machinery runs.
 */
final class NotAuthenticatedException extends RuntimeException {}
