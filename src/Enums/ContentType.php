<?php

namespace Curacel\LlmOrchestrator\Enums;

enum ContentType: string
{
    case TEXT = 'text';
    case IMAGE = 'image';
    case AUDIO = 'audio';
    case DOCUMENT = 'document';
    case FILE = 'file';
}
