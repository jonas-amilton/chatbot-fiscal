<?php

namespace App\Console\Commands;

use App\Models\Conversation;
use App\Models\Message;
use App\Services\Fiscal\FiscalChatOrchestrator;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

#[Signature('fiscal:battery {--file= : JSON com as perguntas e o gabarito} {--report= : Caminho do relatório Markdown}')]
#[Description('Executa a bateria de perguntas fiscais reais e gera o relatório de acerto e latência')]
class RunFiscalBattery extends Command
{
    private const VISITOR = 'bateria-fiscal';

    public function handle(FiscalChatOrchestrator $orchestrator): int
    {
        $file = $this->option('file') ?: base_path('tests/Fixtures/bateria_fiscal.json');
        $cases = json_decode(File::get($file), true);

        if (! is_array($cases) || $cases === []) {
            $this->error("Bateria vazia ou inválida: {$file}");

            return self::FAILURE;
        }

        $results = [];

        foreach ($cases as $i => $case) {
            $conversation = Conversation::create(['visitor_token' => self::VISITOR]);

            $started = hrtime(true);
            $answer = $orchestrator->reply($conversation, $case['pergunta']);
            $seconds = (hrtime(true) - $started) / 1e9;

            $used = $conversation->messages()
                ->where('role', Message::ROLE_TOOL)
                ->pluck('tool_name')
                ->unique()
                ->values()
                ->all();

            $results[] = $this->evaluate($i + 1, $case, $used, $answer->content, $seconds);

            $this->line(sprintf('%2d. %-5s %5.1fs  %s', $i + 1, $results[$i]['ok'] ? 'OK' : 'FALHA', $seconds, Str::limit($case['pergunta'], 70)));
        }

        Conversation::where('visitor_token', self::VISITOR)->delete();

        $report = $this->report($results);
        $path = $this->option('report') ?: storage_path('app/relatorios/bateria-fiscal-'.now()->format('Ymd-His').'.md');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, $report);

        $ok = count(array_filter($results, fn ($r) => $r['ok']));
        $this->info("Acertos: {$ok}/".count($results).". Relatório: {$path}");

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $case
     * @param  array<int, string>  $used
     * @return array<string, mixed>
     */
    private function evaluate(int $id, array $case, array $used, string $answer, float $seconds): array
    {
        $expected = $case['tools'] ?? [];
        $normalized = Str::ascii(Str::lower($answer));

        $toolOk = $expected === []
            ? $used === []
            : count(array_diff($expected, $used)) === 0;

        $containsOk = collect($case['contem'] ?? [])->every(fn ($t) => str_contains($normalized, Str::ascii(Str::lower($t))));
        $notContainsOk = collect($case['nao_contem'] ?? [])->every(fn ($t) => ! str_contains($normalized, Str::ascii(Str::lower($t))));

        return [
            'id' => $id,
            'pergunta' => $case['pergunta'],
            'esperadas' => $expected,
            'usadas' => $used,
            'tool_ok' => $toolOk,
            'texto_ok' => $containsOk && $notContainsOk,
            'ok' => $toolOk && $containsOk && $notContainsOk,
            'segundos' => $seconds,
            'resposta' => $answer,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $results
     */
    private function report(array $results): string
    {
        $total = count($results);
        $ok = count(array_filter($results, fn ($r) => $r['ok']));
        $toolOk = count(array_filter($results, fn ($r) => $r['tool_ok']));
        $times = array_column($results, 'segundos');
        sort($times);
        $median = $times[(int) floor(($total - 1) / 2)];

        $lines = [
            '# Bateria fiscal: '.now()->format('d/m/Y H:i'),
            '',
            "- Acerto geral: **{$ok}/{$total}** (".round(100 * $ok / $total).'%)',
            "- Acerto de ferramenta: **{$toolOk}/{$total}**",
            '- Latência média: **'.round(array_sum($times) / $total, 1).' s**, mediana **'.round($median, 1).' s**, máxima **'.round(max($times), 1).' s**',
            '',
            '| # | Pergunta | Ferramentas esperadas | Ferramentas usadas | Tempo | Resultado |',
            '|---|---|---|---|---|---|',
        ];

        foreach ($results as $r) {
            $lines[] = sprintf(
                '| %d | %s | %s | %s | %.1f s | %s |',
                $r['id'],
                str_replace('|', '\|', $r['pergunta']),
                $r['esperadas'] ? implode(', ', $r['esperadas']) : '(nenhuma)',
                $r['usadas'] ? implode(', ', $r['usadas']) : '(nenhuma)',
                $r['segundos'],
                $r['ok'] ? 'OK' : ($r['tool_ok'] ? 'texto divergente' : 'ferramenta divergente'),
            );
        }

        $lines[] = '';
        $lines[] = '## Respostas';
        foreach ($results as $r) {
            $lines[] = '';
            $lines[] = "### {$r['id']}. {$r['pergunta']}";
            $lines[] = '';
            $lines[] = trim($r['resposta']);
        }

        return implode("\n", $lines)."\n";
    }
}
