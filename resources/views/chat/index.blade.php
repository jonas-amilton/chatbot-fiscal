@extends('layouts.app', ['title' => $active?->title ? $active->title.' · Chatbot Fiscal' : 'Chatbot Fiscal'])

@section('content')
<div class="flex h-screen">
    <aside class="hidden w-64 flex-col border-r border-slate-200 bg-white md:flex">
        <div class="p-4">
            <a href="{{ route('chat.index') }}" class="block rounded-lg bg-indigo-600 px-4 py-2 text-center text-sm font-medium text-white hover:bg-indigo-700">
                Nova consulta
            </a>
        </div>
        <nav class="flex-1 space-y-1 overflow-y-auto px-2 pb-4">
            @forelse ($conversations as $conversation)
                <a href="{{ route('chat.index', ['c' => $conversation->id]) }}"
                   class="block truncate rounded-lg px-3 py-2 text-sm {{ $active?->id === $conversation->id ? 'bg-indigo-50 font-medium text-indigo-700' : 'text-slate-600 hover:bg-slate-100' }}">
                    {{ $conversation->title }}
                </a>
            @empty
                <p class="px-3 py-2 text-sm text-slate-400">Nenhuma consulta ainda.</p>
            @endforelse
        </nav>
    </aside>

    <main class="flex min-w-0 flex-1 flex-col">
        <header class="border-b border-slate-200 bg-white px-6 py-3">
            <h1 class="text-base font-semibold">Chatbot Fiscal</h1>
            <p class="text-xs text-slate-500">
                Estimativas e consultas orientativas. Não substituem contador nem a legislação vigente.
            </p>
        </header>

        <div id="chat-log" class="flex-1 space-y-4 overflow-y-auto px-4 py-6 md:px-10">
            @if ($messages->isEmpty())
                <div class="mx-auto max-w-xl text-center text-slate-500">
                    <p class="mb-4">Pergunte sobre CNPJ, NCM, CFOP, CST, ICMS ou a Reforma Tributária.</p>
                </div>
            @endif

            @foreach ($messages as $message)
                @include('chat.partials.message', ['message' => $message, 'tools' => $tools])
            @endforeach
        </div>

        <div id="chat-typing" class="hidden px-10 pb-2 text-sm text-slate-500">Consultando dados fiscais…</div>

        <div class="border-t border-slate-200 bg-white px-4 py-4 md:px-10">
            <div class="mb-3 flex flex-wrap gap-2">
                @foreach ([
                    'Qual a situação cadastral do CNPJ 00.000.000/0001-91?',
                    'Qual a alíquota de ICMS de SP para RJ?',
                    'Simule a Reforma Tributária para uma empresa de serviços com faturamento de R$ 1,2 milhão no Lucro Presumido',
                    'O que é o CFOP 5102?',
                ] as $prompt)
                    <button type="button" data-prompt="{{ $prompt }}"
                            class="quick-prompt rounded-full border border-slate-300 px-3 py-1 text-xs text-slate-600 hover:bg-slate-100">
                        {{ \Illuminate\Support\Str::limit($prompt, 42) }}
                    </button>
                @endforeach
            </div>

            <form id="chat-form" action="{{ route('chat.messages.store') }}" method="POST"
                  data-conversation-id="{{ $active?->id }}" class="flex items-end gap-3">
                @csrf
                <textarea id="chat-input" name="message" rows="2" maxlength="20000" required
                          placeholder="Digite sua pergunta fiscal (Shift+Enter quebra a linha)"
                          class="min-h-[2.5rem] flex-1 resize-none rounded-xl border border-slate-300 px-4 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200"></textarea>
                <button type="submit" class="rounded-xl bg-indigo-600 px-5 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-50">
                    Enviar
                </button>
            </form>
            <p class="mt-2 text-xs text-slate-400">
                Não informe dados pessoais de terceiros. As conversas ficam salvas no servidor para o seu histórico.
            </p>
        </div>
    </main>
</div>
@endsection
