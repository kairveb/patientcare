<?php

namespace Tests\Feature;

use Tests\TestCase;

class BootstrapOutputTest extends TestCase
{
    public function test_application_config_does_not_emit_output_before_php_open_tag(): void
    {
        $contents = file_get_contents(config_path('app.php'));

        $this->assertIsString($contents);
        $this->assertStringStartsWith('<?php', $contents);
    }
}
