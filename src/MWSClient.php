<?php 
namespace MCS;

use DateTime;
use Exception;
use DateTimeZone;
use MCS\MWSEndPoint;
use League\Csv\Reader;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\BadResponseException;
use Spatie\ArrayToXml\ArrayToXml;

class MWSClient{

    private $m_amazonUrl = '';
    private $m_locale = 'uk';
    private $m_retrieveArray = false;
    private $m_useSSL = false;
    private $m_localeTable = array(
        'ca'	=>	'webservices.amazon.ca/onca/xml',
        'cn'	=>	'webservices.amazon.cn/onca/xml',
        'de'	=>	'webservices.amazon.de/onca/xml',
        'es'	=>	'webservices.amazon.es/onca/xml',
        'fr'	=>	'webservices.amazon.fr/onca/xml',
        'it'	=>	'webservices.amazon.it/onca/xml',
        'jp'	=>	'webservices.amazon.jp/onca/xml',
        'uk'	=>	'webservices.amazon.co.uk/onca/xml',
        'us'	=>	'webservices.amazon.com/onca/xml',
    );

    const SIGNATURE_METHOD = 'HmacSHA256';
    const SIGNATURE_VERSION = '2';
    const DATE_FORMAT = "Y-m-d\TH:i:s.\\0\\0\\0\\Z";
    const APPLICATION_NAME = 'MCS/MwsClient';
    
    private $config = [
        'Seller_Id' => null,
        'Marketplace_Id' => null,
        'Access_Key_ID' => null,
        'Secret_Access_Key' => null,
        'MWSAuthToken' => null,
        'ASSOCIATES_KEY_ID'=> '',
        'ASSOCIATES_SECRET_KEY' => '',
        'ASSOCIATES_ID' => '',
        'MWS_AUTH_TOKEN' => '',
        'Application_Version' => '0.0.*'
    ];  
    
    private $MarketplaceIds = [
        'A2EUQ1WTGCTBG2' => 'mws.amazonservices.ca',
        'ATVPDKIKX0DER'  => 'mws.amazonservices.com',
        'A1AM78C64UM0Y8' => 'mws.amazonservices.com.mx',
        'A1PA6795UKMFR9' => 'mws-eu.amazonservices.com',
        'A1RKKUPIHCS9HS' => 'mws-eu.amazonservices.com',
        'A13V1IB3VIYZZH' => 'mws-eu.amazonservices.com',
        'A21TJRUUN4KGV'  => 'mws.amazonservices.in',
        'APJ6JRA9NG5V4'  => 'mws-eu.amazonservices.com',
        'A1F83G8C2ARO7P' => 'mws-eu.amazonservices.com',
        'A1VC38T7YXB528' => 'mws.amazonservices.jp',
        'AAHKV2X7AFYLW'  => 'mws.amazonservices.com.cn',
    ];
    
    public function __construct(array $config)
    {   
        
        foreach($config as $key => $value) {
            if (array_key_exists($key, $this->config)) {
                $this->config[$key] = $value;
            }
        }
        
        $required_keys = [
            'Marketplace_Id', 'Seller_Id', 'Access_Key_ID', 'Secret_Access_Key'
        ];
        
        foreach ($required_keys as $key) {
            if(is_null($this->config[$key])) {
                throw new Exception('Required field ' . $key . ' is not set');    
            }
        } 
        
        if (!isset($this->MarketplaceIds[$this->config['Marketplace_Id']])) {
            throw new Exception('Invalid Marketplace Id');    
        }
        
        $this->config['Application_Name'] = self::APPLICATION_NAME;
        $this->config['Region_Host'] = $this->MarketplaceIds[$this->config['Marketplace_Id']];
        $this->config['Region_Url'] = 'https://' . $this->config['Region_Host'];
        
    }
    
    /**
     * A method to quickly check if the supplied credentials are valid
     * @return boolean
     */
    public function validateCredentials()
    {
        try{
            $this->ListOrderItems('validate');  
        } catch(Exception $e) {
            if ($e->getMessage() == 'Invalid AmazonOrderId: validate') {
                return true;
            } else {
                return false;    
            }
        }
    }
    
    /**
     * Returns the current competitive price of a product, based on ASIN.
     * @param array [$asin_array = []]
     * @return array
     */
    public function GetCompetitivePricingForASIN($asin_array = [])
    {
        if (count($asin_array) > 20) {
            throw new Exception('Maximum amount of ASIN\'s for this call is 20');    
        }
        
        $counter = 1;
        $query = [
            'MarketplaceId' => $this->config['Marketplace_Id']
        ];
        
        foreach($asin_array as $key){
            $query['ASINList.ASIN.' . $counter] = $key; 
            $counter++;
        }
        
        $response = $this->request(
            'GetCompetitivePricingForASIN',
            $query
        );
        
        if (isset($response['GetCompetitivePricingForASINResult'])) {
            $response = $response['GetCompetitivePricingForASINResult'];
            if (array_keys($response) !== range(0, count($response) - 1)) {
                $response = [$response];
            }
        } else {
            return [];    
        }
        
        $array = [];
        foreach ($response as $product) {
            if (isset($product['Product']['CompetitivePricing']['CompetitivePrices']['CompetitivePrice']['Price'])) {
                $array[$product['Product']['Identifiers']['MarketplaceASIN']['ASIN']] = $product['Product']['CompetitivePricing']['CompetitivePrices']['CompetitivePrice']['Price'];
            }
        }
        return $array;
        
    }
    
    /**
     * Returns lowest priced offers for a single product, based on ASIN.
     * @param string $asin                    
     * @param string [$ItemCondition = 'New'] Should be one in: New, Used, Collectible, Refurbished, Club
     * @return array  
     */
    public function GetLowestPricedOffersForASIN($asin, $ItemCondition = 'New')
    {
        
        $query = [
            'ASIN' => $asin,
            'MarketplaceId' => $this->config['Marketplace_Id'],
            'ItemCondition' => $ItemCondition
        ];
        
        return $this->request(
            'GetLowestPricedOffersForASIN',
            $query
        );
        
    }
    
    /**
     * Returns pricing information for your own offer listings, based on SKU.
     * @param array  [$sku_array = []]       
     * @param string [$ItemCondition = null] 
     * @return array  
     */
    public function GetMyPriceForSKU($sku_array = [], $ItemCondition = null)
    {
        if (count($sku_array) > 20) {
            throw new Exception('Maximum amount of SKU\'s for this call is 20');    
        }
        
        $counter = 1;
        $query = [
            'MarketplaceId' => $this->config['Marketplace_Id']
        ];
        
        if (!is_null($ItemCondition)) {
            $query['ItemCondition'] = $ItemCondition;
        }
        
        foreach($sku_array as $key){
            $query['SellerSKUList.SellerSKU.' . $counter] = $key; 
            $counter++;
        }
        
        $response = $this->request(
            'GetMyPriceForSKU',
            $query
        );
        
        if (isset($response['GetMyPriceForSKUResult'])) {
            $response = $response['GetMyPriceForSKUResult'];
            if (array_keys($response) !== range(0, count($response) - 1)) {
                $response = [$response];
            }
        } else {
            return [];    
        }
        
        $array = [];
        foreach ($response as $product) {
            if (isset($product['@attributes']['status']) && $product['@attributes']['status'] == 'Success') {
                $array[$product['@attributes']['SellerSKU']] = $product['Product']['Offers']['Offer'];
            } else {
                $array[$product['@attributes']['SellerSKU']] = false;
            }
        }
        return $array;
        
    }
    
    /**
     * Returns pricing information for your own offer listings, based on ASIN.
     * @param array [$asin_array = []]
     * @param string [$ItemCondition = null] 
     * @return array
     */
    public function GetMyPriceForASIN($asin_array = [], $ItemCondition = null)
    {
        if (count($asin_array) > 20) {
            throw new Exception('Maximum amount of SKU\'s for this call is 20');    
        }
        
        $counter = 1;
        $query = [
            'MarketplaceId' => $this->config['Marketplace_Id']
        ];
        
        if (!is_null($ItemCondition)) {
            $query['ItemCondition'] = $ItemCondition;
        }
        
        foreach($asin_array as $key){
            $query['ASINList.ASIN.' . $counter] = $key; 
            $counter++;
        }
        
        $response = $this->request(
            'GetMyPriceForASIN',
            $query
        );
        
        if (isset($response['GetMyPriceForASINResult'])) {
            $response = $response['GetMyPriceForASINResult'];
            if (array_keys($response) !== range(0, count($response) - 1)) {
                $response = [$response];
            }
        } else {
            return [];    
        }
        
        $array = [];
        foreach ($response as $product) {
            if (isset($product['@attributes']['status']) && $product['@attributes']['status'] == 'Success') {
                $array[$product['@attributes']['ASIN']] = $product['Product']['Offers']['Offer'];
            } else {
                $array[$product['@attributes']['ASIN']] = false;
            }
        }
        return $array;
        
    }
    
    /**
     * Returns pricing information for the lowest-price active offer listings for up to 20 products, based on ASIN.
     * @param array [$asin_array = []] array of ASIN values
     * @param array [$ItemCondition = null] Should be one in: New, Used, Collectible, Refurbished, Club. Default: All
     * @return array 
     */
    public function GetLowestOfferListingsForASIN($asin_array = [], $ItemCondition = null)
    {
        if (count($asin_array) > 20) {
            throw new Exception('Maximum amount of ASIN\'s for this call is 20');    
        }
        
        $counter = 1;
        $query = [
            'MarketplaceId' => $this->config['Marketplace_Id']
        ];
        
        if (!is_null($ItemCondition)) {
            $query['ItemCondition'] = $ItemCondition;
        }
        
        foreach($asin_array as $key){
            $query['ASINList.ASIN.' . $counter] = $key; 
            $counter++;
        }
        
        $response = $this->request(
            'GetLowestOfferListingsForASIN',
            $query
        );
        
        if (isset($response['GetLowestOfferListingsForASINResult'])) {
            $response = $response['GetLowestOfferListingsForASINResult'];
            if (array_keys($response) !== range(0, count($response) - 1)) {
                $response = [$response];
            }
        } else {
            return [];    
        }
        
        $array = [];
        foreach ($response as $product) {
            if (isset($product['Product']['LowestOfferListings']['LowestOfferListing'])) {
                $array[$product['Product']['Identifiers']['MarketplaceASIN']['ASIN']] = $product['Product']['LowestOfferListings']['LowestOfferListing'];
            } else {
                $array[$product['Product']['Identifiers']['MarketplaceASIN']['ASIN']] = false;
            }
        }
        return $array;
        
    }
    
    /**
     * Returns orders created or updated during a time frame that you specify.
     * @param object DateTime $from 
     * @return array
     */
    public function ListOrders(DateTime $from)
    {
        $query = [
            'CreatedAfter' => gmdate(self::DATE_FORMAT, $from->getTimestamp()),
            'OrderStatus.Status.1' => 'Unshipped',
            'OrderStatus.Status.2' => 'PartiallyShipped',
            'FulfillmentChannel.Channel.1' => 'MFN'
        ];
        
        $response = $this->request(
            'ListOrders',
            $query
        );
        
        if (isset($response['ListOrdersResult']['Orders']['Order'])) {
            $response = $response['ListOrdersResult']['Orders']['Order'];
            if (array_keys($response) !== range(0, count($response) - 1)) {
                return [$response];
            }
            return $response;
        } else {
            return [];    
        }   
    }
    /**
     * Returns unshipped orders created or updated during 60 days.
     * @return array
     */
    public function getShippedOrders()
    {
        $query = [
            'CreatedAfter' => date("c", time() - 7 * 24 * 60 * 60),
            'CreatedBefore' => date("c", time() - 200 ),
            'OrderStatus.Status.1' => 'Shipped',
            'FulfillmentChannel.Channel.1' => 'MFN'
        ];

        $response = $this->request(
            'ListOrders',
            $query
        );

        if (isset($response['ListOrdersResult']['Orders']['Order'])) {
            $response = $response['ListOrdersResult']['Orders']['Order'];
            return $response;
        } else {
            return [];
        }
    }
    /**
     * Returns unshipped orders created or updated during 60 days.
     * @return array
     */
    public function getUnshippedOrders()
    {
        $query = [
            'CreatedAfter' => date("c", time() - 60 * 24 * 60 * 60),
            'CreatedBefore' => date("c", time() - 200 ),
            'OrderStatus.Status.1' => 'Unshipped',
            'OrderStatus.Status.2' => 'PartiallyShipped',
            'FulfillmentChannel.Channel.1' => 'MFN'
        ];

        $response = $this->request(
            'ListOrders',
            $query
        );

        if (isset($response['ListOrdersResult']['Orders']['Order'])) {
            $response = $response['ListOrdersResult']['Orders']['Order'];
            return $response;
        } else {
            return [];
        }
    }

    /**
     * Returns an order based on the AmazonOrderId values that you specify.
     * @param string $AmazonOrderId
     * @return array if the order is found, false if not
     */
    public function GetOrder($AmazonOrderId)
    { 
        $response = $this->request('GetOrder', [
            'AmazonOrderId.Id.1' => $AmazonOrderId
        ]); 
        
        if (isset($response['GetOrderResult']['Orders']['Order'])) {
            return $response['GetOrderResult']['Orders']['Order'];
        } else {
            return false;    
        }
    }
    
    /**
     * Returns order items based on the AmazonOrderId that you specify.
     * @param string $AmazonOrderId
     * @return array  
     */
    public function ListOrderItems($AmazonOrderId)
    {
        $response = $this->request('ListOrderItems', [
            'AmazonOrderId' => $AmazonOrderId
        ]);
        
        $result = array_values($response['ListOrderItemsResult']['OrderItems']);
        
        if (isset($result[0]['QuantityOrdered'])) {
            return $result;  
        } else {
            return $result[0];   
        }  
    }

    /**
     * Returns order items based on the AmazonOrderId that you specify.
     * @param string $AmazonOrderId
     * @return array
     */
    public function getOrderItems($AmazonOrderId)
    {
        $resArr = array();
        $response = $this->request('ListOrderItems', [
            'AmazonOrderId' => $AmazonOrderId
        ]);
        $result = array_values($response['ListOrderItemsResult']['OrderItems']);
        if (isset($result[0]['QuantityOrdered'])) {
            foreach( $result as $Items){
                $transaction_info = array();
                $transaction_info['ItemID'] = $Items['ASIN'];
                $transaction_info['Title'] = $Items['Title'];
                $transaction_info['TransactionID'] = uniqid();
                $transaction_info['QuantityPurchased'] = $Items['QuantityOrdered'];
                $transaction_info['TransactionPriceCurrencyID'] = $Items['ItemPrice']['CurrencyCode'];
                $transaction_info['TransactionPriceValue'] = $Items['ItemPrice']['Amount'];
                $itemDetails = $this->getItemDetails($Items['ASIN']);
                print_r( $itemDetails);exit;
                /*$transaction_info['GalleryURL'] = $itemDetails['GalleryURL'];
                $transaction_info['WeightValue'] = $itemDetails['Weight'];
                $transaction_info['WeightUnit']  = '';
                $transaction_info['DepthValue']  = $itemDetails['Weight'];
                $transaction_info['DepthUnit']   = '';
                $transaction_info['LengthValue'] = $itemDetails['Length'];
                $transaction_info['LengthUnit']  = '';
                $transaction_info['WidthValue']  = $itemDetails['Width'];
                $transaction_info['WidthUnit']   = '';*/

                //$transaction_info['SellerSKU']   = str_replace(' ', '', trim($Items->SellerSKU));
                array_push($resArr, $transaction_info);
            }
            return $resArr;
        } else {
            return $result[0];
        }
    }

    /**
     * Returns the parent product details .
     * @param string $ItemID
     * @return array if found, false if not found
     */
    public function getItemDetails($itemId){
        $this->SetLocale( 'uk' );
        $keyId      =  $this->config['ASSOCIATES_KEY_ID'] ;
        $secretKey  =   $this->config['ASSOCIATES_SECRET_KEY'] ;
        $associateId    = $this->config['ASSOCIATES_ID'];
        $result = array();
        //$advAPI = new AdvertizeAPI( $keyId, $secretKey, $associateId );
        $result = $this->ItemLookup( $itemId );
        echo "<pre>";
        print_r($result->Items);
        echo "</pre>";
        exit;

        $items = $items->Items->Item;
        $result['GalleryURL'] = (string)$items['MediumImage']->URL;
        if( isset($items['ItemAttributes']) ){
            $package_dimensions = get_object_vars($items['ItemAttributes']->PackageDimensions);
            $height = $package_dimensions['Height'];
            $length = $package_dimensions['Length'];
            $weight = $package_dimensions['Weight'];
            $width  = $package_dimensions['Width'];
            $result['Height'] = $height;
            $result['Length'] = $length;
            $result['Weight'] = $weight;
            $result['Width'] = $width;
        }
        else{
            $result['Height'] = '';
            $result['Length'] = '';
            $result['Weight'] = '';
            $result['Width'] = '';
        }
        return $result;
    }

    /**
     * Returns the parent product categories that a product belongs to, based on SellerSKU.
     * @param string $SellerSKU
     * @return array if found, false if not found
     */
    public function GetProductCategoriesForSKU($SellerSKU)
    {
        $result = $this->request('GetProductCategoriesForSKU', [
            'MarketplaceId' => $this->config['Marketplace_Id'],
            'SellerSKU' => $SellerSKU
        ]);
        
        if (isset($result['GetProductCategoriesForSKUResult']['Self'])) {
            return $result['GetProductCategoriesForSKUResult']['Self'];    
        } else {
            return false;    
        }
    }
    
    /**
     * Returns the parent product categories that a product belongs to, based on ASIN.
     * @param string $ASIN
     * @return array if found, false if not found
     */
    public function GetProductCategoriesForASIN($ASIN)
    {
        $result = $this->request('GetProductCategoriesForASIN', [
            'MarketplaceId' => $this->config['Marketplace_Id'],
            'ASIN' => $ASIN
        ]);
        
        if (isset($result['GetProductCategoriesForASINResult']['Self'])) {
            return $result['GetProductCategoriesForASINResult']['Self'];    
        } else {
            return false;    
        }
    }
    
    /**
     * Returns a list of products and their attributes, based on a list of ASIN, GCID, SellerSKU, UPC, EAN, ISBN, and JAN values.
     * @param array $asin_array A list of id's
     * @param string [$type = 'ASIN']  the identifier name
     * @return array
     */
    public function GetMatchingProductForId(array $asin_array, $type = 'ASIN')
    { 
        $asin_array = array_unique($asin_array);
        
        if(count($asin_array) > 5) {
            throw new Exception('Maximum number of id\'s = 5');    
        }
        
        $counter = 1;
        $array = [
            'MarketplaceId' => $this->config['Marketplace_Id'],
            'IdType' => $type
        ];
        
        foreach($asin_array as $asin){
            $array['IdList.Id.' . $counter] = $asin; 
            $counter++;
        }
        
        $response = $this->request(
            'GetMatchingProductForId',
            $array,
            null,
            true
        ); 
        
        $languages = [
            'de-DE', 'en-EN', 'es-ES', 'fr-FR', 'it-IT', 'en-US'
        ];
        
        $replace = [
            '</ns2:ItemAttributes>' => '</ItemAttributes>'
        ];
        
        foreach($languages as $language) {
            $replace['<ns2:ItemAttributes xml:lang="' . $language . '">'] = '<ItemAttributes><Language>' . $language . '</Language>';
        }
        
        $replace['ns2:'] = '';
        
        $response = $this->xmlToArray(strtr($response, $replace));
        
        if (isset($response['GetMatchingProductForIdResult']['@attributes'])) {
            $response['GetMatchingProductForIdResult'] = [
                0 => $response['GetMatchingProductForIdResult']
            ];    
        }
    
        $found = [];
        $not_found = [];
        
        if (isset($response['GetMatchingProductForIdResult']) && is_array($response['GetMatchingProductForIdResult'])) {
            $array = [];
            foreach ($response['GetMatchingProductForIdResult'] as $product) {
                $asin = $product['@attributes']['Id'];
                if ($product['@attributes']['status'] != 'Success') {
                    $not_found[] = $asin;    
                } else {
                    $array = [];
                    if (!isset($product['Products']['Product']['AttributeSets'])) {
                        $product['Products']['Product'] = $product['Products']['Product'][0];    
                    }
                    foreach ($product['Products']['Product']['AttributeSets']['ItemAttributes'] as $key => $value) {
                        if (is_string($key) && is_string($value)) {
                            $array[$key] = $value;    
                        }
                    }
                    if (isset($product['Products']['Product']['AttributeSets']['ItemAttributes']['SmallImage'])) {
                        $image = $product['Products']['Product']['AttributeSets']['ItemAttributes']['SmallImage']['URL'];
                        $array['medium_image'] = $image;
                        $array['small_image'] = str_replace('._SL75_', '._SL50_', $image);
                        $array['large_image'] = str_replace('._SL75_', '', $image);;
                    }
                    $found[$asin] = $array;
                }
            }
        }
        
        return [
            'found' => $found,
            'not_found' => $not_found
        ];
    
    }
    
    /**
     * Returns a list of reports that were created in the previous 90 days.
     * @param array [$ReportTypeList = []]
     * @return array
     */
    public function GetReportList($ReportTypeList = [])
    {
        $array = [];
        $counter = 1;
        if (count($ReportTypeList)) {
            foreach($ReportTypeList as $ReportType) {
                $array['ReportTypeList.Type.' . $counter] = $ReportType;
                $counter++;
            }
        }
        
        return $this->request('GetReportList', $array);   
    }
    
    /**
     * Returns your active recommendations for a specific category or for all categories for a specific marketplace.
     * @param string [$RecommendationCategory = null] One of: Inventory, Selection, Pricing, Fulfillment, ListingQuality, GlobalSelling, Advertising
     * @return array/false if no result
     */
    public function ListRecommendations($RecommendationCategory = null)
    {
        $query = [
            'MarketplaceId' => $this->config['Marketplace_Id']
        ];
        
        if (!is_null($RecommendationCategory)) {
            $query['RecommendationCategory'] = $RecommendationCategory;    
        }
        
        $result = $this->request('ListRecommendations', $query);
        
        if (isset($result['ListRecommendationsResult'])) {
            return $result['ListRecommendationsResult'];
        } else {
            return false;    
        }
        
    }
    
    /**
     * Returns a list of marketplaces that the seller submitting the request can sell in, and a list of participations that include seller-specific information in that marketplace
     * @return array
     */
    public function ListMarketplaceParticipations()
    {
        $result = $this->request('ListMarketplaceParticipations');   
        if (isset($result['ListMarketplaceParticipationsResult'])) {
            return $result['ListMarketplaceParticipationsResult'];    
        } else {
            return $result;
        }
    }
    
    /**
     * Update a product's stock quantity
     * @param array $array array containing sku as key and quantity as value
     * @return array feed submission result
     */
    public function updateStock(array $array)
    {   
        $feed = [
            'MessageType' => 'Inventory',
            'Message' => []
        ];
        
        foreach ($array as $sku => $quantity) {
            $feed['Message'][] = [
                'MessageID' => rand(),
                'OperationType' => 'Update',
                'Inventory' => [
                    'SKU' => $sku,
                    'Quantity' => (int) $quantity
                ]
            ];  
        }
        
        return $this->SubmitFeed('_POST_INVENTORY_AVAILABILITY_DATA_', $feed);
        
    }
    
    /**
     * Update a product's price
     * @param array $array an array containing sku as key and price as value
     * Price has to be formatted as XSD Numeric Data Type (http://www.w3schools.com/xml/schema_dtypes_numeric.asp)
     * @return array feed submission result
     */
    public function updatePrice(array $array)
    {   
        
        $feed = [
            'MessageType' => 'Price',
            'Message' => []
        ];
        
        foreach ($array as $sku => $price) {
            $feed['Message'][] = [
                'MessageID' => rand(),
                'Price' => [
                    'SKU' => $sku,
                    'StandardPrice' => [
                        '_value' => $price,
                        '_attributes' => [
                            'currency' => 'DEFAULT'
                        ]
                    ]
                ]
            ];  
        }
        
        return $this->SubmitFeed('_POST_PRODUCT_PRICING_DATA_', $feed);
        
    }
    
    /**
     * Returns the feed processing report and the Content-MD5 header.
     * @param string $FeedSubmissionId
     * @return array
     */
    public function GetFeedSubmissionResult($FeedSubmissionId)
    {
        $result = $this->request('GetFeedSubmissionResult', [
            'FeedSubmissionId' => $FeedSubmissionId
        ]); 
        
        if (isset($result['Message']['ProcessingReport'])) {
            return $result['Message']['ProcessingReport'];    
        } else {
            return $result;    
        }
    }
    
    /**
     * Uploads a feed for processing by Amazon MWS.
     * @param string $FeedType (http://docs.developer.amazonservices.com/en_US/feeds/Feeds_FeedType.html)
     * @param mixed $feedContent Array will be converted to xml using https://github.com/spatie/array-to-xml. Strings will not be modified.
     * @param boolean $debug Return the generated xml and don't send it to amazon
     * @return array
     */
    public function SubmitFeed($FeedType, $feedContent, $debug = false)
    {
        
        if (is_array($feedContent)) {
            $feedContent = $this->arrayToXml(
                array_merge([
                    'Header' => [
                        'DocumentVersion' => 1.01,
                        'MerchantIdentifier' => $this->config['Seller_Id']
                    ]
                ], $feedContent)
            );
        }
        
        if ($debug === true) {
            return $feedContent;    
        }
        
        $query = [
            'FeedType' => $FeedType,
            'PurgeAndReplace' => 'false',
            'Merchant' => $this->config['Seller_Id'],
            'MarketplaceId.Id.1' => false,
            'SellerId' => false,
        ];
        
        if ($FeedType === '_POST_PRODUCT_PRICING_DATA_') {
            $query['MarketplaceIdList.Id.1'] = $this->config['Marketplace_Id'];        
        }
        
        $response = $this->request(
            'SubmitFeed',
            $query,
            $feedContent
        );
        
        return $response['SubmitFeedResult']['FeedSubmissionInfo'];
    }
    
    /**
     * Convert an array to xml
     * @param $array array to convert
     * @param $customRoot [$customRoot = 'AmazonEnvelope']
     * @return sting
     */
    private function arrayToXml(array $array, $customRoot = 'AmazonEnvelope')
    {
        return ArrayToXml::convert($array, $customRoot);
    }
    
    /**
     * Convert an xml string to an array
     * @param string $xmlstring 
     * @return array
     */
    private function xmlToArray($xmlstring)
    {
        return json_decode(json_encode(simplexml_load_string($xmlstring)), true);
    }
    
    /**
     * Creates a report request and submits the request to Amazon MWS.
     * @param string $report (http://docs.developer.amazonservices.com/en_US/reports/Reports_ReportType.html)
     * @param DateTime [$StartDate = null]
     * @param EndDate [$EndDate = null]
     * @return string ReportRequestId
     */
    public function RequestReport($report, $StartDate = null, $EndDate = null)
    {
        $query = [
            'ReportType' => $report
        ];
        
        if (!is_null($StartDate)) {
            if (!is_a($StartDate, 'DateTime')) {
                throw new Exception('StartDate should be a DateTime object');       
            } else {
                $query['StartDate'] = gmdate(self::DATE_FORMAT, $StartDate->getTimestamp());
            }
        }
        
        if (!is_null($EndDate)) {
            if (!is_a($EndDate, 'DateTime')) {
                throw new Exception('EndDate should be a DateTime object');       
            } else {
                $query['EndDate'] = gmdate(self::DATE_FORMAT, $EndDate->getTimestamp());
            }
        }
    
        $result = $this->request(
            'RequestReport',
            $query
        );
        
        if (isset($result['RequestReportResult']['ReportRequestInfo']['ReportRequestId'])) {
            return $result['RequestReportResult']['ReportRequestInfo']['ReportRequestId'];
        } else {
            throw new Exception('Error trying to request report');    
        }
    }
    
    /**
     * Get a report's content
     * @param string $ReportId
     * @return array on succes
     */
    public function GetReport($ReportId)
    {
        $status = $this->GetReportRequestStatus($ReportId);
        
        if ($status !== false && $status['ReportProcessingStatus'] === '_DONE_NO_DATA_') {
            return [];
        } else if ($status !== false && $status['ReportProcessingStatus'] === '_DONE_') {
            
            $result = $this->request('GetReport', [
                'ReportId' => $status['GeneratedReportId']
            ]);
            
            if (is_string($result)) {
                $csv = Reader::createFromString($result);
                $csv->setDelimiter("\t");
                $headers = $csv->fetchOne();
                $result = [];
                foreach ($csv->setOffset(1)->fetchAll() as $row) {
                    $result[] = array_combine($headers, $row);    
                }
            }
            
            return $result;
            
        } else {
            return false;    
        }
    }
    
    /**
     * Get a report's processing status
     * @param string  $ReportId
     * @return array if the report is found
     */
    public function GetReportRequestStatus($ReportId)
    {
        $result = $this->request('GetReportRequestList', [
            'ReportRequestIdList.Id.1' => $ReportId    
        ]);
          
        if (isset($result['GetReportRequestListResult']['ReportRequestInfo'])) {
            return $result['GetReportRequestListResult']['ReportRequestInfo'];
        } 
        
        return false;
        
    }
    
    /**
     * Request MWS
     */
    private function request($endPoint, array $query = [], $body = null, $raw = false)
    {
    
        $endPoint = MWSEndPoint::get($endPoint);
        
        $query = array_merge([
            'Timestamp' => gmdate(self::DATE_FORMAT, time()),
            'AWSAccessKeyId' => $this->config['Access_Key_ID'],
            'Action' => $endPoint['action'],
            'MarketplaceId.Id.1' => 'A1F83G8C2ARO7P',
            'MarketplaceId.Id.2' => 'A1PA6795UKMFR9',
            'MarketplaceId.Id.3' => 'A1RKKUPIHCS9HS',
            'MarketplaceId.Id.4' => 'A13V1IB3VIYZZH',
            'MarketplaceId.Id.5' => 'APJ6JRA9NG5V4',
            'SellerId' => $this->config['Seller_Id'],
            'SignatureMethod' => self::SIGNATURE_METHOD,
            'SignatureVersion' => self::SIGNATURE_VERSION,
            'Version' => $endPoint['date'],
        ], $query);
        
        if (!is_null($this->config['MWSAuthToken'])) {
            $query['MWSAuthToken'] = $this->config['MWSAuthToken'];
        }
        
        if (isset($query['MarketplaceId'])) {
            unset($query['MarketplaceId.Id.1']);
        }
        
        try{
            
            $headers = [
                'Accept' => 'application/xml',
                'x-amazon-user-agent' => $this->config['Application_Name'] . '/' . $this->config['Application_Version']
            ];
            
            if ($endPoint['action'] === 'SubmitFeed') {
                $headers['Content-MD5'] = base64_encode(md5($body, true));
                $headers['Content-Type'] = 'text/xml; charset=iso-8859-1';
                $headers['Host'] = $this->config['Region_Host'];
                
                unset(
                    $query['MarketplaceId.Id.1'],
                    $query['SellerId']
                );  
            }
            
            $requestOptions = [
                'headers' => $headers,
                'body' => $body
            ];
            
            ksort($query);
            
            $query['Signature'] = base64_encode(
                hash_hmac(
                    'sha256', 
                    $endPoint['method']
                    . PHP_EOL 
                    . $this->config['Region_Host']
                    . PHP_EOL 
                    . $endPoint['path'] 
                    . PHP_EOL 
                    . http_build_query($query), 
                    $this->config['Secret_Access_Key'], 
                    true
                )
            );
            
            $requestOptions['query'] = $query;
            
            $client = new Client();
            
            $response = $client->request(
                $endPoint['method'], 
                $this->config['Region_Url'] . $endPoint['path'], 
                $requestOptions
            );
            
            $body = (string) $response->getBody();
            
            if ($raw) {
                return $body;    
            } else if (strpos(strtolower($response->getHeader('Content-Type')[0]), 'xml') !== false) {
                return $this->xmlToArray($body);          
            } else {
                return $body;
            }
           
        } catch(BadResponseException $e) {
            if ($e->hasResponse()) {
                $message = $e->getResponse();
                $message = $message->getBody();
                if (strpos($message, '<ErrorResponse') !== false) {
                    $error = simplexml_load_string($message);
                    $message = $error->Error->Message;
                }
            } else {
                $message = 'An error occured';    
            }
            throw new Exception($message);
        }  
    }

    /*
     * Advertize API Functions
     */
    /**
     * Lookup items from ASINs
     *
     * @param	asinList			Either a single ASIN or an array of ASINs
     * @param	onlyFromAmazon		True if only requesting items from Amazon and not 3rd party vendors
     *
     * @return	mixed				SimpleXML object, array of data or false if failure.
     */
    /**
     * Enable or disable SSL endpoints
     *
     * @param	useSSL 		True if using SSL, false otherwise
     *
     * @return	None
     */
    public function SetSSL( $useSSL = true )
    {
        $this->m_useSSL = $useSSL;
    }

    /**
     * Enable or disable retrieving items array rather than XML
     *
     * @param	retrieveArray	True if retrieving as array, false otherwise.
     *
     * @return	None
     */
    public function SetRetrieveAsArray( $retrieveArray = true )
    {
        $this->m_retrieveArray	= $retrieveArray;
    }

    /**
     * Sets the locale for the endpoints
     *
     * @param	locale		Set to a valid AWS locale - see link below.
     * @link 	http://docs.amazonwebservices.com/AWSECommerceService/latest/DG/Locales.html
     *
     * @return	None
     */
    public function SetLocale( $locale )
    {
        // Check we have a locale in our table
        if ( !array_key_exists( $locale, $this->m_localeTable ) )
        {
            // If not then just assume it's US
            $locale = 'us';
        }

        // Set the URL for this locale
        $this->m_locale = $locale;

        // Check for SSL
        if ( $this->m_useSSL )
            $this->m_amazonUrl = 'https://' . $this->m_localeTable[$locale];
        else
            $this->m_amazonUrl = 'http://' . $this->m_localeTable[$locale];
    }

    /**
     * Return valid search names
     *
     * @param	None
     *
     * @return	Array 	Array of valid string names
     */
    public function GetValidSearchNames()
    {
        return( $this->mValidSearchNames );
    }

    /**
     * Return data from AWS
     *
     * @param	url			URL request
     *
     * @return	mixed		SimpleXML object or false if failure.
     */
    private function MakeRequest( $url )
    {
        // Check if curl is installed
        if ( !function_exists( 'curl_init' ) )
        {
            $this->AddError( "Curl not available" );
            return( false );
        }

        // Use curl to retrieve data from Amazon
        $session = curl_init( $url );
        curl_setopt( $session, CURLOPT_HEADER, false );
        curl_setopt( $session, CURLOPT_RETURNTRANSFER, true );
        $response = curl_exec( $session );

        $error = NULL;
        if ( $response === false )
            $error = curl_error( $session );

        curl_close( $session );

        // Have we had an error?
        if ( !empty( $error ) )
        {
            $this->AddError( "Error downloading data : $url : " . $error );
            return( false );
        }

        // Interpret data as XML
        $parsedXml = simplexml_load_string( $response );

        return( $parsedXml );
    }

    /**
     * Search for items
     *
     * @param	keywords			Keywords which we're requesting
     * @param	searchIndex			Name of search index (category) requested. NULL if searching all.
     * @param	sortBySalesRank		True if sorting by sales rank, false otherwise.
     * @param	condition			Condition of item. Valid conditions : Used, Collectible, Refurbished, All
     *
     * @return	mixed				SimpleXML object, array of data or false if failure.
     */
    public function ItemSearch( $keywords, $searchIndex = NULL, $sortBySalesRank = true, $condition = 'All' ,$merchantId,$availability)
    {
        // Set the values for some of the parameters.
        $operation = "ItemSearch";
        $responseGroup = "ItemAttributes,Offers,Images";

        //Define the request
        $request= $this->GetBaseUrl()
            . "&Operation=" . $operation
            . "&Keywords=" . $keywords
            . "&ResponseGroup=" . $responseGroup
            . "&MerchantId=FeaturedBuyBoxMerchant"
            . "&Condition=All" //. $condition
            . "&Availability=" . $availability  ;

        // Assume we're searching in all if an index isn't passed
        if ( empty( $searchIndex ) )
        {
            // Search for all
            $request .= "&SearchIndex=All";
        }
        else
        {
            // Searching for specific index
            $request .= "&SearchIndex=" . $searchIndex;

            // If we're sorting by sales rank
            if ( $sortBySalesRank && ( $searchIndex != 'All' ) )
                $request .= "&Sort=salesrank";
        }

        // Need to sign the request now
        $signedUrl = $this->GetSignedRequest( $this->m_secretKey, $request );

        // Get the response from the signed URL
        $parsedXml = $this->MakeRequest( $signedUrl );
        if ( $parsedXml === false )
            return( false );

        if ( $this->m_retrieveArray )
        {
            $items = $this->RetrieveItems( $parsedXml );
        }
        else
        {
            $items = $parsedXml;
        }

        return( $items );
    }

    /**
     * Lookup items from ASINs
     *
     * @param	asinList			Either a single ASIN or an array of ASINs
     * @param	onlyFromAmazon		True if only requesting items from Amazon and not 3rd party vendors
     *
     * @return	mixed				SimpleXML object, array of data or false if failure.
     */
    public function ItemLookup( $asinList, $onlyFromAmazon = false )
    {
        // Check if it's an array
        if ( is_array( $asinList ) )
        {
            $asinList = implode( ',', $asinList );
        }

        // Set the values for some of the parameters.
        $operation = "ItemLookup";
        $responseGroup = "ItemAttributes,Offers,Reviews,Images,EditorialReview";

        // Determine whether we just want Amazon results only or not
        $merchantId = ( $onlyFromAmazon == true ) ? 'Amazon' : 'All';

        $reviewSort = '-OverallRating';
        //Define the request
        $request = $this->GetBaseUrl()
            . "&ItemId=" . $asinList
            . "&Operation=" . $operation
            . "&ResponseGroup=" . $responseGroup
            . "&ReviewSort=" . $reviewSort
            . "&MerchantId=" . $merchantId;

        // Need to sign the request now
        $signedUrl = $this->GetSignedRequest( $this->config['ASSOCIATES_SECRET_KEY'], $request );

        // Get the response from the signed URL
        $parsedXml = $this->MakeRequest( $signedUrl );
        if ( $parsedXml === false )
            return( false );

        if ( $this->m_retrieveArray )
        {
            $items = $this->RetrieveItems( $parsedXml );
        }
        else
        {
            $items = $parsedXml;
        }
        return( $items );
    }

    /**
     * Basic method to retrieve only requested item data as an array
     *
     * @param	responseXML		XML data to be passed
     *
     * @return	Array			Array of item data. Empty array if not found
     */
    private function RetrieveItems( $responseXml )
    {
        $items = array();
        if ( empty( $responseXml ) )
        {
            $this->AddError( "No XML response found from AWS." );
            return( $items );
        }

        if ( empty( $responseXml->Items ) )
        {
            $this->AddError( "No items found." );
            return( $items );
        }

        if ( $responseXml->Items->Request->IsValid != 'True' )
        {
            $errorCode = $responseXml->Items->Request->Errors->Error->Code;
            $errorMessage = $responseXml->Items->Request->Errors->Error->Message;
            $error = "API ERROR ($errorCode) : $errorMessage";
            $this->AddError( $error );
            return( $items );
        }

        // Get each item
        foreach( $responseXml->Items->Item as $responseItem )
        {
            $item = array();
            $item['asin'] = (string) $responseItem->ASIN;
            $item['url'] = (string) $responseItem->DetailPageURL;
            $item['rrp'] = ( (float) $responseItem->ItemAttributes->ListPrice->Amount ) / 100.0;
            $item['title'] = (string) $responseItem->ItemAttributes->Title;

            if ( $responseItem->OfferSummary )
            {
                $item['lowestPrice'] = ( (float) $responseItem->OfferSummary->LowestNewPrice->Amount ) / 100.0;
            }
            else
            {
                $item['lowestPrice'] = 0.0;
            }

            // Images
            $item['largeImage'] = (string) $responseItem->LargeImage->URL;
            $item['mediumImage'] = (string) $responseItem->MediumImage->URL;
            $item['smallImage'] = (string) $responseItem->SmallImage->URL;

            array_push( $items, $item );
        }

        return( $items );
    }

    /**
     * Determines the base address of the request
     *
     * @param	None
     *
     * @return	string		Base URL of AWS request
     */
    private function GetBaseUrl()
    {
        //Define the request
        $request=
            $this->m_amazonUrl
            . "?Service=AWSECommerceService"
            . "&AssociateTag="
            . "&AWSAccessKeyId=" . $this->config['ASSOCIATES_KEY_ID'] ;

        return( $request );
    }

    /**
     * This function will take an existing Amazon request and change it so that it will be usable
     * with the new authentication.
     *
     * @param string $secret_key - your Amazon AWS secret key
     * @param string $request - your existing request URI
     * @param string $access_key - your Amazon AWS access key
     * @param string $version - (optional) the version of the service you are using
     *
     * @link http://www.ilovebonnie.net/2009/07/27/amazon-aws-api-rest-authentication-for-php-5/
     */
    private function GetSignedRequest( $secret_key, $request, $access_key = false, $version = '2011-08-01')
    {
        // Get a nice array of elements to work with
        $uri_elements = parse_url($request);

        // Grab our request elements
        $request = $uri_elements['query'];

        // Throw them into an array
        parse_str($request, $parameters);

        // Add the new required paramters
        $parameters['Timestamp'] = gmdate( "Y-m-d\TH:i:s\Z" );
        $parameters['Version'] = $version;
        if ( strlen($access_key) > 0 )
        {
            $parameters['AWSAccessKeyId'] = $access_key;
        }

        // The new authentication requirements need the keys to be sorted
        ksort( $parameters );

        // Create our new request
        foreach ( $parameters as $parameter => $value )
        {
            // We need to be sure we properly encode the value of our parameter
            $parameter = str_replace( "%7E", "~", rawurlencode( $parameter ) );
            $value = str_replace( "%7E", "~", rawurlencode( $value ) );
            $request_array[] = $parameter . '=' . $value;
        }

        // Put our & symbol at the beginning of each of our request variables and put it in a string
        $new_request = implode( '&', $request_array );

        // Create our signature string
        $signature_string = "GET\n{$uri_elements['host']}\n{$uri_elements['path']}\n{$new_request}";

        // Create our signature using hash_hmac
        $signature = urlencode( base64_encode( hash_hmac( 'sha256', $signature_string, $secret_key, true ) ) );

        // Return our new request
        return "http://{$uri_elements['host']}{$uri_elements['path']}?{$new_request}&Signature={$signature}";
    }

    /**
     * Adds error to an error array
     *
     * @param	error	Error string
     *
     * @return	None
     */
    private function AddError( $error )
    {
        array_push( $this->mErrors, $error );
    }

    /**
     * Returns array of errors
     *
     * @param	None
     *
     * @return	Array		Array of errors. Empty array if none found
     */
    public function GetErrors()
    {
        return( $this->mErrors );
    }


}
