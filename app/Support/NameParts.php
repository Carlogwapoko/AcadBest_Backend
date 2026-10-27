<?php

namespace App\Support;

final class NameParts
{
    /**
     * @return array{first_name: string, middle_name: string, last_name: string}
     */
    public static function resolve(
        ?string $name,
        ?string $firstName,
        ?string $middleName,
        ?string $lastName
    ): array {
        $firstName = self::normalize($firstName);
        $middleName = self::normalize($middleName);
        $lastName = self::normalize($lastName);

        if ($firstName !== '' && $lastName !== '') {
            return [
                'first_name' => $firstName,
                'middle_name' => $middleName,
                'last_name' => $lastName,
            ];
        }

        $tokens = self::tokens($name);
        $middleTokens = self::tokens($middleName);
        $split = self::splitAroundMiddle($tokens, $middleTokens);

        if ($split !== null) {
            // Middle name found inside the full name: left side = first name, right side = last name.
            $firstName = $firstName ?: implode(' ', $split[0]);
            $lastName = $lastName ?: implode(' ', $split[1]);
        } else {
            // Middle name missing or not found: remove any known parts, then split what is left.
            $rest = $tokens;
            $rest = self::stripPrefix($rest, self::tokens($firstName));
            $rest = self::stripSuffix($rest, self::tokens($lastName));
            $rest = self::stripPrefix($rest, $middleTokens);
            $rest = self::stripSuffix($rest, $middleTokens);

            if ($firstName === '' && $lastName === '') {
                if (count($rest) === 2) {
                    $lastName = (string) array_pop($rest);
                    $firstName = implode(' ', $rest);
                } elseif (count($rest) === 1) {
                    $firstName = $rest[0];
                }
            } elseif ($firstName === '') {
                $firstName = implode(' ', $rest);
            } else {
                $lastName = implode(' ', $rest);
            }
        }

        return [
            'first_name' => $firstName,
            'middle_name' => $middleName,
            'last_name' => $lastName,
        ];
    }

    /**
     * @param list<string> $tokens
     * @param list<string> $middleTokens
     * @return array{0: list<string>, 1: list<string>}|null
     */
    private static function splitAroundMiddle(array $tokens, array $middleTokens): ?array
    {
        $size = count($middleTokens);
        if ($size === 0) {
            return null;
        }

        $found = null;
        for ($index = 1; $index + $size < count($tokens); $index++) {
            if (array_slice($tokens, $index, $size) === $middleTokens) {
                $found = $index; // keep the last valid match
            }
        }

        if ($found === null) {
            return null;
        }

        return [array_slice($tokens, 0, $found), array_slice($tokens, $found + $size)];
    }

    /**
     * @param list<string> $tokens
     * @param list<string> $prefix
     * @return list<string>
     */
    private static function stripPrefix(array $tokens, array $prefix): array
    {
        $size = count($prefix);
        if ($size > 0 && $size < count($tokens) && array_slice($tokens, 0, $size) === $prefix) {
            return array_slice($tokens, $size);
        }

        return $tokens;
    }

    /**
     * @param list<string> $tokens
     * @param list<string> $suffix
     * @return list<string>
     */
    private static function stripSuffix(array $tokens, array $suffix): array
    {
        $size = count($suffix);
        if ($size > 0 && $size < count($tokens) && array_slice($tokens, -$size) === $suffix) {
            return array_slice($tokens, 0, -$size);
        }

        return $tokens;
    }

    public static function displayName(
        ?string $name,
        ?string $firstName,
        ?string $middleName,
        ?string $lastName,
        bool $preferParts = false
    ): string {
        $parts = self::resolve($name, $firstName, $middleName, $lastName);
        $composed = self::fullName($parts['first_name'], $parts['middle_name'], $parts['last_name']);
        $stored = self::normalize($name);

        $hasOuterParts = $parts['first_name'] !== '' && $parts['last_name'] !== '';
        if ($composed !== '' && (($preferParts && $hasOuterParts) || $stored === '' || $stored === $composed)) {
            return $composed;
        }

        return $stored ?: $composed;
    }

    public static function fullName(?string $firstName, ?string $middleName, ?string $lastName): string
    {
        return implode(' ', array_filter(
            array_map(
                fn (?string $part): string => self::normalize($part),
                [$firstName, $middleName, $lastName]
            ),
            fn (string $part): bool => $part !== ''
        ));
    }

    private static function normalize(?string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', trim($value ?? '')));
    }

    /**
     * @return list<string>
     */
    private static function tokens(?string $value): array
    {
        $normalized = self::normalize($value);

        return $normalized === '' ? [] : explode(' ', $normalized);
    }
}