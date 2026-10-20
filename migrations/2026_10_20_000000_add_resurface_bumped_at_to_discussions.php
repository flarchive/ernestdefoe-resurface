<?php

use Flarum\Database\Migration;

// When the discussion was last bumped. A bump also moves last_posted_at, which
// is what the discussion list sorts by; this column is how the list tells a
// bump from a reply.
return Migration::addColumns('discussions', [
    'resurface_bumped_at' => ['dateTime', 'nullable' => true],
]);
