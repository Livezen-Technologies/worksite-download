<?php

namespace App\Libraries\Markdown;

use League\CommonMark\CommonMarkConverter;

/**
 * Restricted Markdown (§5.4): raw HTML stripped, unsafe links removed.
 */
final class SafeMarkdown
{
    private static ?CommonMarkConverter $converter = null;

    public static function toHtml(?string $markdown): string
    {
        if ($markdown === null || trim($markdown) === '') {
            return '';
        }
        self::$converter ??= new CommonMarkConverter([
            'html_input'         => 'strip',
            'allow_unsafe_links' => false,
            'max_nesting_level'  => 20,
        ]);

        return trim((string) self::$converter->convert($markdown));
    }

    /** Inline rendering (strips the wrapping paragraph). */
    public static function inline(string $markdown): string
    {
        $html = self::toHtml($markdown);
        if (str_starts_with($html, '<p>') && str_ends_with($html, '</p>') && substr_count($html, '<p>') === 1) {
            return substr($html, 3, -4);
        }

        return $html;
    }

    public static function plain(?string $markdown): string
    {
        return trim(html_entity_decode(strip_tags(self::toHtml($markdown)), ENT_QUOTES | ENT_HTML5));
    }
}
