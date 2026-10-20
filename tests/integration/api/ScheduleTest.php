<?php

namespace Ernestdefoe\Resurface\Tests\integration\api;

use Carbon\Carbon;
use Ernestdefoe\Resurface\Tests\integration\ResurfaceTestCase;
use Flarum\Group\Group;
use PHPUnit\Framework\Attributes\Test;

class ScheduleTest extends ResurfaceTestCase
{
    private function allowMembers(): void
    {
        $this->database()->table('group_permission')->insert(['permission' => 'discussion.resurfaceSchedule', 'group_id' => Group::MEMBER_ID]);
    }

    private function plan(): ?object
    {
        return $this->database()->table('resurface_schedules')->where('discussion_id', 1)->first();
    }

    #[Test]
    public function members_need_the_permission_to_plan_bumps(): void
    {
        $response = $this->patch(1, ['resurfaceSchedule' => ['at' => Carbon::now()->addDay()->toIso8601String()]], 2);

        $this->assertEquals(403, $response->getStatusCode());
        $this->assertNull($this->plan());
    }

    #[Test]
    public function an_owner_plans_repeating_bumps(): void
    {
        $this->allowMembers();
        $at = Carbon::now()->addDay()->startOfSecond();

        $response = $this->patch(1, ['resurfaceSchedule' => ['at' => $at->toIso8601String(), 'every' => 48, 'times' => 3]], 2);
        $this->assertEquals(200, $response->getStatusCode(), (string) $response->getBody());

        $plan = $this->plan();
        $this->assertEquals([2, 48, 3], [(int) $plan->user_id, (int) $plan->interval_hours, (int) $plan->remaining]);
        $this->assertEquals($at->getTimestamp(), Carbon::parse($plan->next_at)->getTimestamp());

        $attributes = $this->json($response)['data']['attributes'];
        $this->assertTrue($attributes['resurfaceCanSchedule']);
        $this->assertSame(['at' => $at->toIso8601String(), 'every' => 48, 'times' => 3], $attributes['resurfaceSchedule']);
    }

    #[Test]
    public function one_off_plans_ignore_a_repeat_count(): void
    {
        $this->allowMembers();

        $this->patch(1, ['resurfaceSchedule' => ['at' => Carbon::now()->addDay()->toIso8601String(), 'every' => null, 'times' => 5]], 2);

        $this->assertNull($this->plan()->interval_hours);
        $this->assertNull($this->plan()->remaining);
    }

    #[Test]
    public function a_member_cannot_repeat_faster_than_their_cooldown(): void
    {
        $this->allowMembers();

        $response = $this->patch(1, ['resurfaceSchedule' => ['at' => Carbon::now()->addDay()->toIso8601String(), 'every' => 2]], 2);

        $this->assertEquals(422, $response->getStatusCode());
        $this->assertNull($this->plan());
    }

    #[Test]
    public function a_member_cannot_plan_a_bump_inside_the_cooldown(): void
    {
        $this->allowMembers();

        // Discussion 2 was active an hour ago; the cooldown is a day.
        $response = $this->patch(2, ['resurfaceSchedule' => ['at' => Carbon::now()->addHour()->toIso8601String()]], 2);

        $this->assertEquals(422, $response->getStatusCode());
    }

    #[Test]
    public function bad_values_are_refused(): void
    {
        $this->allowMembers();

        foreach ([
            ['at' => 'next tuesday-ish'],
            ['at' => Carbon::now()->subDay()->toIso8601String()],
            ['at' => Carbon::now()->addYears(2)->toIso8601String()],
            ['at' => Carbon::now()->addDay()->toIso8601String(), 'every' => 0],
            ['at' => Carbon::now()->addDay()->toIso8601String(), 'every' => '24'],
            ['at' => Carbon::now()->addDay()->toIso8601String(), 'every' => 24, 'times' => 0],
            ['at' => Carbon::now()->addDay()->toIso8601String(), 'every' => 24, 'times' => 5000],
            [],
        ] as $plan) {
            $this->assertEquals(422, $this->patch(1, ['resurfaceSchedule' => $plan], 2)->getStatusCode(), json_encode($plan));
        }

        $this->assertNull($this->plan());
    }

    #[Test]
    public function null_cancels_the_plan(): void
    {
        $this->allowMembers();
        $this->patch(1, ['resurfaceSchedule' => ['at' => Carbon::now()->addDay()->toIso8601String()]], 2);

        $this->assertEquals(200, $this->patch(1, ['resurfaceSchedule' => null], 2)->getStatusCode());
        $this->assertNull($this->plan());
    }

    #[Test]
    public function members_cannot_plan_someone_elses_discussion(): void
    {
        $this->allowMembers();

        $this->assertEquals(403, $this->patch(1, ['resurfaceSchedule' => ['at' => Carbon::now()->addDay()->toIso8601String()]], 3)->getStatusCode());
    }

    #[Test]
    public function staff_plan_any_discussion_at_any_pace_and_the_plan_becomes_theirs(): void
    {
        $response = $this->patch(2, ['resurfaceSchedule' => ['at' => Carbon::now()->addMinute()->toIso8601String(), 'every' => 1]], 4);

        $this->assertEquals(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertEquals(4, (int) $this->database()->table('resurface_schedules')->where('discussion_id', 2)->value('user_id'));
    }

    #[Test]
    public function others_do_not_see_the_plan(): void
    {
        $this->patch(1, ['resurfaceSchedule' => ['at' => Carbon::now()->addDay()->toIso8601String()]], 4);

        $this->assertArrayNotHasKey('resurfaceSchedule', $this->show(1, 3)['data']['attributes']);
        $this->assertNotNull($this->show(1, 4)['data']['attributes']['resurfaceSchedule']);
    }

    #[Test]
    public function errors_point_at_the_plan(): void
    {
        $this->allowMembers();

        $response = $this->patch(1, ['resurfaceSchedule' => ['at' => 'soon']], 2);

        $this->assertEquals(422, $response->getStatusCode());
        $this->assertSame('/data/attributes/resurfaceSchedule', $this->json($response)['errors'][0]['source']['pointer']);
    }
}
