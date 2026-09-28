<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Error with a stable machine code (§10.8). Rendered as
 * { "error": { "code", "message", "details" } }.
 */
class ApiException extends RuntimeException
{
    /** @var array<string, string> */
    private array $headers;

    /**
     * @param array<string, mixed>|list<mixed> $details
     * @param array<string, string>            $headers
     */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 400,
        public readonly array $details = [],
        array $headers = [],
    ) {
        parent::__construct($message, $status);
        $this->headers = $headers;
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return $this->headers;
    }

    public static function notFound(string $code = 'NOT_FOUND', string $message = 'Not found'): self
    {
        return new self($code, $message, 404);
    }

    public static function forbidden(string $message = 'Forbidden', string $code = 'FORBIDDEN'): self
    {
        return new self($code, $message, 403);
    }

    /** @param array<string, mixed> $details */
    public static function validation(array $details, string $message = 'Validation failed'): self
    {
        return new self('VALIDATION_FAILED', $message, 422, $details);
    }

    public static function conflict(string $code, string $message, array $details = []): self
    {
        return new self($code, $message, 409, $details);
    }
}
