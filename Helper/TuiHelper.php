<?php
/**
 * Copyright © Tidycode. All rights reserved.
 */
declare(strict_types=1);

namespace Tidycode\TUI\Helper;

/**
 * Helper class for TUI utilities and formatting
 */
class TuiHelper
{
    // Color codes for terminal output
    public const COLOR_RESET = "\033[0m";
    public const COLOR_BLACK = "\033[0;30m";
    public const COLOR_RED = "\033[0;31m";
    public const COLOR_GREEN = "\033[0;32m";
    public const COLOR_YELLOW = "\033[0;33m";
    public const COLOR_BLUE = "\033[0;34m";
    public const COLOR_MAGENTA = "\033[0;35m";
    public const COLOR_CYAN = "\033[0;36m";
    public const COLOR_WHITE = "\033[0;37m";
    
    // Bold colors
    public const COLOR_BOLD_RED = "\033[1;31m";
    public const COLOR_BOLD_GREEN = "\033[1;32m";
    public const COLOR_BOLD_YELLOW = "\033[1;33m";
    public const COLOR_BOLD_BLUE = "\033[1;34m";
    public const COLOR_BOLD_MAGENTA = "\033[1;35m";
    public const COLOR_BOLD_CYAN = "\033[1;36m";
    public const COLOR_BOLD_WHITE = "\033[1;37m";

    // Background colors
    public const BG_BLACK = "\033[40m";
    public const BG_RED = "\033[41m";
    public const BG_GREEN = "\033[42m";
    public const BG_YELLOW = "\033[43m";
    public const BG_BLUE = "\033[44m";
    public const BG_MAGENTA = "\033[45m";
    public const BG_CYAN = "\033[46m";
    public const BG_WHITE = "\033[47m";

    /**
     * Colorize text
     *
     * @param string $text
     * @param string $color
     * @return string
     */
    public static function colorize(string $text, string $color): string
    {
        return $color . $text . self::COLOR_RESET;
    }

    /**
     * Create a box around text
     *
     * @param string $text
     * @param int $width
     * @return string
     */
    public static function box(string $text, int $width = 80): string
    {
        $lines = explode("\n", $text);
        $output = [];
        
        $output[] = '┌' . str_repeat('─', $width - 2) . '┐';
        
        foreach ($lines as $line) {
            $padding = $width - mb_strlen($line) - 4;
            $output[] = '│ ' . $line . str_repeat(' ', $padding) . ' │';
        }
        
        $output[] = '└' . str_repeat('─', $width - 2) . '┘';
        
        return implode("\n", $output);
    }

    /**
     * Create a separator line
     *
     * @param int $width
     * @param string $char
     * @return string
     */
    public static function separator(int $width = 80, string $char = '─'): string
    {
        return str_repeat($char, $width);
    }

    /**
     * Center text
     *
     * @param string $text
     * @param int $width
     * @return string
     */
    public static function center(string $text, int $width = 80): string
    {
        $padding = max(0, ($width - mb_strlen($text)) / 2);
        return str_repeat(' ', (int)$padding) . $text;
    }

    /**
     * Create a progress bar
     *
     * @param int $current
     * @param int $total
     * @param int $width
     * @return string
     */
    public static function progressBar(int $current, int $total, int $width = 50): string
    {
        $percentage = $total > 0 ? ($current / $total) * 100 : 0;
        $filled = (int)(($current / $total) * $width);
        $empty = $width - $filled;
        
        $bar = '[' . str_repeat('█', $filled) . str_repeat('░', $empty) . ']';
        return sprintf('%s %d%%', $bar, (int)$percentage);
    }

    /**
     * Format table
     *
     * @param array $headers
     * @param array $rows
     * @return string
     */
    public static function table(array $headers, array $rows): string
    {
        if (empty($headers) || empty($rows)) {
            return '';
        }

        // Calculate column widths
        $widths = [];
        foreach ($headers as $i => $header) {
            $widths[$i] = mb_strlen($header);
        }

        foreach ($rows as $row) {
            foreach ($row as $i => $cell) {
                $widths[$i] = max($widths[$i] ?? 0, mb_strlen((string)$cell));
            }
        }

        $output = [];
        
        // Top border
        $output[] = '┌' . implode('┬', array_map(fn($w) => str_repeat('─', $w + 2), $widths)) . '┐';
        
        // Headers
        $headerRow = '│';
        foreach ($headers as $i => $header) {
            $headerRow .= ' ' . str_pad($header, $widths[$i]) . ' │';
        }
        $output[] = $headerRow;
        
        // Header separator
        $output[] = '├' . implode('┼', array_map(fn($w) => str_repeat('─', $w + 2), $widths)) . '┤';
        
        // Data rows
        foreach ($rows as $row) {
            $dataRow = '│';
            foreach ($row as $i => $cell) {
                $dataRow .= ' ' . str_pad((string)$cell, $widths[$i]) . ' │';
            }
            $output[] = $dataRow;
        }
        
        // Bottom border
        $output[] = '└' . implode('┴', array_map(fn($w) => str_repeat('─', $w + 2), $widths)) . '┘';
        
        return implode("\n", $output);
    }

    /**
     * Get ASCII art logo
     *
     * @return string
     */
    public static function getLogo(): string
    {
        return <<<'ASCII'
╔════════════════════════════════════════════════════════════════════════════╗
║                                                                            ║
║   ████████╗██╗██████╗ ██╗   ██╗ ██████╗ ██████╗ ██████╗ ███████╗         ║
║   ╚══██╔══╝██║██╔══██╗╚██╗ ██╔╝██╔════╝██╔═══██╗██╔══██╗██╔════╝         ║
║      ██║   ██║██║  ██║ ╚████╔╝ ██║     ██║   ██║██║  ██║█████╗           ║
║      ██║   ██║██║  ██║  ╚██╔╝  ██║     ██║   ██║██║  ██║██╔══╝           ║
║      ██║   ██║██████╔╝   ██║   ╚██████╗╚██████╔╝██████╔╝███████╗         ║
║      ╚═╝   ╚═╝╚═════╝    ╚═╝    ╚═════╝ ╚═════╝ ╚═════╝ ╚══════╝         ║
║                                                                            ║
║                    Magento 2 Backend Management TUI                       ║
║                                                                            ║
╚════════════════════════════════════════════════════════════════════════════╝
ASCII;
    }

    /**
     * Get color for log level
     *
     * @param string $level
     * @return string
     */
    public static function getLogLevelColor(string $level): string
    {
        return match (strtoupper($level)) {
            'DEBUG' => self::COLOR_CYAN,
            'INFO' => self::COLOR_GREEN,
            'NOTICE' => self::COLOR_BLUE,
            'WARNING' => self::COLOR_YELLOW,
            'ERROR' => self::COLOR_RED,
            'CRITICAL' => self::COLOR_BOLD_RED,
            'ALERT' => self::COLOR_BOLD_MAGENTA,
            'EMERGENCY' => self::BG_RED . self::COLOR_BOLD_WHITE,
            default => self::COLOR_WHITE
        };
    }

    /**
     * Get status indicator
     *
     * @param bool $enabled
     * @return string
     */
    public static function getStatusIndicator(bool $enabled): string
    {
        if ($enabled) {
            return self::colorize('●', self::COLOR_GREEN) . ' Enabled ';
        }
        return self::colorize('●', self::COLOR_RED) . ' Disabled';
    }

    /**
     * Truncate text to fit width
     *
     * @param string $text
     * @param int $width
     * @param string $suffix
     * @return string
     */
    public static function truncate(string $text, int $width, string $suffix = '...'): string
    {
        if (mb_strlen($text) <= $width) {
            return $text;
        }
        
        return mb_substr($text, 0, $width - mb_strlen($suffix)) . $suffix;
    }

    /**
     * Clear screen
     *
     * @return void
     */
    public static function clearScreen(): void
    {
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            system('cls');
        } else {
            system('clear');
        }
    }

    /**
     * Move cursor to position
     *
     * @param int $row
     * @param int $col
     * @return string
     */
    public static function moveCursor(int $row, int $col): string
    {
        return "\033[{$row};{$col}H";
    }

    /**
     * Hide cursor
     *
     * @return string
     */
    public static function hideCursor(): string
    {
        return "\033[?25l";
    }

    /**
     * Show cursor
     *
     * @return string
     */
    public static function showCursor(): string
    {
        return "\033[?25h";
    }
}
