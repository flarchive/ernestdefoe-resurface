<?php

namespace Ernestdefoe\Resurface\Api;

use Carbon\Carbon;
use Ernestdefoe\Resurface\Bump;
use Ernestdefoe\Resurface\Bumper;
use Ernestdefoe\Resurface\Schedule;
use Flarum\Api\Context;
use Flarum\Api\Resource\DiscussionResource;
use Flarum\Api\Schema;
use Flarum\Discussion\Discussion;
use Flarum\Foundation\ValidationException;
use Flarum\Locale\TranslatorInterface;
use WeakMap;

/**
 * Resurface's fields on discussions.
 *
 * Lists get only `resurfaceBumpedAt`, a column, so they cost nothing. The rest
 * needs a policy check and a query or two, so it is only worked out for the
 * one discussion a discussion page shows (or a bump/plan just saved).
 */
class DiscussionFields
{
    /** @var WeakMap<Discussion, array<string, mixed>> */
    private WeakMap $memo;

    public function __construct(private Bumper $bumper, private TranslatorInterface $translator)
    {
        $this->memo = new WeakMap();
    }

    /** @return array<\Tobyz\JsonApiServer\Schema\Field\Field> */
    public function __invoke(): array
    {
        // A discussion page, or a save that touched Resurface: not the read
        // tracking PATCH that fires as people scroll.
        $single = fn (Discussion $d, Context $c) => $c->showing(DiscussionResource::class)
            || ($c->updating(DiscussionResource::class) && $this->touchesResurface($c));

        return [
            Schema\DateTime::make('resurfaceBumpedAt')
                ->get(fn (Discussion $d) => $d->resurface_bumped_at),

            Schema\Boolean::make('resurfaceCanBump')
                ->visible($single)
                ->get(fn (Discussion $d, Context $c) => $this->state($d, $c)['canBump']),

            // When the actor may bump it next, if not now: the cooldown since
            // its last activity, or the daily limit.
            Schema\DateTime::make('resurfaceNextBumpAt')
                ->nullable()
                ->visible($single)
                ->get(fn (Discussion $d, Context $c) => $this->state($d, $c)['status']['nextAt'] ?? null),

            // Bumps the actor has left in the current 24 hours; null: no limit.
            Schema\Integer::make('resurfaceBumpsLeft')
                ->nullable()
                ->visible($single)
                ->get(fn (Discussion $d, Context $c) => $this->state($d, $c)['status']['left'] ?? null),

            // The cooldown in hours for the actor; the planner keeps repeats at least this far apart.
            Schema\Number::make('resurfaceCooldown')
                ->visible($single)
                ->get(fn (Discussion $d, Context $c) => $this->state($d, $c)['status']['cooldown'] ?? 0),

            Schema\Boolean::make('resurfaceCanSchedule')
                ->visible($single)
                ->get(fn (Discussion $d, Context $c) => $this->state($d, $c)['canSchedule']),

            // The log is forum-wide, so it takes the global permission.
            Schema\Boolean::make('resurfaceCanViewLog')
                ->visible($single)
                ->get(fn (Discussion $d, Context $c) => $c->getActor()->hasPermission('discussion.resurfaceBumpAny')),

            Schema\Boolean::make('resurfaceBump')
                ->hidden()
                ->writable(fn (Discussion $d, Context $c) => $c->updating() && $c->getActor()->can('resurfaceBump', $d))
                ->set(fn () => null)
                ->save(function (Discussion $d, mixed $value, Context $c) {
                    if ($value) {
                        $this->bumper->bump($c->getActor(), $d, Bump::MANUAL);
                        unset($this->memo[$d]);
                    }
                }),

            // The plan, for those who may make one: {at, every, times}, or null.
            Schema\Arr::make('resurfaceSchedule')
                ->nullable()
                ->visible(fn (Discussion $d, Context $c) => $single($d, $c) && $this->state($d, $c)['canSchedule'])
                ->get(fn (Discussion $d) => $this->plan($d))
                ->writable(fn (Discussion $d, Context $c) => $c->updating() && $c->getActor()->can('resurfaceSchedule', $d))
                ->set(fn () => null)
                ->save(function (Discussion $d, mixed $value, Context $c) {
                    $this->savePlan($d, $value, $c);
                    unset($this->memo[$d]);
                }),
        ];
    }

    private function touchesResurface(Context $c): bool
    {
        $attributes = $c->request->getParsedBody()['data']['attributes'] ?? null;

        return is_array($attributes) && (array_key_exists('resurfaceBump', $attributes) || array_key_exists('resurfaceSchedule', $attributes));
    }

    /** @return array{canBump: bool, canSchedule: bool, status: array<string, mixed>|null} */
    private function state(Discussion $d, Context $c): array
    {
        if (isset($this->memo[$d])) {
            return $this->memo[$d];
        }

        $actor = $c->getActor();
        $canBump = $actor->can('resurfaceBump', $d);

        return $this->memo[$d] = [
            'canBump' => $canBump,
            'canSchedule' => $canBump && $actor->can('resurfaceSchedule', $d),
            'status' => $canBump ? $this->bumper->status($actor, $d) : null,
        ];
    }

    /** @return array{at: string, every: int|null, times: int|null}|null */
    private function plan(Discussion $d): ?array
    {
        $schedule = Schedule::query()->where('discussion_id', $d->id)->first();

        return $schedule ? [
            'at' => $schedule->next_at->toIso8601String(),
            'every' => $schedule->interval_hours,
            'times' => $schedule->remaining,
        ] : null;
    }

    private function savePlan(Discussion $d, mixed $value, Context $c): void
    {
        if ($value === null) {
            Schedule::query()->where('discussion_id', $d->id)->delete();

            return;
        }

        $actor = $c->getActor();
        $value = is_array($value) ? $value : [];
        $errors = [];

        try {
            $at = Carbon::parse((string) ($value['at'] ?? ''))->utc()->startOfSecond();
        } catch (\Throwable) {
            $at = null;
        }
        if (! $at || empty($value['at']) || $at->lt(Carbon::now()->subMinutes(5)) || $at->gt(Carbon::now()->addYear())) {
            $errors['at'] = $this->translator->trans('ernestdefoe-resurface.api.plan_at');
        }

        $every = $value['every'] ?? null;
        if ($every !== null && (! is_int($every) || $every < 1 || $every > 8760)) {
            $errors['every'] = $this->translator->trans('ernestdefoe-resurface.api.plan_every');
            $every = null;
        }

        $times = $every === null ? null : ($value['times'] ?? null);
        if ($times !== null && (! is_int($times) || $times < 1 || $times > 999)) {
            $errors['times'] = $this->translator->trans('ernestdefoe-resurface.api.plan_times');
        }

        // Members' planned bumps keep to their own limits, so a plan that
        // would always land inside the cooldown is refused up front.
        $status = $this->bumper->status($actor, $d);
        $cooldown = (int) ceil($status['cooldown']);
        if (! $status['bypass'] && $every !== null && $every < $cooldown) {
            $errors['every'] = $this->translator->trans('ernestdefoe-resurface.api.plan_every_cooldown', ['hours' => $cooldown]);
        }
        if (! $status['bypass'] && $at && $d->last_posted_at && $status['cooldown'] > 0
            && $at->lt($d->last_posted_at->copy()->addMinutes((int) ceil($status['cooldown'] * 60)))) {
            $errors['at'] = $this->translator->trans('ernestdefoe-resurface.api.plan_at_cooldown');
        }

        if ($errors) {
            throw new ValidationException(['resurfaceSchedule' => implode(' ', array_unique($errors))]);
        }

        $schedule = Schedule::query()->where('discussion_id', $d->id)->first() ?? new Schedule();
        $schedule->discussion_id = $d->id;
        $schedule->user_id = $actor->id;
        $schedule->next_at = $at;
        $schedule->interval_hours = $every;
        $schedule->remaining = $times;
        $schedule->save();
    }
}
