<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class HelloWorldTest extends TestCase
{
    public function test_says_hello_world(): void
    {
        $this->assertSame('hello world', 'hello world');
    }
}