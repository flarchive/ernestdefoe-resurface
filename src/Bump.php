<?php

namespace Ernestdefoe\Resurface;

use Carbon\Carbon;
use Flarum\Database\AbstractModel;
use Flarum\Discussion\Discussion;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One bump, as the log shows it.
 *
 * @property int $id
 * @property int $discussion_id
 * @property int|null $user_id
 * @property string $source
 * @property Carbon $created_at
 * @property Discussion|null $discussion
 * @property User|null $user
 */
class Bump extends AbstractModel
{
    public const MANUAL = 'manual';
    public const SCHEDULED = 'scheduled';
    public const AUTO = 'auto';

    protected $table = 'resurface_bumps';

    public $timestamps = false;

    protected $casts = [
        'id' => 'integer',
        'discussion_id' => 'integer',
        'user_id' => 'integer',
        'created_at' => 'datetime',
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
