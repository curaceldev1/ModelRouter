<?php

namespace Curacel\LlmOrchestrator\DataObjects;

use Curacel\LlmOrchestrator\Enums\ContentType;

final readonly class Content
{
    /**
     * @param  string|array<int, mixed>  $data
     * @param  array<string, mixed>|null  $metadata
     */
    public function __construct(
        public ContentType $type,
        public string|array $data,
        public ?array $metadata = null,
    ) {}

    /**
     * Create text content.
     */
    public static function text(string $text): self
    {
        return new self(type: ContentType::TEXT, data: $text);
    }

    /**
     * Create image content.
     *
     * @param  string  $path  URL or base64 encoded image
     */
    public static function image(string $path, ?array $metadata = null): self
    {
        return new self(
            type: ContentType::IMAGE,
            data: $path,
            metadata: $metadata,
        );
    }

    /**
     * Create audio content.
     *
     * @param  string  $path  URL or base64 encoded audio
     */
    public static function audio(string $path, ?array $metadata = null): self
    {
        return new self(
            type: ContentType::AUDIO,
            data: $path,
            metadata: $metadata,
        );
    }

    /**
     * Create file content.
     *
     * @param  string  $path  URL or base64 encoded file
     */
    public static function file(string $path, ?array $metadata = null): self
    {
        return new self(
            type: ContentType::FILE,
            data: $path,
            metadata: $metadata,
        );
    }

    /**
     * Create document content.
     *
     * @param  string  $path  URL or base64 encoded document
     */
    public static function document(string $path, ?array $metadata = null): self
    {
        return new self(
            type: ContentType::DOCUMENT,
            data: $path,
            metadata: $metadata,
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
            'type' => $this->type->value,
            'data' => $this->data,
            'metadata' => $this->metadata,
        ];
    }
}
