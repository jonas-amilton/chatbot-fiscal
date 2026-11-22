<?php

namespace App\Services\Fiscal;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class McpFiscalClient
{
    private const SESSION_KEY = 'mcp_fiscal.session_id';

    /**
     * @return array{ok: bool, text: string}
     */
    public function callTool(string $name, array $arguments): array
    {
        $payload = fn (string $sid) => $this->send($sid, [
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/call',
            'params' => ['name' => $name, 'arguments' => $arguments],
        ]);

        $response = $payload($this->session());

        if (in_array($response->status(), [400, 404], true)) {
            Cache::forget(self::SESSION_KEY);
            $response = $payload($this->session());
        }

        $response->throw();

        $result = $this->decode($response->body())['result'] ?? throw new RuntimeException('Resposta MCP sem resultado.');

        return [
            'ok' => ! ($result['isError'] ?? false),
            'text' => $result['content'][0]['text'] ?? '',
        ];
    }

    private function session(): string
    {
        return Cache::remember(self::SESSION_KEY, now()->addMinutes(10), function () {
            $response = $this->send(null, [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'initialize',
                'params' => [
                    'protocolVersion' => '2025-06-18',
                    'capabilities' => new \stdClass,
                    'clientInfo' => ['name' => 'chatbot-fiscal', 'version' => '1.0'],
                ],
            ]);

            $sid = $response->header('mcp-session-id');
            throw_if(! $sid, RuntimeException::class, 'Servidor MCP não retornou sessão.');

            $this->send($sid, ['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);

            return $sid;
        });
    }

    private function send(?string $sid, array $body): Response
    {
        $headers = ['Accept' => 'application/json, text/event-stream'];

        if ($sid) {
            $headers['mcp-session-id'] = $sid;
        }

        return Http::withHeaders($headers)
            ->timeout((int) config('services.mcp_fiscal.timeout'))
            ->post(config('services.mcp_fiscal.tools_url'), $body);
    }

    private function decode(string $body): array
    {
        $json = null;

        foreach (preg_split('/\r?\n/', $body) as $line) {
            if (str_starts_with($line, 'data: ')) {
                $json = substr($line, 6);
            }
        }

        $decoded = json_decode($json ?? $body, true);

        if (! is_array($decoded)) {
            throw new RuntimeException('Resposta MCP inválida.');
        }

        return $decoded;
    }
}
