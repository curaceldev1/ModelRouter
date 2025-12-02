<?php

namespace Curacel\LlmOrchestrator\DataObjects;

final class Tool
{
    /**
     * @param  array<int, Property>  $properties
     */
    public function __construct(
        public string $name,
        public ?string $description = null,
        public array $properties = [],
        public bool $strict = false,
    ) {}

    /**
     * Create a new tool.
     *
     * @param  array<int, Property>  $properties
     */
    public static function make(string $name, string $description = '', array $properties = []): self
    {
        return new self($name, $description, $properties);
    }

    /**
     * Crete a new schema from a Schema
     */
    public static function fromSchema(Schema $schema): self
    {
        return new self(
            name: $schema->name,
            description: $schema->description,
            properties: $schema->properties,
            strict: $schema->strict,
        );
    }

    /**
     * Convert to array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'description' => $this->description,
            'parameters' => [
                'type' => 'object',
                'properties' => collect($this->properties)
                    ->mapWithKeys(fn (Property $property) => [$property->name => $property->toArray()])
                    ->toArray(),
                'required' => collect($this->properties)
                    ->filter(fn (Property $property) => $property->required)
                    ->pluck('name')
                    ->values()
                    ->toArray(),
            ],
            'strict' => $this->strict,
        ];
    }
}
