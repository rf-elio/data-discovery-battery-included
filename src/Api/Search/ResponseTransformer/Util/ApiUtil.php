<?php
declare(strict_types=1);

namespace Elio\ElioBatteryIncludedSearchExtension\Api\Search\ResponseTransformer\Util;

class ApiUtil
{
    public static function prepareFilters(array $filtersRequest, array $preparedFilters = []): array
    {
        foreach ($filtersRequest as $key => $values) {
            $value = array_shift($values['values']);
            if (is_array($value) && isset($value['type']) && ($value['type'] === 'range' || $value['type'] === 'rating')) {
                $preparedFilters["f[{$key}][from]"] = $value['from'] ?? 1;
                $preparedFilters["f[{$key}][till]"] = isset($value['till']) ? min($value['till'], PHP_INT_MAX) : PHP_INT_MAX;
            } else {
                $preparedFilters['f[' . $key . ']'] = $value;
            }
        }

        return $preparedFilters;
    }
}
