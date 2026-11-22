<?php

namespace Tests\Feature;

use App\Models\Conversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class ChatControllerTest extends TestCase
{
    use RefreshDatabase;

    private const LLM = 'http://127.0.0.1:11434/api/chat';

    private const MCP = 'http://127.0.0.1:8002/mcp';

    public function test_visitor_sends_message_and_gets_formatted_answer_with_tool_badge(): void
    {
        $this->fakeServices(
            llm: [
                ['message' => ['role' => 'assistant', 'content' => '', 'tool_calls' => [
                    ['function' => ['name' => 'consultar_cnpj', 'arguments' => ['cnpj' => '00000000000191']]],
                ]]],
                ['message' => ['role' => 'assistant', 'content' => "**Ativa**\n\n| Campo | Valor |\n|---|---|\n| Situação | ATIVA |"]],
            ],
        );

        $response = $this->ask('visitante-a', ['message' => 'Situação do CNPJ 00000000000191?']);

        $response->assertOk()
            ->assertJsonPath('tools.0.label', 'Consulta CNPJ')
            ->assertJsonStructure(['conversation_id', 'title', 'tools', 'answer' => ['html', 'latency_ms']]);

        $this->assertStringContainsString('<strong>Ativa</strong>', $response->json('answer.html'));
        $this->assertStringContainsString('<table>', $response->json('answer.html'));
        $this->assertDatabaseHas('conversations', ['visitor_token' => 'visitante-a']);
    }

    public function test_model_output_cannot_inject_html(): void
    {
        $this->fakeServices(llm: [
            ['message' => ['role' => 'assistant', 'content' => '<script>alert(1)</script> <img src=x onerror=alert(1)>']],
        ]);

        $response = $this->ask('visitante-a', ['message' => 'teste de escape']);

        $response->assertOk();
        $this->assertStringNotContainsString('<script', $response->json('answer.html'));
        $this->assertStringNotContainsString('<img', $response->json('answer.html'));
    }

    public function test_visitor_cannot_post_into_another_visitors_conversation(): void
    {
        $this->fakeServices(llm: [['message' => ['role' => 'assistant', 'content' => 'ok']]]);

        $alheia = Conversation::create(['visitor_token' => 'visitante-b']);

        $this->ask('visitante-a', ['message' => 'oi', 'conversation_id' => $alheia->id])
            ->assertNotFound();

        $this->assertSame(0, $alheia->messages()->count());
    }

    public function test_rejects_empty_message(): void
    {
        $this->ask('visitante-a', ['message' => ' '])->assertUnprocessable();
    }

    public function test_accepts_long_xml_message_without_truncating(): void
    {
        $this->fakeServices(llm: [['message' => ['role' => 'assistant', 'content' => 'ok']]]);

        $xml = '<NFSe>'.str_repeat('x', 5000).'</NFSe>';

        $this->ask('visitante-a', ['message' => $xml])->assertOk();

        $stored = Conversation::where('visitor_token', 'visitante-a')->firstOrFail()
            ->messages()->where('role', 'user')->value('content');

        $this->assertSame($xml, $stored);
    }

    public function test_index_only_lists_own_conversations(): void
    {
        Conversation::create(['visitor_token' => 'visitante-a', 'title' => 'Minha consulta']);
        Conversation::create(['visitor_token' => 'visitante-b', 'title' => 'Consulta alheia']);

        $this->withCookie('visitor_token', 'visitante-a')
            ->get('/')
            ->assertOk()
            ->assertSee('Minha consulta')
            ->assertDontSee('Consulta alheia');
    }

    /**
     * @param  array<int, array<string, mixed>>  $llm  respostas sequenciais do modelo
     */
    private function fakeServices(array $llm): void
    {
        Http::fake([
            self::LLM => Http::sequence(array_map(fn (array $r) => Http::response($r), $llm)),
            self::MCP => function ($request) {
                return match ($request->data()['method']) {
                    'initialize' => Http::response($this->sse(['jsonrpc' => '2.0', 'id' => 1, 'result' => []]), 200, ['mcp-session-id' => 'sess-1']),
                    'notifications/initialized' => Http::response('', 202),
                    'tools/call' => Http::response($this->sse(['jsonrpc' => '2.0', 'id' => 2, 'result' => [
                        'content' => [['type' => 'text', 'text' => json_encode(['sucesso' => true, 'situacao_cadastral' => 'ATIVA'])]],
                    ]])),
                };
            },
        ]);
    }

    private function ask(string $token, array $data): TestResponse
    {
        return $this->withCookie('visitor_token', $token)
            ->withHeaders(['Accept' => 'application/json'])
            ->post('/chat/messages', $data);
    }

    private function sse(array $payload): string
    {
        return "event: message\ndata: ".json_encode($payload)."\n\n";
    }
}
