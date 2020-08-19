<?php

namespace Nipwaayoni\ElasticApmLaravel\Providers;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\App;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Support\Arr;
use Nipwaayoni\Agent;
use Nipwaayoni\AgentBuilder;
use Nipwaayoni\Config;
use Nipwaayoni\ElasticApmLaravel\Contracts\VersionResolver;
use Nipwaayoni\Helper\Timer;
use Psr\Container\ContainerInterface;

class ElasticApmServiceProvider extends ServiceProvider
{
    /** @var float */
    private $startTime;
    /** @var string  */
    private $sourceConfigPath = __DIR__ . '/../config/elastic-apm.php';

    /**
     * Bootstrap the application services.
     *
     * @return void
     */
    public function boot()
    {
        if (class_exists('Illuminate\Foundation\Application', false)) {
            $this->publishes([
                realpath($this->sourceConfigPath) => config_path('elastic-apm.php'),
            ], 'config');
        }

        if (config('elastic-apm.active') === true && config('elastic-apm.spans.querylog.enabled') !== false) {
            $this->listenForQueries();
        }
    }

    /**
     * Register the application services.
     *
     * @return void
     */
    public function register()
    {
        $this->mergeConfigFrom(
            realpath($this->sourceConfigPath),
            'elastic-apm'
        );

        $this->app->singleton(Agent::class, function () {
            $builder = app::make(AgentBuilder::class);

            $builder->withConfig(new Config(
                array_merge(
                    [
                        'active' => config('elastic-apm.active'),
                        'framework' => 'Laravel',
                        'frameworkVersion' => app()->version(),
                        'environment' => config('elastic-apm.environment'),
                    ],
                    $this->getAppConfig(),
                    config('elastic-apm.server')
                )
            ));

            $builder->withEnvData(config('elastic-apm.env'));

            return $builder->build();
        });

        $this->startTime = $this->app['request']->server('REQUEST_TIME_FLOAT') ?? microtime(true);
        $this->app->instance(Timer::class, new Timer($this->startTime));

        $this->app->alias(Agent::class, 'elastic-apm');

        $this->app->instance('query-log', new Collection());
    }

    /**
     * @return array
     */
    protected function getAppConfig(): array
    {
        $config = config('elastic-apm.app');

        if ($this->app->bound(VersionResolver::class)) {
            $config['appVersion'] = $this->app->make(VersionResolver::class)->getVersion();
        }

        return $config;
    }

    /**
     * @param Collection $stackTrace
     * @return Collection
     */
    protected function stripVendorTraces(Collection $stackTrace): Collection
    {
        return collect($stackTrace)->filter(function ($trace) {
            return !Str::startsWith((Arr::get($trace, 'file')), [
                base_path() . '/vendor',
            ]);
        });
    }

    /**
     * @param array $stackTrace
     * @return Collection
     */
    protected function getSourceCode(array $stackTrace): Collection
    {
        if (config('elastic-apm.spans.renderSource', false) === false) {
            return collect([]);
        }

        if (empty(Arr::get($stackTrace, 'file'))) {
            return collect([]);
        }

        $fileLines = file(Arr::get($stackTrace, 'file'));
        return collect($fileLines)->filter(function ($code, $line) use ($stackTrace) {
            //file starts counting from 0, debug_stacktrace from 1
            $stackTraceLine = Arr::get($stackTrace, 'line') - 1;

            $lineStart = $stackTraceLine - 5;
            $lineStop = $stackTraceLine + 5;

            return $line >= $lineStart && $line <= $lineStop;
        })->groupBy(function ($code, $line) use ($stackTrace) {
            if ($line < Arr::get($stackTrace, 'line')) {
                return 'pre_context';
            }

            if ($line == Arr::get($stackTrace, 'line')) {
                return 'context_line';
            }

            if ($line > Arr::get($stackTrace, 'line')) {
                return 'post_context';
            }

            return 'trash';
        });
    }

    protected function listenForQueries()
    {
        $this->app->events->listen(QueryExecuted::class, function (QueryExecuted $query) {
            if (config('elastic-apm.spans.querylog.enabled') === 'auto') {
                if ($query->time < config('elastic-apm.spans.querylog.threshold')) {
                    return;
                }
            }

            $stackTrace = $this->stripVendorTraces(
                collect(
                    debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT, config('elastic-apm.spans.backtraceDepth', 50))
                )
            );

            $stackTrace = $stackTrace->map(function ($trace) {
                $sourceCode = $this->getSourceCode($trace);

                return [
                    'function' => Arr::get($trace, 'function') . Arr::get($trace, 'type') . Arr::get(
                        $trace,
                        'function'
                    ),
                    'abs_path' => Arr::get($trace, 'file'),
                    'filename' => basename(Arr::get($trace, 'file')),
                    'lineno' => Arr::get($trace, 'line', 0),
                    'library_frame' => false,
                    'vars' => $vars ?? null,
                    'pre_context' => optional($sourceCode->get('pre_context'))->toArray(),
                    'context_line' => optional($sourceCode->get('context_line'))->first(),
                    'post_context' => optional($sourceCode->get('post_context'))->toArray(),
                ];
            })->values();

            $query = [
                'name' => 'Eloquent Query',
                'type' => 'db.mysql.query',
                // calculate start time from duration, best we can do
                'start' => microtime(true) - ($query->time / 1000),
                'duration' => round($query->time, 3),
                'stacktrace' => $stackTrace,
                'context' => [
                    'db' => [
                        'instance' => $query->connection->getDatabaseName(),
                        'statement' => $query->sql,
                        'type' => 'sql',
                        'user' => $query->connection->getConfig('username'),
                    ],
                ],
            ];

            app('query-log')->push($query);
        });
    }
}
