<?php

use App\Actions\Reader\TagEntries;
use App\Models\Alert;
use App\Models\Feed;
use App\Models\Rule;
use App\Models\User;
use App\Services\Rules\RuleEvaluator;
use App\Services\Teams\TeamManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

function ruleStory(Feed $feed, string $title)
{
    return $feed->entries()->create([
        'guid' => $title, 'guid_hash' => sha1($feed->id.$title), 'title' => $title,
        'url' => 'https://news.example.com/'.md5($title), 'summary' => "Summary of {$title}", 'published_at' => now()->subHour(),
    ]);
}

function highlightOf(User $user, int $entryId): ?string
{
    return DB::table('entry_user')->where('user_id', $user->id)->where('entry_id', $entryId)->value('highlight');
}

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->admin = User::factory()->create();
    $this->member = User::factory()->create();
    $this->outsider = User::factory()->create();

    $teams = app(TeamManager::class);
    $this->team = $teams->create($this->owner, 'SecOps');
    $teams->addMember($this->team, $this->admin, 'admin');
    $teams->addMember($this->team, $this->member);

    $this->teamFeed = Feed::factory()->create(['title' => 'Team Intel']);
    $this->folder = $this->team->folders()->create(['name' => 'Intel', 'position' => 0]);
    $this->team->subscriptions()->create(['feed_id' => $this->teamFeed->id, 'folder_id' => $this->folder->id]);

    $this->tag = app(TagEntries::class)->createForTeam($this->team, 'ransomware');
});

function teamRule($test, array $actions, array $conditions = [['type' => 'keyword', 'value' => 'ransomware']]): Rule
{
    return $test->team->rules()->create(['name' => 'Ransomware', 'match' => 'all', 'conditions' => $conditions, 'actions' => $actions]);
}

// --- Running ---

it('tags team stories with a team tag and highlights them for every member', function () {
    teamRule($this, ['tag_id' => $this->tag->id, 'highlight' => 'red']);
    $story = ruleStory($this->teamFeed, 'New ransomware gang');

    expect(app(RuleEvaluator::class)->evaluateEntries([$story->id]))->toBe(1);

    expect($this->tag->entries()->pluck('entries.id')->all())->toBe([$story->id])
        ->and(highlightOf($this->owner, $story->id))->toBe('red')
        ->and(highlightOf($this->member, $story->id))->toBe('red')
        ->and(highlightOf($this->outsider, $story->id))->toBeNull();

    // Once per story: a member who removes the highlight keeps it removed.
    DB::table('entry_user')->where('user_id', $this->member->id)->update(['highlight' => null]);
    app(RuleEvaluator::class)->evaluateEntries([$story->id]);
    expect(highlightOf($this->member, $story->id))->toBeNull();
});

it('only runs team rules on the team\'s shared feeds', function () {
    teamRule($this, ['tag_id' => $this->tag->id]);
    $personalFeed = Feed::factory()->create();
    $this->member->subscriptions()->create(['feed_id' => $personalFeed->id]);
    $story = ruleStory($personalFeed, 'Personal ransomware news');

    app(RuleEvaluator::class)->evaluateEntries([$story->id]);

    expect($this->tag->entries()->count())->toBe(0);
});

it('never stars, saves, reads or mutes for the whole team', function () {
    teamRule($this, ['star' => true, 'read' => true, 'mute' => true, 'highlight' => 'amber']);
    $story = ruleStory($this->teamFeed, 'Ransomware everywhere');

    app(RuleEvaluator::class)->evaluateEntries([$story->id]);

    $state = DB::table('entry_user')->where('user_id', $this->member->id)->where('entry_id', $story->id)->first();
    expect($state->highlight)->toBe('amber')
        ->and($state->starred_at)->toBeNull()
        ->and($state->read_at)->toBeNull()
        ->and($state->muted_at)->toBeNull();
});

it('uses the team watchlist and team folders in conditions', function () {
    $rule = teamRule($this, ['tag_id' => $this->tag->id], [['type' => 'folder', 'value' => $this->folder->id]]);
    $story = ruleStory($this->teamFeed, 'Anything from the team folder');

    expect(app(RuleEvaluator::class)->matches($rule, $story, $this->folder->id))->toBeTrue();
    app(RuleEvaluator::class)->evaluateEntries([$story->id]);
    expect($this->tag->entries()->count())->toBe(1);
});

it('back-fills a new team rule over the team\'s recent stories without alerting', function () {
    Queue::fake();
    $old = ruleStory($this->teamFeed, 'Last week ransomware');
    $channel = $this->team->alertChannels()->create(['type' => 'ntfy', 'name' => 'Team ntfy', 'config' => ['server' => 'https://ntfy.example.com', 'topic' => 't'], 'events' => []]);
    $rule = teamRule($this, ['tag_id' => $this->tag->id, 'alert' => [$channel->id]]);

    expect(app(RuleEvaluator::class)->applyToRecent($rule))->toBe(1)
        ->and($this->tag->entries()->pluck('entries.id')->all())->toBe([$old->id])
        ->and(Alert::count())->toBe(0);
});

it('alerts only the team\'s own channels from a team rule', function () {
    Queue::fake();
    $team = $this->team->alertChannels()->create(['type' => 'ntfy', 'name' => 'Team ntfy', 'config' => ['server' => 'https://ntfy.example.com', 'topic' => 't'], 'events' => []]);
    $personal = $this->owner->alertChannels()->create(['type' => 'ntfy', 'name' => 'Mine', 'config' => ['server' => 'https://ntfy.example.com', 'topic' => 'm'], 'events' => []]);
    teamRule($this, ['alert' => [$team->id, $personal->id]]);

    $story = ruleStory($this->teamFeed, 'Live ransomware outbreak');
    app(RuleEvaluator::class)->evaluateEntries([$story->id]);

    expect(Alert::pluck('alert_channel_id')->all())->toBe([$team->id])
        ->and(Alert::sole()->payload['context'])->toContain('Rule: Ransomware');
});

it('lets personal rules add a team tag', function () {
    $this->member->rules()->create([
        'name' => 'Mine', 'match' => 'all', 'conditions' => [['type' => 'keyword', 'value' => 'ransomware']], 'actions' => ['tag_id' => $this->tag->id],
    ]);
    $story = ruleStory($this->teamFeed, 'Ransomware hits hospital');

    app(RuleEvaluator::class)->evaluateEntries([$story->id]);

    expect($this->tag->entries()->pluck('entries.id')->all())->toBe([$story->id]);
});

it('ignores a team tag in a personal rule once the user leaves the team', function () {
    $this->member->rules()->create([
        'name' => 'Mine', 'match' => 'all', 'conditions' => [['type' => 'keyword', 'value' => 'ransomware']], 'actions' => ['tag_id' => $this->tag->id],
    ]);
    $personalFeed = Feed::factory()->create();
    $this->member->subscriptions()->create(['feed_id' => $personalFeed->id]);
    app(TeamManager::class)->leave($this->team, $this->member);

    $story = ruleStory($personalFeed, 'Ransomware after leaving');
    app(RuleEvaluator::class)->evaluateEntries([$story->id]);

    expect($this->tag->entries()->count())->toBe(0);
});

it('deletes team rules with the team', function () {
    teamRule($this, ['tag_id' => $this->tag->id]);
    $this->team->delete();

    expect(Rule::count())->toBe(0);
});

// --- Rules page ---

it('lets team admins manage team rules from the rules page, keeping only team actions', function () {
    $this->actingAs($this->admin);

    Livewire::withQueryParams(['team' => (string) $this->team->id])->test('pages::rules.index')
        ->assertSee('run on the team’s shared feeds', false)
        ->call('create')
        ->set('name', 'Team ransomware')
        ->set('conditions', [['type' => 'keyword', 'value' => 'ransomware']])
        ->set('tagId', (string) $this->tag->id)
        ->set('highlight', 'red')
        ->set('star', true)
        ->call('save')
        ->assertHasNoErrors()
        ->assertSee('Team ransomware');

    $rule = Rule::sole();
    expect($rule->team_id)->toBe($this->team->id)
        ->and($rule->user_id)->toBeNull()
        ->and($rule->actions)->toBe(['highlight' => 'red', 'tag_id' => $this->tag->id]);

    // Their own rules are separate.
    Livewire::withQueryParams(['team' => ''])->test('pages::rules.index')->assertDontSee('Team ransomware');
});

it('adds a security preset as a team rule without starring', function () {
    $this->actingAs($this->owner);

    Livewire::withQueryParams(['team' => (string) $this->team->id])->test('pages::rules.index')->call('useTemplate', 'exploited');

    expect(Rule::sole()->only('team_id', 'actions'))->toBe(['team_id' => $this->team->id, 'actions' => ['highlight' => 'red']]);
});

it('does not let team members manage team rules', function () {
    $rule = teamRule($this, ['tag_id' => $this->tag->id]);
    $this->actingAs($this->member);

    // The team isn't one they manage: they see and edit their own rules instead.
    Livewire::withQueryParams(['team' => (string) $this->team->id])->test('pages::rules.index')
        ->assertDontSee('Ransomware')
        ->call('delete', $rule->id)
        ->assertNotFound();

    expect(Rule::find($rule->id))->not->toBeNull();
});

it('offers personal and team tags in personal rules, but only the team\'s in team rules', function () {
    $this->admin->tags()->create(['name' => 'my-own']);
    $other = app(TeamManager::class)->create($this->admin, 'Red Team');
    app(TagEntries::class)->createForTeam($other, 'red-only');
    $this->actingAs($this->admin);

    Livewire::withQueryParams(['team' => ''])->test('pages::rules.index')->call('create')
        ->assertSee('#my-own')->assertSee('SecOps · #ransomware')->assertSee('Red Team · #red-only');

    Livewire::withQueryParams(['team' => (string) $this->team->id])->test('pages::rules.index')->call('create')
        ->assertSee('#ransomware')->assertDontSee('my-own')->assertDontSee('red-only');
});
