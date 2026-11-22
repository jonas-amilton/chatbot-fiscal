<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Message;
use App\Services\Fiscal\FiscalChatOrchestrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FiscalChatOrchestratorTest extends TestCase
{
    use RefreshDatabase;

    private const LLM = 'http://127.0.0.1:11434/api/chat';

    private const MCP = 'http://127.0.0.1:8002/mcp';

    public function test_calls_tool_redacts_personal_data_and_answers(): void
    {
        $cnpjData = json_encode([
            'sucesso' => true,
            'razao_social' => 'BANCO DO BRASIL SA',
            'situacao_cadastral' => 'ATIVA',
            'telefone' => '6134939002',
            'qsa' => [['nome' => 'FULANO DE TAL']],
        ]);

        Http::fake([
            self::LLM => Http::sequence()
                ->push(['message' => [
                    'role' => 'assistant',
                    'content' => '',
                    'tool_calls' => [[
                        'function' => ['name' => 'consultar_cnpj', 'arguments' => ['cnpj' => '00.000.000/0001-91']],
                    ]],
                ]])
                ->push(['message' => ['role' => 'assistant', 'content' => 'A empresa está ativa.']]),
            self::MCP => function ($request) use ($cnpjData) {
                return match ($request->data()['method']) {
                    'initialize' => Http::response($this->sse(['jsonrpc' => '2.0', 'id' => 1, 'result' => []]), 200, ['mcp-session-id' => 'sess-1']),
                    'notifications/initialized' => Http::response('', 202),
                    'tools/call' => Http::response($this->sse(['jsonrpc' => '2.0', 'id' => 2, 'result' => [
                        'content' => [['type' => 'text', 'text' => $cnpjData]],
                    ]])),
                };
            },
        ]);

        $conversation = Conversation::create(['visitor_token' => 'token-teste']);

        $answer = app(FiscalChatOrchestrator::class)->reply($conversation, 'Qual a situação do CNPJ 00.000.000/0001-91?');

        $this->assertSame('A empresa está ativa.', $answer->content);
        $this->assertSame(Message::ROLE_ASSISTANT, $answer->role);

        Http::assertSent(fn ($req) => $req->url() === self::MCP
            && ($req->data()['params']['arguments']['cnpj'] ?? null) === '00000000000191');

        $tool = $conversation->messages()->where('role', Message::ROLE_TOOL)->firstOrFail();
        $this->assertSame('consultar_cnpj', $tool->tool_name);
        $this->assertTrue($tool->tool_payload['outcome']['ok']);
        $this->assertSame('BANCO DO BRASIL SA', $tool->tool_payload['outcome']['dados']['razao_social']);
        $this->assertStringNotContainsString('FULANO', json_encode($tool->tool_payload));
        $this->assertStringNotContainsString('6134939002', json_encode($tool->tool_payload));
    }

    public function test_reports_unavailable_assistant_instead_of_failing(): void
    {
        Http::fake([self::LLM => Http::response('erro', 500)]);

        $conversation = Conversation::create(['visitor_token' => 'token-teste']);

        $answer = app(FiscalChatOrchestrator::class)->reply($conversation, 'Qual o NCM do café?');

        $this->assertStringContainsString('indisponível', $answer->content);
        $this->assertSame(2, $conversation->messages()->count());
    }

    public function test_sends_previous_user_message_as_context_for_follow_up(): void
    {
        Http::fake([self::LLM => Http::response(['message' => ['role' => 'assistant', 'content' => 'Ok.']])]);

        $conversation = Conversation::create(['visitor_token' => 'token-teste']);
        $orchestrator = app(FiscalChatOrchestrator::class);

        $orchestrator->reply($conversation, '<NFSe><emit>EXEMPLO</emit>');
        $orchestrator->reply($conversation, 'falta alguma tag?');

        Http::assertSent(function ($request) {
            if ($request->url() !== self::LLM) {
                return false;
            }

            $user = collect($request->data()['messages'])->firstWhere('role', 'user');

            return str_contains($user['content'], 'EXEMPLO')
                && str_contains($user['content'], 'falta alguma tag?');
        });
    }

    public function test_injects_nfse_xsd_validation_into_prompt(): void
    {
        Http::fake([self::LLM => Http::response(['message' => ['role' => 'assistant', 'content' => 'ok']])]);

        $conversation = Conversation::create(['visitor_token' => 'token-teste']);
        $xml = '<NFSe xmlns="http://www.sped.fazenda.gov.br/nfse" versao="1.00">'
            .'<infNFSe Id="NFS'.str_repeat('0', 50).'"><xLocEmi>X</xLocEmi></infNFSe></NFSe>';

        app(FiscalChatOrchestrator::class)->reply($conversation, 'falta alguma tag? '.$xml);

        Http::assertSent(function ($request) {
            if ($request->url() !== self::LLM) {
                return false;
            }

            $user = collect($request->data()['messages'])->firstWhere('role', 'user');

            return str_contains($user['content'], 'Validação XSD da NFSe')
                && str_contains($user['content'], 'xLocPrestacao');
        });
    }

    private function sse(array $payload): string
    {
        return "event: message\ndata: ".json_encode($payload)."\n\n";
    }
}
