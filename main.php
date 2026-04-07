<?php

require __DIR__ . '/vendor/autoload.php';

use MigrationS3NC\Configuration\NextcloudS3Configuration;
use MigrationS3NC\Constants;
use MigrationS3NC\Environment;
use MigrationS3NC\File\FileNextcloudConfiguration;
use MigrationS3NC\Managers\File\FileLocalStorageManager;
use MigrationS3NC\Managers\File\FileUserManager;
use MigrationS3NC\Managers\S3\S3Manager;
use MigrationS3NC\Managers\Storage\HomeStorageManager;
use MigrationS3NC\Managers\Storage\LocalStorageManager;
use MigrationS3NC\Service\StorageService;

/**
 * Display usage information
 */
function showUsage(): void
{
    print("Nextcloud S3 Migration Tool\n");
    print("===========================\n\n");
    print("Usage: php main.php [options]\n\n");
    print("Options:\n");
    print("  --upload    Upload files to S3 only\n");
    print("  --sql       Update database (oc_storages table) only\n");
    print("  --config    Generate new Nextcloud config file only\n");
    print("  --all       Run all steps (default)\n");
    print("  --help      Show this help message\n\n");
}

/**
 * Parse command line arguments
 * @return array
 */
function parseArguments(): array
{
    $args = $argv = $_SERVER['argv'] ?? [];

    $options = [
        'upload' => false,
        'sql' => false,
        'config' => false,
        'all' => false,
        'help' => false,
    ];

    foreach ($args as $arg) {
        $arg = strtolower(trim($arg));
        switch ($arg) {
            case '--upload':
                $options['upload'] = true;
                break;
            case '--sql':
                $options['sql'] = true;
                break;
            case '--config':
                $options['config'] = true;
                break;
            case '--all':
                $options['all'] = true;
                break;
            case '--help':
            case '-h':
            case 'help':
                $options['help'] = true;
                break;
        }
    }

    if (!$options['upload'] && !$options['sql'] && !$options['config'] && !$options['all'] && !$options['help']) {
        $options['all'] = true;
    }

    if ($options['all']) {
        $options['upload'] = true;
        $options['sql'] = true;
        $options['config'] = true;
    }

    return $options;
}

/**
 * Upload files to s3
 */
function uploadFilesToS3(): void
{
    $fileManager = new FileUserManager();
    $filesLocalStorageManager = new FileLocalStorageManager();

    $userFiles = $fileManager->getAll();
    $localFiles = $filesLocalStorageManager->getAll();

    $totalFiles = count($userFiles) + count($localFiles);
    print("Uploading {$totalFiles} files to S3...\n");

    if ($totalFiles === 0) {
        return;
    }

    $s3Manager = new S3Manager();
    $commands = $s3Manager->generatorPutObject(
        array_merge($userFiles, $localFiles)
    );

    $pool = $s3Manager->pool($commands);
    $promise = $pool->promise();
    $promise->wait();

    print("Upload complete.\n");
}

/**
 * Update database tables to link to new S3
 */
function updateDatabase(): void
{
    $homeStorageManager = new HomeStorageManager();
    foreach ($homeStorageManager->getAll() as $storage) {
        $homeStorageManager->updateId(
            $storage->getNumericId(),
            Constants::ID_USER_OBJECT . $storage->getUid()
        );
    }

    $localStorageManager = new LocalStorageManager();
    $localStorages = $localStorageManager->getAll();
    if (count($localStorages) > 0) {
        $idObjectStorage = StorageService::getNewIdLocalStorage();
        $localStorage = $localStorages[0];
        $localStorageManager->updateId($localStorage->getNumericId(), $idObjectStorage);
    }

    print("Database updated.\n");
}

/**
 * Generates new configuration with S3 Connection URLs
 */
function generateConfig(): void
{
    $data = NextcloudS3Configuration::getS3Configuration();
    $file = new FileNextcloudConfiguration("new_config.php");
    $file->write($data);
    $file->close();

    print("Config generated: new_config.php\n");
}

// Main execution
$options = parseArguments();

if ($options['help']) {
    showUsage();
    exit(0);
}

Environment::load();

try {
    if ($options['upload']) {
        uploadFilesToS3();
    }

    if ($options['sql']) {
        updateDatabase();
    }

    if ($options['config']) {
        generateConfig();
    }

    print("\nMigration completed. Review new_config.php before replacing your Nextcloud config.\n");

} catch (Exception $e) {
    print("ERROR: " . $e->getMessage() . "\n");
    exit(1);
}