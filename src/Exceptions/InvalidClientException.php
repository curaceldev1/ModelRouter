<?php

namespace Curacel\LlmOrchestrator\Exceptions;

class InvalidClientException extends LlmOrchestratorException
{
    public static function forClient(string $client, array $availableClients = []): self
    {
        return new self(
            message: "Invalid LLM client requested: {$client}",
            context: [
                'requested_client' => $client,
                'available_clients' => $availableClients,
            ]
        );
    }
}
