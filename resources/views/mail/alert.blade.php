<x-mail::message>
# {{ $alert->title }}

@if ($alert->context)
<small>{{ $alert->context }}</small>
@endif

@if ($alert->items)
@foreach ($alert->items as $item)
- @if ($item['url'])[{{ $item['title'] }}]({{ $item['url'] }})@else{{ $item['title'] }}@endif

@endforeach
@else
{{ $alert->body }}
@endif

@if ($alert->url)
<x-mail::button :url="$alert->url" :color="$alert->level === 'critical' ? 'error' : 'success'">
Open
</x-mail::button>
@endif

<small>Sent by Newsstand. Manage alerts under Settings → Alerts.</small>
</x-mail::message>
