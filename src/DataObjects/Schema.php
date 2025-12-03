<?php

namespace Curacel\LlmOrchestrator\DataObjects;

final class Schema
{
    /**
     * @param  array<int, Property>  $properties
     */
    public function __construct(
        public readonly string $name,
        public readonly ?string $description = null,
        public readonly array $properties = [],
        public readonly bool $strict = true,
        public readonly bool $additionalProperties = false,
    ) {}

    /**
     * Create a new schema.
     *
     * @param  array<int, Property>  $properties
     */
    public static function make(
        string $name,
        ?string $description = null,
        array $properties = [],
        bool $strict = true
    ): self {
        return new self($name, $description, $properties, $strict);
    }

    /**
     * Crete a new schema from a Tool
     */
    public static function fromTool(Tool $tool): self
    {
        return new self(
            name: $tool->name,
            description: $tool->description,
            properties: $tool->properties,
            strict: $tool->strict,
        );
    }

    /**
     * Convert to JSON Schema format.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $propertiesMap = collect($this->properties)
            ->mapWithKeys(fn (Property $property) => [$property->name => $property->toArray()])
            ->toArray();

        $required = collect($this->properties)
            ->filter(fn (Property $property) => $property->required)
            ->pluck('name')
            ->values()
            ->toArray();

        return [
            'name' => $this->name,
            'description' => $this->description,
            'strict' => $this->strict,
            'schema' => [
                'type' => 'object',
                'properties' => $propertiesMap,
                'required' => $required,
                'additionalProperties' => $this->additionalProperties,
            ],
        ];
    }
}
