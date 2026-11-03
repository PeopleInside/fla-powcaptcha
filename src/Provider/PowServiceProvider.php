<?php

namespace PeopleInside\PowCaptcha\Provider;

use Flarum\Foundation\AbstractServiceProvider;
use PeopleInside\PowCaptcha\Support\ClientContext;

class PowServiceProvider extends AbstractServiceProvider
{
    public function register(): void
    {
        $this->container->singleton(ClientContext::class);
    }
}
