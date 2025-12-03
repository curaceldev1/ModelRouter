<?php

namespace Curacel\LlmOrchestrator\DataObjects;

final class ToolCall
{
    /**
     * @param  array<string, mixed>  $arguments
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $type = 'function',
        public readonly array $arguments = [],
    ) {}

    /**
     * Create a new ToolCall instance.
     *
     * @param  array<string, mixed>  $arguments
     */
    public static function make(
        string $id,
        string $name,
        string $type = 'function',
        array $arguments = [],
    ): self {
        return new self($id, $name, $type, $arguments);
    }

    /**
     * Convert to array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type,
            'arguments' => $this->arguments,
        ];
    }
}
