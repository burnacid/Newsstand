<?php

use App\Actions\Reader\TagEntries;
use App\Models\Feed;
use App\Models\Tag;
use App\Models\User;
use App\Services\Reader\EntryQuery;
use App\Services\Reader\ReaderSource;
use App\Services\Teams\TeamManager;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Livewire;

beforeEach(function () {
    $this->owner = User::factory()->create(['name' => 'Olivia']);
    $this->member = User::factory()->create(['name' => 'Max']);
    $this->outsider = User::factory()->create(['name' => 'Eve']);

    $teams = app(TeamManager::class);
    $this->team = $teams->create($this->owner, 'SecOps');
    $teams->addMember($this->team, $this->member);

    $this->feed = Feed::factory()->create(['title' => 'Team Intel']);
    $folder = $this->team->folders()->create(['name' => 'Intel', 'position' => 0]);
    $this->team->subscriptions()->create(['feed_id' => $this->feed->id, 'folder_id' => $folder->id]);
    $this->story = $this->feed->entries()->create([
        'guid' => 'fortios', 'guid_hash' => sha1('fortios'), 'title' => 'Fortinet patches FortiOS', 'published_at' => now()->subHour(),
    ]);

    $this->tag = app(TagEntries::class)->createForTeam($this->team, 'needs-patching');
});

// --- Managing ---

it('lets team admins create, rename, recolor and delete team tags', function () {
    $this->actingAs($this->owner);

    $page = Livewire::test('pages::teams.show', ['team' => $this->team])
        ->set('newTag', 'Phishing')
        ->call('createTag')
        ->assertHasNoErrors()
        ->assertSee('#Phishing');

    $tag = $this->team->tags()->firstWhere('name', 'Phishing');
    expect($tag->user_id)->toBeNull()->and($tag->team_id)->toBe($this->team->id);

    $page->call('renameTag', $tag->id, 'phishing-wave')->call('recolorTag', $tag->id, 'red');
    expect($tag->fresh()->only('name', 'color'))->toBe(['name' => 'phishing-wave', 'color' => 'red']);

    $page->call('deleteTag', $tag->id);
    expect(Tag::find($tag->id))->toBeNull();
});

it('does not let team members manage team tags', function () {
    $this->actingAs($this->member);

    Livewire::test('pages::teams.show', ['team' => $this->team])
        ->assertSee('#needs-patching')
        ->set('newTag', 'Mine')
        ->call('createTag')
        ->assertForbidden();

    Livewire::test('pages::teams.show', ['team' => $this->team])->call('deleteTag', $this->tag->id)->assertForbidden();

    expect($this->team->tags()->count())->toBe(1);
});

it('reuses a team tag with the same name', function () {
    $again = app(TagEntries::class)->createForTeam($this->team, 'NEEDS-PATCHING');

    expect($again->id)->toBe($this->tag->id);
});

// --- Using ---

it('lets any member tag a story with a team tag, shared with the whole team', function () {
    app(TagEntries::class)->toggle($this->member, $this->story, $this->tag->id);

    $query = app(EntryQuery::class);

    // The owner sees the member's tag on the story and in the tag listing.
    expect($query->tagsFor($this->owner, [$this->story->id])->get($this->story->id)->pluck('name')->all())->toBe(['needs-patching'])
        ->and($query->for($this->owner, ReaderSource::tag($this->tag->id))->pluck('entries.id')->all())->toBe([$this->story->id]);

    // And can take it off again.
    expect(app(TagEntries::class)->toggle($this->owner, $this->story, $this->tag->id))->toBeFalse()
        ->and($this->tag->entries()->count())->toBe(0);
});

it('keeps team tags away from outsiders and former members', function () {
    app(TagEntries::class)->toggle($this->member, $this->story, $this->tag->id);

    expect(fn () => app(TagEntries::class)->toggle($this->outsider, $this->story, $this->tag->id))->toThrow(ModelNotFoundException::class)
        ->and(app(EntryQuery::class)->tagsFor($this->outsider, [$this->story->id])->all())->toBe([]);

    app(TeamManager::class)->leave($this->team, $this->member);

    expect(app(EntryQuery::class)->for($this->member, ReaderSource::tag($this->tag->id))->count())->toBe(0);
});

it('offers personal tags first, then team tags, in the reader tag menu', function () {
    $this->member->tags()->create(['name' => 'zzz-mine']);
    $this->actingAs($this->member);

    Livewire::test('pages::reader.index')
        ->call('select', $this->story->id)
        ->call('toggleTag', $this->tag->id, $this->story->id)
        ->assertSeeInOrder(['zzz-mine', 'SecOps', 'needs-patching']);

    expect($this->tag->entries()->pluck('entries.id')->all())->toBe([$this->story->id]);
});

it('opens a team tag in the reader for members', function () {
    app(TagEntries::class)->toggle($this->owner, $this->story, $this->tag->id);
    $this->actingAs($this->member);

    Livewire::withQueryParams(['s' => 'tag:'.$this->tag->id])->test('pages::reader.index')
        ->assertSee('#needs-patching')
        ->assertSee('Fortinet patches FortiOS');
});

it('keeps team tags out of personal tag management', function () {
    $this->actingAs($this->owner);

    expect($this->owner->tags()->count())->toBe(0);
    Livewire::test('pages::feeds.index')->assertDontSee('needs-patching');
});

it('lists team tags in the sidebar under the team', function () {
    app(TagEntries::class)->toggle($this->owner, $this->story, $this->tag->id);
    $this->actingAs($this->member);

    Livewire::test('reader.nav')->assertSeeInOrder(['SecOps', 'Intel', 'needs-patching']);

    $this->actingAs($this->outsider);
    Livewire::test('reader.nav')->assertDontSee('needs-patching');
});

it('deletes team tags with the team', function () {
    app(TagEntries::class)->toggle($this->owner, $this->story, $this->tag->id);
    $this->team->delete();

    expect(Tag::count())->toBe(0);
});

// --- Team dashboards ---

it('shows stories with a team tag on a team dashboard', function () {
    $elsewhere = Feed::factory()->create();
    $curated = $elsewhere->entries()->create([
        'guid' => 'curated', 'guid_hash' => sha1('curated'), 'title' => 'Curated from a personal feed', 'published_at' => now()->subHour(),
    ]);
    app(TagEntries::class)->toggle($this->member, $curated, $this->tag->id);

    $dashboard = $this->owner->dashboards()->create(['name' => 'Team board', 'team_id' => $this->team->id]);

    $this->actingAs($this->owner);
    Livewire::test('pages::dashboards.show', ['dashboard' => $dashboard])
        ->set('wType', 'tag')
        ->set('wTagId', (string) $this->tag->id)
        ->call('saveWidget')
        ->assertHasNoErrors()
        ->assertSee('#needs-patching')
        ->assertSee('Curated from a personal feed');

    // Members can open it even though it isn't from a team feed.
    $this->actingAs($this->member);
    Livewire::test('pages::dashboards.show', ['dashboard' => $dashboard])
        ->call('open', $curated->id)
        ->assertSet('openEntryId', $curated->id);
});

it('does not show another team\'s tag on a team dashboard', function () {
    $other = app(TeamManager::class)->create($this->owner, 'Red Team');
    $foreign = app(TagEntries::class)->createForTeam($other, 'red-only');
    app(TagEntries::class)->toggle($this->owner, $this->story, $foreign->id);

    $dashboard = $this->owner->dashboards()->create(['name' => 'Team board', 'team_id' => $this->team->id]);

    $this->actingAs($this->owner);
    Livewire::test('pages::dashboards.show', ['dashboard' => $dashboard])
        ->set('wType', 'tag')
        ->set('wTagId', (string) $foreign->id)
        ->call('saveWidget')
        ->assertHasErrors('wTagId');
});
