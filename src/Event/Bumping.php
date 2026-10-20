<?php

namespace Ernestdefoe\Resurface\Event;

use Flarum\Discussion\Discussion;
use Flarum\User\User;

/**
 * Before a bump, once every check has passed. A listener may throw (for
 * example a ValidationException with a message for the member) to stop it:
 * the seam for charging for bumps elsewhere, such as points or a payment.
 */
class Bumping
{
    public function __construct(
        public readonly Discussion $discussion,
        public readonly User $actor,
        public readonly string $source,
    ) {
    }
}
