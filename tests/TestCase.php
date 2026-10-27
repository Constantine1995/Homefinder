<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Str;

abstract class TestCase extends BaseTestCase
{
    /**
     * Render pages without the built frontend: tests check what the server sends, and CI does not build assets.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    /**
     * Get the path of a new temporary file with the extension; the file is deleted when the test ends.
     */
    public function temporaryPath(string $extension): string
    {
        $path = sys_get_temp_dir().'/test-'.Str::uuid().".{$extension}";

        $this->beforeApplicationDestroyed(function () use ($path): void {
            if (is_file($path)) {
                unlink($path);
            }
        });

        return $path;
    }

    /**
     * Free the test's application right away. It references itself, so PHP frees it only when
     * the cycle collector runs, and every application keeps Faker's real-text chains (about 10 MB),
     * which made the suite outgrow the memory limit.
     */
    protected function tearDown(): void
    {
        parent::tearDown();

        gc_collect_cycles();
    }
}
