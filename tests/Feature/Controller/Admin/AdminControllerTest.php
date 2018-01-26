<?php namespace AGCMS\Tests\Feature\Controller\Admin;

use AGCMS\Entity\User;
use AGCMS\ORM;
use AGCMS\Tests\TestCase;

class AdminControllerTest extends TestCase
{
    public function testIndex(): void
    {
        $user = ORM::getOne(User::class, 1);
        $this->actingAs($user)->call('GET', '/admin/')
            ->assertResponseStatus(200)
            ->assertSee('<title>Administration</title>');
    }
}
