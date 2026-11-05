<?php

namespace App\Support;

/**
 * Minimal ESC/POS command builder (Epson-compatible). Thermal printers speak single-byte code pages, so text
 * is reduced to printable ASCII: non-ASCII (including Bengali and the ৳ sign) cannot be printed as text and
 * is replaced ("৳" becomes "Tk"). Bengali receipts need bitmap rendering — planned with the Phase 17 i18n work.
 */
class EscPos
{
    private string $buf = '';

    public function init(): self
    {
        $this->buf .= "\x1B@";

        return $this;
    }

    public function align(string $where): self
    {
        $this->buf .= "\x1Ba".chr(['left' => 0, 'center' => 1, 'right' => 2][$where] ?? 0);

        return $this;
    }

    public function bold(bool $on): self
    {
        $this->buf .= "\x1BE".chr($on ? 1 : 0);

        return $this;
    }

    public function doubleSize(bool $on): self
    {
        $this->buf .= "\x1D!".chr($on ? 0x11 : 0x00);

        return $this;
    }

    public function text(string $text): self
    {
        $this->buf .= self::ascii($text);

        return $this;
    }

    public function line(string $text = ''): self
    {
        return $this->text($text."\n");
    }

    public function feed(int $lines = 3): self
    {
        $this->buf .= "\x1Bd".chr(max(0, min(255, $lines)));

        return $this;
    }

    public function cut(): self
    {
        $this->buf .= "\x1DV\x42\x00"; // feed then partial cut

        return $this;
    }

    /** Pulse pin 2 (the usual cash-drawer kick). */
    public function openDrawer(): self
    {
        $this->buf .= "\x1Bp\x00\x19\xFA";

        return $this;
    }

    public function bytes(): string
    {
        return $this->buf;
    }

    public static function ascii(string $text): string
    {
        $text = str_replace(['৳', '–', '—', '×', '“', '”', '‘', '’'], ['Tk', '-', '-', 'x', '"', '"', "'", "'"], $text);

        return preg_replace('/[^\x20-\x7E\n]/u', '?', $text) ?? '';
    }

    /** "left ........ right" padded to $width columns. */
    public static function pair(string $left, string $right, int $width): string
    {
        $left = self::ascii($left);
        $right = self::ascii($right);
        $space = max(1, $width - strlen($left) - strlen($right));
        if (strlen($left) + strlen($right) + 1 > $width) {
            $left = substr($left, 0, max(1, $width - strlen($right) - 1));
            $space = max(1, $width - strlen($left) - strlen($right));
        }

        return $left.str_repeat(' ', $space).$right;
    }
}
