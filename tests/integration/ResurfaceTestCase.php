<?php

namespace Ernestdefoe\Resurface\Tests\integration;

use Carbon\Carbon;
use Flarum\Discussion\Discussion;
use Flarum\Group\Group;
use Flarum\Post\Post;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use Psr\Http\Message\ResponseInterface;

/**
 * Users: 1 admin, 2 owner (member), 3 another member, 4 moderator.
 * Discussions (all but 2 quiet for three days):
 *  1 owner's, tag 1 · 2 owner's, active an hour ago · 3 member 3's
 *  4 owner's, locked · 5 owner's, hidden · 6 owner's, tag 2 · 7 owner's, private
 */
abstract class ResurfaceTestCase extends TestCase
{
    use RetrievesAuthorizedUsers;

    protected Carbon $old;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('flarum-tags', 'flarum-lock', 'ernestdefoe-resurface');

        $this->old = Carbon::now()->subDays(3)->startOfSecond();
        $recent = Carbon::now()->subHour()->startOfSecond();

        $discussion = fn (int $id, int $user, Carbon $at, array $extra = []) => array_merge([
            'id' => $id, 'title' => "Discussion $id", 'user_id' => $user, 'created_at' => $at, 'last_posted_at' => $at,
            'first_post_id' => $id, 'last_post_id' => $id, 'last_post_number' => 1, 'last_posted_user_id' => $user,
            'comment_count' => 1, 'participant_count' => 1, 'is_private' => 0,
        ], $extra);
        $post = fn (int $id, int $user, Carbon $at, array $extra = []) => array_merge([
            'id' => $id, 'discussion_id' => $id, 'number' => 1, 'user_id' => $user, 'created_at' => $at,
            'type' => 'comment', 'content' => '<t><p>Hello</p></t>', 'is_private' => 0,
        ], $extra);

        $this->prepareDatabase([
            User::class => [
                $this->normalUser(),
                ['id' => 3, 'username' => 'member', 'email' => 'member@machine.local', 'is_email_confirmed' => 1],
                ['id' => 4, 'username' => 'moderator', 'email' => 'moderator@machine.local', 'is_email_confirmed' => 1],
            ],
            'group_user' => [
                ['user_id' => 4, 'group_id' => Group::MODERATOR_ID],
            ],
            Discussion::class => [
                $discussion(1, 2, $this->old),
                $discussion(2, 2, $recent),
                $discussion(3, 3, $this->old),
                $discussion(4, 2, $this->old, ['is_locked' => 1]),
                $discussion(5, 2, $this->old, ['hidden_at' => $this->old, 'hidden_user_id' => 4]),
                $discussion(6, 2, $this->old),
                $discussion(7, 2, $this->old, ['is_private' => 1]),
            ],
            Post::class => [
                $post(1, 2, $this->old), $post(2, 2, $recent), $post(3, 3, $this->old), $post(4, 2, $this->old),
                $post(5, 2, $this->old), $post(6, 2, $this->old), $post(7, 2, $this->old, ['is_private' => 1]),
            ],
            'tags' => [
                ['id' => 1, 'name' => 'Market', 'slug' => 'market', 'position' => 0, 'last_posted_at' => $recent, 'last_posted_discussion_id' => 2],
                ['id' => 2, 'name' => 'Chat', 'slug' => 'chat', 'position' => 1],
            ],
            'discussion_tag' => [
                ['discussion_id' => 1, 'tag_id' => 1],
                ['discussion_id' => 2, 'tag_id' => 1],
                ['discussion_id' => 6, 'tag_id' => 2],
            ],
        ]);
    }

    protected function json(ResponseInterface $response): array
    {
        return json_decode((string) $response->getBody(), true) ?? [];
    }

    protected function patch(int $discussion, array $attributes, ?int $as): ResponseInterface
    {
        return $this->send($this->request('PATCH', "/api/discussions/$discussion", array_filter([
            'authenticatedAs' => $as,
            'json' => ['data' => ['type' => 'discussions', 'id' => (string) $discussion, 'attributes' => $attributes]],
        ])));
    }

    protected function bump(int $discussion, ?int $as): ResponseInterface
    {
        return $this->patch($discussion, ['resurfaceBump' => true], $as);
    }

    protected function show(int $discussion, int $as): array
    {
        return $this->json($this->send($this->request('GET', "/api/discussions/$discussion", ['authenticatedAs' => $as])));
    }

    /** @return object{last_posted_at: string, resurface_bumped_at: string|null, last_post_id: int, last_post_number: int, last_posted_user_id: int} */
    protected function row(int $discussion): object
    {
        return $this->database()->table('discussions')->where('id', $discussion)->first();
    }

    protected function bumps(?int $discussion = null): int
    {
        return $this->database()->table('resurface_bumps')->when($discussion, fn ($q) => $q->where('discussion_id', $discussion))->count();
    }

    /** A setting changed after the app has booted. */
    protected function put(string $key, mixed $value): void
    {
        $this->app()->getContainer()->make(\Flarum\Settings\SettingsRepositoryInterface::class)->set($key, is_string($value) ? $value : json_encode($value));
    }
}
