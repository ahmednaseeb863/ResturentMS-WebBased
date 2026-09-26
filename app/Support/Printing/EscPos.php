<?php

namespace App\Support\Printing;

use Illuminate\Support\Str;

/**
 * Minimal ESC/POS builder for thermal printers (sent raw through QZ Tray). Text is
 * ASCII-only; lines wrap at the paper's characters per line (80 mm = 48, 58 mm = 32).
 */
class EscPos
{
    private const ESC = "\x1B";

    private const GS = "\x1D";

    private string $bytes;

    private bool $double = false;

    public function __construct(private int $paperWidth = 80)
    {
        $this->bytes = self::ESC.'@'; // initialise
    }

    public function columns(): int
    {
        $columns = $this->paperWidth === 58 ? 32 : 48;

        return $this->double ? intdiv($columns, 2) : $columns;
    }

    public function center(): static
    {
        $this->bytes .= self::ESC."a\x01";

        return $this;
    }

    public function left(): static
    {
        $this->bytes .= self::ESC."a\x00";

        return $this;
    }

    public function bold(bool $on = true): static
    {
        $this->bytes .= self::ESC.'E'.($on ? "\x01" : "\x00");

        return $this;
    }

    /** Double width + height (titles, quantities the cook must not miss). */
    public function large(bool $on = true): static
    {
        $this->double = $on;
        $this->bytes .= self::GS.'!'.($on ? "\x11" : "\x00");

        return $this;
    }

    /** One or more wrapped lines; `$indent` spaces before continuation lines. */
    public function text(string $text, int $indent = 0): static
    {
        $text = static::ascii($text);
        $width = $this->columns();
        $lines = explode("\n", wordwrap($text, $width, "\n", true));

        foreach ($lines as $i => $line) {
            if ($i > 0 && $indent) {
                $line = str_repeat(' ', $indent).ltrim($line);
            }
            $this->bytes .= $line."\n";
        }

        return $this;
    }

    /** "2 x Zinger ........ 1,200" style: left text and right text on one line. */
    public function pair(string $left, string $right): static
    {
        $left = static::ascii($left);
        $right = static::ascii($right);
        $space = $this->columns() - strlen($right) - 1;

        if (strlen($left) > $space) {
            return $this->text($left)->text(str_pad($right, $this->columns(), ' ', STR_PAD_LEFT));
        }

        $this->bytes .= str_pad($left, $space).' '.$right."\n";

        return $this;
    }

    public function rule(string $char = '-'): static
    {
        $this->bytes .= str_repeat($char, $this->columns())."\n";

        return $this;
    }

    public function feed(int $lines = 1): static
    {
        $this->bytes .= self::ESC.'d'.chr(max(0, min(255, $lines)));

        return $this;
    }

    /** Feed past the cutter and cut (partial cut). */
    public function cut(): static
    {
        $this->bytes .= self::GS."V\x42\x03";

        return $this;
    }

    public function toString(): string
    {
        return $this->bytes;
    }

    public static function ascii(string $text): string
    {
        return Str::ascii(strtr($text, ['×' => 'x', '—' => '-', '–' => '-', '·' => '-', '…' => '...', '“' => '"', '”' => '"', '’' => "'"]));
    }
}
