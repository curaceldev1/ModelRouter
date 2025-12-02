<?php

namespace Curacel\LlmOrchestrator\Exceptions;

use Exception;

class LlmOrchestratorException extends Exception
{
    /**
     * Contextual data for debugging/logging.
     *
     * @var array<string, mixed>
     */
    public array $context = [];

    /**
     * Create a new exception instance.
     */
    public function __construct(
        string $message = '',
        array $context = [],
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);

        $this->context = $context;
    }

    /**
     * Get the context.
     */
    public function getContext(): array
    {
        return $this->context;
    }
}
