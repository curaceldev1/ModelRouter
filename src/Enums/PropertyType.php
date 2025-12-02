<?php

namespace Curacel\LlmOrchestrator\Enums;

enum PropertyType: string
{
    case STRING = 'string';
    case NUMBER = 'number';
    case INTEGER = 'integer';
    case BOOLEAN = 'boolean';
    case OBJECT = 'object';
    case ARRAY = 'array';
}
