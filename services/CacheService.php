<?php

class CacheService
{
    private int $expiry;

    /**
     * Constructs a new CacheService instance with a specified expiry time.
     *
     * @param int $expiry The cache expiry time in seconds. Defaults to 300 seconds.
     */
    public function __construct(int $expiry = 300)
    {
        $this->expiry = $expiry;
    }

    /**
     * Retrieves a cached item from the file system.
     *
     * @param string $key The cache key to retrieve.
     * @return mixed The cached item if found, otherwise null.
     */
    public function get(string $key)
    {
        $file = $this->getFilePath($key);

        if (file_exists($file) && (time() - filemtime($file) < $this->expiry)) {
            return json_decode(file_get_contents($file), true);
        }

        return null;
    }

    /**
     * Sets a cache item with the given key and data.
     *
     * @param string $key The cache key to set.
     * @param mixed $data The data to store in the cache.
     * @return bool True if the operation was successful, false otherwise.
     */
    public function set(string $key, $data): bool
    {
        $file = $this->getFilePath($key);
        return file_put_contents($file, json_encode($data)) !== false;
    }

    /**
     * Deletes a cached item with the given key.
     *
     * @param string $key The cache key to delete.
     * @return bool True if the item was successfully deleted or didn't exist, false on failure.
     */
    public function delete(string $key): bool
    {
        $file = $this->getFilePath($key);

        if (file_exists($file)) {
            return unlink($file);
        }

        return true; // Return true if file doesn't exist (already deleted)
    }

    /**
     * Deletes all cached items that match a given pattern.
     *
     * @param string $pattern The pattern to match cache keys against.
     * @return int The number of cache items deleted.
     */
    public function deletePattern(string $pattern): int
    {
        $count = 0;
        $prefix = "vortexweb-ticketing-system-";
        $files = glob(sys_get_temp_dir() . "/{$prefix}*.cache");

        foreach ($files as $file) {
            $filename = basename($file);
            // Extract the original key by removing the prefix and suffix
            if (preg_match("/{$pattern}/", $filename) && unlink($file)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Returns the file path for the given cache key.
     *
     * This function hashes the cache key with MD5 to generate a unique filename.
     * The filename is then saved in the system's temporary directory.
     *
     * @param string $key The cache key to generate a file path for.
     * @return string The cache file path.
     */
    private function getFilePath(string $key): string
    {
        return sys_get_temp_dir() . "/vortexweb-ticketing-system-" . md5($key) . ".cache";
    }
}
