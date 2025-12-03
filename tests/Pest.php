<?php

use Curacel\LlmOrchestrator\Tests\TestCase;

uses(TestCase::class)->in(__DIR__);

/**
 * Helper function to invoke protected/private methods using reflection.
 */
function invokeProtectedMethod(object $object, string $methodName, array $parameters = []): mixed
{
    $reflection = new ReflectionClass($object);
    $method = $reflection->getMethod($methodName);
    $method->setAccessible(true);

    return $method->invokeArgs($object, $parameters);
}
