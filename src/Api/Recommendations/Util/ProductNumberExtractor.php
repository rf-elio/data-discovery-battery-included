<?php declare(strict_types=1);

namespace Elio\ElioBatteryIncludedSearchExtension\Api\Recommendations\Util;

use Elio\ElioBatteryIncludedApiClient\Model\RecommendationResult;
use Elio\ElioBatteryIncludedApiClient\Model\RecommendationResultCollection;
use Elio\ElioDataDiscovery\Core\Exception\InvalidTypeException;
use Elio\ElioDataDiscovery\Swagger\ModelInterface;

class ProductNumberExtractor
{
    public static function extractProductNumbers(ModelInterface $result): array
    {
        if (!$result instanceof RecommendationResultCollection) {
            throw new InvalidTypeException(
                $result,
                RecommendationResultCollection::class,
            );
        }

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

//        return array_map(static function (RecommendationResult $record) {
//            return $record->getDocument()['_product']->productNumber[0];
//        }, array_filter($result->getRecommendationResults(), static function (RecommendationResult $record) {
//            return array_key_exists('_product', $record->getDocument());
//        }));
    }

}
