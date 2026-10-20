<?php

namespace Ernestdefoe\Resurface;

use Ernestdefoe\Resurface\Listener\KeepBump;
use Flarum\Discussion\Discussion;
use Flarum\Foundation\AbstractServiceProvider;

class ResurfaceServiceProvider extends AbstractServiceProvider
{
    public function boot(): void
    {
        Discussion::saving(fn (Discussion $discussion) => (new KeepBump())($discussion));
    }
}
