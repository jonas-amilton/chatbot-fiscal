<?php

namespace App\Services\Fiscal;

use DOMDocument;

class NfseXmlValidator
{
    private const NAMESPACE = 'sped.fazenda.gov.br/nfse';

    private const MAX_ERRORS = 10;

    private const ROOTS = ['NFSe', 'DPS'];

    private const HINTS = [
        'cTribNac' => 'Correção: falta cTribNac (Código de Tributação Nacional do serviço, 6 dígitos, não NCM) antes de xDescServ. Não remova xDescServ: insira cTribNac antes dele; os dois coexistem.',
        'cNBS' => 'cNBS é o código da NBS (serviços), não NCM.',
        'ender' => 'No grupo toma, o elemento correto é end (não ender): renomeie ender para end e mantenha o conteúdo.',
    ];

    /**
     * Valida o último XML (NFSe ou DPS) do padrão nacional presente no texto.
     * Retorna null quando não há XML para validar.
     */
    public function validate(string $text): ?string
    {
        if (! str_contains($text, self::NAMESPACE)) {
            return null;
        }

        $found = $this->locate($text);

        if ($found === null) {
            return null;
        }

        $root = $found['root'];
        $xml = $this->extract($text, $root, $found['pos']);

        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        try {
            if (! $dom->loadXML($xml)) {
                return 'XML malformado: '.$this->errors();
            }

            $version = $dom->documentElement?->getAttribute('versao') ?: '1.00';
            $schema = resource_path("schemas/nfse/{$version}/{$root}_v{$version}.xsd");

            if (! is_file($schema)) {
                return "XML bem formado; não há XSD disponível para {$root} v{$version}.";
            }

            if ($dom->schemaValidate($schema)) {
                return "XML válido segundo o XSD oficial da {$root} v{$version}.";
            }

            return "XML inválido segundo o XSD oficial da {$root} v{$version}: ".$this->errors();
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /**
     * @return array{root: string, pos: int}|null
     */
    private function locate(string $text): ?array
    {
        $nfse = strrpos($text, '<NFSe');
        $dps = strrpos($text, '<DPS');

        if ($nfse !== false && $dps !== false) {
            $nfseClose = strpos($text, '</NFSe>', $nfse);

            // DPS dentro da NFSe herda o xmlns do documento: valida a NFSe inteira.
            if ($nfse < $dps && ($nfseClose === false || $dps < $nfseClose)) {
                return ['root' => 'NFSe', 'pos' => $nfse];
            }

            return $dps > $nfse
                ? ['root' => 'DPS', 'pos' => $dps]
                : ['root' => 'NFSe', 'pos' => $nfse];
        }

        if ($nfse !== false) {
            return ['root' => 'NFSe', 'pos' => $nfse];
        }

        if ($dps !== false) {
            return ['root' => 'DPS', 'pos' => $dps];
        }

        return null;
    }

    private function extract(string $text, string $root, int $start): string
    {
        $close = '</'.$root.'>';
        $end = strpos($text, $close, $start);

        return $end === false
            ? substr($text, $start)
            : substr($text, $start, $end + strlen($close) - $start);
    }

    private function errors(): string
    {
        $errors = collect(libxml_get_errors())
            ->map(fn ($error) => [
                'tag' => $this->tag($error->message),
                'line' => $error->line + 1,
                'message' => trim(str_replace(
                    ['{http://www.sped.fazenda.gov.br/nfse}', '{http://www.w3.org/2000/09/xmldsig#}'],
                    ['', 'ds:'],
                    $error->message,
                )),
            ])
            ->filter(fn (array $error) => $error['message'] !== '');

        $lines = [];

        foreach ($errors as $error) {
            $tag = $error['tag'];

            // O erro do xDescServ é, na prática, a falta do cTribNac.
            if (str_contains($error['message'], 'xDescServ') && str_contains($error['message'], 'cTribNac')) {
                $tag = 'cTribNac';
            }

            if (isset(self::HINTS[$tag])) {
                $lines[$tag] = self::HINTS[$tag].' (linha '.$error['line'].')';

                continue;
            }

            $lines[$tag.'|'.$error['message']] ??= $error['message'].' (linha '.$error['line'].')';
        }

        $lines = array_slice(array_values(array_unique($lines)), 0, self::MAX_ERRORS);

        return $lines !== [] ? implode("\n", $lines) : 'erro desconhecido';
    }

    private function tag(string $message): string
    {
        return preg_match("/Element '(?:\{[^}]+\})?([^']+)'/", $message, $match)
            ? $match[1]
            : '';
    }
}
