<?php

use Ernestdefoe\Resurface\Access\DiscussionPolicy;
use Ernestdefoe\Resurface\Api;
use Ernestdefoe\Resurface\ResurfaceServiceProvider;
use Ernestdefoe\Resurface\Bump;
use Ernestdefoe\Resurface\Console\RunSchedules;
use Ernestdefoe\Resurface\Search\BumpSearcher;
use Ernestdefoe\Resurface\Search\DiscussionFilter;
use Ernestdefoe\Resurface\Settings;
use Flarum\Api\Endpoint;
use Flarum\Api\Resource\DiscussionResource;
use Flarum\Discussion\Discussion;
use Flarum\Extend;
use Flarum\Search\Database\DatabaseSearchDriver;
use Illuminate\Console\Scheduling\Event;

return [
    (new Extend\Frontend('forum'))
        ->js(__DIR__.'/js/dist/forum.js')
        ->css(__DIR__.'/less/forum.less'),

    (new Extend\Frontend('admin'))
        ->js(__DIR__.'/js/dist/admin.js')
        ->css(__DIR__.'/less/admin.less'),

    new Extend\Locales(__DIR__.'/locale'),

    (new Extend\Settings())
        ->default(Settings::COOLDOWN, Settings::DEFAULTS[Settings::COOLDOWN])
        ->default(Settings::DAILY, Settings::DEFAULTS[Settings::DAILY])
        ->default(Settings::GROUPS, Settings::DEFAULTS[Settings::GROUPS])
        ->default(Settings::TAGS, Settings::DEFAULTS[Settings::TAGS])
        ->default(Settings::LOG_DAYS, Settings::DEFAULTS[Settings::LOG_DAYS]),

    (new Extend\Model(Discussion::class))
        ->cast('resurface_bumped_at', 'datetime'),

    (new Extend\ApiResource(DiscussionResource::class))
        ->fields(Api\DiscussionFields::class),

    new Extend\ApiResource(Api\BumpResource::class),

    (new Extend\Conditional())
        ->whenExtensionEnabled('flarum-tags', fn () => [
            (new Extend\ApiResource(Api\BumpResource::class))
                ->endpoint('index', fn (Endpoint\Index $endpoint) => $endpoint->eagerLoad(['discussion.tags'])),
        ]),

    (new Extend\SearchDriver(DatabaseSearchDriver::class))
        ->addSearcher(Bump::class, BumpSearcher::class)
        ->addFilter(BumpSearcher::class, DiscussionFilter::class),

    (new Extend\Policy())
        ->modelPolicy(Discussion::class, DiscussionPolicy::class),

    (new Extend\ServiceProvider())
        ->register(ResurfaceServiceProvider::class),

    (new Extend\Console())
        ->command(RunSchedules::class)
        ->schedule(RunSchedules::class, function (Event $event): void {
            $event->everyMinute()->withoutOverlapping();
        }),
];
