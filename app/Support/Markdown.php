<?php

namespace App\Support;

use League\CommonMark\GithubFlavoredMarkdownConverter;

class Markdown
{
    private static ?GithubFlavoredMarkdownConverter $converter = null;

    /**
     * HTML bruto é escapado: o texto do modelo nunca injeta markup ou script na página.
     */
    public static function toHtml(string $text): string
    {
        self::$converter ??= new GithubFlavoredMarkdownConverter([
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
        ]);

        return (string) self::$converter->convert($text);
    }
}
