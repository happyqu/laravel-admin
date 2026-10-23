<?php

namespace Encore\Admin\Tests\Grid;

use Encore\Admin\Grid\Exporters\CsvExporter;
use PHPUnit\Framework\TestCase;

class CsvExporterTest extends TestCase
{
    public function test_formula_prefixes_are_escaped(): void
    {
        $exporter = new class extends CsvExporter {
            public function sanitize($value)
            {
                return $this->sanitizeCell($value);
            }
        };

        $this->assertSame("'=1+1", $exporter->sanitize('=1+1'));
        $this->assertSame("'+SUM(A1)", $exporter->sanitize('+SUM(A1)'));
        $this->assertSame("'@cmd", $exporter->sanitize('@cmd'));
        $this->assertSame('-1.5', $exporter->sanitize('-1.5'));
        $this->assertSame('plain', $exporter->sanitize('plain'));
    }

    public function test_array_values_are_json_encoded(): void
    {
        $exporter = new class extends CsvExporter {
            public function sanitize($value)
            {
                return $this->sanitizeCell($value);
            }
        };

        $this->assertSame('{"a":"b"}', $exporter->sanitize(['a' => 'b']));
    }
}
