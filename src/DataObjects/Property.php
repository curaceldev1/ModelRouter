<?php

namespace Curacel\LlmOrchestrator\DataObjects;

use Curacel\LlmOrchestrator\Enums\PropertyType;

final readonly class Property
{
    /**
     * @param  array<int, Property>  $properties  For object types
     * @param  Property|null  $items  For array types
     * @param  array<int, mixed>|null  $enum  Allowed values
     */
    public function __construct(
        public string $name,
        public PropertyType $type,
        public ?string $description = null,
        public bool $required = false,
        public array $properties = [],
        public ?Property $items = null,
        public ?array $enum = null,
        public mixed $default = null,
    ) {}

    /**
     * Create a string property.
     */
    public static function string(string $name, ?string $description = null, bool $required = false): self
    {
        return new self($name, PropertyType::STRING, $description, $required, [], null);
    }

    /**
     * Create a number property.
     */
    public static function number(string $name, ?string $description = null, bool $required = false): self
    {
        return new self($name, PropertyType::NUMBER, $description, $required);
    }

    /**
     * Create an integer property.
     */
    public static function integer(string $name, ?string $description = null, bool $required = false): self
    {
        return new self($name, PropertyType::INTEGER, $description, $required);
    }

    /**
     * Create a boolean property.
     */
    public static function boolean(string $name, ?string $description = null, bool $required = false): self
    {
        return new self($name, PropertyType::BOOLEAN, $description, $required);
    }

    /**
     * Create an object property.
     *
     * @param  array<int, Property>  $properties
     */
    public static function object(string $name, array $properties, ?string $description = null, bool $required = false): self
    {
        return new self($name, PropertyType::OBJECT, $description, $required, $properties);
    }

    /**
     * Create an array property.
     */
    public static function array(string $name, Property $items, ?string $description = null, bool $required = false): self
    {
        return new self($name, PropertyType::ARRAY, $description, $required, [], $items);
    }

    /**
     * Create an enum property (string with allowed values).
     *
     * @param  array<int, string>  $values
     */
    public static function enum(string $name, array $values, ?string $description = null, bool $required = false): self
    {
        return new self($name, PropertyType::STRING, $description, $required, [], null, $values);
    }

    /**
     * Convert to JSON Schema format.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'type' => $this->type->value,
        ];

        if ($this->description) {
            $data['description'] = $this->description;
        }

        if ($this->enum !== null) {
            $data['enum'] = $this->enum;
        }

        if ($this->default !== null) {
            $data['default'] = $this->default;
        }

        // Handle object type
        if ($this->type === PropertyType::OBJECT && ! empty($this->properties)) {
            $data['properties'] = collect($this->properties)
                ->mapWithKeys(fn (self $property) => [$property->name => $property->toArray()])
                ->toArray();

            $required = collect($this->properties)
                ->filter(fn (self $property) => $property->required)
                ->pluck('name')
                ->values()
                ->toArray();

            if (! empty($required)) {
                $data['required'] = $required;
            }
        }

        // Handle array type
        if ($this->type === PropertyType::ARRAY && $this->items) {
            $data['items'] = $this->items->toArray();
        }

        return $data;
    }
}
