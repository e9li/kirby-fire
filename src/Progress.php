<?php

namespace E9li\Fire;

/**
 * CLI progress display: a rolling window of the last few processed items
 * above a progress bar, redrawn in place — 3000 pages produce six lines of
 * output instead of 3000. Falls back to one plain line per item when the
 * output is not a terminal (cron, CI logs, pipes), and stays silent under
 * --quiet, where the exit code carries the outcome. Failure lines always
 * persist above the window, on the CLI's error channel.
 */
class Progress
{
    protected bool $tty;
    protected int $width;
    protected int $done = 0;
    protected int $failed = 0;
    protected array $recent = [];
    protected int $drawn = 0;

    public function __construct(
        protected int $total,
        protected bool $enabled = true,
        protected int $window = 5
    ) {
        $this->tty = $enabled && defined('STDOUT') && stream_isatty(STDOUT);
        $this->width = $this->terminalWidth();
    }

    public function advance(string $label): void
    {
        $this->done++;

        if ($this->enabled === false) {
            return;
        }

        if ($this->tty === false) {
            fwrite(STDOUT, $this->done . '/' . $this->total . ': ' . $label . PHP_EOL);

            return;
        }

        $this->recent[] = $label;

        if (count($this->recent) > $this->window) {
            array_shift($this->recent);
        }

        $this->draw();
    }

    /**
     * Failure lines persist above the window and go through the CLI's error
     * channel — so they reach STDERR and disappear under --quiet.
     */
    public function error($cli, string $line): void
    {
        $this->failed++;
        $this->clear();
        $cli->error($line);
        $this->draw();
    }

    /**
     * Replaces the window with the final bar, so the summary follows it
     * cleanly.
     */
    public function finish(): void
    {
        if ($this->tty === false) {
            return;
        }

        $this->clear();
        fwrite(STDOUT, static::bar($this->done, $this->total, $this->failed) . PHP_EOL);
    }

    public static function bar(int $done, int $total, int $failed, int $width = 30): string
    {
        $ratio = $total > 0 ? min(1, $done / $total) : 1;
        $filled = (int)round($ratio * $width);

        $bar = '[' . str_repeat('█', $filled) . str_repeat('░', $width - $filled) . '] '
            . $done . '/' . $total;

        if ($failed > 0) {
            $bar .= ' — ' . $failed . ' failed';
        }

        return $bar;
    }

    protected function draw(): void
    {
        $this->clear();

        $out = '';

        foreach ($this->recent as $label) {
            $out .= "\033[2m" . $this->fit('  ' . $label) . "\033[0m" . PHP_EOL;
        }

        $out .= $this->fit(static::bar($this->done, $this->total, $this->failed)) . PHP_EOL;

        fwrite(STDOUT, $out);
        $this->drawn = count($this->recent) + 1;
    }

    protected function clear(): void
    {
        if ($this->tty === false || $this->drawn === 0) {
            return;
        }

        // cursor up over the drawn block, then clear to the end of screen
        fwrite(STDOUT, "\033[" . $this->drawn . "A\033[0J");
        $this->drawn = 0;
    }

    /**
     * Hard truncation to the terminal width — a wrapped line would break
     * the cursor-up arithmetic of the redraw.
     */
    protected function fit(string $line): string
    {
        if (mb_strlen($line) < $this->width) {
            return $line;
        }

        return mb_substr($line, 0, $this->width - 2) . '…';
    }

    protected function terminalWidth(): int
    {
        $columns = (int)(getenv('COLUMNS') ?: 0);

        if ($columns <= 0 && $this->tty === true && function_exists('exec')) {
            $columns = (int)@exec('tput cols 2>/dev/null');
        }

        return $columns > 20 ? $columns : 80;
    }
}
