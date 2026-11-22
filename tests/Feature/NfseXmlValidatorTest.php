<?php

namespace Tests\Feature;

use App\Services\Fiscal\NfseXmlValidator;
use Tests\TestCase;

class NfseXmlValidatorTest extends TestCase
{
    public function test_ignores_text_without_nfse(): void
    {
        $this->assertNull(app(NfseXmlValidator::class)->validate('Qual o NCM do café?'));
    }

    public function test_reports_truncated_xml(): void
    {
        $result = app(NfseXmlValidator::class)->validate(
            '<NFSe xmlns="http://www.sped.fazenda.gov.br/nfse" versao="1.00"><infNFSe Id="NFS'.str_repeat('0', 50).'">'
        );

        $this->assertStringContainsString('malformado', $result);
    }

    public function test_reports_schema_errors_for_incomplete_nfse(): void
    {
        $xml = '<NFSe xmlns="http://www.sped.fazenda.gov.br/nfse" versao="1.00">'
            .'<infNFSe Id="NFS'.str_repeat('0', 50).'"><xLocEmi>X</xLocEmi></infNFSe></NFSe>';

        $result = app(NfseXmlValidator::class)->validate($xml);

        $this->assertStringContainsString('inválido', $result);
        $this->assertStringContainsString('xLocPrestacao', $result);
    }

    public function test_reports_schema_errors_for_incomplete_dps(): void
    {
        $xml = '<DPS xmlns="http://www.sped.fazenda.gov.br/nfse" versao="1.00">'
            .'<infDPS Id="DPS'.str_repeat('0', 42).'"></infDPS></DPS>';

        $result = app(NfseXmlValidator::class)->validate($xml);

        $this->assertStringContainsString('inválido', $result);
        $this->assertStringContainsString('DPS', $result);
    }

    public function test_accepts_valid_dps(): void
    {
        $xml = file_get_contents(base_path('tests/Fixtures/dps_valida.xml'));

        $result = app(NfseXmlValidator::class)->validate($xml);

        $this->assertStringContainsString('válido', $result);
    }

    public function test_reports_missing_ctribnac_without_hint_comments(): void
    {
        $xml = str_replace(
            '<cTribNac>060401</cTribNac>',
            '',
            file_get_contents(base_path('tests/Fixtures/dps_valida.xml')),
        );

        $result = app(NfseXmlValidator::class)->validate($xml);

        $this->assertStringContainsString('inválido', $result);
        $this->assertStringContainsString('cTribNac', $result);
        $this->assertStringContainsString('não NCM', $result);
        $this->assertStringContainsString('insira cTribNac', $result);
        $this->assertStringContainsString('Não remova xDescServ', $result);
        $this->assertStringContainsString('linha', $result);
        $this->assertStringNotContainsString('Expected is ( cTribNac )', $result);
    }

    public function test_validates_nested_dps_through_the_nfse_schema(): void
    {
        $xml = file_get_contents(base_path('tests/Fixtures/nfse_com_dps.xml'));

        $result = app(NfseXmlValidator::class)->validate($xml);

        $this->assertStringContainsString('inválido', $result);
        $this->assertStringContainsString('cTribNac', $result);
        $this->assertSame(1, substr_count($result, 'renomeie ender para end'));
        $this->assertStringContainsString('NFS[0-9]{50}', $result);
        $this->assertStringNotContainsString("Element 'ender'", $result);
        $this->assertStringNotContainsString('No matching global declaration', $result);
    }
}
