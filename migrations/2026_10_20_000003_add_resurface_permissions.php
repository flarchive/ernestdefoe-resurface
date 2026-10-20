<?php

use Flarum\Database\Migration;
use Flarum\Group\Group;

return Migration::addPermissions([
    'discussion.resurfaceBump' => Group::MEMBER_ID,
    'discussion.resurfaceBumpAny' => Group::MODERATOR_ID,
]);
