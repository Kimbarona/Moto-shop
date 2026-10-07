<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * Order / return slip layout. Slip prefix and next number live in the sequences table (see SequenceService).
 */
class SlipSettings extends Settings
{
    public string $document_title;

    public ?string $header_text;

    public string $footer_text;

    public bool $show_logo;

    public string $paper_size;

    public int $copies;

    public static function group(): string
    {
        return 'slip';
    }
}
