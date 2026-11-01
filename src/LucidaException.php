<?php
declare(strict_types=1);

namespace Lucida;

/** Delivery/import failure; also what onError receives (status 0 = network error). */
final class LucidaException extends \RuntimeException
{
    public function __construct(string $message, public readonly int $status = 0)
    {
        parent::__construct($message);
    }
}
