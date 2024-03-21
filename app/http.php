<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Appwrite\Utopia\Response;
use Swoole\Process;
use Swoole\Http\Server;
use Swoole\Http\Request as SwooleRequest;
use Swoole\Http\Response as SwooleResponse;
use Utopia\App;
use Utopia\CLI\Console;
use Utopia\Config\Config;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Validator\Authorization;
use Utopia\Audit\Audit;
use Utopia\Abuse\Adapters\TimeLimit;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Swoole\Files;
use Appwrite\Utopia\Request;
use Swoole\Timer;
use Utopia\Database\DateTime;
use Utopia\Database\Query;
use Utopia\Logger\Log;
use Utopia\Logger\Log\User;
use Utopia\Pools\Group;

const DOMAIN_SYNC_TIMER = 30; // 30 seconds

$domains = [];
$lastSyncUpdate = null;

$http = new Server("0.0.0.0", App::getEnv('PORT', 80), SWOOLE_PROCESS);

$payloadSize = 6 * (1024 * 1024); // 6MB
$workerNumber = swoole_cpu_num() * intval(App::getEnv('_APP_WORKER_PER_CORE', 6));

$http
    ->set([
        'worker_num' => $workerNumber,
        'open_http2_protocol' => true,
        // 'document_root' => __DIR__.'/../public',
        // 'enable_static_handler' => true,
        'http_compression' => true,
        'dispatch_func' => 'dispatch',
        'http_compression_level' => 6,
        'package_max_length' => $payloadSize,
        'buffer_output_size' => $payloadSize,
    ]);

function dispatch(Server $server, $fd, $type, $data = null)
{
    global $workerNumber, $domains;

    $safeThreadsPercent = intval(App::getEnv('_APP_SAFE_THREADS_PERCENT', 80)) / 100;

    $safeThreads = (int) floor($workerNumber * $safeThreadsPercent);

    $risky = false;
    if ($data && str_contains($data, 'POST') && str_contains($data, '/executions')) {
        $risky = true;
    } elseif ($data && str_contains($data, '.appwrite.global')) {
        $risky = true;
    } elseif ($data) {
        $lines = explode($data, '\n');
        if (count($lines) > 2) {
            $host = trim(explode('Host: ', $lines[1])[1]);
            if (array_key_exists($host, $domains)) {
                $risky = true;
            }
        }
    }

    if ($risky) {
        var_dump('execution request sending to unsafe thread');
        for ($j = $safeThreads; $j < $workerNumber; $j++) {
            if ($server->getWorkerStatus($j) === 2) {
                return $j;
            }
        }
        return rand($safeThreads, $workerNumber - 1);
    }

    for ($i = 0; $i < $workerNumber; $i++) {
        if ($server->getWorkerStatus($i) === 2) {
            return $i;
        }
    }
    return rand(0, $safeThreads - 1);
}

$http->on('WorkerStart', function ($server, $workerId) {
    Console::success('Worker ' . ++$workerId . ' started successfully');
});

$http->on('BeforeReload', function ($server, $workerId) {
    Console::success('Starting reload...');
});

$http->on('AfterReload', function ($server, $workerId) {
    Console::success('Reload completed...');
});

Files::load(__DIR__ . '/../console');

include __DIR__ . '/controllers/general.php';

$http->on('start', function (Server $http) use ($payloadSize, $register, &$domains, &$lastSyncUpdate) {
    $app = new App('UTC');

    go(function () use ($register, $app, &$domains, &$lastSyncUpdate) {
        $pools = $register->get('pools');
        /** @var Group $pools */
        App::setResource('pools', fn () => $pools);

        // wait for database to be ready
        $attempts = 0;
        $max = 10;
        $sleep = 1;

        do {
            try {
                $attempts++;
                $dbForConsole = $app->getResource('dbForConsole');
                /** @var Utopia\Database\Database $dbForConsole */
                break; // leave the do-while if successful
            } catch (\Exception $e) {
                Console::warning("Database not ready. Retrying connection ({$attempts})...");
                if ($attempts >= $max) {
                    throw new \Exception('Failed to connect to database: ' . $e->getMessage());
                }
                sleep($sleep);
            }
        } while ($attempts < $max);

        Console::success('[Setup] - Server database init started...');

        try {
            Console::success('[Setup] - Creating database: appwrite...');
            $dbForConsole->create();
        } catch (\Exception $e) {
            Console::success('[Setup] - Skip: metadata table already exists');
        }

        if ($dbForConsole->getCollection(Audit::COLLECTION)->isEmpty()) {
            $audit = new Audit($dbForConsole);
            $audit->setup();
        }

        if ($dbForConsole->getCollection(TimeLimit::COLLECTION)->isEmpty()) {
            $adapter = new TimeLimit("", 0, 1, $dbForConsole);
            $adapter->setup();
        }

        /** @var array $collections */
        $collections = Config::getParam('collections', []);
        $consoleCollections = $collections['console'];
        foreach ($consoleCollections as $key => $collection) {
            if (($collection['$collection'] ?? '') !== Database::METADATA) {
                continue;
            }
            if (!$dbForConsole->getCollection($key)->isEmpty()) {
                continue;
            }

            Console::success('[Setup] - Creating collection: ' . $collection['$id'] . '...');

            $attributes = [];
            $indexes = [];

            foreach ($collection['attributes'] as $attribute) {
                $attributes[] = new Document([
                    '$id' => ID::custom($attribute['$id']),
                    'type' => $attribute['type'],
                    'size' => $attribute['size'],
                    'required' => $attribute['required'],
                    'signed' => $attribute['signed'],
                    'array' => $attribute['array'],
                    'filters' => $attribute['filters'],
                    'default' => $attribute['default'] ?? null,
                    'format' => $attribute['format'] ?? ''
                ]);
            }

            foreach ($collection['indexes'] as $index) {
                $indexes[] = new Document([
                    '$id' => ID::custom($index['$id']),
                    'type' => $index['type'],
                    'attributes' => $index['attributes'],
                    'lengths' => $index['lengths'],
                    'orders' => $index['orders'],
                ]);
            }

            $dbForConsole->createCollection($key, $attributes, $indexes);
        }

        if ($dbForConsole->getDocument('buckets', 'default')->isEmpty() && !$dbForConsole->exists($dbForConsole->getDefaultDatabase(), 'bucket_1')) {
            Console::success('[Setup] - Creating default bucket...');
            $dbForConsole->createDocument('buckets', new Document([
                '$id' => ID::custom('default'),
                '$collection' => ID::custom('buckets'),
                'name' => 'Default',
                'maximumFileSize' => (int) App::getEnv('_APP_STORAGE_LIMIT', 0), // 10MB
                'allowedFileExtensions' => [],
                'enabled' => true,
                'compression' => 'gzip',
                'encryption' => true,
                'antivirus' => true,
                'fileSecurity' => true,
                '$permissions' => [
                    Permission::create(Role::any()),
                    Permission::read(Role::any()),
                    Permission::update(Role::any()),
                    Permission::delete(Role::any()),
                ],
                'search' => 'buckets Default',
            ]));

            $bucket = $dbForConsole->getDocument('buckets', 'default');

            Console::success('[Setup] - Creating files collection for default bucket...');
            $files = $collections['buckets']['files'] ?? [];
            if (empty($files)) {
                throw new Exception('Files collection is not configured.');
            }

            $attributes = [];
            $indexes = [];

            foreach ($files['attributes'] as $attribute) {
                $attributes[] = new Document([
                    '$id' => ID::custom($attribute['$id']),
                    'type' => $attribute['type'],
                    'size' => $attribute['size'],
                    'required' => $attribute['required'],
                    'signed' => $attribute['signed'],
                    'array' => $attribute['array'],
                    'filters' => $attribute['filters'],
                    'default' => $attribute['default'] ?? null,
                    'format' => $attribute['format'] ?? ''
                ]);
            }

            foreach ($files['indexes'] as $index) {
                $indexes[] = new Document([
                    '$id' => ID::custom($index['$id']),
                    'type' => $index['type'],
                    'attributes' => $index['attributes'],
                    'lengths' => $index['lengths'],
                    'orders' => $index['orders'],
                ]);
            }

            $dbForConsole->createCollection('bucket_' . $bucket->getInternalId(), $attributes, $indexes);
        }

        getDomains($dbForConsole, $lastSyncUpdate, $domains, $pools);

        $pools->reclaim();

        Console::success('[Setup] - Server database init completed...');
    });

    Console::success('Server started successfully (max payload is ' . number_format($payloadSize) . ' bytes)');
    Console::info("Master pid {$http->master_pid}, manager pid {$http->manager_pid}");

    // listen ctrl + c
    Process::signal(2, function () use ($http) {
        Console::log('Stop by Ctrl+C');
        $http->shutdown();
    });
});

$http->on('request', function (SwooleRequest $swooleRequest, SwooleResponse $swooleResponse) use ($register) {
    App::setResource('swooleRequest', fn () => $swooleRequest);
    App::setResource('swooleResponse', fn () => $swooleResponse);
    $request = new Request($swooleRequest);
    $response = new Response($swooleResponse);

    if (Files::isFileLoaded($request->getURI())) {
        $time = (60 * 60 * 24 * 365 * 2); // 45 days cache

        $response
            ->setContentType(Files::getFileMimeType($request->getURI()))
            ->addHeader('Cache-Control', 'public, max-age=' . $time)
            ->addHeader('Expires', \date('D, d M Y H:i:s', \time() + $time) . ' GMT') // 45 days cache
            ->send(Files::getFileContents($request->getURI()));

        return;
    }

    $app = new App('UTC');

    $pools = $register->get('pools');
    App::setResource('pools', fn () => $pools);

    try {
        Authorization::cleanRoles();
        Authorization::setRole(Role::any()->toString());

        $app->run($request, $response);
    } catch (\Throwable $th) {
        $version = App::getEnv('_APP_VERSION', 'UNKNOWN');

        $logger = $app->getResource("logger");
        if ($logger) {
            try {
                /** @var Utopia\Database\Document $user */
                $user = $app->getResource('user');
            } catch (\Throwable $_th) {
                // All good, user is optional information for logger
            }

            $route = $app->getRoute();

            $log = $app->getResource("log");

            if (isset($user) && !$user->isEmpty()) {
                $log->setUser(new User($user->getId()));
            }

            $log->setNamespace("http");
            $log->setServer(\gethostname());
            $log->setVersion($version);
            $log->setType(Log::TYPE_ERROR);
            $log->setMessage($th->getMessage());

            $log->addTag('method', $route->getMethod());
            $log->addTag('url', $route->getPath());
            $log->addTag('verboseType', get_class($th));
            $log->addTag('code', $th->getCode());
            // $log->addTag('projectId', $project->getId()); // TODO: Figure out how to get ProjectID, if it becomes relevant
            $log->addTag('hostname', $request->getHostname());
            $log->addTag('locale', (string)$request->getParam('locale', $request->getHeader('x-appwrite-locale', '')));

            $log->addExtra('file', $th->getFile());
            $log->addExtra('line', $th->getLine());
            $log->addExtra('trace', $th->getTraceAsString());
            $log->addExtra('detailedTrace', $th->getTrace());
            $log->addExtra('roles', Authorization::getRoles());

            $action = $route->getLabel("sdk.namespace", "UNKNOWN_NAMESPACE") . '.' . $route->getLabel("sdk.method", "UNKNOWN_METHOD");
            $log->setAction($action);

            $isProduction = App::getEnv('_APP_ENV', 'development') === 'production';
            $log->setEnvironment($isProduction ? Log::ENVIRONMENT_PRODUCTION : Log::ENVIRONMENT_STAGING);

            $responseCode = $logger->addLog($log);
            Console::info('Log pushed with status code: ' . $responseCode);
        }

        Console::error('[Error] Type: ' . get_class($th));
        Console::error('[Error] Message: ' . $th->getMessage());
        Console::error('[Error] File: ' . $th->getFile());
        Console::error('[Error] Line: ' . $th->getLine());

        $swooleResponse->setStatusCode(500);

        $output = ((App::isDevelopment())) ? [
            'message' => 'Error: ' . $th->getMessage(),
            'code' => 500,
            'file' => $th->getFile(),
            'line' => $th->getLine(),
            'trace' => $th->getTrace(),
            'version' => $version,
        ] : [
            'message' => 'Error: Server Error',
            'code' => 500,
            'version' => $version,
        ];

        $swooleResponse->end(\json_encode($output));
    } finally {
        $pools->reclaim();
    }
});

function getDomains(Database $dbForConsole, &$lastSyncUpdate, &$domains, $pools)
{
    go(function () use ($dbForConsole, &$lastSyncUpdate, &$domains, $pools) {
        Timer::tick(DOMAIN_SYNC_TIMER * 1000, function () use ($dbForConsole, &$domains, &$lastSyncUpdate, $pools) {
            $time = DateTime::now();
            $timerStart = \microtime(true);
            var_dump($domains);
            $limit = 1000;
            $sum = $limit;
            $total = 0;
            $latestDocument = null;

            while ($sum === $limit) {
                $queries = [Query::limit($limit)];
                if ($latestDocument !== null) {
                    $queries[] =  Query::cursorAfter($latestDocument);
                }
                if ($lastSyncUpdate != null) {
                    $queries[] = Query::greaterThanEqual('$updatedAt', $lastSyncUpdate);
                }
                $queries[] = Query::equal('resourceType', ['function']);

                $results = Authorization::skip(fn () =>  $dbForConsole->find('rules', $queries));

                $sum = count($results);
                $total = $total + $sum;
                foreach ($results as $document) {
                    $domain = $document->getAttribute('domain');
                    if (str_contains($domain, '.appwrite.global')) {
                        continue;
                    }
                    $domains[$domain] = true;
                }
                $latestDocument = !empty(array_key_last($results)) ? $results[array_key_last($results)] : null;
            }

            $lastSyncUpdate = $time;
            $timerEnd = \microtime(true);

            $pools->reclaim();

            Console::log("Sync domains tick: {$total} domains were updated in " . ($timerEnd - $timerStart) . " seconds");
        });
    });
}

$http->start();
