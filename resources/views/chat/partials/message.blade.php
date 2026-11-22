@php
    $outcome = $message->tool_payload['outcome'] ?? [];
@endphp

@if ($message->role === 'user')
    <div class="flex justify-end">
        <div class="max-w-[80%] whitespace-pre-wrap rounded-2xl rounded-br-sm bg-indigo-600 px-4 py-2 text-white">{{ $message->content }}</div>
    </div>
@elseif ($message->role === 'assistant')
    <div class="flex justify-start">
        <div class="prose prose-sm max-w-[85%] rounded-2xl rounded-bl-sm bg-white px-4 py-2 shadow-sm ring-1 ring-slate-200">
            {!! \App\Support\Markdown::toHtml($message->content) !!}
        </div>
    </div>
@elseif ($message->role === 'tool')
    <div class="flex justify-start">
        <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium {{ ($outcome['ok'] ?? false) ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800' }}">
            {{ $tools->label($message->tool_name) }}{{ ($outcome['ok'] ?? false) ? '' : ' · indisponível' }}
        </span>
    </div>
@endif
