<?php

namespace Curacel\LlmOrchestrator\DataObjects;

final readonly class Message
{
    /**
     * @param  string|Content|array<int, Content>  $content
     */
    public function __construct(
        public string $role,
        public string|Content|array $content,
        public ?array $additionalProperties = [],
    ) {}

    /**
     * Create a new message.
     *
     * @param  string|Content|array<int, Content>  $content
     */
    public static function make(string $role, string|Content|array $content, ?array $additionalProperties = []): self
    {
        return new self(role: $role, content: $content, additionalProperties: $additionalProperties);
    }

    /**
     * Get content as an array of Content objects.
     *
     * @return array<int, Content>
     */
    public function getContentParts(): array
    {
        if (is_string($this->content)) {
            return [Content::text($this->content)];
        }

        if ($this->content instanceof Content) {
            return [$this->content];
        }

        return $this->content;
    }

    /**
     * Convert to array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'role' => $this->role,
            'content' => array_map(fn (Content $part) => $part->toArray(), $this->getContentParts()),
            ...$this->additionalProperties,
        ];
    }
}
