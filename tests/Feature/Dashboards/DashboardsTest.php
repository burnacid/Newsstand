<?php

use App\Models\Cve;
use App\Models\Dashboard;
use App\Models\Feed;
use App\Models\User;
use App\Services\Reader\EntryQuery;
use Livewire\Livewire;

function dashStory(Feed $feed, string $title, array $attrs = [])
{
    return $feed->entries()->create([
        'guid' => $title, 'guid_hash' => sha1($feed->id.$title), 'title' => $title,
        'url' => 'https://example.com/'.md5($title), 'content' => "<p>Body of {$title}</p>", 'published_at' => now()->subHour(), ...$attrs,
    ]);
}

beforeEach(function () {
    $this->owner = User::factory()->create(['name' => 'Olivia Owner', 'email' => 'owner@example.com']);
    $this->viewer = User::factory()->create(['name' => 'Vic Viewer', 'email' => 'viewer@example.com']);
    $this->editor = User::factory()->create(['name' => 'Eddie Editor', 'email' => 'editor@example.com']);
    $this->stranger = User::factory()->create();

    $this->feed = Feed::factory()->create(['title' => 'Krebs']);
    $this->folder = $this->owner->folders()->create(['name' => 'Security']);
    $this->owner->subscriptions()->create(['feed_id' => $this->feed->id, 'folder_id' => $this->folder->id]);
    $this->story = dashStory($this->feed, 'Zero-day in VPN');

    $this->dashboard = $this->owner->dashboards()->create(['name' => 'Security briefing']);
    $this->widget = $this->dashboard->widgets()->create(['type' => 'feed', 'config' => ['feed_id' => $this->feed->id], 'position' => 0]);
    $this->dashboard->members()->attach($this->viewer->id, ['role' => 'viewer']);
    $this->dashboard->members()->attach($this->editor->id, ['role' => 'editor']);
});

// --- Access ---

it('lets owners and members view, and keeps strangers out', function () {
    foreach ([$this->owner, $this->viewer, $this->editor] as $user) {
        $this->actingAs($user)->get(route('dashboards.show', $this->dashboard))->assertOk()->assertSee('Zero-day in VPN');
    }

    $this->actingAs($this->stranger)->get(route('dashboards.show', $this->dashboard))->assertForbidden();
});

it('shows the owner\'s content to viewers who do not subscribe', function () {
    expect($this->viewer->subscriptions()->count())->toBe(0);

    Livewire::actingAs($this->viewer)->test('pages::dashboards.show', ['dashboard' => $this->dashboard])
        ->assertSee('Krebs')
        ->assertSee('Zero-day in VPN')
        ->call('open', $this->story->id)
        ->assertSee('Body of Zero-day in VPN');
});

it('keeps read state per viewer', function () {
    Livewire::actingAs($this->viewer)->test('pages::dashboards.show', ['dashboard' => $this->dashboard])
        ->call('open', $this->story->id);

    expect($this->viewer->entryStates()->find($this->story->id)?->pivot->read_at)->not->toBeNull()
        ->and($this->owner->entryStates()->find($this->story->id))->toBeNull()
        ->and(app(EntryQuery::class)->unreadCounts($this->owner)[$this->feed->id])->toBe(1);
});

it('refuses to open stories that are not part of the dashboard', function () {
    $other = dashStory(Feed::factory()->create(), 'Someone else\'s feed');

    Livewire::actingAs($this->viewer)->test('pages::dashboards.show', ['dashboard' => $this->dashboard])
        ->call('open', $other->id)
        ->assertSet('openEntryId', null)
        ->assertDontSee('Body of Someone');
});

it('lets editors change widgets but not share or delete', function () {
    $component = Livewire::actingAs($this->editor)->test('pages::dashboards.show', ['dashboard' => $this->dashboard])
        ->call('toggleEditing')
        ->call('newWidget')
        ->set('wType', 'search')
        ->set('wQuery', 'vpn')
        ->call('saveWidget')
        ->assertHasNoErrors();

    expect($this->dashboard->widgets()->count())->toBe(2);

    $component->set('shareEmail', 'someone@example.com')->call('share')->assertForbidden();
    Livewire::actingAs($this->editor)->test('pages::dashboards.show', ['dashboard' => $this->dashboard])->call('deleteDashboard')->assertForbidden();
    expect(Dashboard::find($this->dashboard->id))->not->toBeNull();
});

it('keeps viewers read-only', function () {
    Livewire::actingAs($this->viewer)->test('pages::dashboards.show', ['dashboard' => $this->dashboard])
        ->call('toggleEditing')->assertForbidden();

    Livewire::actingAs($this->viewer)->test('pages::dashboards.show', ['dashboard' => $this->dashboard])
        ->call('removeWidget', $this->widget->id)->assertForbidden();

    expect($this->dashboard->widgets()->count())->toBe(1);
});

it('validates widget options against the owner\'s feeds', function () {
    $notOwners = Feed::factory()->create();

    Livewire::actingAs($this->editor)->test('pages::dashboards.show', ['dashboard' => $this->dashboard])
        ->call('newWidget')
        ->set('wType', 'feed')
        ->set('wFeedId', (string) $notOwners->id)
        ->call('saveWidget')
        ->assertHasErrors('wFeedId');
});

// --- Widgets ---

it('renders every widget type', function () {
    $tag = $this->owner->tags()->create(['name' => 'must-read']);
    $tagged = dashStory($this->feed, 'Tagged story');
    $tag->entries()->attach($tagged->id);
    $starred = dashStory($this->feed, 'Starred story');
    $this->owner->entryStates()->attach($starred->id, ['starred_at' => now()]);
    $cveStory = dashStory($this->feed, 'Citrix Bleed');
    $cveStory->cves()->attach(Cve::create(['cve_id' => 'CVE-2023-4966', 'in_kev' => true, 'cvss_score' => 9.4, 'severity' => 'critical']));

    foreach ([
        ['folder', ['folder_id' => $this->folder->id]],
        ['search', ['query' => 'zero-day']],
        ['tag', ['tag_id' => $tag->id]],
        ['starred', []],
        ['top', []],
        ['cves', ['filter' => 'kev']],
    ] as $i => [$type, $config]) {
        $this->dashboard->widgets()->create(['type' => $type, 'config' => $config, 'position' => $i + 1]);
    }

    Livewire::actingAs($this->viewer)->test('pages::dashboards.show', ['dashboard' => $this->dashboard])
        ->assertSee('Security')                  // folder title
        ->assertSee('Search: zero-day')
        ->assertSee('#must-read')
        ->assertSee('Tagged story')
        ->assertSee('Starred by Olivia Owner')
        ->assertSee('Starred story')
        ->assertSee('Top stories')
        ->assertSee('Actively exploited')
        ->assertSee('CVE-2023-4966');
});

it('lets viewers open the owner\'s tagged stories from unsubscribed feeds', function () {
    $outside = dashStory(Feed::factory()->create(), 'Curated from elsewhere');
    $tag = $this->owner->tags()->create(['name' => 'curated']);
    $tag->entries()->attach($outside->id);
    $this->dashboard->widgets()->create(['type' => 'tag', 'config' => ['tag_id' => $tag->id], 'position' => 1]);

    Livewire::actingAs($this->viewer)->test('pages::dashboards.show', ['dashboard' => $this->dashboard])
        ->call('open', $outside->id)
        ->assertSee('Body of Curated from elsewhere');
});

it('reorders widgets', function () {
    $second = $this->dashboard->widgets()->create(['type' => 'top', 'config' => [], 'position' => 1]);

    Livewire::actingAs($this->owner)->test('pages::dashboards.show', ['dashboard' => $this->dashboard])
        ->call('sortWidget', $second->id, 0);

    expect($this->dashboard->widgets()->pluck('id')->all())->toBe([$second->id, $this->widget->id]);
});

it('sizes widgets normal, wide or full width', function () {
    $component = Livewire::actingAs($this->owner)->test('pages::dashboards.show', ['dashboard' => $this->dashboard])
        ->call('editWidget', $this->widget->id)
        ->assertSet('wSize', 'normal')
        ->set('wSize', 'full')
        ->call('saveWidget')
        ->assertHasNoErrors();

    expect($this->widget->fresh()->size)->toBe('full');
    $component->assertSeeHtml('xl:col-span-3');

    $component->call('resizeWidget', $this->widget->id, 'wide');
    expect($this->widget->fresh()->size)->toBe('wide');

    // Unknown sizes are ignored.
    $component->call('resizeWidget', $this->widget->id, 'huge');
    expect($this->widget->fresh()->size)->toBe('wide');

    $component->call('editWidget', $this->widget->id)->set('wSize', 'huge')->call('saveWidget')->assertHasErrors('wSize');
});

it('does not let viewers resize widgets', function () {
    Livewire::actingAs($this->viewer)->test('pages::dashboards.show', ['dashboard' => $this->dashboard])
        ->call('resizeWidget', $this->widget->id, 'full')
        ->assertForbidden();

    expect($this->widget->fresh()->size)->toBe('normal');
});

it('re-creates the widget grid with drag and drop when editing starts', function () {
    // wire:sort only initializes on a new element, so the grid's key must change with edit mode.
    Livewire::actingAs($this->owner)->test('pages::dashboards.show', ['dashboard' => $this->dashboard])
        ->assertSeeHtml('wire:key="widgets-view"')
        ->assertDontSeeHtml('wire:sort="sortWidget"')
        ->call('toggleEditing')
        ->assertSeeHtml('wire:key="widgets-edit"')
        ->assertSeeHtml('wire:sort="sortWidget"');
});

// --- Unread only ---

it('shows only the viewer\'s unread stories when asked', function () {
    $read = dashStory($this->feed, 'Already read story');
    $this->viewer->entryStates()->attach($read->id, ['read_at' => now()]);

    Livewire::actingAs($this->viewer)->test('pages::dashboards.show', ['dashboard' => $this->dashboard])
        ->assertSee('Already read story')
        ->set('unreadOnly', true)
        ->assertDontSee('Already read story')
        ->assertSee('Zero-day in VPN');

    // Someone else's read state doesn't matter.
    Livewire::actingAs($this->owner)->test('pages::dashboards.show', ['dashboard' => $this->dashboard])
        ->set('unreadOnly', true)
        ->assertSee('Already read story');
});

it('keeps an opened story visible until you leave, and remembers the choice', function () {
    Livewire::actingAs($this->viewer)->test('pages::dashboards.show', ['dashboard' => $this->dashboard])
        ->set('unreadOnly', true)
        ->call('open', $this->story->id)
        ->assertSee('Zero-day in VPN');

    // Next visit: remembered, and the story is now read so it's gone.
    Livewire::actingAs($this->viewer)->test('pages::dashboards.show', ['dashboard' => $this->dashboard])
        ->assertSet('unreadOnly', true)
        ->assertDontSee('Zero-day in VPN')
        ->assertSee('All caught up here.');
});

it('filters top stories by unread too', function () {
    $this->dashboard->widgets()->create(['type' => 'top', 'config' => [], 'position' => 1]);
    $this->viewer->entryStates()->attach($this->story->id, ['read_at' => now()]);

    Livewire::actingAs($this->viewer)->test('pages::dashboards.show', ['dashboard' => $this->dashboard])
        ->set('unreadOnly', true)
        ->assertDontSee('Zero-day in VPN');
});

// --- Sharing ---

it('shares with users by email and manages roles', function () {
    $new = User::factory()->create(['email' => 'new@example.com', 'name' => 'Nina New']);

    Livewire::actingAs($this->owner)->test('pages::dashboards.show', ['dashboard' => $this->dashboard])
        ->set('shareEmail', 'NEW@example.com')
        ->set('shareRole', 'editor')
        ->call('share')
        ->assertHasNoErrors()
        ->assertSee('Nina New')
        ->set('shareEmail', 'nobody@example.com')
        ->call('share')
        ->assertHasErrors('shareEmail')
        ->call('changeRole', $new->id, 'viewer')
        ->call('removeMember', $this->viewer->id);

    expect($this->dashboard->roleOf($new))->toBe('viewer')
        ->and($this->dashboard->roleOf($this->viewer))->toBeNull();
});

it('lists own and shared dashboards', function () {
    Livewire::actingAs($this->viewer)->test('pages::dashboards.index')
        ->assertSee('Shared with you')
        ->assertSee('Security briefing')
        ->assertSee('by Olivia Owner');
});

it('creates a dashboard from the security template', function () {
    Livewire::actingAs($this->stranger)->test('pages::dashboards.index')
        ->set('name', 'My briefing')
        ->call('create')
        ->assertRedirect();

    $dashboard = $this->stranger->dashboards()->sole();
    expect($dashboard->widgets()->pluck('type')->all())->toBe(['top', 'cves', 'cves', 'search']);
});

it('lets members leave and owners delete', function () {
    Livewire::actingAs($this->viewer)->test('pages::dashboards.show', ['dashboard' => $this->dashboard])->call('leave');
    expect($this->dashboard->roleOf($this->viewer))->toBeNull();

    Livewire::actingAs($this->owner)->test('pages::dashboards.show', ['dashboard' => $this->dashboard])->call('deleteDashboard');
    expect(Dashboard::count())->toBe(0);
});

// --- Default dashboard ---

it('opens your default dashboard from the Dashboards link, or the overview without one', function () {
    $this->actingAs($this->owner)->get(route('dashboard'))->assertRedirect(route('dashboards.index'));

    Livewire::actingAs($this->viewer)->test('pages::dashboards.show', ['dashboard' => $this->dashboard])
        ->call('toggleDefault');

    expect($this->viewer->fresh()->default_dashboard_id)->toBe($this->dashboard->id);
    $this->actingAs($this->viewer->fresh())->get(route('dashboard'))->assertRedirect(route('dashboards.show', $this->dashboard));

    // Toggling again unsets it.
    Livewire::actingAs($this->viewer->fresh())->test('pages::dashboards.show', ['dashboard' => $this->dashboard])
        ->call('toggleDefault');
    expect($this->viewer->fresh()->default_dashboard_id)->toBeNull();
});

it('ignores a default dashboard you can no longer see', function () {
    $this->viewer->forceFill(['default_dashboard_id' => $this->dashboard->id])->save();
    $this->dashboard->members()->detach($this->viewer->id);

    expect($this->viewer->fresh()->defaultDashboard())->toBeNull();
    $this->actingAs($this->viewer->fresh())->get(route('dashboard'))->assertRedirect(route('dashboards.index'));
});

it('clears the default when the dashboard is deleted', function () {
    $this->owner->forceFill(['default_dashboard_id' => $this->dashboard->id])->save();
    $this->dashboard->delete();

    expect($this->owner->fresh()->default_dashboard_id)->toBeNull();
});

it('does not let strangers set a dashboard as their default', function () {
    Livewire::actingAs($this->stranger)->test('pages::dashboards.show', ['dashboard' => $this->dashboard])
        ->assertForbidden();

    expect($this->stranger->fresh()->default_dashboard_id)->toBeNull();
});

// --- Sidebar ---

it('lists personal and shared dashboards in the sidebar, default first', function () {
    $other = $this->owner->dashboards()->create(['name' => 'Another board']);

    $this->actingAs($this->owner);
    Livewire::test('reader.nav')->assertSeeInOrder(['Dashboards', 'Another board', 'Security briefing']);

    $this->owner->forceFill(['default_dashboard_id' => $this->dashboard->id])->save();
    Livewire::test('reader.nav')->assertSeeInOrder(['Dashboards', 'Security briefing', 'Another board']);

    // Shared directly with you.
    $this->actingAs($this->viewer);
    Livewire::test('reader.nav')->assertSee('Security briefing')->assertDontSee('Another board');

    $this->actingAs($this->stranger);
    Livewire::test('reader.nav')->assertDontSee('Security briefing');
});

// --- Public link ---

it('publishes a read-only public link', function () {
    Livewire::actingAs($this->owner)->test('pages::dashboards.show', ['dashboard' => $this->dashboard])->call('togglePublicLink');
    $token = $this->dashboard->fresh()->share_token;
    expect($token)->toHaveLength(40);

    auth()->logout();
    $this->get(route('dashboards.public', $token))
        ->assertOk()
        ->assertSee('Security briefing')
        ->assertSee('Zero-day in VPN')
        ->assertSee('https://example.com/', false)
        ->assertDontSee('Body of Zero-day in VPN')
        ->assertDontSee('owner@example.com');

    $this->get(route('dashboards.public', 'wrong-token-'.str_repeat('x', 30)))->assertNotFound();

    // Turning it off revokes the link.
    Livewire::actingAs($this->owner)->test('pages::dashboards.show', ['dashboard' => $this->dashboard])->call('togglePublicLink');
    auth()->logout();
    $this->get(route('dashboards.public', $token))->assertNotFound();
});

it('only lets owners manage the public link', function () {
    Livewire::actingAs($this->editor)->test('pages::dashboards.show', ['dashboard' => $this->dashboard])
        ->call('togglePublicLink')->assertForbidden();

    expect($this->dashboard->fresh()->share_token)->toBeNull();
});
