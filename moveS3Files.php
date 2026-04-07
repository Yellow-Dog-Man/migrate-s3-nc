<?php

require __DIR__ . '/vendor/autoload.php';

use Aws\CommandPool;
use Aws\S3\S3Client;
use Aws\Exception\AwsException;
use MigrationS3NC\Db\DatabaseSingleton;
use MigrationS3NC\Environment;
use MigrationS3NC\Logger\LoggerSingleton;

/**
 * I misunderstood how this script worked and synced the entire nextcloud file collection to R2 already.
 * This script, uses similar parts of this repo, to figure out where to move the files to the expected format.
 * 
 * Generated with the support of Claude.
 */

Environment::load();

$s3Client = new S3Client([
    'version'   => '2006-03-01',
    'region'    => $_ENV['S3_REGION'],
    'credentials' => [
        'key'   => $_ENV['S3_KEY'],
        'secret' => $_ENV['S3_SECRET'],
    ],
    'endpoint'  => $_ENV['S3_ENDPOINT'],
    'signature_version' => 'v4',
]);

$bucket = $_ENV['S3_BUCKET_NAME'];

// Initialize database using the repo's DatabaseSingleton
try {
    $database = DatabaseSingleton::getInstance();
    $database->open();
    $pdo = $database->getPdo();
    echo "Connected to database successfully.\n";
} catch (\PDOException $e) {
    fwrite(STDERR, "Database connection failed: " . $e->getMessage() . "\n");
    exit(1);
}

/**
 * Build a mapping of old S3 paths to file IDs
 * 
 * Query the database to get all files and their IDs, then construct
 * the old S3 key pattern that matches what was uploaded.
 */
function buildPathToFileIdMapping($pdo): array
{
    echo "Building path to file ID mapping from database...\n";
    
    $mapping = [];
    
    // Get the storage ID for home storages (user storage)
    // Using same pattern as StoragesMapper but filtering for home:: specifically
    $storageQuery = "SELECT numeric_id, id FROM oc_storages WHERE id LIKE 'home::%'";
    $storageStmt = $pdo->query($storageQuery);
    $storages = $storageStmt->fetchAll();
    
    $storageMap = [];
    foreach ($storages as $storage) {
        // Extract username from storage id (home::username)
        $parts = explode('::', $storage['id']);
        if (isset($parts[1])) {
            $storageMap[$storage['numeric_id']] = $parts[1];
        }
    }
    
    // Query filecache for all non-directory files in the files/ path
    // Excluding trash, versions, cache, and thumbnails like the main migration
    $storageIds = implode(',', array_keys($storageMap));
    $query = "
        SELECT 
            fc.fileid,
            fc.path,
            fc.storage,
            fc.name
        FROM oc_filecache fc
        WHERE fc.storage IN ($storageIds)
        AND fc.path NOT LIKE 'files_trash/%'
        AND fc.path NOT LIKE 'files_versions/%'
        AND fc.path NOT LIKE 'cache/%'
        AND fc.path NOT LIKE 'thumbnails/%'
        AND fc.path LIKE 'files/%'
        ORDER BY fc.fileid ASC
    ";
    
    $stmt = $pdo->query($query);
    $files = $stmt->fetchAll();
    
    foreach ($files as $file) {
        $username = $storageMap[$file['storage']] ?? null;
        if (!$username) {
            continue;
        }
        
        // Construct the old S3 key pattern: username/path
        // The path in oc_filecache is like "files/folder/document.pdf"
        // The old S3 key would be: "username/files/folder/document.pdf"
        $oldS3Key = $username . '/' . $file->path;
        
        $mapping[$oldS3Key] = [
            'fileid' => $file->fileid,
            'username' => $username,
            'path' => $file->path,
        ];
    }
    
    echo "Found " . count($mapping) . " files in database.\n";
    return $mapping;
}

/**
 * Get all objects from S3 bucket
 */
function getS3Objects(S3Client $s3Client, string $bucket): array
{
    echo "Listing objects in S3 bucket...\n";
    
    $objects = [];
    $continuationToken = null;
    
    do {
        $params = [
            'Bucket' => $bucket,
            'MaxKeys' => 1000,
        ];
        
        if ($continuationToken) {
            $params['ContinuationToken'] = $continuationToken;
        }
        
        $result = $s3Client->listObjectsV2($params);
        
        if (isset($result['Contents'])) {
            foreach ($result['Contents'] as $object) {
                $objects[] = $object['Key'];
            }
        }
        
        $continuationToken = $result['NextContinuationToken'] ?? null;
        
    } while ($continuationToken);
    
    echo "Found " . count($objects) . " objects in S3 bucket.\n";
    return $objects;
}

/**
 * Filter objects that match the old path pattern
 */
function filterOldPathObjects(array $objects): array
{
    echo "Filtering objects with old path pattern...\n";
    
    $filtered = [];
    
    foreach ($objects as $key) {
        // Skip system files and directories
        if (strpos($key, '/') === false) {
            // Root level files like .ncdata, nextcloud.log - skip these
            continue;
        }
        
        // Match pattern: username/files/...
        $parts = explode('/', $key, 2);
        if (count($parts) === 2 && $parts[1] !== '' && strpos($parts[1], 'files/') === 0) {
            $filtered[] = $key;
        }
    }
    
    echo "Found " . count($filtered) . " objects with old path pattern.\n";
    return $filtered;
}

/**
 * Generate copy commands for S3 objects
 */
function generateCopyCommands(
    S3Client $s3Client,
    string $bucket,
    array $objectsToMove,
    array $pathToFileIdMap
): Generator {
    foreach ($objectsToMove as $oldKey) {
        // Look up the file ID
        $mapping = $pathToFileIdMap[$oldKey] ?? null;
        
        if (!$mapping) {
            // Try to find a partial match (the path might be slightly different)
            foreach ($pathToFileIdMap as $dbKey => $dbMapping) {
                // Check if the filename matches
                $oldFilename = basename($oldKey);
                $dbFilename = basename($dbKey);
                
                if ($oldFilename === $dbFilename) {
                    // Check if the username matches
                    $oldUsername = explode('/', $oldKey)[0];
                    $dbUsername = explode('/', $dbKey)[0];
                    
                    if ($oldUsername === $dbUsername) {
                        $mapping = $dbMapping;
                        echo "Partial match found: $oldKey -> {$dbMapping['fileid']}\n";
                        break;
                    }
                }
            }
        }
        
        if (!$mapping) {
            echo "WARNING: No mapping found for: $oldKey\n";
            continue;
        }
        
        $newKey = 'urn:oid:' . $mapping['fileid'];
        
        // Check if the new key already exists
        try {
            $s3Client->headObject([
                'Bucket' => $bucket,
                'Key' => $newKey,
            ]);
            echo "SKIP: Target already exists: $newKey\n";
            continue;
        } catch (AwsException $e) {
            if ($e->getAwsErrorCode() !== '404') {
                echo "ERROR checking $newKey: " . $e->getMessage() . "\n";
                continue;
            }
        }
        
        // Generate copy command
        yield $s3Client->getCommand('CopyObject', [
            'Bucket' => $bucket,
            'Key' => $newKey,
            'CopySource' => $bucket . '/' . $oldKey,
        ]);
        
        echo "QUEUED: $oldKey -> $newKey\n";
    }
}

/**
 * Generate delete commands for old objects
 */
function generateDeleteCommands(
    S3Client $s3Client,
    string $bucket,
    array $objectsToDelete
): Generator {
    foreach ($objectsToDelete as $oldKey) {
        yield $s3Client->getCommand('DeleteObject', [
            'Bucket' => $bucket,
            'Key' => $oldKey,
        ]);
    }
}

echo "===========================================\n";
echo "Moving files\n";
echo "===========================================\n\n";

$pathToFileIdMap = buildPathToFileIdMapping($pdo);

if (empty($pathToFileIdMap)) {
    echo "No files found in database to migrate. Exiting.\n";
    exit(0);
}

$s3Objects = getS3Objects($s3Client, $bucket);
$oldPathObjects = filterOldPathObjects($s3Objects);

if (empty($oldPathObjects)) {
    echo "No objects with old path pattern found. Nothing to do.\n";
    exit(0);
}

echo "\n--- Copying files to new locations ---\n";
$copyCommands = generateCopyCommands($s3Client, $bucket, $oldPathObjects, $pathToFileIdMap);
$copyPool = new CommandPool($s3Client, $copyCommands, [
    'concurrency' => 10,
    'rejected' => function (AwsException $reason, $iterKey) {
        echo "COPY ERROR: " . $reason->getMessage() . " (index: $iterKey)\n";
    },
]);

try {
    $copyPool->promise()->wait();
    echo "Copy phase completed.\n";
} catch (Exception $e) {
    echo "Copy phase error: " . $e->getMessage() . "\n";
}

echo "\n--- Deleting old objects ---\n";
echo "WARNING: This will delete the old path objects. Press Ctrl+C to cancel.\n";
sleep(3);

$deleteCommands = generateDeleteCommands($s3Client, $bucket, $oldPathObjects);
$deletePool = new CommandPool($s3Client, $deleteCommands, [
    'concurrency' => 10,
    'rejected' => function (AwsException $reason, $iterKey) {
        echo "DELETE ERROR: " . $reason->getMessage() . " (index: $iterKey)\n";
    },
]);

try {
    $deletePool->promise()->wait();
    echo "Delete phase completed.\n";
} catch (Exception $e) {
    echo "Delete phase error: " . $e->getMessage() . "\n";
}

// Close database connection
$database->close();

echo "Migration complete!\n";
