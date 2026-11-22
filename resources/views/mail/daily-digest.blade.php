<x-mail::message>
# {{ now()->isoFormat('dddd, D MMMM YYYY') }}

Good morning {{ $user->name }}, here's what happened in your feeds over the last 24 hours.

@if (count($digest['exploited']))
## Actively exploited

@foreach ($digest['exploited'] as $cve)
- **[{{ $cve->cve_id }}]({{ $cve->nvdUrl() }})**@if ($cve->cvss_score !== null) · CVSS {{ number_format($cve->cvss_score, 1) }}@endif @if ($cve->kev_ransomware) · used in ransomware @endif
  {{ \Illuminate\Support\Str::limit($cve->kev_name ?: $cve->description, 120) }}
@endforeach
@endif

@if (count($digest['top']))
## Top stories

@foreach ($digest['top'] as $entry)
- [{{ $entry->title }}]({{ $entry->url }}) · {{ $entry->feed->displayTitle() }}@if (($entry->top_sources ?? 1) > 1) · {{ $entry->top_sources }} sources @endif

@endforeach
@endif

@if (count($digest['highlighted']))
## Highlighted by your rules

@foreach ($digest['highlighted'] as $entry)
- [{{ $entry->title }}]({{ $entry->url }}) · {{ $entry->feed->displayTitle() }}
@endforeach
@endif

<x-mail::button :url="route('reader', ['s' => 'top'])" color="success">
Open Newsstand
</x-mail::button>

<small>You get this because the daily digest is on in your [Newsstand settings]({{ route('digest.edit') }}).</small>
</x-mail::message>
