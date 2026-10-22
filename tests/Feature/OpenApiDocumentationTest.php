<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use L5Swagger\CustomGeneratorInterface;
use L5Swagger\GeneratorFactory;
use OpenApi\Generator;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

final class OpenApiDocumentationTest extends TestCase
{
    private function document(): array
    {
        return json_decode(file_get_contents(base_path('docs/openapi/openapi.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_ui_document_and_referenced_package_assets_are_published(): void
    {
        $ui = $this->get('/docs')->assertOk()->assertHeader('Content-Type', 'text/html; charset=utf-8');
        $ui->assertSee('SwaggerUIBundle', false)->assertSee('validatorUrl: null', false)->assertSee('persistAuthorization: false', false);
        self::assertStringNotContainsString('oauth2', $ui->getContent());
        self::assertFalse(Route::has('l5-swagger.default.oauth2_callback'));

        $document = $this->get('/docs/openapi.json')->assertOk()->assertHeader('Content-Type', 'application/json');
        self::assertSame(file_get_contents(base_path('docs/openapi/openapi.json')), $document->getContent());
        self::assertFalse(config('l5-swagger.defaults.generate_always'));

        preg_match_all('/(?:src|href)="([^"]+)"/', $ui->getContent(), $matches);
        self::assertCount(4, $matches[1]);
        foreach ($matches[1] as $url) {
            $url = html_entity_decode($url);
            self::assertStringStartsWith('/docs/openapi.json/asset/', $url);
            $asset = basename(parse_url($url, PHP_URL_PATH));
            $type = match (pathinfo($asset, PATHINFO_EXTENSION)) {
                'js' => 'application/javascript', 'css' => 'text/css', 'png' => 'image/png',
            };
            $response = $this->get($url)->assertOk();
            self::assertSame($type, explode(';', $response->headers->get('Content-Type'))[0]);
            self::assertSame(file_get_contents(swagger_ui_dist_path('default', $asset)), $response->getContent());
        }
    }

    public function test_a_missing_document_is_not_regenerated_by_a_request(): void
    {
        $directory = sys_get_temp_dir().'/prex-openapi-'.bin2hex(random_bytes(8));
        config(['l5-swagger.defaults.paths.docs' => $directory]);
        $this->get('/docs/openapi.json')->assertNotFound();
        self::assertDirectoryDoesNotExist($directory);
    }

    public function test_generation_validates_without_warnings_and_matches_the_versioned_artifact(): void
    {
        $logger = new class extends AbstractLogger
        {
            public array $issues = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                if (in_array($level, ['warning', 'error', 'critical', 'alert', 'emergency'], true)) {
                    $this->issues[] = (string) $message;
                }
            }
        };
        $factory = new class($logger) implements CustomGeneratorInterface
        {
            public function __construct(private LoggerInterface $logger) {}

            public function create(): Generator
            {
                // Generator::generate validates the analysis before saving the document.
                return new Generator($this->logger);
            }
        };
        $directory = sys_get_temp_dir().'/prex-openapi-'.bin2hex(random_bytes(8));
        config([
            'l5-swagger.defaults.paths.docs' => $directory,
            'l5-swagger.defaults.scanOptions.generator_factory' => $factory,
        ]);

        try {
            app(GeneratorFactory::class)->make('default')->generateDocs();
            self::assertSame([], $logger->issues, implode("\n", $logger->issues));
            self::assertFileEquals(base_path('docs/openapi/openapi.json'), $directory.'/openapi.json');
        } finally {
            File::deleteDirectory($directory);
        }
    }

    public function test_all_references_resolve_within_the_document(): void
    {
        $document = $this->document();
        $references = 0;
        $walk = function (array $node) use (&$walk, &$references, $document): void {
            if (isset($node['$ref'])) {
                $references++;
                self::assertStringStartsWith('#/', $node['$ref']);
                $target = $document;
                foreach (explode('/', substr($node['$ref'], 2)) as $segment) {
                    $key = str_replace(['~1', '~0'], ['/', '~'], $segment);
                    self::assertArrayHasKey($key, $target, $node['$ref']);
                    $target = $target[$key];
                }
            }
            foreach ($node as $value) {
                if (is_array($value)) {
                    $walk($value);
                }
            }
        };
        $walk($document);
        self::assertGreaterThan(0, $references);
    }

    public function test_operations_and_security_match_the_registered_v1_routes(): void
    {
        $document = $this->document();
        self::assertSame('3.0.0', $document['openapi']);
        self::assertSame('/', $document['servers'][0]['url']);
        $scheme = $document['components']['securitySchemes'];
        self::assertSame(['bearerAuth'], array_keys($scheme));
        self::assertSame('http', $scheme['bearerAuth']['type']);
        self::assertSame('bearer', $scheme['bearerAuth']['scheme']);
        self::assertArrayNotHasKey('bearerFormat', $scheme['bearerAuth']);
        self::assertArrayNotHasKey('flows', $scheme['bearerAuth']);

        $registered = [];
        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1/')) {
                continue; // Health, docs and auxiliary test routes are outside the API contract.
            }
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $key = strtolower($method).' /'.$route->uri();
                $registered[$key] = in_array('auth:api', $route->gatherMiddleware(), true);
            }
        }
        $documented = [];
        $operationIds = [];
        foreach ($document['paths'] as $path => $item) {
            foreach ($item as $method => $operation) {
                if (! in_array($method, ['get', 'post', 'put', 'patch', 'delete', 'options', 'head', 'trace'], true)) {
                    continue;
                }
                $key = $method.' '.$path;
                self::assertArrayHasKey($key, $registered);
                $protected = $registered[$key];
                self::assertSame($protected ? [['bearerAuth' => []]] : [], $operation['security']);
                $documented[$key] = $protected;
                $operationIds[] = $operation['operationId'];
            }
        }
        ksort($registered);
        ksort($documented);
        self::assertCount(4, $registered);
        self::assertSame($registered, $documented);
        self::assertCount(4, array_unique($operationIds));
        self::assertFalse($registered['post /api/v1/login']);
    }

    public function test_password_and_access_token_have_no_examples_or_defaults(): void
    {
        $schemas = $this->document()['components']['schemas'];
        foreach ([$schemas['LoginInput']['properties']['password'], $schemas['LoginResult']['properties']['access_token']] as $property) {
            self::assertArrayNotHasKey('example', $property);
            self::assertArrayNotHasKey('default', $property);
        }
    }
}
