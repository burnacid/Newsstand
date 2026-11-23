<?php

use App\Actions\Feeds\SubscribeToFeed;
use App\Jobs\FlushAlerts;
use App\Models\Alert;
use App\Models\Cve;
use App\Models\CveProduct;
use App\Models\Dashboard;
use App\Models\EntryNote;
use App\Models\Feed;
use App\Models\Subscription;
use App\Models\Team;
use App\Models\User;
use App\Services\Alerts\AlertDispatcher;
use App\Services\Reader\EntryQuery;
use App\Services\Reader\EntryStates;
use App\Services\Reader\ReaderSource;
use App\Services\Rules\RuleEvaluator;
use App\Services\Teams\FeedAccess;
use App\Services\Teams\TeamManager;
use App\Services\Watchlist\WatchlistMatcher;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

function teamStory(Feed $feed, string $title, array $attrs = [])
{
    return $feed->entries()->create([
        'guid' => $title, 'guid_hash' => sha1($feed->id.$title), 'title' => $title,
        'published_at' => now()->subHour(), ...$attrs,
    ]);
}

/** A team owned by $owner with one folder holding $feed. */
function teamWithFeed(User $owner, Feed $feed, string $name = 'SecOps'): Team
{
    $team = app(TeamManager::class)->create($owner, $name);
    $folder = $team->folders()->create(['name' => 'Intel', 'position' => 0]);
    $team->subscriptions()->create(['feed_id' => $feed->id, 'folder_id' => $folder->id]);

    return $team;
}

beforeEach(function () {
    $this->owner = User::factory()->create(['name' => 'Olivia']);
    $this->member = User::factory()->create(['name' => 'Max']);
    $this->outsider = User::factory()->create(['name' => 'Eve']);
    $this->feed = Feed::factory()->create(['title' => 'Team Intel']);
    $this->team = teamWithFeed($this->owner, $this->feed);
    app(TeamManager::class)->addMember($this->team, $this->member);
    $this->story = teamStory($this->feed, 'Fortinet patches FortiOS');
});

// --- Visibility ---

it('shows team feeds to members only, with personal read state', function () {
    $query = app(EntryQuery::class);

    expect($query->for($this->member, ReaderSource::all())->pluck('entries.id')->all())->toBe([$this->story->id])
        ->and($query->for($this->outsider, ReaderSource::all())->count())->toBe(0)
        ->and($query->find($this->outsider, $this->story->id))->toBeNull()
        ->and($query->unreadCounts($this->member)->all())->toBe([$this->feed->id => 1])
        ->and($query->unreadCounts($this->outsider)->all())->toBe([]);

    $folder = $this->team->folders()->first();
    expect($query->for($this->member, ReaderSource::folder($folder->id))->count())->toBe(1)
        ->and($query->for($this->outsider, ReaderSource::folder($folder->id))->count())->toBe(0);

    // Reading is personal.
    app(EntryStates::class)->markRead($this->member, [$this->story->id]);
    expect($query->unreadCounts($this->member)->all())->toBe([])
        ->and($query->unreadCounts($this->owner)->all())->toBe([$this->feed->id => 1]);
});

it('does not double count a feed that is both personal and in a team', function () {
    $this->member->subscriptions()->create(['feed_id' => $this->feed->id]);

    expect(app(EntryQuery::class)->unreadCounts($this->member)->all())->toBe([$this->feed->id => 1])
        ->and(FeedAccess::readersOf($this->feed->id)->get($this->member->id))->toBeNull(); // personal (unfiled) wins
});

it('loses access after leaving the team', function () {
    app(TeamManager::class)->leave($this->team, $this->member);

    expect(app(EntryQuery::class)->for($this->member, ReaderSource::all())->count())->toBe(0);
});

it('shows team folders in the sidebar', function () {
    $this->actingAs($this->member);

    Livewire::test('reader.nav')->assertSeeInOrder(['SecOps', 'Intel', 'Team Intel']);

    $this->actingAs($this->outsider);
    Livewire::test('reader.nav')->assertDontSee('SecOps');
});

// --- Roles ---

it('enforces team roles', function () {
    $teams = app(TeamManager::class);
    $admin = User::factory()->create();
    $teams->addMember($this->team, $admin, 'admin');

    // Members can't manage; admins manage members but not admins; the owner manages all.
    expect($teams->removeMember($this->team, $this->member, $admin))->toBeFalse()
        ->and($teams->changeRole($this->team, $admin, $this->member, 'admin'))->toBeFalse()
        ->and($teams->removeMember($this->team, $admin, $this->owner))->toBeFalse()
        ->and($teams->changeRole($this->team, $this->owner, $admin, 'member'))->toBeTrue()
        ->and($teams->leave($this->team, $this->owner))->toBeFalse();

    expect($teams->transferOwnership($this->team, $this->owner, $this->member))->toBeTrue()
        ->and($this->team->roleOf($this->member))->toBe('owner')
        ->and($this->team->roleOf($this->owner))->toBe('admin');
});

it('lets members join through an invite link until it expires', function () {
    $teams = app(TeamManager::class);
    $invite = $teams->invite($this->team, $this->owner);

    $this->actingAs($this->outsider)->get(route('teams.join', $invite->token))->assertOk()->assertSee('Join SecOps');
    Livewire::test('pages::teams.join', ['token' => $invite->token])->call('join')->assertRedirect(route('teams.show', $this->team));
    expect($this->team->roleOf($this->outsider))->toBe('member');

    $expired = $teams->invite($this->team, $this->owner);
    $expired->update(['expires_at' => now()->subMinute()]);
    $late = User::factory()->create();
    expect($teams->accept($expired->token, $late))->toBeNull()
        ->and($teams->accept('nope', $late))->toBeNull();
});

it('only lets the owner hand out admin invites', function () {
    $teams = app(TeamManager::class);
    $teams->changeRole($this->team, $this->owner, $this->member, 'admin');

    expect($teams->invite($this->team, $this->member, 'admin')->role)->toBe('member')
        ->and($teams->invite($this->team, $this->owner, 'admin')->role)->toBe('admin');
});

it('hides the team page from outsiders and blocks members from managing it', function () {
    $this->actingAs($this->outsider)->get(route('teams.show', $this->team))->assertNotFound();

    $this->actingAs($this->member);
    Livewire::test('pages::teams.show', ['team' => $this->team])
        ->assertSee('Team Intel')
        ->assertDontSee('New invite link')
        ->call('createInvite')->assertForbidden();

    Livewire::test('pages::teams.show', ['team' => $this->team])->call('deleteTeam')->assertForbidden();
});

it('creates teams and manages team folders from the team page', function () {
    $this->actingAs($this->outsider);

    Livewire::test('pages::teams.index')->set('name', 'Blue team')->call('create');
    $team = Team::where('name', 'Blue team')->firstOrFail();
    expect($team->roleOf($this->outsider))->toBe('owner');

    Livewire::test('pages::teams.show', ['team' => $team])
        ->set('folderName', 'News')->call('createFolder')
        ->set('memberEmail', $this->member->email)->call('addMember')
        ->assertHasNoErrors();

    expect($team->folders()->pluck('name')->all())->toBe(['News'])
        ->and($team->isMember($this->member))->toBeTrue();
});

it('adds feeds to team folders only for team admins', function () {
    $subscribe = app(SubscribeToFeed::class);
    $folder = $this->team->folders()->first();
    Queue::fake();

    expect(fn () => $subscribe->handle($this->member, 'https://example.com/feed', $folder))
        ->toThrow(AuthorizationException::class);

    $subscription = $subscribe->handle($this->owner, 'https://example.com/feed', $folder);
    expect($subscription->team_id)->toBe($this->team->id)
        ->and($subscription->user_id)->toBeNull();
});

// --- Managing shared feeds ---

it('lets team admins refresh and edit shared feeds', function () {
    Http::fake(['*' => Http::response('', 304)]);
    $this->actingAs($this->owner);
    $subscription = $this->team->subscriptions()->first();
    $other = $this->team->folders()->create(['name' => 'Vendors', 'position' => 1]);

    Livewire::test('pages::teams.show', ['team' => $this->team])
        ->call('refreshFeed', $subscription->id)
        ->assertHasNoErrors()
        ->call('editFeed', $subscription->id)
        ->assertSet('editFolderId', (string) $subscription->folder_id)
        ->set('editTitle', 'Our intel')
        ->set('editFolderId', (string) $other->id)
        ->set('editFullText', true)
        ->call('saveFeed')
        ->assertHasNoErrors()
        ->assertSee('Our intel');

    $subscription->refresh();
    expect($this->feed->fresh()->last_fetched_at)->not->toBeNull()
        ->and($subscription->custom_title)->toBe('Our intel')
        ->and($subscription->folder_id)->toBe($other->id)
        ->and($this->feed->fresh()->full_text)->toBeTrue();
});

it('keeps shared feeds in a team folder and away from members and other teams', function () {
    $subscription = $this->team->subscriptions()->first();
    $personal = $this->owner->folders()->create(['name' => 'Mine']);

    $this->actingAs($this->owner);
    Livewire::test('pages::teams.show', ['team' => $this->team])
        ->call('editFeed', $subscription->id)
        ->set('editFolderId', (string) $personal->id)
        ->call('saveFeed')
        ->assertHasErrors('editFolderId');

    // A feed of another team can't be reached through this team's page.
    $otherTeam = teamWithFeed($this->owner, Feed::factory()->create(), 'Red team');
    Livewire::test('pages::teams.show', ['team' => $this->team])
        ->call('refreshFeed', $otherTeam->subscriptions()->first()->id)
        ->assertNotFound();

    $this->actingAs($this->member);
    Livewire::test('pages::teams.show', ['team' => $this->team])
        ->assertDontSeeHtml('data-test="team-sub-menu"')
        ->call('refreshFeed', $subscription->id)
        ->assertForbidden();
    Livewire::test('pages::teams.show', ['team' => $this->team])
        ->call('editFeed', $subscription->id)
        ->assertForbidden();
});

// --- Rules, watchlist and alerts ---

it('runs members\' own rules on team feeds', function () {
    $rule = $this->member->rules()->create([
        'name' => 'Fortinet', 'match' => 'all', 'enabled' => true,
        'conditions' => [['type' => 'keyword', 'value' => 'fortinet']],
        'actions' => ['star' => true],
    ]);

    app(RuleEvaluator::class)->evaluateEntries([$this->story->id]);

    expect(app(EntryQuery::class)->collectionCounts($this->member)['starred'])->toBe(1)
        ->and($rule->fresh()->matches_count)->toBe(1);
});

it('matches the team watchlist for every member and alerts the team channels', function () {
    Queue::fake();
    $item = $this->team->watchlist()->create(['vendor' => 'Fortinet', 'product' => 'FortiOS', 'alert_level' => 'any']);
    $channel = $this->team->alertChannels()->create(['type' => 'ntfy', 'name' => 'SOC', 'config' => ['server' => 'https://ntfy.sh', 'topic' => 't'], 'events' => ['watchlist']]);
    $personal = $this->member->alertChannels()->create(['type' => 'ntfy', 'name' => 'Mine', 'config' => ['server' => 'https://ntfy.sh', 'topic' => 'm'], 'events' => ['watchlist']]);

    app(WatchlistMatcher::class)->matchEntries([$this->story->id]);

    $query = app(EntryQuery::class);
    expect($query->for($this->member, ReaderSource::parse('watchlist'))->count())->toBe(1)
        ->and($query->for($this->outsider, ReaderSource::parse('watchlist'))->count())->toBe(0)
        ->and(app(WatchlistMatcher::class)->labelsFor($this->member, [$this->story->id])->get($this->story->id)?->all())->toBe(['Fortinet FortiOS'])
        ->and(Alert::where('alert_channel_id', $channel->id)->count())->toBe(1)
        ->and(Alert::where('alert_channel_id', $personal->id)->count())->toBe(0);

    Queue::assertPushed(FlushAlerts::class);
    expect($item->fresh()->owner()?->is($this->team))->toBeTrue();
});

it('backfills a new team watchlist item over the team\'s feeds', function () {
    $item = $this->team->watchlist()->create(['vendor' => 'Fortinet']);

    expect(app(WatchlistMatcher::class)->matchItem($item))->toBe(1);
});

it('includes team watchlist CVEs on the vulnerabilities filter', function () {
    $cve = Cve::create(['cve_id' => 'CVE-2024-21762', 'in_kev' => true]);
    CveProduct::record($cve->id, [['fortinet', 'fortios']], 'kev');
    $this->team->watchlist()->create(['vendor' => 'Fortinet', 'product' => 'FortiOS']);

    expect(app(WatchlistMatcher::class)->cveIdsFor($this->member)->all())->toBe([$cve->id])
        ->and(app(WatchlistMatcher::class)->cveIdsFor($this->outsider)->all())->toBe([]);
});

it('lets team admins (not members) use team channels in rules', function () {
    $channel = $this->team->alertChannels()->create(['type' => 'ntfy', 'name' => 'SOC', 'config' => ['topic' => 't'], 'events' => []]);

    expect(AlertDispatcher::usableChannels($this->owner)->pluck('id')->all())->toBe([$channel->id])
        ->and(AlertDispatcher::usableChannels($this->member)->pluck('id')->all())->toBe([]);
});

it('keeps team watchlist and channels read-only for members', function () {
    $this->team->watchlist()->create(['vendor' => 'Fortinet']);
    $this->actingAs($this->member);

    Livewire::test('pages::watchlist.index', ['teamId' => (string) $this->team->id])
        ->assertSee('Fortinet')
        ->set('vendor', 'Ivanti')->call('save')->assertForbidden();

    // Members can't pick the team on the alerts page: it falls back to their own channels.
    Livewire::test('pages::settings.alerts')->set('teamId', (string) $this->team->id)
        ->set('type', 'ntfy')->set('name', 'Sneaky')->set('fields', ['server' => 'https://ntfy.sh', 'topic' => 'x'])->call('save');
    expect($this->team->alertChannels()->count())->toBe(0)
        ->and($this->member->alertChannels()->count())->toBe(1);
});

it('manages the team watchlist as a team admin', function () {
    $this->actingAs($this->owner);

    Livewire::test('pages::watchlist.index', ['teamId' => (string) $this->team->id])
        ->set('vendor', 'Fortinet')->call('save')->assertHasNoErrors();

    expect($this->team->watchlist()->count())->toBe(1)
        ->and($this->owner->watchlist()->count())->toBe(0);
});

// --- Notes ---

it('shares notes with the team and keeps private notes private', function () {
    $this->actingAs($this->member);

    Livewire::test('reader.notes', ['entryId' => $this->story->id])
        ->assertSet('visibility', (string) $this->team->id) // story comes through the team
        ->set('body', 'We run this on the edge firewalls.')->call('add')
        ->set('visibility', '')->set('body', 'Check my lab box')->call('add');

    $this->actingAs($this->owner);
    Livewire::test('reader.notes', ['entryId' => $this->story->id])
        ->assertSee('We run this on the edge firewalls.')
        ->assertDontSee('Check my lab box');

    expect(EntryNote::visibleTo($this->outsider)->count())->toBe(0);
});

it('only lets authors edit notes and team admins delete them', function () {
    $note = new EntryNote(['entry_id' => $this->story->id, 'team_id' => $this->team->id, 'body' => 'hello']);
    $note->user_id = $this->member->id;
    $note->save();

    $this->actingAs($this->owner);
    Livewire::test('reader.notes', ['entryId' => $this->story->id])->call('edit', $note->id)->assertNotFound();
    Livewire::test('reader.notes', ['entryId' => $this->story->id])->call('delete', $note->id);
    expect(EntryNote::count())->toBe(0);
});

it('does not let outsiders write notes on stories they cannot read', function () {
    $this->actingAs($this->outsider);

    Livewire::test('reader.notes', ['entryId' => $this->story->id])
        ->set('body', 'hi')->call('add')->assertForbidden();
});

// --- Dashboards ---

it('moves a dashboard to a team: team admins manage it, members view it', function () {
    $dashboard = $this->owner->dashboards()->create(['name' => 'Briefing']);
    $admin = User::factory()->create();
    app(TeamManager::class)->addMember($this->team, $admin, 'admin');

    expect($dashboard->roleOf($this->member))->toBeNull();

    $this->actingAs($this->owner);
    Livewire::test('pages::dashboards.show', ['dashboard' => $dashboard])->call('moveTo', (string) $this->team->id);

    $dashboard->refresh();
    expect($dashboard->team_id)->toBe($this->team->id)
        ->and($dashboard->roleOf($this->owner))->toBe('owner')
        ->and($dashboard->roleOf($admin))->toBe('owner')
        ->and($dashboard->roleOf($this->member))->toBe('viewer')
        ->and($dashboard->roleOf($this->outsider))->toBeNull();

    $this->actingAs($this->member);
    Livewire::test('pages::dashboards.index')->assertSee('Briefing');
});

it('lets a direct share make a team member an editor of a team dashboard', function () {
    $dashboard = $this->owner->dashboards()->create(['name' => 'Briefing', 'team_id' => $this->team->id]);
    $dashboard->members()->attach($this->member->id, ['role' => 'editor']);

    expect($dashboard->roleOf($this->member))->toBe('editor');
});

it('does not let team members move or manage a team dashboard', function () {
    $dashboard = $this->owner->dashboards()->create(['name' => 'Briefing', 'team_id' => $this->team->id]);

    $this->actingAs($this->member);
    Livewire::test('pages::dashboards.show', ['dashboard' => $dashboard])
        ->call('moveTo', '')
        ->assertForbidden();

    expect($dashboard->fresh()->team_id)->toBe($this->team->id);
});

it('only moves a dashboard to teams you administer', function () {
    $dashboard = $this->member->dashboards()->create(['name' => 'Mine']);

    $this->actingAs($this->member);
    Livewire::test('pages::dashboards.show', ['dashboard' => $dashboard])->call('moveTo', (string) $this->team->id);

    expect($dashboard->fresh()->team_id)->toBeNull();
});

it('makes a team dashboard personal for the admin who takes it out of the team', function () {
    $creator = User::factory()->create();
    app(TeamManager::class)->addMember($this->team, $creator, 'admin');
    $dashboard = $creator->dashboards()->create(['name' => 'Briefing', 'team_id' => $this->team->id]);

    $this->actingAs($this->owner);
    Livewire::test('pages::dashboards.show', ['dashboard' => $dashboard])->call('moveTo', '');

    $dashboard->refresh();
    expect($dashboard->team_id)->toBeNull()
        ->and($dashboard->owner_id)->toBe($this->owner->id)
        ->and($dashboard->roleOf($creator))->toBeNull();
});

it('keeps a team dashboard when its creator leaves the team or is deleted', function () {
    $creator = User::factory()->create();
    app(TeamManager::class)->addMember($this->team, $creator, 'admin');
    $dashboard = $creator->dashboards()->create(['name' => 'Team board', 'team_id' => $this->team->id]);
    $personal = $creator->dashboards()->create(['name' => 'Own board']);

    app(TeamManager::class)->leave($this->team, $creator);
    expect($dashboard->fresh()->roleOf($creator))->toBeNull()
        ->and($dashboard->fresh()->roleOf($this->member))->toBe('viewer');

    $creator->delete();

    expect($dashboard->fresh())->not->toBeNull()
        ->and($dashboard->fresh()->owner_id)->toBeNull()
        ->and($dashboard->fresh()->roleOf($this->owner))->toBe('owner')
        ->and(Dashboard::find($personal->id))->toBeNull();

    $this->actingAs($this->member)->get(route('dashboards.show', $dashboard))->assertOk()->assertSee('Team board');
});

it('fills team dashboards from the team\'s feeds, not the creator\'s', function () {
    $personalFeed = Feed::factory()->create(['title' => 'Owner private feed']);
    $this->owner->subscriptions()->create(['feed_id' => $personalFeed->id]);
    $private = teamStory($personalFeed, 'Private ransomware story');
    $teamRansomware = teamStory($this->feed, 'Team ransomware story');

    $dashboard = $this->owner->dashboards()->create(['name' => 'Team board', 'team_id' => $this->team->id]);
    $dashboard->widgets()->create(['type' => 'search', 'config' => ['query' => 'ransomware'], 'position' => 0]);
    $dashboard->widgets()->create(['type' => 'top', 'config' => [], 'position' => 1]);

    $this->actingAs($this->member);
    Livewire::test('pages::dashboards.show', ['dashboard' => $dashboard])
        ->assertSee('Team ransomware story')
        ->assertSee('Fortinet patches FortiOS')
        ->assertDontSee('Private ransomware story')
        ->call('open', $private->id)
        ->assertSet('openEntryId', null)
        ->call('open', $teamRansomware->id)
        ->assertSet('openEntryId', $teamRansomware->id);
});

it('offers no personal tag or starred widgets on team dashboards', function () {
    $dashboard = $this->owner->dashboards()->create(['name' => 'Team board', 'team_id' => $this->team->id]);
    $tag = $this->owner->tags()->create(['name' => 'private']);

    $this->actingAs($this->owner);
    Livewire::test('pages::dashboards.show', ['dashboard' => $dashboard])
        ->set('wType', 'tag')
        ->set('wTagId', (string) $tag->id)
        ->call('saveWidget')
        ->assertHasErrors('wType');

    expect($dashboard->widgets()->count())->toBe(0);
});

it('lets team admins create a team dashboard', function () {
    $this->actingAs($this->owner);

    Livewire::test('pages::dashboards.index')
        ->set('name', 'Team board')
        ->set('template', 'blank')
        ->set('teamId', (string) $this->team->id)
        ->call('create')
        ->assertHasNoErrors();

    $dashboard = Dashboard::firstWhere('name', 'Team board');
    expect($dashboard->team_id)->toBe($this->team->id)
        ->and($dashboard->roleOf($this->member))->toBe('viewer');
});

it('does not let team members or outsiders create a team dashboard', function () {
    foreach ([$this->member, $this->outsider] as $user) {
        $this->actingAs($user);

        Livewire::test('pages::dashboards.index')
            ->set('name', 'Sneaky')
            ->set('teamId', (string) $this->team->id)
            ->call('create')
            ->assertHasErrors('teamId');
    }

    expect(Dashboard::count())->toBe(0);
});

it('lists team dashboards in the sidebar under the team, for members only', function () {
    $dashboard = $this->owner->dashboards()->create(['name' => 'Morning briefing', 'team_id' => $this->team->id]);
    $this->owner->dashboards()->create(['name' => 'Private board']);

    $this->actingAs($this->member);
    Livewire::test('reader.nav')
        ->assertSeeInOrder(['SecOps', 'Morning briefing', 'Intel'])
        ->assertSee(route('dashboards.show', $dashboard))
        ->assertDontSee('Private board');

    $this->actingAs($this->outsider);
    Livewire::test('reader.nav')->assertDontSee('Morning briefing');
});

it('lists a team dashboard only under the team, not also as a personal one', function () {
    $dashboard = $this->owner->dashboards()->create(['name' => 'Morning briefing', 'team_id' => $this->team->id]);
    $dashboard->members()->attach($this->member->id, ['role' => 'editor']);

    $this->actingAs($this->member);
    $html = Livewire::test('reader.nav')->html();

    // Count links (Flux repeats the label in a tooltip for the collapsed sidebar).
    expect(substr_count($html, 'href="'.route('dashboards.show', $dashboard).'"'))->toBe(1);
});

it('shows a team in the sidebar when it only has dashboards', function () {
    $team = app(TeamManager::class)->create($this->owner, 'Red Team');
    $this->owner->dashboards()->create(['name' => 'Engagements', 'team_id' => $team->id]);

    $this->actingAs($this->owner);
    Livewire::test('reader.nav')->assertSeeInOrder(['Red Team', 'Engagements']);
});

it('removes team-owned things when the team is deleted', function () {
    $dashboard = $this->owner->dashboards()->create(['name' => 'Briefing', 'team_id' => $this->team->id]);
    $personal = $this->owner->dashboards()->create(['name' => 'Personal']);
    $this->actingAs($this->owner);

    Livewire::test('pages::teams.show', ['team' => $this->team])->call('deleteTeam')->assertRedirect(route('teams.index'));

    expect(Team::count())->toBe(0)
        ->and(Subscription::whereNotNull('team_id')->count())->toBe(0)
        ->and($dashboard->fresh())->toBeNull()
        ->and(Dashboard::pluck('id')->all())->toBe([$personal->id]);
});
