<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Models\Message;
use App\Services\Fiscal\FiscalChatOrchestrator;
use App\Services\Fiscal\FiscalToolRegistry;
use App\Support\Markdown;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Cookie;

class ChatController extends Controller
{
    private const COOKIE = 'visitor_token';

    private const COOKIE_MINUTES = 60 * 24 * 30;

    public function index(Request $request, FiscalToolRegistry $tools)
    {
        $token = $this->token($request);

        $conversations = Conversation::query()
            ->where('visitor_token', $token)
            ->latest('updated_at')
            ->limit(30)
            ->get();

        $active = $request->integer('c')
            ? $conversations->firstWhere('id', $request->integer('c'))
            : null;

        $messages = $active?->messages()->get() ?? collect();

        return response()
            ->view('chat.index', [
                'conversations' => $conversations,
                'active' => $active,
                'messages' => $messages,
                'tools' => $tools,
            ])
            ->cookie($this->cookie($request, $token));
    }

    public function store(Request $request, FiscalChatOrchestrator $orchestrator, FiscalToolRegistry $tools)
    {
        $data = $request->validate([
            'message' => ['required', 'string', 'min:2', 'max:20000'],
            'conversation_id' => ['nullable', 'integer'],
        ]);

        $token = $this->token($request);

        $conversation = isset($data['conversation_id'])
            ? Conversation::where('visitor_token', $token)->find($data['conversation_id'])
            : Conversation::create(['visitor_token' => $token]);

        if (! $conversation) {
            return response()->json(['message' => 'Conversa não encontrada.'], 404);
        }

        $before = Message::where('conversation_id', $conversation->id)->max('id') ?? 0;

        $answer = $orchestrator->reply($conversation, $data['message']);

        $used = $conversation->messages()
            ->where('role', Message::ROLE_TOOL)
            ->where('id', '>', $before)
            ->pluck('tool_name')
            ->unique()
            ->map(fn (string $name) => ['name' => $name, 'label' => $tools->label($name)])
            ->values();

        return response()
            ->json([
                'conversation_id' => $conversation->id,
                'title' => $conversation->title,
                'tools' => $used,
                'answer' => [
                    'html' => Markdown::toHtml($answer->content),
                    'latency_ms' => $answer->latency_ms,
                ],
            ])
            ->cookie($this->cookie($request, $token));
    }

    /**
     * Identificador anônimo do visitante (modo visitante do MVP). Não guarda IP nem dados pessoais.
     */
    private function token(Request $request): string
    {
        return $request->cookie(self::COOKIE) ?: Str::random(40);
    }

    private function cookie(Request $request, string $token): Cookie
    {
        return cookie(self::COOKIE, $token, self::COOKIE_MINUTES, '/', null, $request->isSecure(), true, false, 'lax');
    }
}
