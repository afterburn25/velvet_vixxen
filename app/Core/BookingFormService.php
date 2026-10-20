<?php
declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/** Definition management and one shared server validator for all booking submissions. */
final class BookingFormService
{
    public const TYPES = [
        'text' => 'Short text', 'textarea' => 'Paragraph', 'email' => 'Email address', 'phone' => 'US phone number',
        'select' => 'Dropdown', 'radio' => 'Single choice', 'checkbox' => 'Multiple choices', 'datetime-local' => 'Date and time',
    ];
    private const CORE_FIELDS = ['inquiry_type', 'contact_name', 'contact_email', 'phone', 'requested_date', 'city', 'venue', 'budget', 'details'];

    public function __construct(private readonly Store $store) {}

    public function definition(): array
    {
        $saved = $this->store->setting('bookingForm');
        return is_array($saved) && is_array($saved['fields'] ?? null) && is_string($saved['revision'] ?? null)
            ? $saved : $this->defaults();
    }

    public function defaults(): array
    {
        $fields = [];
        foreach ([
            ['inquiry_type', 'Inquiry type', 'select', true, 120],
            ['contact_name', 'Your name', 'text', true, 120],
            ['contact_email', 'Email address', 'email', true, 190],
            ['phone', 'Phone', 'phone', false, 32],
            ['requested_date', 'Requested date', 'datetime-local', false, 32],
            ['city', 'City', 'text', false, 190],
            ['venue', 'Venue or platform', 'text', false, 255],
            ['budget', 'Estimated budget', 'text', false, 190],
            ['details', 'Inquiry details', 'textarea', true, 10000],
        ] as [$id, $label, $type, $required, $maxLength]) {
            $fields[] = ['id' => $id, 'label' => $label, 'type' => $type, 'required' => $required, 'enabled' => true,
                'help' => '', 'placeholder' => '', 'minSelections' => $required ? 1 : 0, 'maxLength' => $maxLength, 'options' => []];
        }
        foreach (['appearance' => 'Appearance', 'collaboration' => 'Collaboration', 'interview' => 'Interview / Podcast',
            'event' => 'Private or Public Event', 'media' => 'Media / Press', 'other' => 'Other Professional Inquiry'] as $value => $label) {
            $fields[0]['options'][] = ['value' => $value, 'label' => $label];
        }
        $fields[3]['placeholder'] = '(337) 450-8598';
        return ['revision' => 'initial-booking-form-v1', 'fields' => $fields];
    }

    public function saveField(array $input, string $revision): array
    {
        $definition = $this->currentRevision($revision);
        $id = $this->scalar($input['id'] ?? '');
        $index = null;
        foreach ($definition['fields'] as $i => $field) {
            if ($field['id'] === $id) $index = $i;
        }
        if ($id !== '' && $index === null) throw new RuntimeException('This field no longer exists. Refresh the builder.');
        if ($index === null && count($definition['fields']) >= 50) throw new RuntimeException('Use no more than 50 booking fields.');
        $prior = $index !== null ? $definition['fields'][$index] : [];
        $id = $id !== '' ? $id : 'field_' . bin2hex(random_bytes(6));
        $type = $this->scalar($input['type'] ?? 'text');
        if (!isset(self::TYPES[$type])) throw new RuntimeException('Choose a supported field type.');
        $label = trim($this->scalar($input['label'] ?? ''));
        if ($label === '' || $this->length($label) > 120) throw new RuntimeException('Enter a field label of 120 characters or fewer.');
        $help = trim($this->scalar($input['help'] ?? ''));
        $placeholder = trim($this->scalar($input['placeholder'] ?? ''));
        if ($this->length($help) > 400 || $this->length($placeholder) > 190) throw new RuntimeException('Shorten the field help or placeholder.');
        $required = !empty($input['required']);
        $enabled = !empty($input['enabled']);
        $options = [];
        if (in_array($type, ['select', 'radio', 'checkbox'], true)) {
            $raw = $this->scalar($input['options'] ?? '');
            $labels = array_values(array_filter(array_map('trim', preg_split('/\R/u', $raw) ?: []), static fn(string $v): bool => $v !== ''));
            if (count($labels) < 2 || count($labels) > 30 || count(array_unique($labels)) !== count($labels)) {
                throw new RuntimeException('Enter 2 to 30 different choices, one per line.');
            }
            foreach ($labels as $optionLabel) {
                if ($this->length($optionLabel) > 120) throw new RuntimeException('Each choice must contain 120 characters or fewer.');
                $value = null;
                foreach ((array) ($prior['options'] ?? []) as $oldOption) {
                    if ($oldOption['label'] === $optionLabel) $value = $oldOption['value'];
                }
                $options[] = ['value' => $value ?? 'choice_' . bin2hex(random_bytes(5)), 'label' => $optionLabel];
            }
        }
        $minimum = filter_var($input['minSelections'] ?? 0, FILTER_VALIDATE_INT);
        if ($minimum === false || $minimum < 0 || $minimum > count($options)) {
            if ($type === 'checkbox') throw new RuntimeException('The minimum cannot exceed the number of choices.');
            $minimum = 0;
        }
        $maxLength = match ($id) {
            'contact_name' => 120, 'contact_email' => 190, 'phone' => 32, 'city', 'budget' => 190, 'venue' => 255,
            'details' => 10000, default => $type === 'textarea' ? 5000 : ($type === 'email' ? 254 : 255),
        };
        $field = compact('id', 'label', 'type', 'required', 'enabled', 'help', 'placeholder', 'options', 'maxLength');
        $field['minSelections'] = $type === 'checkbox' ? max($required ? 1 : 0, $minimum) : 0;
        if ($index === null) $definition['fields'][] = $field; else $definition['fields'][$index] = $field;
        return $this->persist($definition);
    }

    public function deleteField(string $id, string $revision): array
    {
        $definition = $this->currentRevision($revision);
        $before = count($definition['fields']);
        $definition['fields'] = array_values(array_filter($definition['fields'], static fn(array $field): bool => $field['id'] !== $id));
        if (count($definition['fields']) === $before) throw new RuntimeException('This field no longer exists.');
        return $this->persist($definition);
    }

    public function moveField(string $id, string $direction, string $revision): array
    {
        if (!in_array($direction, ['up', 'down'], true)) throw new RuntimeException('Choose Move Up or Move Down.');
        $definition = $this->currentRevision($revision);
        foreach ($definition['fields'] as $index => $field) {
            if ($field['id'] !== $id) continue;
            $target = $index + ($direction === 'up' ? -1 : 1);
            if (isset($definition['fields'][$target])) {
                [$definition['fields'][$index], $definition['fields'][$target]] = [$definition['fields'][$target], $definition['fields'][$index]];
            }
            return $this->persist($definition);
        }
        throw new RuntimeException('This field no longer exists.');
    }

    public function toggleField(string $id, string $revision): array
    {
        $definition = $this->currentRevision($revision);
        foreach ($definition['fields'] as &$field) {
            if ($field['id'] !== $id) continue;
            $field['enabled'] = !$field['enabled'];
            unset($field);
            return $this->persist($definition);
        }
        unset($field);
        throw new RuntimeException('This field no longer exists.');
    }

    /** Existing default field names keep old integrations and saved forms compatible. */
    public function inputName(string $id): string
    {
        return in_array($id, self::CORE_FIELDS, true) ? $id : 'booking_fields[' . $id . ']';
    }

    public function valuesFromPost(array $post): array
    {
        $values = [];
        foreach ($this->definition()['fields'] as $field) {
            $id = $field['id'];
            $values[$id] = in_array($id, self::CORE_FIELDS, true) ? ($post[$id] ?? null)
                : (is_array($post['booking_fields'] ?? null) ? ($post['booking_fields'][$id] ?? null) : null);
        }
        return $values;
    }

    /** @return array{errors:array<string,string>,values:array,submission:array,mapped:array} */
    public function validate(array $input, string $revision = ''): array
    {
        $definition = $this->definition();
        $errors = [];
        if ($revision !== '' && !hash_equals($definition['revision'], $revision)) {
            $errors['_form'] = 'The booking form changed. Review the current fields and submit again.';
        }
        $values = [];
        $fields = [];
        foreach ($definition['fields'] as $field) {
            if (!$field['enabled']) continue;
            $fields[] = $field;
            $id = $field['id'];
            $type = $field['type'];
            $raw = $input[$id] ?? null;
            $allowed = array_column($field['options'], 'value');
            if ($type === 'checkbox') {
                if ($raw !== null && !is_array($raw)) {
                    $errors[$id] = 'Choose from the listed options.';
                    $values[$id] = [];
                    continue;
                }
                $answer = [];
                foreach ((array) $raw as $choice) {
                    if (!is_string($choice) || !in_array($choice, $allowed, true)) {
                        $errors[$id] = 'Choose from the listed options.';
                        continue;
                    }
                    $answer[] = $choice;
                }
                $answer = array_values(array_unique($answer));
                $minimum = max($field['required'] ? 1 : 0, (int) $field['minSelections']);
                if (($field['required'] || $answer !== []) && count($answer) < $minimum) {
                    $errors[$id] = 'Choose at least ' . $minimum . ' option' . ($minimum === 1 ? '.' : 's.');
                }
                $values[$id] = $answer;
                continue;
            }
            if ($raw !== null && !is_string($raw)) {
                $errors[$id] = 'Enter one valid value for this field.';
                $values[$id] = '';
                continue;
            }
            $value = trim((string) $raw);
            if ($value === '') {
                if ($field['required']) $errors[$id] = 'This field is required.';
                $values[$id] = '';
                continue;
            }
            if ($this->length($value) > (int) $field['maxLength'] || str_contains($value, "\0")) {
                $errors[$id] = 'Use ' . $field['maxLength'] . ' characters or fewer.';
            } elseif (in_array($type, ['select', 'radio'], true) && !in_array($value, $allowed, true)) {
                $errors[$id] = 'Choose one of the listed options.';
            } elseif ($type === 'email' && !self::validEmail($value)) {
                $errors[$id] = 'Enter a complete email address, such as name@example.com.';
            } elseif ($type === 'phone') {
                $formatted = self::formatPhone($value);
                if ($formatted === null) $errors[$id] = 'Enter a valid 10-digit US phone number, including the area code.';
                else $value = $formatted;
            } elseif ($type === 'datetime-local') {
                $date = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value);
                if ($date === false || $date->format('Y-m-d\TH:i') !== $value) $errors[$id] = 'Choose a valid date and time.';
            }
            if ($type === 'email') $value = strtolower($value);
            $values[$id] = $value;
        }
        if (strlen(json_encode($values, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '') > 100000) {
            $errors['_form'] = 'The inquiry is too long. Shorten the answers and try again.';
        }
        $mapped = [];
        foreach (['contact_name' => 'contactName', 'contact_email' => 'contactEmail', 'phone' => 'phone', 'requested_date' => 'requestedDate',
            'city' => 'city', 'venue' => 'venue', 'budget' => 'budget', 'details' => 'details', 'inquiry_type' => 'inquiryType'] as $id => $key) {
            $mapped[$key] = is_string($values[$id] ?? null) ? $values[$id] : '';
        }
        $fieldTypes = array_column($fields, 'type', 'id');
        // Owners may change any answer type; only matching typed contact fields feed legacy email/date workflows.
        if (($fieldTypes['contact_email'] ?? '') !== 'email') $mapped['contactEmail'] = '';
        if (($fieldTypes['phone'] ?? '') !== 'phone') $mapped['phone'] = '';
        if (($fieldTypes['requested_date'] ?? '') !== 'datetime-local') $mapped['requestedDate'] = '';
        if ($mapped['requestedDate'] !== '') $mapped['requestedDate'] = str_replace('T', ' ', $mapped['requestedDate']) . ':00';
        return ['errors' => $errors, 'values' => $values,
            'submission' => ['definitionRevision' => $definition['revision'], 'fields' => $fields, 'values' => $values, 'submittedAt' => now_iso()],
            'mapped' => $mapped];
    }

    public static function validEmail(string $email): bool
    {
        return strlen($email) <= 254 && filter_var($email, FILTER_VALIDATE_EMAIL) !== false
            && preg_match('/@[^@\s]+\.[^@\s.]+$/D', $email) === 1;
    }

    public static function formatPhone(string $value): ?string
    {
        if (!preg_match('/^\+?[0-9\s().-]+$/D', $value)) return null;
        $digits = preg_replace('/\D/', '', $value) ?? '';
        if (strlen($digits) === 11 && str_starts_with($digits, '1')) $digits = substr($digits, 1);
        if (!preg_match('/^[2-9][0-9]{2}[2-9][0-9]{6}$/D', $digits) || preg_match('/^([0-9])\1{9}$/D', $digits)) return null;
        return '(' . substr($digits, 0, 3) . ') ' . substr($digits, 3, 3) . '-' . substr($digits, 6);
    }

    public static function displayValue(array $field, mixed $value): string
    {
        if (in_array($field['type'] ?? '', ['select', 'radio', 'checkbox'], true)) {
            $labels = [];
            foreach ((array) $value as $choice) {
                foreach ((array) ($field['options'] ?? []) as $option) {
                    if ($option['value'] === $choice) $labels[] = $option['label'];
                }
            }
            return implode(', ', $labels);
        }
        return is_string($value) ? $value : '';
    }

    private function currentRevision(string $revision): array
    {
        $definition = $this->definition();
        if (!hash_equals($definition['revision'], $revision)) throw new RuntimeException('This form was changed in another tab. Refresh before saving.');
        return $definition;
    }

    private function persist(array $definition): array
    {
        $definition['revision'] = bin2hex(random_bytes(16));
        $definition['updatedAt'] = now_iso();
        $this->store->setSetting('bookingForm', $definition);
        return $definition;
    }

    private function scalar(mixed $value): string
    {
        if (!is_string($value) && !is_int($value)) throw new RuntimeException('A field setting has an invalid value.');
        return (string) $value;
    }

    private function length(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
    }
}
