<?php

namespace Appwrite\Resque;

use Exception;
use Utopia\App;
use Utopia\Cache\Adapter\Redis as RedisCache;
use Utopia\Cache\Cache;
use Utopia\CLI\Console;
use Utopia\Database\Adapter\MariaDB;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Storage\Device;
use Utopia\Storage\Device\Backblaze;
use Utopia\Storage\Device\DOSpaces;
use Utopia\Storage\Device\Linode;
use Utopia\Storage\Device\Local;
use Utopia\Storage\Device\S3;
use Utopia\Storage\Device\Wasabi;
use Utopia\Storage\Storage;

abstract class Worker
{
    /**
     * Callbacks that will be executed when an error occurs
     *
     * @var array
     */
    protected static array $errorCallbacks = [];

    /**
     * Associative array holding all information passed into the worker
     *
     * @return array
     */
    public array $args = [];

    /**
     * Function for identifying the worker needs to be set to unique name
     *
     * @return string
     * @throws Exception
     */
    public function getName(): string
    {
        throw new Exception("Please implement getName method in worker");
    }

    /**
     * Function executed before running first task.
     * Can include any preparations, such as connecting to external services or loading files
     *
     * @return void
     * @throws \Exception|\Throwable
     */
    public function init(): void
    {
        throw new Exception("Please implement init method in worker");
    }

    /**
     * Function executed when new task requests is received.
     * You can access $args here, it will contain event information
     *
     * @return void
     * @throws \Exception|\Throwable
     */
    public function run(): void
    {
        throw new Exception("Please implement run method in worker");
    }

    /**
     * Function executed just before shutting down the worker.
     * You can do cleanup here, such as disconnecting from services or removing temp files
     *
     * @return void
     * @throws \Exception|\Throwable
     */
    public function shutdown(): void
    {
        throw new Exception("Please implement shutdown method in worker");
    }

    public const DATABASE_PROJECT = 'project';
    public const DATABASE_CONSOLE = 'console';

    /**
     * A wrapper around 'init' function with non-worker-specific code
     *
     * @return void
     * @throws \Exception|\Throwable
     */
    public function setUp(): void
    {
        try {
            $this->init();
        } catch (\Throwable $error) {
            foreach (self::$errorCallbacks as $errorCallback) {
                $errorCallback($error, "init", $this->getName());
            }

            throw $error;
        }
    }

    /**
     * A wrapper around 'run' function with non-worker-specific code
     *
     * @return void
     * @throws \Exception|\Throwable
     */
    public function perform(): void
    {
        try {
            /**
             * Disabling global authorization in workers.
             */
            Authorization::disable();
            Authorization::setDefaultStatus(false);
            $this->run();
        } catch (\Throwable $error) {
            foreach (self::$errorCallbacks as $errorCallback) {
                $errorCallback($error, "run", $this->getName(), $this->args);
            }

            throw $error;
        }
    }

    /**
     * A wrapper around 'shutdown' function with non-worker-specific code
     *
     * @return void
     * @throws \Exception|\Throwable
     */
    public function tearDown(): void
    {
        try {
            $this->shutdown();
        } catch (\Throwable $error) {
            foreach (self::$errorCallbacks as $errorCallback) {
                $errorCallback($error, "shutdown", $this->getName());
            }

            throw $error;
        }
    }


    /**
     * Register callback. Will be executed when error occurs.
     * @param callable $callback
     * @return void
     */
    public static function error(callable $callback): void
    {
        self::$errorCallbacks[] = $callback;
    }

    /**
     * Get internal project database
     * @param string $projectId
     * @return Database
     * @throws Exception
     */
    protected function getProjectDB(string $projectId, ?Document $project = null): Database
    {
        if ($project === null) {
            $consoleDB = $this->getConsoleDB();

            if ($projectId === 'console') {
                return $consoleDB;
            }

            /** @var Document $project */
            $project = Authorization::skip(fn() => $consoleDB->getDocument('projects', $projectId));
        }

        return $this->getDB(self::DATABASE_PROJECT, $projectId, $project->getInternalId(), $project);
    }

    /**
     * Get console database
     * @return Database
     * @throws Exception
     */
    protected function getConsoleDB(): Database
    {
        return $this->getDB(self::DATABASE_CONSOLE);
    }

    /**
     * Get database
     * @param string $type One of (project, console)
     * @param string $projectId of project or console DB
     * @param string $projectInternalId
     * @param Document|null $project
     * @return Database
     * @throws Exception
     */
    private function getDB(
        string $type,
        string $projectId = '',
        string $projectInternalId = '',
        ?Document $project = null
    ): Database {
        global $register;

        $sleep = DATABASE_RECONNECT_SLEEP; // overwritten when necessary

        if ($project !== null) {
            $projectId = $project->getId();
            $projectInternalId = $project->getInternalId();
        }

        switch ($type) {
            case self::DATABASE_PROJECT:
                if (!$projectId) {
                    throw new \Exception('ProjectID not provided - cannot get database');
                }
                $namespace = "_$projectInternalId";
                break;
            case self::DATABASE_CONSOLE:
                $namespace = "_console";
                $sleep = 5; // ConsoleDB needs extra sleep time to ensure tables are created
                break;
            default:
                throw new \Exception('Unknown database type: ' . $type);
        }

        $attempts = 0;

        while (true) {
            try {
                $attempts++;
                $cache = new Cache(new RedisCache($register->get('cache')));
                $database = new Database(new MariaDB($register->get('db')), $cache);
                $database->setDefaultDatabase(App::getEnv('_APP_DB_SCHEMA', 'appwrite'));
                $database->setNamespace($namespace); // Main DB

                if (
                    $project === null
                    && !empty($projectId)
                    && !$database->getDocument('projects', $projectId)->isEmpty()
                ) {
                    throw new \Exception("Project does not exist: $projectId");
                }

                if ($type === self::DATABASE_CONSOLE && !$database->exists($database->getDefaultDatabase(), Database::METADATA)) {
                    throw new \Exception('Console project not ready');
                }

                break; // leave loop if successful
            } catch (\Exception $e) {
                Console::warning("Database not ready. Retrying connection ($attempts)...");
                if ($attempts >= DATABASE_RECONNECT_MAX_ATTEMPTS) {
                    throw new \Exception('Failed to connect to database: ' . $e->getMessage());
                }
                sleep($sleep);
            }
        }

        return $database;
    }

    /**
     * Get Functions Storage Device
     * @param string $projectId of the project
     * @return Device
     */
    protected function getFunctionsDevice(string $projectId): Device
    {
        return $this->getDevice(APP_STORAGE_FUNCTIONS . '/app-' . $projectId);
    }

    /**
     * Get Files Storage Device
     * @param string $projectId of the project
     * @return Device
     */
    protected function getFilesDevice(string $projectId): Device
    {
        return $this->getDevice(APP_STORAGE_UPLOADS . '/app-' . $projectId);
    }


    /**
     * Get Builds Storage Device
     * @param string $projectId of the project
     * @return Device
     */
    protected function getBuildsDevice(string $projectId): Device
    {
        return $this->getDevice(APP_STORAGE_BUILDS . '/app-' . $projectId);
    }

    protected function getCacheDevice(string $projectId): Device
    {
        return $this->getDevice(APP_STORAGE_CACHE . '/app-' . $projectId);
    }

    /**
     * Get Device based on selected storage environment
     * @param string $root path of the device
     * @return Device
     */
    public function getDevice(string $root): Device
    {
        switch (strtolower(App::getEnv('_APP_STORAGE_DEVICE', Storage::DEVICE_LOCAL))) {
            case Storage::DEVICE_LOCAL:
            default:
                return new Local($root);
            case Storage::DEVICE_S3:
                $s3AccessKey = App::getEnv('_APP_STORAGE_S3_ACCESS_KEY', '');
                $s3SecretKey = App::getEnv('_APP_STORAGE_S3_SECRET', '');
                $s3Region = App::getEnv('_APP_STORAGE_S3_REGION', '');
                $s3Bucket = App::getEnv('_APP_STORAGE_S3_BUCKET', '');
                $s3Acl = 'private';
                return new S3($root, $s3AccessKey, $s3SecretKey, $s3Bucket, $s3Region, $s3Acl);
            case Storage::DEVICE_DO_SPACES:
                $doSpacesAccessKey = App::getEnv('_APP_STORAGE_DO_SPACES_ACCESS_KEY', '');
                $doSpacesSecretKey = App::getEnv('_APP_STORAGE_DO_SPACES_SECRET', '');
                $doSpacesRegion = App::getEnv('_APP_STORAGE_DO_SPACES_REGION', '');
                $doSpacesBucket = App::getEnv('_APP_STORAGE_DO_SPACES_BUCKET', '');
                $doSpacesAcl = 'private';
                return new DOSpaces($root, $doSpacesAccessKey, $doSpacesSecretKey, $doSpacesBucket, $doSpacesRegion, $doSpacesAcl);
            case Storage::DEVICE_BACKBLAZE:
                $backblazeAccessKey = App::getEnv('_APP_STORAGE_BACKBLAZE_ACCESS_KEY', '');
                $backblazeSecretKey = App::getEnv('_APP_STORAGE_BACKBLAZE_SECRET', '');
                $backblazeRegion = App::getEnv('_APP_STORAGE_BACKBLAZE_REGION', '');
                $backblazeBucket = App::getEnv('_APP_STORAGE_BACKBLAZE_BUCKET', '');
                $backblazeAcl = 'private';
                return new Backblaze($root, $backblazeAccessKey, $backblazeSecretKey, $backblazeBucket, $backblazeRegion, $backblazeAcl);
            case Storage::DEVICE_LINODE:
                $linodeAccessKey = App::getEnv('_APP_STORAGE_LINODE_ACCESS_KEY', '');
                $linodeSecretKey = App::getEnv('_APP_STORAGE_LINODE_SECRET', '');
                $linodeRegion = App::getEnv('_APP_STORAGE_LINODE_REGION', '');
                $linodeBucket = App::getEnv('_APP_STORAGE_LINODE_BUCKET', '');
                $linodeAcl = 'private';
                return new Linode($root, $linodeAccessKey, $linodeSecretKey, $linodeBucket, $linodeRegion, $linodeAcl);
            case Storage::DEVICE_WASABI:
                $wasabiAccessKey = App::getEnv('_APP_STORAGE_WASABI_ACCESS_KEY', '');
                $wasabiSecretKey = App::getEnv('_APP_STORAGE_WASABI_SECRET', '');
                $wasabiRegion = App::getEnv('_APP_STORAGE_WASABI_REGION', '');
                $wasabiBucket = App::getEnv('_APP_STORAGE_WASABI_BUCKET', '');
                $wasabiAcl = 'private';
                return new Wasabi($root, $wasabiAccessKey, $wasabiSecretKey, $wasabiBucket, $wasabiRegion, $wasabiAcl);
            case Storage::DEVICE_DREAMOBJECTS:
                $dreamObjectsAccessKey = App::getEnv('_APP_STORAGE_DREAMOBJECTS_ACCESS_KEY', '');
                $dreamObjectsSecretKey = App::getEnv('_APP_STORAGE_DREAMOBJECTS_SECRET', '');
                $dreamObjectsRegion = App::getEnv('_APP_STORAGE_DREAMOBJECTS_REGION', '');
                $dreamObjectsBucket = App::getEnv('_APP_STORAGE_DREAMOBJECTS_BUCKET', '');
                $dreamObjectsAcl = 'private';
                return new DreamObjects($root, $dreamObjectsAccessKey, $dreamObjectsSecretKey, $dreamObjectsBucket, $dreamObjectsRegion, $dreamObjectsAcl);
        }
    }
}
