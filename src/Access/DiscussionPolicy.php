<?php

namespace Ernestdefoe\Resurface\Access;

use Ernestdefoe\Resurface\Settings;
use Flarum\Discussion\Discussion;
use Flarum\Extension\ExtensionManager;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\Access\AbstractPolicy;
use Flarum\User\User;

/**
 * Who may bump, and who may plan bumps, on a discussion. Limits (cooldown,
 * bumps per day) are not permissions: they live in Limits and Bumper.
 *
 * Returning null hands the decision to core and flarum/tags: core allows it
 * with the `discussion.resurfaceBump` / `discussion.resurfaceSchedule` permission, and
 * tags asks for the tag-scoped one in restricted tags.
 */
class DiscussionPolicy extends AbstractPolicy
{
    public function __construct(
        private SettingsRepositoryInterface $settings,
        private ExtensionManager $extensions,
    ) {
    }

    public function resurfaceBump(User $actor, Discussion $discussion): ?string
    {
        if ($discussion->hidden_at || $actor->isGuest()) {
            return $this->deny();
        }

        // Force: in a restricted tag, flarum/tags would otherwise deny
        // `resurfaceBump` to staff who hold only the tag's "bump any" permission.
        if ($actor->can('resurfaceBumpAny', $discussion)) {
            return $this->forceAllow();
        }

        if ((int) $discussion->user_id !== (int) $actor->id || ! $this->open($discussion) || ! $this->inAllowedTag($discussion)) {
            return $this->deny();
        }

        return null;
    }

    public function resurfaceSchedule(User $actor, Discussion $discussion): ?string
    {
        if (! $actor->can('resurfaceBump', $discussion)) {
            return $this->deny();
        }

        if ($actor->can('resurfaceBumpAny', $discussion)) {
            return $this->forceAllow();
        }

        return null;
    }

    /** Owners cannot bump what is locked or still waiting for approval. */
    private function open(Discussion $discussion): bool
    {
        $attributes = $discussion->getAttributes();

        return empty($attributes['is_locked'])
            && (! array_key_exists('is_approved', $attributes) || $attributes['is_approved'] === null || (bool) $attributes['is_approved']);
    }

    private function inAllowedTag(Discussion $discussion): bool
    {
        $allowed = json_decode((string) $this->settings->get(Settings::TAGS), true);

        if (! is_array($allowed) || ! $allowed || ! $this->extensions->isEnabled('flarum-tags')) {
            return true;
        }

        $allowed = array_map('intval', $allowed);

        return $discussion->getAttribute('tags')->contains(fn ($tag) => in_array((int) $tag->id, $allowed, true));
    }
}
