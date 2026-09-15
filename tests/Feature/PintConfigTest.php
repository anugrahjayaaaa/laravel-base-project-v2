<?php

namespace Tests\Feature;

use Tests\TestCase;

class PintConfigTest extends TestCase
{
    public function test_pint_config_file_exists_and_is_valid_json(): void
    {
        $path = base_path('pint.json');

        $this->assertFileExists($path);

        $contents = file_get_contents($path);

        $config = json_decode($contents, true);

        $this->assertIsArray($config);
        $this->assertEquals('psr12', $config['preset']);
    }

    public function test_pint_executable_available(): void
    {
        $this->assertFileExists(base_path('vendor/bin/pint'));
    }
}
