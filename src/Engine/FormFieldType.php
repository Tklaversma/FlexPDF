<?php

declare(strict_types=1);

namespace FlexPDF\Engine;

/**
 * What kind of interactive field an HTML control becomes.
 *
 * PDF has three field types and spells the rest with flag bits, so this names
 * the HTML control and {@see FormField} turns it into the pair. A pushbutton is
 * deliberately absent: its only purpose is the action it runs, this engine
 * writes no actions, and a button that says it does something it cannot is
 * worse than the drawn box a `<button>` already gets.
 */
enum FormFieldType: string
{
    case Text = 'text';

    case Multiline = 'multiline';

    case Password = 'password';

    case Checkbox = 'checkbox';

    case Radio = 'radio';

    case Combo = 'combo';

    case ListBox = 'list_box';

    /** The `/FT` a field of this kind is written with. */
    public function fieldType(): string
    {
        return match ($this) {
            self::Text, self::Multiline, self::Password => 'Tx',
            self::Checkbox, self::Radio                 => 'Btn',
            self::Combo, self::ListBox                  => 'Ch',
        };
    }

    /**
     * The `/Ff` bits this kind sets on its own, from ISO 32000-2 tables 228,
     * 231 and 233. The numbers are the spec's bit positions, counting from 1.
     */
    public function flags(): int
    {
        return match ($this) {
            self::Text, self::Checkbox => 0,
            self::Multiline            => 1 << 12,
            self::Password             => 1 << 13,
            self::Radio                => 1 << 15,
            self::Combo                => 1 << 17,
            self::ListBox              => 0,
        };
    }

    /** Whether several controls sharing one name are one field with one state. */
    public function isToggle(): bool
    {
        return $this === self::Checkbox || $this === self::Radio;
    }
}
