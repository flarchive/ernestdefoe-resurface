<?php

namespace Ernestdefoe\Resurface;

use Carbon\Carbon;
use Flarum\Database\AbstractModel;
use Flarum\Discussion\Discussion;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A discussion's bump plan: one bump at `next_at`, or one every
 * `interval_hours` from then, `remaining` more times (null: until cancelled).
 * The bumps are made as `user`, under that user's permissions and limits.
 *
 * @property int $id
 * @property int $discussion_id
 * @property int $user_id
 * @property Carbon $next_at
 * @property int|null $interval_hours
 * @property int|null $remaining
 * @property Discussion|null $discussion
 * @property User|null $user
 */
class Schedule extends AbstractModel
{
    protected $table = 'resurface_schedules';

    public $timestamps = true;

    protected $casts = [
        'id' => 'integer',
        'discussion_id' => 'integer',
        'user_id' => 'integer',
        'interval_hours' => 'integer',
        'remaining' => 'integer',
        'next_at' => 'datetime',
    ];

    /** @return BelongsTo<Discussion, $this> */
    public function discussion(): BelongsTo
    {
        return $this->belongsTo(Discussion::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
