<?php

namespace Curacel\LlmOrchestrator\DataObjects;

final readonly class Schema
{
    /**
     * @param  array<int, Property>  $properties
     */
    public function __construct(
        public string $name,
        public ?string $description = null,
        public array $properties = [],
        public bool $strict = true,
        public bool $additionalProperties = false,
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
