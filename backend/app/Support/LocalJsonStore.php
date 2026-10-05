<?php

namespace App\Support;

use JsonException;
use RuntimeException;

class LocalJsonStore
{
    private string $path;

    public function __construct()
    {
        $this->path = app()->environment('testing')
            ? storage_path('framework/testing/application-state.json')
            : storage_path('app/private/application-state.json');
    }

    public function read(): array
    {
        return $this->update(function (array &$state): array {
            return $state;
        });
    }

    public function update(callable $callback): mixed
    {
        $directory = dirname($this->path);

        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException("Unable to create local state directory: {$directory}");
        }

        $lock = fopen($this->path.'.lock', 'c');

        if ($lock === false) {
            throw new RuntimeException("Unable to open local state lock: {$this->path}.lock");
        }

        try {
            if (! flock($lock, LOCK_EX)) {
                throw new RuntimeException("Unable to lock local state file: {$this->path}.lock");
            }

            $state = $this->readState();
            $this->dropExpiredEntries($state);
            $result = $callback($state);
            $this->writeState($state);

            return $result;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function readState(): array
    {
        if (! is_file($this->path)) {
            return ['registration' => [], 'otp' => []];
        }

        $contents = file_get_contents($this->path);

        if ($contents === false) {
            throw new RuntimeException("Unable to read local state file: {$this->path}");
        }

        try {
            $state = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return ['registration' => [], 'otp' => []];
        }

        if (! is_array($state)
            || ! isset($state['registration'], $state['otp'])
            || ! is_array($state['registration'])
            || ! is_array($state['otp'])) {
            return ['registration' => [], 'otp' => []];
        }

        return $state;
    }

    private function dropExpiredEntries(array &$state): void
    {
        foreach (['registration', 'otp'] as $section) {
            foreach ($state[$section] as $key => $entry) {
                if (! is_array($entry)
                    || ! isset($entry['expires_at'])
                    || ! is_numeric($entry['expires_at'])
                    || (int) $entry['expires_at'] <= now()->timestamp) {
                    unset($state[$section][$key]);
                }
            }
        }
    }

    private function writeState(array $state): void
    {
        try {
            $contents = json_encode($state, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT).PHP_EOL;
        } catch (JsonException $error) {
            throw new RuntimeException('Unable to encode local state.', previous: $error);
        }

        $temporaryPath = tempnam(dirname($this->path), basename($this->path).'.');

        if ($temporaryPath === false) {
            throw new RuntimeException("Unable to create temporary local state file beside: {$this->path}");
        }

        try {
            $written = file_put_contents($temporaryPath, $contents, LOCK_EX);

            if ($written !== strlen($contents)) {
                throw new RuntimeException("Unable to write local state file: {$temporaryPath}");
            }

            if (! rename($temporaryPath, $this->path)) {
                throw new RuntimeException("Unable to atomically replace local state file: {$this->path}");
            }
        } finally {
            if (is_file($temporaryPath)) {
                unlink($temporaryPath);
            }
        }
    }
}
