<?php

namespace PeopleInside\PowCaptcha\Service;

final class VerifyResult
{
    public function __construct(
        public readonly bool $ok,
        public readonly string $reason,
        public readonly int $difficulty = 0
    ) {
    }
}
