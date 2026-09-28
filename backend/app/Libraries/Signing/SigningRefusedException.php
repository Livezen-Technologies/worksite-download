<?php

namespace App\Libraries\Signing;

use RuntimeException;

final class SigningRefusedException extends RuntimeException
{
    /** @param list<string> $reasons */
    public function __construct(public readonly array $reasons)
    {
        parent::__construct('Signing refused: ' . implode('; ', $reasons));
    }
}
