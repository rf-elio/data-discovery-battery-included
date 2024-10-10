<?php declare(strict_types=1);

namespace Elio\ElioBatteryIncludedSearchExtension\Api\Recommendations\Util;

use Elio\ElioBatteryIncludedApiClient\Model\RecommendationResult;
use Elio\ElioBatteryIncludedApiClient\Model\RecommendationResultCollection;

class ProductNumberExtractor
{
    public static function extractProductNumbers(RecommendationResultCollection $result): array
    {
        $filteredResults = array_filter($result->getRecommendationResults(), static function (RecommendationResult $record) {
            return array_key_exists('_product', $record->getDocument());
        });

        $productNumbersByType = [];
        foreach ($filteredResults as $record) {
            if (!isset($productNumbersByType[$record->getType()])) {
                $productNumbersByType[$record->getType()] = [];
            }
            $productNumbersByType[$record->getType()][] = $record->getDocument()['_product']->productNumber[0];
        }

        return $productNumbersByType;
    }

}
