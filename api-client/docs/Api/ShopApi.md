# Swagger\Client\ShopApi

All URIs are relative to *https://api.batteryincluded.io*

Method | HTTP request | Description
------------- | ------------- | -------------
[**filter**](ShopApi.md#filter) | **GET** /api/v1/collections/demo/documents/browse | Filter
[**suggest**](ShopApi.md#suggest) | **GET** /api/v1/collections/demo/documents/suggest | Suggest

# **filter**
> filter($q, $attributes_brand, $category, $x_bi_api_key)

Filter

Filter

### Example

```php
<?php
require_once(__DIR__ . '/vendor/autoload.php');

$apiInstance = new Swagger\Client\Api\SearchApi(
    // If you want use custom http client, pass your client which implements `GuzzleHttp\ClientInterface`.
    // This is optional, `GuzzleHttp\Client` will be used as default.
    new GuzzleHttp\Client()
);
$q = "q_example"; // string | 
$attributes_brand = "attributes_brand_example"; // string | 
$category = "category_example"; // string | 
$x_bi_api_key = "x_bi_api_key_example"; // string | 

try {
    $apiInstance->filter($q, $attributes_brand, $category, $x_bi_api_key);
} catch (Exception $e) {
    echo 'Exception when calling ShopApi->filter: ', $e->getMessage(), PHP_EOL;
}
?>
```

### Parameters

Name | Type | Description  | Notes
------------- | ------------- | ------------- | -------------
 **q** | **string**|  | [optional]
 **attributes_brand** | **string**|  | [optional]
 **category** | **string**|  | [optional]
 **x_bi_api_key** | **string**|  | [optional]

### Return type

void (empty response body)

### Authorization

No authorization required

### HTTP request headers

 - **Content-Type**: Not defined
 - **Accept**: Not defined

[[Back to top]](#) [[Back to API list]](../../README.md#documentation-for-api-endpoints) [[Back to Model list]](../../README.md#documentation-for-models) [[Back to README]](../../README.md)

# **suggest**
> suggest($q, $x_bi_api_key)

Suggest

Suggest

### Example

```php
<?php
require_once(__DIR__ . '/vendor/autoload.php');

$apiInstance = new Swagger\Client\Api\SearchApi(
    // If you want use custom http client, pass your client which implements `GuzzleHttp\ClientInterface`.
    // This is optional, `GuzzleHttp\Client` will be used as default.
    new GuzzleHttp\Client()
);
$q = "q_example"; // string | 
$x_bi_api_key = "x_bi_api_key_example"; // string | 

try {
    $apiInstance->suggest($q, $x_bi_api_key);
} catch (Exception $e) {
    echo 'Exception when calling ShopApi->suggest: ', $e->getMessage(), PHP_EOL;
}
?>
```

### Parameters

Name | Type | Description  | Notes
------------- | ------------- | ------------- | -------------
 **q** | **string**|  | [optional]
 **x_bi_api_key** | **string**|  | [optional]

### Return type

void (empty response body)

### Authorization

No authorization required

### HTTP request headers

 - **Content-Type**: Not defined
 - **Accept**: Not defined

[[Back to top]](#) [[Back to API list]](../../README.md#documentation-for-api-endpoints) [[Back to Model list]](../../README.md#documentation-for-models) [[Back to README]](../../README.md)

