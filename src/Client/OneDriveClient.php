<?php

namespace JacekBarecki\FlysystemOneDrive\Client;

use League\OAuth2\Client\Tool\QueryBuilderTrait;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Message\Request;
use GuzzleHttp\Psr7\Stream;

class OneDriveClient
{
    use QueryBuilderTrait;

    /**
     * @var string
     */
    private $accessToken;

    /**
     * @var Client
     */
    private $guzzle;

    const BASE_URI = 'https://api.onedrive.com/v1.0/';
    const ROOT_PATH = 'drive/root';

    /**
     * @param string $accessToken
     * @param Client $guzzle
     */
    public function __construct($accessToken, Client $guzzle)
    {
        $this->accessToken = $accessToken;
        $this->guzzle = $guzzle;
    }

    /**
     * @param string $path
     *
     * @return \Psr\Http\Message\ResponseInterface
     *
     * @throws OneDriveClientException
     *
     * @link https://dev.onedrive.com/items/download.htm
     */
    public function download($path)
    {
        $url = $this->getDownloadUrlForFile($path);

        return $this->getResponse('GET', $url);
    }

    /**
     * @param string $path
     *
     * @return resource
     *
     * @throws OneDriveClientException
     *
     * @link https://dev.onedrive.com/items/download.htm
     */
    public function downloadStream($path)
    {
        $url = $this->getDownloadUrlForFile($path);
        $input = $this->getStreamForUrl($url);

        $output = fopen('php://temp', 'w+');
        if ($output === false) {
            throw new OneDriveClientException('Error when saving the downloaded file');
        }

        while (!$input->eof()) {
            $writeResult = fwrite($output, $input->read(8192));
            if ($writeResult === false) {
                throw new OneDriveClientException('Error when saving the downloaded file');
            }
        }

        $input->close();

        rewind($output);

        return $output;
    }

    /**
     * @param string $url
     *
     * @return Stream
     */
    public function getReadableResource($path)
    {
        $url = $this->getDownloadUrlForFile($path);
        $resource = fopen($url, 'r');

        return $resource;
    }

    /**
     * @param string $url
     *
     * @return Stream
     */
    public function getStreamForUrl($url)
    {
        $resource = fopen($url, 'r');

        return new Stream($resource);
    }

    /**
     * @param string $path
     *
     * @return \Psr\Http\Message\ResponseInterface
     *
     * @link https://dev.onedrive.com/items/get.htm
     */
    public function getMetadata($path)
    {
        $url = self::BASE_URI.$this->getPathUnderRootDrive($path);

        return $this->getResponse('GET', $url);
    }


    /**
     * @param string $fileId
     *
     * @return \Psr\Http\Message\ResponseInterface
     *
     * @link https://dev.onedrive.com/items/get.htm
     */
    public function getFileMetadata($fileId) {
        $url = self::BASE_URI.$this->getPathFileMetadata($fileId);

        return $this->getResponse('GET', $url);
    }


    /**
     * @return \Psr\Http\Message\ResponseInterface
     *
     * @link https://dev.onedrive.com/resources/drive.htm
     */
    public function getUserDriveMetadata()
    {
        $url = self::BASE_URI.$this->getPathUserDriveMetadata();

        return $this->getResponse('GET', $url);
    }

    /**
     * @param string $path
     * @param string $content
     *
     * @return \Psr\Http\Message\ResponseInterface
     *
     * @link https://dev.onedrive.com/items/upload_put.htm
     */
    public function createFile($path, $content)
    {
        return $this->simpleItemUpload($path, $content, 'fail');
    }

    /**
     * @param string $path
     * @param string $content
     *
     * @return \Psr\Http\Message\ResponseInterface
     *
     * @link https://dev.onedrive.com/items/upload_put.htm
     */
    public function updateFile($path, $content)
    {
        return $this->simpleItemUpload($path, $content, 'replace');
    }

    /**
     * @param string $oldPath
     * @param string $newPath
     *
     * @return \Psr\Http\Message\ResponseInterface
     */
    public function rename($oldPath, $newPath)
    {
        $payload = $this->getNewFileLocationPayload($newPath);

        return $this->updateMetadata($oldPath, $payload);
    }

    /**
     * @param string $oldPath
     * @param string $newPath
     *
     * @return \Psr\Http\Message\ResponseInterface
     *
     * @throws OneDriveClientException
     *
     * @link https://dev.onedrive.com/items/copy.htm
     */
    public function copy($oldPath, $newPath)
    {
        $url = self::BASE_URI.$this->getPathUnderRootDrive($oldPath).':/action.copy';

        $payload = $this->getNewFileLocationPayload($newPath);

        $headers = [
            'Content-Type' => 'application/json',
            'Prefer' => 'respond-async',
        ];
        $response = $this->getResponse('POST', $url, json_encode($payload), $headers);
        $asyncStatusLocation = $response->getHeader('Location');

        //check for the status of an async operation
        $completed = false;
        while (!$completed) {
            $statusResponse = $this->getResponse('GET', $asyncStatusLocation);

            $statusCode = $statusResponse->getStatusCode();
            if ($statusCode == '303' || $statusCode == '200') {
                $completed = true;
            } else {
                $statusRaw = $statusResponse->getBody()->getContents();
                $status = json_decode($statusRaw);
                if ($status->status == 'failed') {
                    throw new OneDriveClientException('API error when copying the file');
                }

                //wait some time until the next status check
                sleep(0.5);
            }
        }
        return $this->getMetadata($newPath);
    }

    /**
     * @param string $path
     * @param array  $metadata
     *
     * @return \Psr\Http\Message\ResponseInterface
     *
     * @link https://dev.onedrive.com/items/update.htm
     */
    public function updateMetadata($path, array $metadata)
    {
        $url = self::BASE_URI.$this->getPathUnderRootDrive($path);

        return $this->getResponse('PATCH', $url, json_encode($metadata), ['Content-Type' => 'application/json']);
    }

    /**
     * @param string $path
     *
     * @return \Psr\Http\Message\ResponseInterface
     *
     * @link https://dev.onedrive.com/items/create.htm
     */
    public function createFolder($path)
    {
        $pathinfo = pathinfo($path);

        $parentDir = ($pathinfo['dirname'] != '.') ? $pathinfo['dirname'] : '';
        $url = self::BASE_URI.$this->getFolderUrl($parentDir).'children';

        $folder = new Folder();
        $folder->name = $pathinfo['basename'];

        return $this->getResponse('POST', $url, json_encode($folder), ['Content-Type' => 'application/json']);
    }

    /**
     * @param string $path
     *
     * @return \Psr\Http\Message\ResponseInterface
     *
     * @link https://dev.onedrive.com/items/list.htm
     */
    public function listChildren($path)
    {
        $url = self::BASE_URI.$this->getFolderUrl($path).'children';

        return $this->getResponse('GET', $url);
    }

    /**
     * @param string $path
     *
     * @return bool Success
     *
     * @link https://dev.onedrive.com/items/delete.htm
     */
    public function delete($path)
    {
        $url = self::BASE_URI.$this->getPathUnderRootDrive($path);

        $response = $this->getResponse('DELETE', $url);
        $success = ($response->getStatusCode() == '204');

        return $success;
    }

    /**
     * @param string $path
     *
     * @return bool
     */
    public function itemExists($path)
    {
        try {
            $this->getMetadata($path);
        } catch (ClientException $e) {
            if ($e->getResponse()->getStatusCode() == '404') {
                return false;
            }

            throw $e;
        }

        return true;
    }


    /**
     * @param string $notificationUrl
     * @param string $expirationDate
     * @param string $clientState
     * @param string $resource
     *
     * @return \Psr\Http\Message\ResponseInterface
     *
     * @throws OneDriveClientException
     *
     * @link https://dev.onedrive.com/webhooks/create-subscription.htm
     */
    public function subscribe($notificationUrl, $expirationDate, $clientState=null, $resource = "") 
    {
        if(!isset($notificationUrl)) {
            throw new OneDriveClientException('NotificationUrl is mandatory');
        }

        $url = self::BASE_URI.self::ROOT_PATH.'/subscriptions';

        $payload = [
            'notificationUrl' => $notificationUrl,
            'resource' => $resource,
            'expirationDateTime' => $expirationDate
        ];

        if(isset($clientState)) {
            $payload['clientState'] = $clientState;
        }

        $headers = [
            'Content-Type' => 'application/json'
        ];

        return $this->getResponse('POST', $url, json_encode($payload), $headers);
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
    public function delta($deltaToken=null) 
    {
        $url = self::BASE_URI.self::ROOT_PATH.'/view.delta';

        if(isset($deltaToken)) {
            $url .= '?token='.$deltaToken;
        }

        return $this->getResponse('GET', $url);
    }


    /**
     * @param string $path
     * @param string $content
     * @param string $conflictBehavior fail|replace|rename
     *
     * @return \Psr\Http\Message\ResponseInterface
     *
     * @throws OneDriveClientException
     *
     * @link https://dev.onedrive.com/items/upload_put.htm
     */
    private function simpleItemUpload($path, $content, $conflictBehavior)
    {
        if (!in_array($conflictBehavior, ['fail', 'replace', 'rename'])) {
            throw new OneDriveClientException('Incorrect conflict behavior parameter value');
        }

        $url = self::BASE_URI.$this->getPathUnderRootDrive($path).':/content?@name.conflictBehavior='.$conflictBehavior;

        return $this->getResponse('PUT', $url, $content);
    }

    /**
     * @param string $path
     *
     * @return string
     */
    private function getDownloadUrlForFile($path)
    {
        $metadataRaw = $this->getMetadata($path)->getBody()->getContents();
        $metadata = json_decode($metadataRaw);

        return $metadata->{'@content.downloadUrl'};
    }

    /**
     * Returns the payload for a copy/rename request.
     *
     * @param string $newPath
     *
     * @return array
     */
    private function getNewFileLocationPayload($newPath)
    {
        $pathinfo = pathinfo($newPath);
        $newParentDir = ($pathinfo['dirname'] != '.') ? $pathinfo['dirname'] : '';
        $newFilename = $pathinfo['basename'];

        $payload = [
            'name' => $newFilename,
            'parentReference' => ['path' => $this->getParentReferenceFolder($newParentDir)],
        ];

        return $payload;
    }

    /**
     * @param string $folder
     *
     * @return string
     */
    private function getParentReferenceFolder($folder)
    {
        if ($folder == '') {
            $parentReference = '/drive/root/';
        } else {
            $parentReference = '/drive/root:/'.$folder;
        }

        return $parentReference;
    }

    /**
     * @param string $folder
     *
     * @return string
     */
    private function getFolderUrl($folder)
    {
        if ($folder == '') {
            $url = 'drive/root/';
        } else {
            $url = $this->getPathUnderRootDrive($folder).':/';
        }

        return $url;
    }

    /**
     * @param string $path
     *
     * @return string
     */
    private function getPathUnderRootDrive($path)
    {
        return 'drive/root:/'.$path;
    }

    /**
     * @return string
     */
    private function getPathUserDriveMetadata()
    {
        return 'drive';
    }


    /**
     * @param string $fileId
     *
     * @return string path to get file metadata
     */
    private function getPathFileMetadata($fileId)
    {
        return 'drive/items/'.$fileId;
    }


    /**
     * @param string $method
     * @param string $path
     * @param string $body
     * @param array  $headers
     *
     * @return \Psr\Http\Message\ResponseInterface
     */
    private function getResponse($method, $path, $body = null, $headers = [])
    {
        $allHeaders = array_merge($this->getAuthorizationHeader(), $headers);
        $uri = $path;

        if(is_string($body)) {
            $body = $this->buildQueryStringAsStream($body);
        }
        $request = new Request($method, $uri, $allHeaders, $body);
        return $this->guzzle->send($request);
    }

    /**
     * @return array
     *
     * @link https://dev.onedrive.com/auth/msa_oauth.htm
     */
    private function getAuthorizationHeader()
    {
        return ['Authorization' => 'bearer '.$this->accessToken];
    }
}
