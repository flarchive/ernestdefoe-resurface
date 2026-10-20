<?php

namespace Ernestdefoe\Resurface\Console;

use Carbon\Carbon;
use Ernestdefoe\Resurface\Bump;
use Ernestdefoe\Resurface\Bumper;
use Ernestdefoe\Resurface\Schedule;
use Ernestdefoe\Resurface\Settings;
use Flarum\Discussion\Discussion;
use Flarum\Foundation\ValidationException;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\Exception\PermissionDeniedException;
use Illuminate\Console\Command;
use Psr\Log\LoggerInterface;

/**
 * Makes the planned bumps that are due, then trims the log. Runs every minute
 * from Flarum's scheduler; each run handles at most BATCH plans, oldest due
 * first, so a backlog drains over a few runs instead of in one long one.
 */
class RunSchedules extends Command
{
    public const BATCH = 100;

    /** Rows deleted per statement when trimming the log, and statements per run. */
    private const PRUNE_CHUNK = 1000;
    private const PRUNE_ROUNDS = 5;

    protected $signature = 'resurface:run';

    protected $description = 'Make the scheduled and automatic bumps that are due, and trim the bump log.';

    public function handle(Bumper $bumper, SettingsRepositoryInterface $settings, LoggerInterface $log): int
    {
        $due = Schedule::query()
            ->with(['discussion', 'user'])
            ->where('next_at', '<=', Carbon::now())
            ->orderBy('next_at')
            ->limit(self::BATCH)
            ->get();

        $made = 0;

        foreach ($due as $schedule) {
            $made += (int) $this->runPlan($schedule, $bumper, $log);
        }

        $this->prune($settings);

        $this->info("Made $made of {$due->count()} due bumps.");

        return self::SUCCESS;
    }

    private function runPlan(Schedule $schedule, Bumper $bumper, LoggerInterface $log): bool
    {
        $discussion = $schedule->discussion;
        $user = $schedule->user;

        // Its maker must still be able to see it (it may have gone private,
        // or into a tag they cannot see), or the plan ends.
        if (! $discussion || ! $user || ! Discussion::whereVisibleTo($user)->whereKey($discussion->id)->exists()) {
            $schedule->delete();

            return false;
        }

        // The plan needs its maker to still be allowed to plan here (the
        // permission can be taken away, or they can leave the group).
        if (! $user->can('resurfaceSchedule', $discussion)) {
            $schedule->delete();

            return false;
        }

        $at = null;

        try {
            $at = $bumper->bump($user, $discussion, $schedule->interval_hours ? Bump::AUTO : Bump::SCHEDULED)->created_at;
        } catch (PermissionDeniedException) {
            // Its maker can no longer bump it (left the group, discussion
            // locked or hidden, tag no longer allowed): the plan ends.
            $schedule->delete();

            return false;
        } catch (ValidationException) {
            // Too soon or over the daily limit this time: this run is skipped.
        } catch (\Throwable $e) {
            // Never let one plan stop the rest, or sit at the head of the
            // queue failing every minute.
            $log->error("[resurface] Planned bump of discussion $discussion->id failed: {$e->getMessage()}");
        }

        if (! $schedule->interval_hours || ($at && $schedule->remaining === 1)) {
            $schedule->delete();

            return (bool) $at;
        }

        if ($at && $schedule->remaining !== null) {
            $schedule->remaining--;
        }

        // From the bump itself, so the next one is a full interval later and
        // clears a cooldown of the same length. A run that was skipped (or a
        // scheduler that was down) waits a full interval from now: missed
        // bumps are not made up in a burst.
        $schedule->next_at = ($at ?? Carbon::now()->startOfSecond())->copy()->addHours($schedule->interval_hours);
        $schedule->save();

        return (bool) $at;
    }

    private function prune(SettingsRepositoryInterface $settings): void
    {
        // A day at least: the daily limit counts the last 24 hours of the log.
        $days = (int) $settings->get(Settings::LOG_DAYS);
        if ($days <= 0) {
            return;
        }

        $before = Carbon::now()->subDays(max(1, $days));

        for ($i = 0; $i < self::PRUNE_ROUNDS; $i++) {
            $ids = Bump::query()->where('created_at', '<', $before)->orderBy('id')->limit(self::PRUNE_CHUNK)->pluck('id');
            if ($ids->isEmpty()) {
                return;
            }

            Bump::query()->whereIn('id', $ids)->delete();

            if ($ids->count() < self::PRUNE_CHUNK) {
                return;
            }
        }
    }
}
