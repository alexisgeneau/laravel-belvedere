<?php

declare(strict_types=1);

namespace Belvedere\Tests;

abstract class DisabledTestCase extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('belvedere.enabled', false);
    }
}
