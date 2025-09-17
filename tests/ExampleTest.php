<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ExampleTest extends TestCase
{
    public function test_hello_world_addition(): void
    {
        $this->assertSame(2, 1 + 1);
    }
}