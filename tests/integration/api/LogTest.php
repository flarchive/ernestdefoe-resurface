<?php

namespace Ernestdefoe\Resurface\Tests\integration\api;

use Carbon\Carbon;
use Ernestdefoe\Resurface\Tests\integration\ResurfaceTestCase;
use PHPUnit\Framework\Attributes\Test;

class LogTest extends ResurfaceTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $now = Carbon::now();
        $this->prepareDatabase([
            'resurface_bumps' => [
                ['id' => 1, 'discussion_id' => 1, 'user_id' => 2, 'source' => 'manual', 'created_at' => $now->copy()->subHours(3)],
                ['id' => 2, 'discussion_id' => 6, 'user_id' => 2, 'source' => 'auto', 'created_at' => $now->copy()->subHours(2)],
                ['id' => 3, 'discussion_id' => 7, 'user_id' => 2, 'source' => 'manual', 'created_at' => $now->copy()->subHour()],
            ],
        ]);
    }

    private function log(int $as, array $query = []): \Psr\Http\Message\ResponseInterface
    {
        return $this->send($this->request('GET', '/api/resurface-bumps', ['authenticatedAs' => $as])->withQueryParams($query));
    }

    #[Test]
    public function members_cannot_read_the_log(): void
    {
        $this->assertEquals(403, $this->log(2)->getStatusCode());
    }

    #[Test]
    public function staff_read_it_newest_first_without_what_they_cannot_see(): void
    {
        $response = $this->log(4);
        $this->assertEquals(200, $response->getStatusCode(), (string) $response->getBody());

        $body = $this->json($response);
        // The private discussion's bump (3) is left out.
        $this->assertSame(['2', '1'], array_column($body['data'], 'id'));
        $this->assertSame('auto', $body['data'][0]['attributes']['source']);

        $included = array_map(fn ($r) => $r['type'].':'.$r['id'], $body['included']);
        $this->assertContains('discussions:6', $included);
        $this->assertContains('users:2', $included);
    }

    #[Test]
    public function the_log_filters_to_one_discussion(): void
    {
        $body = $this->json($this->log(4, ['filter' => ['discussion' => '1']]));

        $this->assertSame(['1'], array_column($body['data'], 'id'));
    }

    #[Test]
    public function a_full_page_costs_no_query_per_row(): void
    {
        // flarum/testing fails the request when one query shape repeats
        // past its threshold: what a missing eager load looks like here.
        $now = Carbon::now();
        for ($id = 20; $id < 32; $id++) {
            $this->database()->table('discussions')->insert(['id' => $id, 'title' => "Listing $id", 'slug' => "listing-$id", 'user_id' => 2, 'created_at' => $now, 'last_posted_at' => $now, 'comment_count' => 1, 'is_private' => 0]);
            $this->database()->table('discussion_tag')->insert(['discussion_id' => $id, 'tag_id' => 1]);
            $this->database()->table('resurface_bumps')->insert(['discussion_id' => $id, 'user_id' => 2, 'source' => 'manual', 'created_at' => $now]);
        }

        $response = $this->log(4);

        $this->assertEquals(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertCount(14, $this->json($response)['data']);
    }
}
