<?php

namespace Ernestdefoe\Resurface\Tests\integration\console;

use Carbon\Carbon;
use Ernestdefoe\Resurface\Settings;
use Ernestdefoe\Resurface\Tests\integration\ResurfaceTestCase;
use Flarum\Group\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class RunSchedulesTest extends ResurfaceTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Members may plan bumps here; one test takes it away.
        $this->prepareDatabase(['group_permission' => [['permission' => 'discussion.resurfaceSchedule', 'group_id' => Group::MEMBER_ID]]]);
    }

    private function runResurface(): string
    {
        $this->app();
        $console = new Application('Flarum');
        $console->setAutoExit(false);
        $console->addCommands([$this->app()->getContainer()->make(\Ernestdefoe\Resurface\Console\RunSchedules::class)]);
        foreach ($console->all() as $command) {
            if ($command instanceof \Illuminate\Console\Command) {
                $command->setLaravel($this->app()->getContainer());
            }
        }
        $output = new BufferedOutput();
        $console->run(new ArrayInput(['command' => 'resurface:run']), $output);

        return $output->fetch();
    }

    private function plan(int $discussion, int $user, Carbon $at, ?int $every = null, ?int $times = null): void
    {
        $this->database()->table('resurface_schedules')->insert([
            'discussion_id' => $discussion, 'user_id' => $user, 'next_at' => $at,
            'interval_hours' => $every, 'remaining' => $times, 'created_at' => Carbon::now(), 'updated_at' => Carbon::now(),
        ]);
    }

    private function planRow(int $discussion): ?object
    {
        return $this->database()->table('resurface_schedules')->where('discussion_id', $discussion)->first();
    }

    #[Test]
    public function a_due_one_off_bump_is_made_and_the_plan_ends(): void
    {
        $this->plan(1, 2, Carbon::now()->subMinute());

        $this->assertStringContainsString('Made 1 of 1', $this->runResurface());

        $this->assertNotNull($this->row(1)->resurface_bumped_at);
        $this->assertSame('scheduled', $this->database()->table('resurface_bumps')->value('source'));
        $this->assertNull($this->planRow(1));
    }

    #[Test]
    public function plans_not_yet_due_wait(): void
    {
        $this->plan(1, 2, Carbon::now()->addHour());

        $this->runResurface();

        $this->assertNull($this->row(1)->resurface_bumped_at);
        $this->assertNotNull($this->planRow(1));
    }

    #[Test]
    public function a_repeating_plan_counts_down_from_the_bump_itself(): void
    {
        $this->plan(1, 2, Carbon::now()->subHours(5), 24, 2);

        $this->runResurface();

        $plan = $this->planRow(1);
        $this->assertSame(1, (int) $plan->remaining);
        $this->assertEquals(
            Carbon::parse($this->row(1)->resurface_bumped_at)->addHours(24)->getTimestamp(),
            Carbon::parse($plan->next_at)->getTimestamp()
        );
        $this->assertSame('auto', $this->database()->table('resurface_bumps')->value('source'));
    }

    #[Test]
    public function the_last_repeat_ends_the_plan(): void
    {
        $this->plan(1, 2, Carbon::now()->subMinute(), 24, 1);

        $this->runResurface();

        $this->assertNull($this->planRow(1));
        $this->assertSame(1, $this->bumps(1));
    }

    #[Test]
    public function an_open_ended_plan_keeps_going(): void
    {
        $this->plan(1, 2, Carbon::now()->subMinute(), 24);

        $this->runResurface();

        $this->assertNull($this->planRow(1)->remaining);
        $this->assertSame(1, $this->bumps(1));
    }

    #[Test]
    public function over_the_daily_limit_a_run_is_skipped_not_counted(): void
    {
        $this->database()->table('resurface_bumps')->insert(['discussion_id' => 6, 'user_id' => 2, 'source' => 'manual', 'created_at' => Carbon::now()->subHour()]);
        $this->plan(1, 2, Carbon::now()->subMinute(), 24, 2);

        $this->runResurface();

        $plan = $this->planRow(1);
        $this->assertNull($this->row(1)->resurface_bumped_at);
        $this->assertSame(2, (int) $plan->remaining);
        $this->assertTrue(Carbon::parse($plan->next_at)->gt(Carbon::now()->addHours(23)));
    }

    #[Test]
    public function a_skipped_one_off_plan_ends(): void
    {
        $this->plan(2, 2, Carbon::now()->subMinute());

        $this->runResurface();

        $this->assertNull($this->row(2)->resurface_bumped_at);
        $this->assertNull($this->planRow(2));
    }

    #[Test]
    public function a_plan_whose_maker_lost_the_right_ends(): void
    {
        $this->plan(4, 2, Carbon::now()->subMinute(), 24); // locked
        $this->plan(7, 2, Carbon::now()->subMinute(), 24); // private: not visible to its maker any more
        $this->plan(3, 2, Carbon::now()->subMinute(), 24); // someone else's

        $this->runResurface();

        $this->assertSame(0, $this->bumps());
        $this->assertSame(0, $this->database()->table('resurface_schedules')->count());
    }

    #[Test]
    public function staff_plans_run_without_limits(): void
    {
        $this->plan(2, 4, Carbon::now()->subMinute(), 1);

        $this->runResurface();

        $this->assertNotNull($this->row(2)->resurface_bumped_at);
    }

    #[Test]
    public function several_due_plans_run_in_one_go(): void
    {
        $this->database()->table('discussions')->where('id', '<=', 7)->update(['is_locked' => 0, 'hidden_at' => null, 'is_private' => 0]);
        $this->database()->table('posts')->update(['is_private' => 0]);
        $this->put(Settings::DAILY, '0');
        foreach ([1, 3, 4, 5, 6, 7] as $i => $discussion) {
            $this->plan($discussion, 4, Carbon::now()->subMinutes(10 - $i));
        }

        $this->assertStringContainsString('of 6 due', $this->runResurface());
        $this->assertSame(0, $this->database()->table('resurface_schedules')->count());
    }

    #[Test]
    public function the_log_is_trimmed_to_its_retention(): void
    {
        $this->put(Settings::LOG_DAYS, '30');
        $this->database()->table('resurface_bumps')->insert([
            ['discussion_id' => 1, 'user_id' => 2, 'source' => 'manual', 'created_at' => Carbon::now()->subDays(31)],
            ['discussion_id' => 1, 'user_id' => 2, 'source' => 'manual', 'created_at' => Carbon::now()->subDays(29)],
        ]);

        $this->runResurface();

        $this->assertSame(1, $this->bumps());
    }

    #[Test]
    public function zero_keeps_the_log(): void
    {
        $this->put(Settings::LOG_DAYS, '0');
        $this->database()->table('resurface_bumps')->insert(['discussion_id' => 1, 'user_id' => 2, 'source' => 'manual', 'created_at' => Carbon::now()->subYears(3)]);

        $this->runResurface();

        $this->assertSame(1, $this->bumps());
    }

    #[Test]
    public function a_plan_ends_when_its_maker_may_no_longer_plan(): void
    {
        $this->database()->table('group_permission')->where('permission', 'discussion.resurfaceSchedule')->delete();
        $this->plan(1, 2, Carbon::now()->subMinute(), 24);

        $this->runResurface();

        $this->assertSame(0, $this->bumps());
        $this->assertNull($this->planRow(1));
    }
}
