<?php

namespace Ernestdefoe\Resurface\Tests\integration\api;

use Carbon\Carbon;
use Ernestdefoe\Resurface\Settings;
use Ernestdefoe\Resurface\Tests\integration\ResurfaceTestCase;
use Flarum\Discussion\Discussion;
use Flarum\User\Guest;
use PHPUnit\Framework\Attributes\Test;

class BumpTest extends ResurfaceTestCase
{
    #[Test]
    public function owner_bumps_a_quiet_discussion_without_a_post(): void
    {
        $before = $this->row(1);

        $response = $this->bump(1, 2);
        $this->assertEquals(200, $response->getStatusCode(), (string) $response->getBody());

        $after = $this->row(1);
        $this->assertNotNull($after->resurface_bumped_at);
        $this->assertEquals($after->resurface_bumped_at, $after->last_posted_at);
        $this->assertTrue(Carbon::parse($after->last_posted_at)->gt(Carbon::now()->subMinute()));

        // Nothing about the posts changes: no unread, no "replied" by anyone new.
        $this->assertEquals($before->last_post_id, $after->last_post_id);
        $this->assertEquals($before->last_post_number, $after->last_post_number);
        $this->assertEquals($before->last_posted_user_id, $after->last_posted_user_id);
        $this->assertEquals(1, $this->database()->table('posts')->where('discussion_id', 1)->count());

        $log = $this->database()->table('resurface_bumps')->first();
        $this->assertEquals([1, 2, 'manual'], [(int) $log->discussion_id, (int) $log->user_id, $log->source]);

        $attributes = $this->json($response)['data']['attributes'];
        $this->assertNotNull($attributes['resurfaceBumpedAt']);
        $this->assertSame(0, $attributes['resurfaceBumpsLeft']);
        $this->assertNotNull($attributes['resurfaceNextBumpAt']);

        // The tags page names it as the tag's latest discussion, as a reply would.
        $this->assertEquals(1, (int) $this->database()->table('tags')->where('id', 1)->value('last_posted_discussion_id'));
    }

    #[Test]
    public function a_discussion_active_within_the_cooldown_cannot_be_bumped(): void
    {
        $this->assertEquals(422, $this->bump(2, 2)->getStatusCode());
        $this->assertNull($this->row(2)->resurface_bumped_at);
        $this->assertSame(0, $this->bumps());
    }

    #[Test]
    public function the_daily_limit_counts_bumps_across_discussions(): void
    {
        $this->assertEquals(200, $this->bump(1, 2)->getStatusCode());
        $this->assertEquals(422, $this->bump(6, 2)->getStatusCode());
        $this->assertSame(1, $this->bumps());
    }

    #[Test]
    public function bumps_older_than_a_day_no_longer_count(): void
    {
        $this->database()->table('resurface_bumps')->insert(['discussion_id' => 6, 'user_id' => 2, 'source' => 'manual', 'created_at' => Carbon::now()->subHours(25)]);

        $this->assertEquals(200, $this->bump(1, 2)->getStatusCode());
    }

    #[Test]
    public function a_group_override_wins_and_the_most_generous_group_counts(): void
    {
        $this->database()->table('groups')->insert(['id' => 5, 'name_singular' => 'Seller', 'name_plural' => 'Sellers']);
        $this->database()->table('group_user')->insert(['user_id' => 2, 'group_id' => 5]);
        // Members get stricter, sellers looser; the owner is both.
        $this->put(Settings::GROUPS, ['3' => ['daily' => 1, 'cooldown' => 48], '5' => ['daily' => 2, 'cooldown' => null]]);

        $this->assertEquals(200, $this->bump(1, 2)->getStatusCode());
        $this->assertEquals(200, $this->bump(6, 2)->getStatusCode());
        $this->assertSame(0, $this->show(1, 2)['data']['attributes']['resurfaceBumpsLeft']);
    }

    #[Test]
    public function a_stricter_override_applies_to_its_group(): void
    {
        $this->put(Settings::GROUPS, ['3' => ['cooldown' => 96]]);

        // Quiet for three days, but members now wait four.
        $this->assertEquals(422, $this->bump(1, 2)->getStatusCode());
    }

    #[Test]
    public function a_daily_limit_of_zero_means_no_limit(): void
    {
        $this->put(Settings::DAILY, '0');

        $this->assertEquals(200, $this->bump(1, 2)->getStatusCode());
        $this->assertEquals(200, $this->bump(6, 2)->getStatusCode());
        $this->assertNull($this->show(1, 2)['data']['attributes']['resurfaceBumpsLeft']);
    }

    #[Test]
    public function members_cannot_bump_someone_elses_discussion(): void
    {
        $this->assertEquals(403, $this->bump(1, 3)->getStatusCode());
        $this->assertFalse($this->show(1, 3)['data']['attributes']['resurfaceCanBump']);
    }

    #[Test]
    public function guests_cannot_bump(): void
    {
        // Over HTTP a guest is stopped by the CSRF check first; the policy
        // refuses them as well.
        $this->assertContains($this->bump(1, null)->getStatusCode(), [400, 401, 403]);
        $this->app();
        $this->assertFalse((new Guest())->can('resurfaceBump', Discussion::query()->find(1)));
        $this->assertSame(0, $this->bumps());
    }

    #[Test]
    public function without_the_permission_owners_cannot_bump(): void
    {
        $this->database()->table('group_permission')->where('permission', 'discussion.resurfaceBump')->delete();

        $this->assertEquals(403, $this->bump(1, 2)->getStatusCode());
    }

    #[Test]
    public function moderators_bump_anything_visible_without_limits(): void
    {
        $this->assertEquals(200, $this->bump(2, 4)->getStatusCode());
        $this->assertEquals(200, $this->bump(3, 4)->getStatusCode());
        $this->assertEquals(200, $this->bump(4, 4)->getStatusCode());
        // Straight away again: no cooldown for staff either.
        $this->assertEquals(200, $this->bump(3, 4)->getStatusCode());
        $this->assertSame(4, $this->bumps());

        $attributes = $this->show(3, 4)['data']['attributes'];
        $this->assertNull($attributes['resurfaceNextBumpAt']);
        $this->assertNull($attributes['resurfaceBumpsLeft']);
        $this->assertTrue($attributes['resurfaceCanViewLog']);
    }

    #[Test]
    public function owners_cannot_bump_a_locked_discussion(): void
    {
        $this->assertEquals(403, $this->bump(4, 2)->getStatusCode());
    }

    #[Test]
    public function nobody_bumps_a_hidden_discussion(): void
    {
        $this->assertEquals(403, $this->bump(5, 4)->getStatusCode());
        $this->assertEquals(403, $this->bump(5, 1)->getStatusCode());
    }

    #[Test]
    public function a_private_discussion_cannot_be_reached(): void
    {
        $this->assertEquals(404, $this->bump(7, 3)->getStatusCode());
    }

    #[Test]
    public function the_tag_list_limits_where_members_bump(): void
    {
        $this->put(Settings::TAGS, [1]);

        $this->assertEquals(403, $this->bump(6, 2)->getStatusCode());
        $this->assertEquals(200, $this->bump(1, 2)->getStatusCode());
        // Staff are not held to it.
        $this->assertEquals(200, $this->bump(6, 4)->getStatusCode());
    }

    #[Test]
    public function the_discussion_page_says_when_the_next_bump_is_possible(): void
    {
        $attributes = $this->show(1, 2)['data']['attributes'];
        $this->assertTrue($attributes['resurfaceCanBump']);
        $this->assertNull($attributes['resurfaceNextBumpAt']);
        $this->assertSame(1, $attributes['resurfaceBumpsLeft']);
        $this->assertFalse($attributes['resurfaceCanSchedule']);
        $this->assertFalse($attributes['resurfaceCanViewLog']);
        $this->assertArrayNotHasKey('resurfaceSchedule', $attributes);

        $wait = $this->show(2, 2)['data']['attributes']['resurfaceNextBumpAt'];
        $this->assertEqualsWithDelta(Carbon::now()->addHours(23)->getTimestamp(), Carbon::parse($wait)->getTimestamp(), 5);
    }

    #[Test]
    public function lists_carry_only_the_bump_time(): void
    {
        $this->bump(1, 2);

        $response = $this->send($this->request('GET', '/api/discussions', ['authenticatedAs' => 2]));
        $this->assertEquals(200, $response->getStatusCode());

        $data = $this->json($response)['data'];
        $this->assertSame('1', $data[0]['id'], 'the bumped discussion leads the list');
        foreach ($data as $discussion) {
            $this->assertArrayHasKey('resurfaceBumpedAt', $discussion['attributes']);
            $this->assertArrayNotHasKey('resurfaceCanBump', $discussion['attributes']);
            $this->assertArrayNotHasKey('resurfaceBump', $discussion['attributes']);
        }
    }

    #[Test]
    public function a_reader_who_read_it_does_not_see_it_as_unread(): void
    {
        $this->database()->table('discussion_user')->insert(['user_id' => 3, 'discussion_id' => 1, 'last_read_post_number' => 1, 'last_read_at' => $this->old]);

        $this->bump(1, 2);

        $response = $this->send($this->request('GET', '/api/discussions', ['authenticatedAs' => 3])->withQueryParams(['filter' => ['unread' => '1']]));
        $ids = array_column($this->json($response)['data'], 'id');
        $this->assertNotContains('1', $ids);
    }

    #[Test]
    public function hiding_a_later_post_keeps_the_bump(): void
    {
        $this->bump(1, 2);
        $bumped = $this->row(1)->resurface_bumped_at;

        $discussion = Discussion::query()->find(1);
        $discussion->refreshLastPost();
        $discussion->save();

        $this->assertEquals($bumped, $this->row(1)->last_posted_at);
    }

    #[Test]
    public function a_newer_reply_passes_the_bump(): void
    {
        $this->bump(1, 2);

        $response = $this->send($this->request('POST', '/api/posts', [
            'authenticatedAs' => 3,
            'json' => ['data' => ['type' => 'posts', 'attributes' => ['content' => 'Still for sale?'], 'relationships' => ['discussion' => ['data' => ['type' => 'discussions', 'id' => '1']]]]],
        ]));
        $this->assertEquals(201, $response->getStatusCode(), (string) $response->getBody());

        $row = $this->row(1);
        $this->assertGreaterThanOrEqual($row->resurface_bumped_at, $row->last_posted_at);
        $this->assertEquals(3, (int) $row->last_posted_user_id);
    }

    #[Test]
    public function a_cleared_setting_falls_back_to_the_default(): void
    {
        // An admin emptying the box must not mean "no cooldown, no limit".
        $this->put(Settings::COOLDOWN, '');
        $this->put(Settings::DAILY, '');

        $this->assertEquals(422, $this->bump(2, 2)->getStatusCode());
        $this->assertEquals(200, $this->bump(1, 2)->getStatusCode());
        $this->assertEquals(422, $this->bump(6, 2)->getStatusCode());
    }

    #[Test]
    public function read_tracking_saves_cost_resurface_nothing(): void
    {
        $queries = [];
        $this->app();
        $this->database()->listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        $response = $this->patch(1, ['lastReadPostNumber' => 1], 2);

        $this->assertEquals(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame([], array_values(array_filter($queries, fn ($sql) => str_contains($sql, 'resurface_'))));
        $this->assertArrayNotHasKey('resurfaceCanBump', $this->json($response)['data']['attributes']);
    }
}
