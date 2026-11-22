<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use PDO;
use Tests\TestCase;

class ImportCestTest extends TestCase
{
    private string $db;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = sys_get_temp_dir().'/cest-test-'.uniqid().'.db';
        $pdo = new PDO('sqlite:'.$this->db);
        $pdo->exec('CREATE TABLE cest (cest TEXT PRIMARY KEY, descricao TEXT NOT NULL, segmento TEXT NOT NULL)');
        $pdo->exec('CREATE TABLE cest_ncm (cest TEXT NOT NULL, ncm TEXT NOT NULL, PRIMARY KEY (cest, ncm))');
        $pdo->exec("INSERT INTO cest VALUES ('9900000', 'amostra antiga', '99')");

        config(['services.mcp_fiscal.tabelas_db' => $this->db]);
    }

    protected function tearDown(): void
    {
        File::delete(glob($this->db.'*'));

        parent::tearDown();
    }

    public function test_imports_valid_csv(): void
    {
        $csv = $this->csv("cest,ncm,descricao,segmento\n0100100,38151200,Catalisadores,01\n0100100,38151900,Catalisadores,01\n");

        $this->artisan('fiscal:import-cest', ['--file' => $csv])->assertSuccessful();

        $pdo = new PDO('sqlite:'.$this->db);
        $this->assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM cest')->fetchColumn());
        $this->assertSame(2, (int) $pdo->query('SELECT COUNT(*) FROM cest_ncm')->fetchColumn());
    }

    public function test_invalid_csv_imports_nothing_and_keeps_existing_table(): void
    {
        $csv = $this->csv("cest,ncm,descricao,segmento\n0100100,38151200,Ok,01\n0100X00,38151900,Ruim,01\n");

        $this->artisan('fiscal:import-cest', ['--file' => $csv])->assertFailed();

        $pdo = new PDO('sqlite:'.$this->db);
        $this->assertSame('9900000', $pdo->query('SELECT cest FROM cest')->fetchColumn());
    }

    private function csv(string $content): string
    {
        $path = sys_get_temp_dir().'/cest-'.uniqid().'.csv';
        file_put_contents($path, $content);

        return $path;
    }
}
