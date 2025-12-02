<?php

namespace Curacel\LlmOrchestrator\Exceptions;

class MessageValidationException extends LlmOrchestratorException
{
    public static function forDriver(string $driver, string $message): self
    {

        return new self(message: $message, context: ['driver' => $driver]);
    }
}
