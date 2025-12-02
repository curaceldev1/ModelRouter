<?php

namespace Curacel\LlmOrchestrator\Exceptions;

class AllClientsFailedException extends LlmOrchestratorException
{
    public static function afterAttempts(array $attemptedClients, array $errors): self
    {
        return new self(
            message: 'All LLM clients failed: '.implode(', ', $attemptedClients),
            context: [
                'attempted_clients' => $attemptedClients,
                'errors' => $errors,
            ]
        );
    }
}
