<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $response = $this->get(route('dashboard'));
        $response->assertRedirect(route('login'));
    }

    public function test_the_dashboards_link_opens_the_overview_without_a_default(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $this->get(route('dashboard'))->assertRedirect(route('dashboards.index'));
    }

    public function test_guests_see_the_welcome_page(): void
    {
        $this->get(route('home'))->assertOk()->assertSee('Log in');
    }

    public function test_signed_in_users_opening_the_site_go_to_the_reader(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('home'))->assertRedirect(route('reader'));
        $this->get(route('login'))->assertRedirect(route('reader'));
        $this->get(route('reader'))->assertOk();
    }
}
