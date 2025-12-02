<?php

namespace Curacel\LlmOrchestrator\Exceptions;

class InvalidDriverException extends LlmOrchestratorException
{
    public static function forDriver(string|object $driver, string $client): self
    {
        $driverName = is_object($driver) ? get_class($driver) : $driver;

        return new self(
            message: "Driver class [{$driverName}] for client [{$client}] is invalid or does not implement the required driver interface",
            context: [
                'client' => $client,
                'driver' => $driverName,
            ]
        );
    }
}
