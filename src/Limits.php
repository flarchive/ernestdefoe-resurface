<?php

namespace Ernestdefoe\Resurface;

use Carbon\Carbon;
use Flarum\Group\Group;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;

/**
 * A member's bump allowance: how long a discussion must sit before it can be
 * bumped, and how many bumps fit in any 24 hours.
 *
 * Defaults apply to everyone. Groups with an override replace them, and a
 * member in several such groups gets the most generous of each value, so an
 * override can be stricter than the default (new members) or looser (VIPs).
 */
class Limits
{
    public function __construct(private SettingsRepositoryInterface $settings)
    {
    }

    /**
     * @return array{cooldown: float, daily: int} hours (0: none) and bumps per 24 hours (0: no limit)
     */
    public function for(User $user): array
    {
        // A cleared field saves "", which must not read as 0 (no limit).
        $cooldown = max(0.0, (float) $this->number(Settings::COOLDOWN));
        $daily = max(0, (int) $this->number(Settings::DAILY));

        $overrides = json_decode((string) $this->settings->get(Settings::GROUPS), true);
        if (! is_array($overrides) || ! $overrides) {
            return ['cooldown' => $cooldown, 'daily' => $daily];
        }

        $ids = $user->groups->pluck('id')->all();
        // Flarum's Members group is implicit: no row in group_user for it.
        if ($user->is_email_confirmed) {
            $ids[] = Group::MEMBER_ID;
        }

        $cooldowns = [];
        $dailies = [];
        foreach ($ids as $id) {
            $row = $overrides[(string) $id] ?? null;
            if (! is_array($row)) {
                continue;
            }
            $cooldowns[] = is_numeric($row['cooldown'] ?? null) ? max(0.0, (float) $row['cooldown']) : $cooldown;
            $dailies[] = is_numeric($row['daily'] ?? null) ? max(0, (int) $row['daily']) : $daily;
        }

        if (! $cooldowns) {
            return ['cooldown' => $cooldown, 'daily' => $daily];
        }

        return [
            'cooldown' => min($cooldowns),
            'daily' => in_array(0, $dailies, true) ? 0 : max($dailies),
        ];
    }

    /**
     * Bumps left in the current 24 hours, and when the next one frees up if
     * none are left.
     *
     * @return array{left: int|null, freeAt: Carbon|null} left is null for no limit
     */
    public function today(User $user, int $daily): array
    {
        if ($daily === 0) {
            return ['left' => null, 'freeAt' => null];
        }

        // The newest $daily bumps in the window: their count is what was used,
        // and once that is all of them the oldest decides when one frees up.
        $times = Bump::query()
            ->where('user_id', $user->id)
            ->where('created_at', '>', Carbon::now()->subDay())
            ->orderByDesc('created_at')
            ->limit($daily)
            ->pluck('created_at');

        $left = $daily - $times->count();

        return [
            'left' => $left,
            'freeAt' => $left > 0 ? null : Carbon::parse($times->last())->addDay(),
        ];
    }

    private function number(string $key): mixed
    {
        $value = $this->settings->get($key);

        return is_numeric($value) ? $value : Settings::DEFAULTS[$key];
    }
}
