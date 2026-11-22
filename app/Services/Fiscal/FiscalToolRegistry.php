<?php

namespace App\Services\Fiscal;

use Illuminate\Support\Str;

class FiscalToolRegistry
{
    /**
     * Dados pessoais de terceiros (sócios, contatos) nunca vão ao modelo nem ao histórico (LGPD).
     * Campos verbosos sem valor para a resposta também saem, para reduzir tokens de entrada no E2B.
     */
    private const OMITTED_KEYS = ['qsa', 'telefone', 'email', 'cpf', 'representante_legal', 'nota_ano', 'consultado_em'];

    private const LABELS = [
        'consultar_cnpj' => 'Consulta CNPJ',
        'consultar_simples_nacional' => 'Simples Nacional',
        'consultar_ncm' => 'Consulta NCM',
        'consultar_cfop' => 'Consulta CFOP',
        'validar_cst' => 'Validação CST',
        'consultar_cest' => 'Consulta CEST',
        'consultar_aliquota_icms' => 'Alíquota ICMS',
        'simular_transicao_reforma_tributaria' => 'Simulador IBS/CBS',
    ];

    private const TOOLS = [
        'consultar_cnpj' => [
            'keywords' => ['cnpj', 'empresa', 'razao', 'situacao', 'cadastral', 'endereco'],
            'description' => 'Consulta dados cadastrais de uma empresa pelo CNPJ: razão social, situação, porte, atividade e endereço.',
            'parameters' => [
                'cnpj' => ['type' => 'string', 'description' => 'CNPJ com 14 dígitos, com ou sem pontuação.', 'pattern' => '/^[0-9A-Z]{14}$/'],
            ],
        ],
        'consultar_simples_nacional' => [
            'keywords' => ['simples', 'mei', 'optante', 'regime'],
            'description' => 'Verifica se uma empresa é optante do Simples Nacional ou MEI.',
            'parameters' => [
                'cnpj' => ['type' => 'string', 'description' => 'CNPJ com 14 dígitos, com ou sem pontuação.', 'pattern' => '/^[0-9A-Z]{14}$/'],
            ],
        ],
        'consultar_ncm' => [
            'keywords' => ['ncm', 'produto', 'classificacao', 'mercadoria'],
            'description' => 'Consulta a NCM (Nomenclatura Comum do Mercosul) de um produto pelo código de 8 dígitos.',
            'parameters' => [
                'ncm' => ['type' => 'string', 'description' => 'Código NCM com 8 dígitos.', 'pattern' => '/^[0-9]{8}$/'],
            ],
        ],
        'consultar_cfop' => [
            'keywords' => ['cfop', 'operacao', 'nota fiscal', 'natureza'],
            'description' => 'Consulta o CFOP (código fiscal de operações e prestações) de 4 dígitos.',
            'parameters' => [
                'cfop' => ['type' => 'string', 'description' => 'Código CFOP com 4 dígitos.', 'pattern' => '/^[0-9]{4}$/'],
            ],
        ],
        'validar_cst' => [
            'keywords' => ['cst', 'csosn', 'situacao tributaria'],
            'description' => 'Valida um código CST (regime normal) ou CSOSN (Simples Nacional).',
            'parameters' => [
                'cst' => ['type' => 'string', 'description' => 'Código CST ou CSOSN a validar.', 'pattern' => '/^[0-9]{2,3}$/'],
                'regime' => ['type' => 'string', 'description' => '"normal" ou "simples".', 'enum' => ['normal', 'simples']],
            ],
        ],
        'consultar_cest' => [
            'keywords' => ['cest', 'substituicao tributaria'],
            'description' => 'Consulta o CEST (especificador da substituição tributária) de 7 dígitos.',
            'parameters' => [
                'cest' => ['type' => 'string', 'description' => 'Código CEST com 7 dígitos.', 'pattern' => '/^[0-9]{7}$/'],
            ],
        ],
        'consultar_aliquota_icms' => [
            'keywords' => ['icms', 'aliquota', 'interestadual', 'uf'],
            'description' => 'Consulta as alíquotas de ICMS para operações interestaduais entre duas UFs.',
            'parameters' => [
                'uf_origem' => ['type' => 'string', 'description' => 'Sigla da UF de origem (ex.: SP).', 'pattern' => '/^[A-Z]{2}$/'],
                'uf_destino' => ['type' => 'string', 'description' => 'Sigla da UF de destino (ex.: RJ).', 'pattern' => '/^[A-Z]{2}$/'],
            ],
        ],
        'simular_transicao_reforma_tributaria' => [
            'keywords' => ['reforma', 'ibs', 'cbs', 'transicao', 'iva'],
            'description' => 'Simula o impacto da Reforma Tributária (LC 214/2025, IBS/CBS) ano a ano de 2026 a 2033. É uma estimativa, não uma apuração.',
            'parameters' => [
                'faturamento_anual' => ['type' => 'number', 'description' => 'Receita bruta anual em reais.', 'min' => 0.01],
                'setor' => ['type' => 'string', 'description' => '"comércio", "serviços" ou "indústria".', 'enum' => ['comércio', 'serviços', 'indústria']],
                'regime_atual' => ['type' => 'string', 'description' => '"Simples Nacional", "Lucro Presumido" ou "Lucro Real".', 'enum' => ['Simples Nacional', 'Lucro Presumido', 'Lucro Real']],
            ],
            'required' => ['faturamento_anual', 'setor', 'regime_atual'],
        ],
    ];

    public function has(string $name): bool
    {
        return isset(self::TOOLS[$name]);
    }

    public function label(string $name): string
    {
        return self::LABELS[$name] ?? $name;
    }

    /**
     * Seleciona as ferramentas pelas palavras-chave da pergunta; sem correspondência, envia todas.
     *
     * @return array<int, array<string, mixed>>
     */
    public function select(string $question): array
    {
        $text = Str::ascii(Str::lower($question));

        $names = array_keys(array_filter(
            self::TOOLS,
            fn (array $tool) => collect($tool['keywords'])->contains(fn (string $k) => str_contains($text, $k)),
        ));

        return collect($names ?: array_keys(self::TOOLS))
            ->map(fn (string $name) => $this->schema($name))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(string $name): array
    {
        $tool = self::TOOLS[$name];
        $properties = [];

        foreach ($tool['parameters'] as $param => $spec) {
            $properties[$param] = collect($spec)->only(['type', 'description', 'enum'])->all();
        }

        return [
            'type' => 'function',
            'function' => [
                'name' => $name,
                'description' => $tool['description'],
                'parameters' => [
                    'type' => 'object',
                    'properties' => $properties,
                    'required' => $tool['required'] ?? array_keys($tool['parameters']),
                ],
            ],
        ];
    }

    /**
     * Valida e normaliza os argumentos gerados pelo modelo.
     *
     * @return array{arguments: array<string, mixed>, error: ?string}
     */
    public function validate(string $name, array $arguments): array
    {
        $tool = self::TOOLS[$name];
        $clean = [];

        foreach (($tool['required'] ?? array_keys($tool['parameters'])) as $param) {
            if (! array_key_exists($param, $arguments)) {
                return ['arguments' => [], 'error' => "Parâmetro obrigatório ausente: {$param}."];
            }
        }

        foreach ($tool['parameters'] as $param => $spec) {
            if (! array_key_exists($param, $arguments)) {
                continue;
            }

            $value = $arguments[$param];

            if ($spec['type'] === 'number') {
                if (! is_numeric($value) || (float) $value < ($spec['min'] ?? 0)) {
                    return ['arguments' => [], 'error' => "Valor inválido para {$param}."];
                }
                $clean[$param] = (float) $value;

                continue;
            }

            $value = is_string($value) ? trim($value) : '';

            if (isset($spec['pattern'])) {
                $value = Str::upper(preg_replace('/[.\/\-\s]/', '', $value));
                if (! preg_match($spec['pattern'], $value)) {
                    return ['arguments' => [], 'error' => "Formato inválido para {$param}."];
                }
            }

            if (isset($spec['enum']) && ! in_array($value, $spec['enum'], true)) {
                return ['arguments' => [], 'error' => "Valor não suportado para {$param}."];
            }

            $clean[$param] = $value;
        }

        return ['arguments' => $clean, 'error' => null];
    }

    /**
     * Remove dados pessoais de terceiros recursivamente antes de qualquer uso ou armazenamento.
     */
    public function redact(mixed $data): mixed
    {
        if (! is_array($data)) {
            return $data;
        }

        $out = [];

        foreach ($data as $key => $value) {
            if (is_string($key) && in_array(Str::lower($key), self::OMITTED_KEYS, true)) {
                continue;
            }

            $out[$key] = $this->redact($value);
        }

        return $out;
    }
}
