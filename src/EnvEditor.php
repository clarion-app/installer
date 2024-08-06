<?php

namespace ClarionApp\Installer;

class EnvEditor
{
    private string $filename;
    private array $settings = [];
    private array $lines = [];

    public function __construct(string $filename)
    {
        $this->filename = $filename;
        $contents = file_get_contents($this->filename);
        $this->lines = explode("\n", $contents);
        foreach ($this->lines as $index => $line) {
            $line = trim($line);
            if (empty($line) || $line[0] === '#') {
                continue; // Preserve comments and empty lines
            }
            
            $parts = explode('=', $line, 2);
            if (count($parts) === 2) {
                $this->settings[trim($parts[0])] = ['index' => $index, 'value' => trim($parts[1])];
            } else {
                // Keep lines with keys but no values
                $this->settings[trim($parts[0])] = ['index' => $index, 'value' => ''];
            }
        }
    }

    public function set(string $key, string $value): void
    {
        $key = trim($key);
        if (isset($this->settings[$key])) {
            $this->lines[$this->settings[$key]['index']] = "$key=$value";
            $this->settings[$key]['value'] = $value; // Update the value in settings as well
        } else {
            // Add new key-value pair if not originally present
            $this->lines[] = "$key=$value";
            $this->settings[$key] = ['index' => count($this->lines) - 1, 'value' => $value];
        }
    }

    public function get(string $key): ?string
    {
        return $this->settings[$key]['value'] ?? null;
    }

    public function save(): void
    {
        $contents = implode("\n", $this->lines);
        file_put_contents($this->filename, $contents);
    }
}
