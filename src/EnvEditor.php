<?php

namespace ClarionApp\Installer;

class EnvEditor
{
    private string $filename;
    private array $settings;

    public function __construct(string $filename)
    {
        $contents = file_get_contents($filename);
        $this->lines = explode("\n", $contents);
        foreach($this->lines as $line)
        {
            $parts = explode("=", $line);
            if(count($parts) == 2)
            {
                if($parts[0][0] == '#') continue;
                $this->settings[$parts[0]] = $parts[1];
            }
        }

        $this->filename = $filename;
    }

    public function set(string $key, string $value)
    {
        $this->settings[$key] = $value;
    }

    public function get(string $key)
    {
        return $this->settings[$key];
    }

    public function save()
    {
        $contents = "";
        foreach($this->settings as $key => $value)
        {
            $contents .= "$key=$value\n";
        }
        file_put_contents($this->filename, $contents);
    }
}