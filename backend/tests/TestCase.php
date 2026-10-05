<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            storage_path('framework/testing/application-state.json'),
            storage_path('framework/testing/application-state.json.lock'),
        ] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }
}
