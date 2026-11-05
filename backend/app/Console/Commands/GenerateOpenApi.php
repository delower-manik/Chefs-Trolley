<?php

namespace App\Console\Commands;

use App\Services\OpenApi\OpenApiGenerator;
use Illuminate\Console\Command;

class GenerateOpenApi extends Command
{
    protected $signature = 'openapi:generate {--check : Fail if docs/openapi.json is out of date instead of writing it}';

    protected $description = 'Generate docs/openapi.json from the router';

    public function handle(OpenApiGenerator $gen): int
    {
        $json = json_encode($gen->build(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
        $file = base_path('docs/openapi.json');

        if ($this->option('check')) {
            if (! is_file($file) || file_get_contents($file) !== $json) {
                $this->error('docs/openapi.json is out of date. Run: php artisan openapi:generate');

                return self::FAILURE;
            }
            $this->info('docs/openapi.json is up to date.');

            return self::SUCCESS;
        }

        @mkdir(dirname($file), 0755, true);
        file_put_contents($file, $json);
        $this->info('Wrote docs/openapi.json ('.count($gen->apiRoutes()).' routes).');

        return self::SUCCESS;
    }
}
