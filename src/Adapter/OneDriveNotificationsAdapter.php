<?php

namespace JacekBarecki\FlysystemOneDrive\Adapter;

use JacekBarecki\FlysystemOneDrive\Client\OneDriveClient;
use JacekBarecki\FlysystemOneDrive\Client\OneDriveClientException;
use League\Flysystem\Adapter\Polyfill\NotSupportingVisibilityTrait;

class OneDriveNotificationsAdapter
{
    use NotSupportingVisibilityTrait;

    /**
     * @var OneDriveClient
     */
    private $client;

    /**
     * @param OneDriveClient $client
     */
    public function __construct(OneDriveClient $client) {
        $this->client = $client;
    }


    public function createSubscription($notificationUrl, $expirationDate, $clientState = "", $resource = "") {
        if(!isset($notificationUrl)) {
            throw new OneDriveClientException('NotificationUrl is mandatory');
        }
        $response = $this->client->subscribe($notificationUrl, $expirationDate, $clientState, $resource);
        $responseContent = json_decode((string) $response->getBody());
        return $responseContent;
    }


    /**
     * @param string $deltaToken
     *
     * @return \Psr\Http\Message\ResponseInterface
     *
     * @throws OneDriveClientException
     *
     * @link https://dev.onedrive.com/items/view_delta.htm
     */
    public function delta($deltaToken=null) {
        $response = $this->client->delta($deltaToken);
        $responseContent = json_decode((string) $response->getBody());
        return $responseContent;
    }


}