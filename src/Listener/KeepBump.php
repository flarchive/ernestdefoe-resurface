<?php

namespace Ernestdefoe\Resurface\Listener;

use Flarum\Discussion\Discussion;

/**
 * Hiding or deleting a post makes core recompute last_posted_at from the
 * newest visible post, which would drop a bumped discussion back down. A
 * bump stays where it put the discussion until a newer reply passes it.
 */
class KeepBump
{
    public function __invoke(Discussion $discussion): void
    {
        $bumpedAt = $discussion->resurface_bumped_at;

        if ($bumpedAt && $discussion->last_posted_at && $discussion->isDirty('last_posted_at') && $discussion->last_posted_at->lt($bumpedAt)) {
            $discussion->last_posted_at = $bumpedAt;
        }
    }
}
