<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use PDO;

#[Signature('fiscal:import-cest {--file= : Caminho do CSV (padrão: storage/app/dados/cest.csv)}')]
#[Description('Importa a tabela CEST completa (cest,ncm,descricao,segmento) para a base de tabelas fiscais do MCP')]
class ImportCest extends Command
{
    private const HEADER = ['cest', 'ncm', 'descricao', 'segmento'];

    private const MAX_ERRORS_SHOWN = 20;

    public function handle(): int
    {
        $path = $this->option('file') ?: storage_path('app/dados/cest.csv');
        $db = config('services.mcp_fiscal.tabelas_db');

        if (! is_file($path)) {
            $this->error("CSV não encontrado: {$path}. Veja storage/app/dados/README.md.");

            return self::FAILURE;
        }

        if (! is_file($db)) {
            $this->error("Base de tabelas do MCP não encontrada: {$db} (ajuste MCP_TABELAS_DB).");

            return self::FAILURE;
        }

        $result = $this->readCsv($path);

        if ($result['errors']) {
            $this->error('CSV inválido. Nada foi importado.');
            foreach (array_slice($result['errors'], 0, self::MAX_ERRORS_SHOWN) as $error) {
                $this->line("  - {$error}");
            }

            return self::FAILURE;
        }

        $backup = $db.'.bak-'.now()->format('YmdHis');
        copy($db, $backup);
        $this->line("Backup da base: {$backup}");

        $pdo = new PDO('sqlite:'.$db, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->beginTransaction();

        try {
            $pdo->exec('DELETE FROM cest_ncm; DELETE FROM cest;');

            $insertCest = $pdo->prepare('INSERT OR IGNORE INTO cest (cest, descricao, segmento) VALUES (?, ?, ?)');
            $insertRelation = $pdo->prepare('INSERT OR IGNORE INTO cest_ncm (cest, ncm) VALUES (?, ?)');

            foreach ($result['rows'] as $row) {
                $insertCest->execute([$row['cest'], $row['descricao'], $row['segmento']]);
                $insertRelation->execute([$row['cest'], $row['ncm']]);
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            $this->error('Falha na importação, base mantida intacta: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'CEST importados: %d códigos, %d relações NCM. Reinicie o MCP para recarregar a base.',
            count(array_unique(array_column($result['rows'], 'cest'))),
            count($result['rows']),
        ));

        return self::SUCCESS;
    }

    /**
     * @return array{rows: array<int, array{cest: string, ncm: string, descricao: string, segmento: string}>, errors: array<int, string>}
     */
    private function readCsv(string $path): array
    {
        $handle = fopen($path, 'r');
        $header = array_map(fn ($h) => strtolower(trim((string) $h)), fgetcsv($handle) ?: []);

        if ($header !== self::HEADER) {
            return ['rows' => [], 'errors' => ['Cabeçalho deve ser exatamente: '.implode(',', self::HEADER)]];
        }

        $rows = [];
        $errors = [];
        $line = 1;

        while (($data = fgetcsv($handle)) !== false) {
            $line++;

            if (count(array_filter($data, fn ($v) => trim((string) $v) !== '')) === 0) {
                continue;
            }

            $row = array_combine(self::HEADER, array_map(fn ($v) => trim((string) $v), array_pad($data, 4, '')));
            $cest = str_replace('.', '', $row['cest']);
            $ncm = str_replace('.', '', $row['ncm']);

            if (! preg_match('/^\d{7}$/', $cest)) {
                $errors[] = "Linha {$line}: CEST inválido '{$row['cest']}' (esperado 7 dígitos).";

                continue;
            }

            if (! preg_match('/^\d{8}$/', $ncm)) {
                $errors[] = "Linha {$line}: NCM inválido '{$row['ncm']}' (esperado 8 dígitos).";

                continue;
            }

            if ($row['segmento'] !== substr($cest, 0, 2)) {
                $errors[] = "Linha {$line}: segmento '{$row['segmento']}' não corresponde aos dois primeiros dígitos do CEST {$cest}.";

                continue;
            }

            if ($row['descricao'] === '') {
                $errors[] = "Linha {$line}: descrição vazia para o CEST {$cest}.";

                continue;
            }

            $rows[] = ['cest' => $cest, 'ncm' => $ncm, 'descricao' => $row['descricao'], 'segmento' => $row['segmento']];
        }

        fclose($handle);

        return ['rows' => $rows, 'errors' => $errors];
    }
}
