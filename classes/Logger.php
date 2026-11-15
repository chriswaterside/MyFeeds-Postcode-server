<?php
declare(strict_types=1);

namespace RaPostcode;

/** Simple dated log file with automatic clean-up. Optionally echoes to the console. */
final class Logger
{
    private $handle = null;
    private int $errors = 0;
    /** @var string[] first few error messages, for the summary email */
    private array $errorSamples = [];

    public function __construct(private string $dir, private int $keepDays = 30, private bool $echo = false)
    {
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            return; // logging is best effort
        }
        $this->handle = @fopen($dir . '/postcodes-' . date('Y-m-d') . '.log', 'ab') ?: null;
        $this->cleanUp();
    }

    public function info(string $msg): void
    {
        $this->write('INFO', $msg);
    }

    public function error(string $msg): void
    {
        $this->errors++;
        if (count($this->errorSamples) < 10) {
            $this->errorSamples[] = $msg;
        }
        $this->write('ERROR', $msg);
    }

    public function errorCount(): int
    {
        return $this->errors;
    }

    /** @return string[] */
    public function errorSamples(): array
    {
        return $this->errorSamples;
    }

    private function write(string $level, string $msg): void
    {
        $line = date('Y-m-d H:i:s') . " $level $msg\n";
        if ($this->handle) {
            fwrite($this->handle, $line);
        }
        if ($this->echo) {
            fwrite($level === 'ERROR' ? STDERR : STDOUT, $line);
        }
    }

    private function cleanUp(): void
    {
        $cutoff = time() - $this->keepDays * 86400;
        foreach (glob($this->dir . '/postcodes-*.log') ?: [] as $file) {
            if (filemtime($file) < $cutoff) {
                @unlink($file);
            }
        }
    }
}
