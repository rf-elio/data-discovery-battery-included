<?php declare(strict_types=1);

namespace Elio\ElioBatteryIncludedApiClient\Model;

use Elio\ElioDataDiscovery\Swagger\ModelInterface;

class RecommendationResultCollection implements ModelInterface
{
    /**
     * The original name of the model.
     *
     * @var string
     */
    protected static $swaggerModelName = 'RecommendationResultCollection';

    public function __construct(private readonly array $recommendationResults)
    {
    }

    /**
     * @return RecommendationResult[]
     */
    public function getRecommendationResults(): array
    {
        return $this->recommendationResults;
    }

    public function getModelName()
    {
        return self::$swaggerModelName;
    }

    public static function swaggerTypes()
    {
        return [];
    }

    public static function swaggerFormats()
    {
        return [];
    }

    public static function attributeMap()
    {
        return [];
    }

    public static function setters()
    {
        return [];
    }

    public static function getters()
    {
        return [];
    }

    public function listInvalidProperties()
    {
        return [];
    }

    public function valid()
    {
        return count($this->listInvalidProperties()) === 0;
    }
}
