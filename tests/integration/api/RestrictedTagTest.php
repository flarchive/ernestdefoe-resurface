<?php

namespace Ernestdefoe\Resurface\Tests\integration\api;

use Ernestdefoe\Resurface\Tests\integration\ResurfaceTestCase;
use Flarum\Group\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * Discussion 6 sits in tag 2, restricted here. Flarum/tags then asks for the
 * tag's own permissions: tagN.discussion.resurfaceBump and friends.
 */
class RestrictedTagTest extends ResurfaceTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->prepareDatabase([
            'groups' => [['id' => 5, 'name_singular' => 'Tag keeper', 'name_plural' => 'Tag keepers']],
            'group_user' => [['user_id' => 3, 'group_id' => 5]],
            'group_permission' => [
                ['group_id' => 5, 'permission' => 'tag2.viewForum'],
                ['group_id' => 5, 'permission' => 'tag2.discussion.resurfaceBumpAny'],
                ['group_id' => Group::MEMBER_ID, 'permission' => 'tag2.viewForum'],
                ['group_id' => Group::MODERATOR_ID, 'permission' => 'tag2.discussion.resurfaceBumpAny'],
            ],
        ]);
    }

    private function restrict(): void
    {
        $this->database()->table('tags')->where('id', 2)->update(['is_restricted' => 1]);
    }

    #[Test]
    public function the_tags_bump_any_permission_alone_lets_staff_bump_there(): void
    {
        $this->restrict();

        $this->assertEquals(200, $this->bump(6, 3)->getStatusCode());
    }

    #[Test]
    public function moderators_with_the_tags_bump_any_bump_there(): void
    {
        $this->restrict();

        $this->assertEquals(200, $this->bump(6, 4)->getStatusCode());
    }

    #[Test]
    public function owners_need_the_tags_own_bump_permission(): void
    {
        $this->restrict();

        $this->assertEquals(403, $this->bump(6, 2)->getStatusCode());
    }

    #[Test]
    public function owners_with_the_tags_own_bump_permission_bump_there(): void
    {
        $this->prepareDatabase(['group_permission' => [['group_id' => Group::MEMBER_ID, 'permission' => 'tag2.discussion.resurfaceBump']]]);
        $this->restrict();

        $this->assertEquals(200, $this->bump(6, 2)->getStatusCode());
    }

    #[Test]
    public function the_log_button_shows_only_to_those_who_can_open_the_log(): void
    {
        $this->restrict();

        // Tag-only staff: can bump there, but the log is forum-wide.
        $this->assertTrue($this->show(6, 3)['data']['attributes']['resurfaceCanBump']);
        $this->assertFalse($this->show(6, 3)['data']['attributes']['resurfaceCanViewLog']);
        $this->assertTrue($this->show(6, 4)['data']['attributes']['resurfaceCanViewLog']);
    }
}
