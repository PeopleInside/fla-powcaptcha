<?php

namespace PeopleInside\PowCaptcha\Listener;

use Flarum\User\Event\Registered;
use PeopleInside\PowCaptcha\Service\AbuseGuard;
use PeopleInside\PowCaptcha\Support\ClientContext;

/** Counts a completed registration against the network's daily sign-up limit. */
class RecordRegistration
{
    public function __construct(
        private readonly AbuseGuard $guard,
        private readonly ClientContext $context
    ) {
    }

    public function handle(Registered $event): void
    {
        // Accounts created by an administrator do not count against anyone's IP.
        if ($event->actor !== null && $event->actor->isAdmin()) {
            return;
        }

        $this->guard->recordSignup($this->context->ip());
    }
}
