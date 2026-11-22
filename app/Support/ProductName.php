<?php

namespace App\Support;

/** Comparable keys for vendor and product names from NVD, CISA KEV and user input. */
class ProductName
{
    /** "Palo Alto Networks" → "paloaltonetworks", "PAN-OS" → "panos". */
    public static function key(?string $name): string
    {
        return preg_replace('/[^a-z0-9]+/', '', mb_strtolower((string) $name)) ?? '';
    }

    /**
     * Split a CPE 2.3 string into vendor and product, readable form.
     * "cpe:2.3:o:paloaltonetworks:pan-os:10.2.0:..." → ["paloaltonetworks", "pan-os"]
     *
     * @return array{0: string, 1: string}|null
     */
    public static function fromCpe(string $cpe): ?array
    {
        // Fields are ':'-separated; '\:' is an escaped colon inside a field.
        $fields = preg_split('/(?<!\\\\):/', $cpe);

        if (! $fields || count($fields) < 5 || $fields[0] !== 'cpe' || $fields[1] !== '2.3') {
            return null;
        }

        $clean = fn (string $field) => trim(str_replace('_', ' ', stripslashes($field)));
        [$vendor, $product] = [$clean($fields[3]), $clean($fields[4])];

        return $vendor !== '' && $vendor !== '*' && $product !== '' && $product !== '*' ? [$vendor, $product] : null;
    }
}
