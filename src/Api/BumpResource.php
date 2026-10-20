<?php

namespace Ernestdefoe\Resurface\Api;

use Ernestdefoe\Resurface\Bump;
use Flarum\Api\Endpoint;
use Flarum\Api\Resource\AbstractDatabaseResource;
use Flarum\Api\Schema;
use Flarum\Api\Sort\SortColumn;

/**
 * The bump log, for staff: those who may bump any discussion. BumpSearcher
 * leaves out discussions the reader cannot see.
 *
 * @extends AbstractDatabaseResource<Bump>
 */
class BumpResource extends AbstractDatabaseResource
{
    public function type(): string
    {
        return 'resurface-bumps';
    }

    public function model(): string
    {
        return Bump::class;
    }

    public function endpoints(): array
    {
        return [
            Endpoint\Index::make()
                ->can('discussion.resurfaceBumpAny')
                ->defaultInclude(['discussion', 'user'])
                // What the included discussions' and users' own fields read
                // (read state, groups); tags adds its relation in extend.php.
                ->eagerLoad(['discussion.state', 'user.groups'])
                ->defaultSort('-createdAt')
                ->paginate(20, 50),
        ];
    }

    public function fields(): array
    {
        return [
            Schema\Str::make('source'),
            Schema\DateTime::make('createdAt'),
            Schema\Relationship\ToOne::make('discussion')
                ->includable()
                ->type('discussions'),
            Schema\Relationship\ToOne::make('user')
                ->includable()
                ->type('users'),
        ];
    }

    public function sorts(): array
    {
        return [SortColumn::make('createdAt')];
    }
}
