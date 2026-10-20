<?php

namespace Ernestdefoe\Resurface;

use Carbon\Carbon;
use Ernestdefoe\Resurface\Event\Bumped;
use Ernestdefoe\Resurface\Event\Bumping;
use Flarum\Discussion\Discussion;
use Flarum\Extension\ExtensionManager;
use Flarum\Foundation\ValidationException;
use Flarum\Locale\TranslatorInterface;
use Flarum\User\Exception\PermissionDeniedException;
use Flarum\User\User;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;

/**
 * Bumps a discussion: moves it to the top of the list without a post.
 *
 * Only last_posted_at moves (the list sorts by it) and resurface_bumped_at records
 * the bump. Post numbers, the last post and its author stay, so nobody who
 * has read the discussion sees it as unread and nobody is notified.
 */
class Bumper
{
    public function __construct(
        private Limits $limits,
        private Dispatcher $events,
        private TranslatorInterface $translator,
        private ConnectionInterface $db,
        private ExtensionManager $extensions,
    ) {
    }

    /**
     * Where the actor stands with this discussion right now. Assumes the
     * policy already allows the bump.
     *
     * @return array{bypass: bool, cooldown: float, nextAt: Carbon|null, left: int|null}
     */
    public function status(User $actor, Discussion $discussion): array
    {
        if ($actor->can('resurfaceBumpAny', $discussion)) {
            return ['bypass' => true, 'cooldown' => 0.0, 'nextAt' => null, 'left' => null];
        }

        $limits = $this->limits->for($actor);
        $today = $this->limits->today($actor, $limits['daily']);

        $nextAt = $today['freeAt'];
        if ($limits['cooldown'] > 0 && $discussion->last_posted_at) {
            $ready = $discussion->last_posted_at->copy()->addMinutes((int) ceil($limits['cooldown'] * 60));
            if ($ready->isFuture() && (! $nextAt || $ready->gt($nextAt))) {
                $nextAt = $ready;
            }
        }

        return ['bypass' => false, 'cooldown' => $limits['cooldown'], 'nextAt' => $nextAt, 'left' => $today['left']];
    }

    /**
     * @throws PermissionDeniedException when the actor may not bump it at all
     * @throws ValidationException when it is too soon, or a listener refused
     */
    public function bump(User $actor, Discussion $discussion, string $source): Bump
    {
        if (! $actor->can('resurfaceBump', $discussion)) {
            throw new PermissionDeniedException();
        }

        // One transaction, the member's own row locked first: two bumps at
        // once (two tabs, two discussions) take turns, so the daily limit
        // holds; and if anything fails, whatever a Bumping listener charged
        // rolls back with it. The lock must be the transaction's first read:
        // MySQL's snapshot then starts after the other bump has committed.
        $bump = $this->db->transaction(function () use ($actor, $discussion, $source) {
            $this->db->table('users')->where('id', $actor->id)->lockForUpdate()->value('id');

            $status = $this->status($actor, $discussion);
            if ($status['nextAt']) {
                throw $this->tooSoon();
            }

            $this->events->dispatch(new Bumping($discussion, $actor, $source));

            $now = Carbon::now()->startOfSecond();

            $query = Discussion::query()->whereKey($discussion->id);
            $values = ['last_posted_at' => $now, 'resurface_bumped_at' => $now];
            if ($status['cooldown'] > 0) {
                // Checked again in the update itself, so the same discussion
                // bumped by its owner and by staff at once cannot both pass.
                // Only here does "no row updated" mean "too soon": MySQL counts
                // rows CHANGED, so an unconditional bump in the same second as
                // the last reports 0.
                $query->where('last_posted_at', '<=', $now->copy()->subMinutes((int) ceil($status['cooldown'] * 60)));
                if (! $query->update($values)) {
                    throw $this->tooSoon();
                }
            } else {
                $query->update($values);
            }

            $bump = new Bump();
            $bump->discussion_id = $discussion->id;
            $bump->user_id = $actor->id;
            $bump->source = $source;
            $bump->created_at = $now;
            $bump->save();

            $this->updateTags($discussion, $now);

            return $bump;
        });

        $discussion->last_posted_at = $bump->created_at;
        $discussion->resurface_bumped_at = $bump->created_at;
        $discussion->syncOriginalAttributes(['last_posted_at', 'resurface_bumped_at']);

        $this->events->dispatch(new Bumped($discussion, $actor, $bump));

        return $bump;
    }

    /**
     * The tags page names each tag's latest discussion. A bumped one is now
     * the latest by the same measure the list uses, so it is named there too,
     * exactly as a reply would make it.
     */
    private function updateTags(Discussion $discussion, Carbon $now): void
    {
        if ($discussion->is_private || $discussion->hidden_at || ! $this->extensions->isEnabled('flarum-tags')) {
            return;
        }

        $this->db->table('tags')
            ->whereIn('id', $this->db->table('discussion_tag')->select('tag_id')->where('discussion_id', $discussion->id))
            ->update([
                'last_posted_at' => $now,
                'last_posted_discussion_id' => $discussion->id,
                'last_posted_user_id' => $discussion->last_posted_user_id,
            ]);
    }

    private function tooSoon(): ValidationException
    {
        return new ValidationException(['resurfaceBump' => $this->translator->trans('ernestdefoe-resurface.api.too_soon')]);
    }
}
