<?php

namespace Ernestdefoe\Resurface\Event;

use Ernestdefoe\Resurface\Bump;
use Flarum\Discussion\Discussion;
use Flarum\User\User;

class Bumped
{
    public function __construct(
        public readonly Discussion $discussion,
        public readonly User $actor,
        public readonly Bump $bump,
    ) {
    }
}
