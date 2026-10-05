<?php
declare(strict_types=1);

namespace App\TruckPlanner\Model;

/**
 * The last check before a result leaves the model: no INF and no NAN, anywhere in it.
 *
 * Valid inputs cannot produce one. Inputs outside what the model accepts can (an overflowing exponent, the
 * logarithm of zero, a non-finite number handed in), and such a value must not travel on: JSON cannot carry
 * it and a comparison with it is silently false.
 *
 * @internal Part of the model package; the rest of the backend calls Estimator.
 */
final class Finite
{
    /**
     * Returns the value unchanged, or raises the model error `non_finite` naming the function and the path
     * of the first offending number.
     *
     * @template T
     * @param T $value
     * @return T
     */
    public static function check(mixed $value, string $function): mixed
    {
        if (is_float($value)) {
            if (!is_finite($value)) {
                throw new ModelError(ModelError::NON_FINITE, ['function' => $function, 'path' => '']);
            }
        } elseif (is_array($value)) {
            $path = self::find($value);
            if ($path !== null) {
                throw new ModelError(ModelError::NON_FINITE, ['function' => $function, 'path' => $path]);
            }
        }
        return $value;
    }

    /**
     * The dotted path of the first non-finite number in a nested array, or null.
     *
     * @param array<int|string, mixed> $value
     */
    private static function find(array $value): ?string
    {
        foreach ($value as $key => $item) {
            if (is_float($item)) {
                if (!is_finite($item)) {
                    return (string) $key;
                }
            } elseif (is_array($item)) {
                $inner = self::find($item);
                if ($inner !== null) {
                    return $key . '.' . $inner;
                }
            }
        }
        return null;
    }
}
