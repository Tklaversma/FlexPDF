<?php

declare(strict_types=1);

namespace FlexPDF\Engine;

/**
 * What one HTML form control asks the writer for.
 *
 * The control still draws exactly as it did before this existed: a box the size
 * a browser gives it, carrying what it shows. This says what the box *is*, so
 * the writer can put an interactive field over it. Nothing here is layout and
 * nothing here reaches the paper on its own.
 *
 * A control with no `name` gets none of this. Without one there is no way to
 * fill the field or to read it back, so a field would be a box claiming to be
 * addressable that no caller can address.
 */
final class FormField
{
    /**
     * @param string             $name     the control's `name`, which becomes `/T`
     * @param string             $value    what a text field holds now
     * @param string             $export   a checkbox or radio's on-state name
     * @param array<int,array{0:string,1:string}> $options  a choice field's value and label pairs
     * @param string[]           $selected which of those options are chosen
     */
    public function __construct(
        public readonly FormFieldType $type,
        public readonly string $name,
        public readonly string $value = '',
        public readonly bool $checked = false,
        public readonly string $export = 'Yes',
        public readonly array $options = [],
        public readonly array $selected = [],
        public readonly bool $multiSelect = false,
        public readonly bool $readOnly = false,
        public readonly bool $required = false,
        public readonly ?int $maxLength = null,
        public readonly string $tooltip = '',
    ) {}

    /** The `/Ff` value: the kind's own bits plus the ones any field can carry. */
    public function flags(): int
    {
        return $this->type->flags()
            | ($this->readOnly ? 1 : 0)
            | ($this->required ? 1 << 1 : 0)
            | ($this->multiSelect ? 1 << 21 : 0);
    }

    /**
     * Whether this field's value is written into the file.
     *
     * A password field's is not. The control already draws bullets, and a
     * template that carries a secret in its markup should not have the writer
     * copy it into a `/V` where every reader will show it back.
     */
    public function writesValue(): bool
    {
        return $this->type !== FormFieldType::Password;
    }
}
