<?php

use App\Jobs\EvaluateRules;
use App\Models\Rule;
use App\Services\Rules\RuleEvaluator;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule as ValidationRule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Rules')] class extends Component {
    public ?int $editingId = null;

    public string $name = '';

    public string $match = 'all';

    /** @var list<array{type: string, value: mixed}> */
    public array $conditions = [];

    public string $highlight = '';

    public string $tagId = '';

    public bool $star = false;

    public bool $later = false;

    public bool $markRead = false;

    public bool $mute = false;

    /** @var list<string> alert channel ids to notify */
    public array $alertChannels = [];

    public bool $applyToRecent = true;

    /** Ready-made rules for the security use case. */
    public const TEMPLATES = [
        'exploited' => ['name' => 'Actively exploited vulnerabilities', 'conditions' => [['type' => 'kev', 'value' => null]], 'actions' => ['highlight' => 'red', 'star' => true]],
        'critical' => ['name' => 'Critical vulnerabilities (CVSS 9+)', 'conditions' => [['type' => 'cvss', 'value' => 9]], 'actions' => ['highlight' => 'orange']],
        'big' => ['name' => 'Big stories (3+ sources)', 'conditions' => [['type' => 'sources', 'value' => 3]], 'actions' => ['highlight' => 'amber']],
    ];

    #[Computed]
    public function rules(): Collection
    {
        return Auth::user()->rules()->get();
    }

    #[Computed]
    public function feeds(): Collection
    {
        return Auth::user()->subscriptions()->with('feed:id,title,url')->get()
            ->map(fn ($s) => ['id' => $s->feed_id, 'title' => $s->displayTitle()])
            ->sortBy('title', SORT_NATURAL | SORT_FLAG_CASE)->values();
    }

    #[Computed]
    public function folders(): Collection
    {
        return Auth::user()->folders()->get();
    }

    #[Computed]
    public function channels(): Collection
    {
        return Auth::user()->alertChannels()->get(['id', 'name', 'type', 'enabled']);
    }

    #[Computed]
    public function tags(): Collection
    {
        return Auth::user()->tags()->get();
    }

    // --- Editor ---

    public function create(): void
    {
        $this->resetEditor();
        $this->conditions = [['type' => 'keyword', 'value' => '']];

        Flux::modal('rule-editor')->show();
    }

    public function edit(int $id): void
    {
        $rule = $this->findRule($id);

        $this->resetEditor();
        $this->editingId = $rule->id;
        $this->name = $rule->name;
        $this->match = $rule->match;
        $this->conditions = array_values($rule->conditions);
        $this->highlight = (string) ($rule->actions['highlight'] ?? '');
        $this->tagId = (string) ($rule->actions['tag_id'] ?? '');
        $this->star = (bool) ($rule->actions['star'] ?? false);
        $this->later = (bool) ($rule->actions['later'] ?? false);
        $this->markRead = (bool) ($rule->actions['read'] ?? false);
        $this->mute = (bool) ($rule->actions['mute'] ?? false);
        $this->alertChannels = array_map('strval', $rule->actions['alert'] ?? []);
        $this->applyToRecent = false;

        Flux::modal('rule-editor')->show();
    }

    public function addCondition(): void
    {
        $this->conditions[] = ['type' => 'keyword', 'value' => ''];
    }

    public function removeCondition(int $index): void
    {
        unset($this->conditions[$index]);
        $this->conditions = array_values($this->conditions);
    }

    /** Reset the value when the condition type changes. */
    public function updatedConditions(mixed $value, string $key): void
    {
        if (str_ends_with($key, '.type')) {
            $index = (int) explode('.', $key)[0];
            $this->conditions[$index]['value'] = match ($value) {
                'cvss' => 9,
                'sources' => 3,
                'has_cve', 'kev', 'watchlist' => null,
                default => '',
            };
        }
    }

    public function save(RuleEvaluator $evaluator): void
    {
        $data = $this->validated();

        $rule = $this->editingId
            ? tap($this->findRule($this->editingId))->update($data)
            : Auth::user()->rules()->create([...$data, 'position' => (int) Auth::user()->rules()->max('position') + 1]);

        $applied = $this->applyToRecent ? $evaluator->applyToRecent($rule) : 0;

        Flux::modal('rule-editor')->close();
        unset($this->rules);
        $this->dispatch('unread-changed');
        $this->dispatch('tags-changed');

        Flux::toast(variant: 'success', text: $this->applyToRecent
            ? trans_choice('Rule saved. It matched :count story from the last 30 days.|Rule saved. It matched :count stories from the last 30 days.', $applied)
            : __('Rule saved.'));
    }

    public function useTemplate(string $key, RuleEvaluator $evaluator): void
    {
        $template = self::TEMPLATES[$key] ?? null;

        if (! $template) {
            return;
        }

        $rule = Auth::user()->rules()->create([...$template, 'match' => 'all', 'position' => (int) Auth::user()->rules()->max('position') + 1]);
        $applied = $evaluator->applyToRecent($rule);

        unset($this->rules);
        $this->dispatch('unread-changed');
        Flux::toast(variant: 'success', text: trans_choice('Added. Matched :count story from the last 30 days.|Added. Matched :count stories from the last 30 days.', $applied));
    }

    public function toggle(int $id): void
    {
        $rule = $this->findRule($id);
        $rule->update(['enabled' => ! $rule->enabled]);
        unset($this->rules);
    }

    public function delete(int $id): void
    {
        $this->findRule($id)->delete();
        unset($this->rules);
    }

    // --- Helpers ---

    /** @return array<string, mixed> */
    private function validated(): array
    {
        $this->validate([
            'name' => ['required', 'string', 'max:100'],
            'match' => ['required', 'in:all,any'],
            'conditions' => ['required', 'array', 'min:1', 'max:10'],
            'conditions.*.type' => ['required', ValidationRule::in(Rule::CONDITION_TYPES)],
            'highlight' => ['nullable', ValidationRule::in(['', ...Rule::HIGHLIGHTS])],
            'tagId' => ['nullable', ValidationRule::exists('tags', 'id')->where('user_id', Auth::id())],
        ], [], ['conditions.*.type' => __('condition')]);

        $conditions = [];
        foreach ($this->conditions as $i => $condition) {
            $conditions[] = $this->cleanCondition($i, $condition);
        }

        $actions = array_filter([
            'highlight' => $this->highlight ?: null,
            'tag_id' => $this->tagId !== '' ? (int) $this->tagId : null,
            'star' => $this->star,
            'later' => $this->later,
            'read' => $this->markRead,
            'mute' => $this->mute,
            // Only channels this user owns.
            'alert' => $this->channels->pluck('id')->intersect(array_map('intval', $this->alertChannels))->values()->all() ?: null,
        ]);

        if ($actions === []) {
            throw ValidationException::withMessages(['actions' => __('Choose at least one thing for the rule to do.')]);
        }

        return ['name' => $this->name, 'match' => $this->match, 'conditions' => $conditions, 'actions' => $actions];
    }

    /** @return array{type: string, value: mixed} */
    private function cleanCondition(int $i, array $condition): array
    {
        $type = $condition['type'];
        $value = $condition['value'] ?? null;
        $fail = fn (string $message) => throw ValidationException::withMessages(["conditions.{$i}.value" => $message]);

        $value = match ($type) {
            'keyword', 'not_keyword', 'title' => trim((string) $value) !== '' ? mb_substr(trim((string) $value), 0, 500) : $fail(__('Enter one or more words, separated by commas.')),
            'regex' => RuleEvaluator::isValidRegex((string) $value) ? (string) $value : $fail(__('That is not a valid regular expression.')),
            'feed' => $this->feeds->contains('id', (int) $value) ? (int) $value : $fail(__('Pick one of your feeds.')),
            'folder' => $this->folders->contains('id', (int) $value) ? (int) $value : $fail(__('Pick one of your folders.')),
            'cvss' => is_numeric($value) && $value >= 0 && $value <= 10 ? round((float) $value, 1) : $fail(__('Enter a score from 0 to 10.')),
            'sources' => is_numeric($value) && $value >= 2 && $value <= 50 ? (int) $value : $fail(__('Enter a number from 2 to 50.')),
            default => null,
        };

        return ['type' => $type, 'value' => $value];
    }

    private function findRule(int $id): Rule
    {
        return Auth::user()->rules()->findOrFail($id);
    }

    private function resetEditor(): void
    {
        $this->reset('editingId', 'name', 'match', 'conditions', 'highlight', 'tagId', 'star', 'later', 'markRead', 'mute', 'alertChannels');
        $this->applyToRecent = true;
        $this->resetErrorBag();
    }

    /** Human summary of a rule, e.g. "mentions an exploited CVE → highlight red, star". */
    public function describe(Rule $rule): string
    {
        $feeds = $this->feeds->pluck('title', 'id');
        $folders = $this->folders->pluck('name', 'id');

        $conditions = collect($rule->conditions)->map(fn ($c) => match ($c['type']) {
            'keyword' => __('text has “:v”', ['v' => $c['value']]),
            'not_keyword' => __('text lacks “:v”', ['v' => $c['value']]),
            'title' => __('title has “:v”', ['v' => $c['value']]),
            'regex' => __('matches /:v/', ['v' => $c['value']]),
            'feed' => __('from :v', ['v' => $feeds[$c['value']] ?? __('a removed feed')]),
            'folder' => __('in :v', ['v' => $folders[$c['value']] ?? __('a removed folder')]),
            'has_cve' => __('mentions a CVE'),
            'cvss' => __('CVSS ≥ :v', ['v' => $c['value']]),
            'kev' => __('actively exploited'),
            'watchlist' => __('on my watchlist'),
            'sources' => __(':v+ sources', ['v' => $c['value']]),
            default => $c['type'],
        })->implode($rule->match === 'any' ? ' '.__('or').' ' : ' '.__('and').' ');

        $tags = $this->tags->pluck('name', 'id');
        $a = $rule->actions;
        $actions = collect([
            ! empty($a['highlight']) ? __('highlight :c', ['c' => $a['highlight']]) : null,
            ! empty($a['tag_id']) ? '#'.($tags[$a['tag_id']] ?? '?') : null,
            ! empty($a['star']) ? __('star') : null,
            ! empty($a['later']) ? __('read later') : null,
            ! empty($a['read']) ? __('mark read') : null,
            ! empty($a['mute']) ? __('mute') : null,
            ! empty($a['alert']) ? __('alert') : null,
        ])->filter()->implode(', ');

        return "{$conditions} → {$actions}";
    }
}; ?>

<div class="mx-auto w-full max-w-4xl space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('Rules') }}</flux:heading>
            <flux:text class="mt-1 max-w-xl">{{ __('Decide what matters. When a new story matches a rule, Newsstand highlights, tags, stars or mutes it for you. Each rule acts on a story once, so undoing an action sticks.') }}</flux:text>
        </div>
        <flux:button variant="primary" icon="plus" wire:click="create" data-test="new-rule">{{ __('New rule') }}</flux:button>
    </div>

    @if ($this->rules->isEmpty())
        <flux:card class="space-y-4">
            <flux:heading size="lg">{{ __('Start with a security preset') }}</flux:heading>
            <div class="grid gap-3 md:grid-cols-3">
                @foreach ([
                    'exploited' => ['fire', __('Actively exploited'), __('Stories about CVEs in CISA’s Known Exploited Vulnerabilities list: highlight red and star.')],
                    'critical' => ['shield-exclamation', __('Critical CVEs'), __('Stories mentioning a CVE with CVSS 9.0 or higher: highlight orange.')],
                    'big' => ['newspaper', __('Big stories'), __('Stories covered by 3 or more of your feeds: highlight amber.')],
                ] as $key => [$icon, $title, $text])
                    <button type="button" wire:click="useTemplate('{{ $key }}')" data-test="template-{{ $key }}"
                        class="rounded-xl border border-zinc-200 p-4 text-start transition hover:border-emerald-500 hover:bg-emerald-50/50 dark:border-zinc-700 dark:hover:bg-emerald-950/30">
                        <flux:icon :name="$icon" class="text-emerald-700 dark:text-emerald-400" />
                        <div class="mt-2 font-serif text-lg font-semibold">{{ $title }}</div>
                        <div class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">{{ $text }}</div>
                    </button>
                @endforeach
            </div>
        </flux:card>
    @else
        <div class="space-y-2">
            @foreach ($this->rules as $rule)
                <div class="flex items-center gap-4 rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800/60" wire:key="rule-{{ $rule->id }}">
                    <flux:switch :checked="$rule->enabled" wire:click="toggle({{ $rule->id }})" />
                    <div @class(['min-w-0 flex-1', 'opacity-50' => ! $rule->enabled])>
                        <div class="flex items-center gap-2">
                            @if (! empty($rule->actions['highlight']))
                                <span @class(['size-2.5 shrink-0 rounded-full', 'highlight-bar-'.$rule->actions['highlight']])></span>
                            @endif
                            <span class="truncate font-medium">{{ $rule->name }}</span>
                        </div>
                        <div class="mt-0.5 truncate text-sm text-zinc-500 dark:text-zinc-400">{{ $this->describe($rule) }}</div>
                    </div>
                    <span class="shrink-0 text-xs text-zinc-500">{{ trans_choice(':count match|:count matches', $rule->matches_count) }}</span>
                    <flux:button size="sm" variant="subtle" icon="pencil" wire:click="edit({{ $rule->id }})" :tooltip="__('Edit')" />
                    <flux:button size="sm" variant="subtle" icon="trash" wire:click="delete({{ $rule->id }})"
                        wire:confirm="{{ __('Delete this rule? Stories it already changed stay as they are.') }}" :tooltip="__('Delete')" />
                </div>
            @endforeach
        </div>
    @endif

    {{-- Editor --}}
    <flux:modal name="rule-editor" class="w-full md:w-[40rem]">
        <form wire:submit="save" class="space-y-6">
            <flux:heading size="lg">{{ $editingId ? __('Edit rule') : __('New rule') }}</flux:heading>

            <flux:input wire:model="name" :label="__('Name')" :placeholder="__('e.g. Fortinet or Ivanti news')" />

            <div class="space-y-3">
                <div class="flex items-center gap-2 text-sm">
                    <span>{{ __('When a story matches') }}</span>
                    <flux:select wire:model="match" size="sm" class="!w-28">
                        <flux:select.option value="all">{{ __('all') }}</flux:select.option>
                        <flux:select.option value="any">{{ __('any') }}</flux:select.option>
                    </flux:select>
                    <span>{{ __('of these conditions:') }}</span>
                </div>

                @foreach ($conditions as $i => $condition)
                    <div class="flex items-start gap-2" wire:key="condition-{{ $i }}">
                        <flux:select wire:model.live="conditions.{{ $i }}.type" size="sm" class="!w-52 shrink-0">
                            <flux:select.option value="keyword">{{ __('Text contains') }}</flux:select.option>
                            <flux:select.option value="title">{{ __('Title contains') }}</flux:select.option>
                            <flux:select.option value="not_keyword">{{ __('Text does not contain') }}</flux:select.option>
                            <flux:select.option value="regex">{{ __('Text matches regex') }}</flux:select.option>
                            <flux:select.option value="feed">{{ __('Feed is') }}</flux:select.option>
                            <flux:select.option value="folder">{{ __('Folder is') }}</flux:select.option>
                            <flux:select.option value="has_cve">{{ __('Mentions a CVE') }}</flux:select.option>
                            <flux:select.option value="cvss">{{ __('CVSS score at least') }}</flux:select.option>
                            <flux:select.option value="kev">{{ __('Actively exploited (KEV)') }}</flux:select.option>
                            <flux:select.option value="sources">{{ __('Covered by at least') }}</flux:select.option>
                            <flux:select.option value="watchlist">{{ __('Concerns my watchlist') }}</flux:select.option>
                        </flux:select>

                        <div class="min-w-0 flex-1">
                            @switch($condition['type'] ?? 'keyword')
                                @case('feed')
                                    <flux:select wire:model="conditions.{{ $i }}.value" size="sm" :placeholder="__('Choose a feed')">
                                        @foreach ($this->feeds as $feed)
                                            <flux:select.option :value="$feed['id']">{{ $feed['title'] }}</flux:select.option>
                                        @endforeach
                                    </flux:select>
                                    @break
                                @case('folder')
                                    <flux:select wire:model="conditions.{{ $i }}.value" size="sm" :placeholder="__('Choose a folder')">
                                        @foreach ($this->folders as $folder)
                                            <flux:select.option :value="$folder->id">{{ $folder->name }}</flux:select.option>
                                        @endforeach
                                    </flux:select>
                                    @break
                                @case('cvss')
                                    <flux:input type="number" step="0.1" min="0" max="10" wire:model="conditions.{{ $i }}.value" size="sm" />
                                    @break
                                @case('sources')
                                    <flux:input type="number" min="2" max="50" wire:model="conditions.{{ $i }}.value" size="sm" :suffix="__('sources')" />
                                    @break
                                @case('has_cve')
                                @case('kev')
                                @case('watchlist')
                                    <div class="py-1.5 text-sm text-zinc-500">{{ __('No value needed.') }}</div>
                                    @break
                                @default
                                    <flux:input wire:model="conditions.{{ $i }}.value" size="sm"
                                        :placeholder="($condition['type'] ?? '') === 'regex' ? 'fortinet|fortios' : __('ransomware, zero-day, “supply chain”')" />
                            @endswitch
                            <flux:error name="conditions.{{ $i }}.value" />
                        </div>

                        <flux:button size="sm" variant="subtle" icon="x-mark" wire:click="removeCondition({{ $i }})" :disabled="count($conditions) === 1" />
                    </div>
                @endforeach

                <flux:button size="sm" variant="ghost" icon="plus" wire:click="addCondition" :disabled="count($conditions) >= 10">{{ __('Add condition') }}</flux:button>
                <flux:error name="conditions" />
            </div>

            <div class="space-y-3">
                <flux:text class="font-medium text-zinc-800 dark:text-zinc-200">{{ __('Then:') }}</flux:text>
                <div class="grid gap-3 sm:grid-cols-2">
                    <flux:select wire:model="highlight" :label="__('Highlight')" size="sm">
                        <flux:select.option value="">{{ __('No highlight') }}</flux:select.option>
                        @foreach (\App\Models\Rule::HIGHLIGHTS as $color)
                            <flux:select.option :value="$color">{{ ucfirst($color) }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:select wire:model="tagId" :label="__('Add tag')" size="sm">
                        <flux:select.option value="">{{ __('No tag') }}</flux:select.option>
                        @foreach ($this->tags as $tag)
                            <flux:select.option :value="$tag->id">#{{ $tag->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                </div>
                <div class="grid gap-2 sm:grid-cols-2">
                    <flux:checkbox wire:model="star" :label="__('Star it')" />
                    <flux:checkbox wire:model="later" :label="__('Save for later')" />
                    <flux:checkbox wire:model="markRead" :label="__('Mark as read')" />
                    <flux:checkbox wire:model="mute" :label="__('Mute (hide from lists)')" />
                </div>
                <div class="space-y-2">
                    <flux:text size="sm" class="font-medium text-zinc-700 dark:text-zinc-300">{{ __('Send alert to') }}</flux:text>
                    @forelse ($this->channels as $channel)
                        <flux:checkbox wire:model="alertChannels" :value="(string) $channel->id" :label="$channel->name.' ('.\App\Models\AlertChannel::label($channel->type).')'" data-test="rule-alert-{{ $channel->id }}" />
                    @empty
                        <flux:text size="sm"><flux:link :href="route('alerts.edit')" wire:navigate>{{ __('Add an alert channel') }}</flux:link> {{ __('to get notified when this rule matches.') }}</flux:text>
                    @endforelse
                </div>
                <flux:error name="actions" />
            </div>

            <flux:checkbox wire:model="applyToRecent" :label="__('Also apply to stories from the last 30 days')" />

            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary" data-test="save-rule">{{ __('Save rule') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
