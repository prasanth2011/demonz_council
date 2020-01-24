<?php
namespace Drupal\flysystem_objective\Adapter;
use Drupal\flysystem_objective\Client\ObjectiveClient;
use League\Flysystem\Config;
use League\Flysystem\Util\MimeType;
//use Drupal\flysystem_objective\Exceptions\BadRequest;
use League\Flysystem\Adapter\AbstractAdapter;
use League\Flysystem\Adapter\Polyfill\NotSupportingVisibilityTrait;

class ObjectiveAdapter extends AbstractAdapter
{

    /**
     * Disconnect.
     */
    public function disconnect()
    {
        $this->connection = null;
    }

    use NotSupportingVisibilityTrait;

    protected $configuration;

    protected $client;

    public function __construct($configuration, $client, $prefix)
    {
      $this -> configuration = $configuration;
      $this->client = $client;
      $this->setPathPrefix($prefix);
    }
    /**
     * {@inheritdoc}
     */
    public function write($path, $contents, Config $config)
    {
        return $this->upload($path, $contents, 'add');
    }
    /**
     * {@inheritdoc}
     */
    public function writeStream($path, $resource, Config $config)
    {
        return $this->upload($path, $resource, 'add');
    }
    /**
     * {@inheritdoc}
     */
    public function update($path, $contents, Config $config)
    {
        return $this->upload($path, $contents, 'overwrite');
    }
    /**
     * {@inheritdoc}
     */
    public function updateStream($path, $resource, Config $config)
    {
        return $this->upload($path, $resource, 'overwrite');
    }
    /**
     * {@inheritdoc}
     */
    public function rename($path, $newPath): bool
    {
        $path = $this->applyPathPrefix($path);
        $newPath = $this->applyPathPrefix($newPath);
        try {
            $this->client->move($path, $newPath);
        } catch (BadRequest $e) {
            return false;
        }
        return true;
    }
    /**
     * {@inheritdoc}
     */
    public function copy($path, $newpath): bool
    {
        $path = $this->applyPathPrefix($path);
        $newpath = $this->applyPathPrefix($newpath);
        try {
            $this->client->copy($path, $newpath);
        } catch (BadRequest $e) {
            return false;
        }
        return true;
    }
    /**
     * {@inheritdoc}
     */
    public function delete($path): bool
    {
        $location = $this->applyPathPrefix($path);
        try {
            $this->client->delete($location);
        } catch (BadRequest $e) {
            return false;
        }
        return true;
    }
    /**
     * {@inheritdoc}
     */
    public function deleteDir($dirname): bool
    {
        return $this->delete($dirname);
    }
    /**
     * {@inheritdoc}
     */
    public function createDir($dirname, Config $config)
    {
        $path = $this->applyPathPrefix($dirname);
        try {
            $object = $this->client->createFolder($path);
        } catch (BadRequest $e) {
            return false;
        }
        return $this->normalizeResponse($object);
    }
    /**
     * {@inheritdoc}
     */
    public function has($path)
    {
          return $this->getMetadata($path);
    }
    /**
     * {@inheritdoc}
     */
    public function read($path)
    {
        if (! $object = $this->readStream($path)) {
            return false;
        }
        $object['contents'] = stream_get_contents($object['stream']);
        fclose($object['stream']);
        unset($object['stream']);
        return $object;
    }
    /**
     * {@inheritdoc}
     */
    public function readStream($path)
            {
                $location = $this->sanitizePath($path);
        try {
            $stream = $this->client->download($location);
        } catch (BadRequest $e) {
            return false;
        }
        return compact('stream');
    }
    /**
     * {@inheritdoc}
     */
    public function listContents($directory = '', $recursive = false): array
    {
            $location = $this->applyPathPrefix($directory);
            $location = $this->sanitizePath($location);
            try {
                    $results = $this->client->listFolder($location, $recursive);
            } catch (BadRequest $e) {
                return [];
            }
            $entries = $results;

            if (!count($entries)) {
                return [];
            }
            return array_map(function ($entry) {
                $path = $this->removePathPrefix($entry['$ref']);
                return $this->normalizeResponse($entry, $path);
            }, $results);
    }
    /**
     * {@inheritdoc}
     */
    public function getMetadata($path)

    {
        $path = $this->sanitizePath($path);
        try {

            $object = $this->client->getMetadata('/'.$path);
        } catch (BadRequest $e) {

            return false;
        }

        return $this->normalizeResponse($object);
    }
    /**
     * {@inheritdoc}
     */
    public function getSize($path)
    {
        return $this->getMetadata($path);
    }
    /**
     * {@inheritdoc}
     */
    public function getMimetype($path)
    {
        return ['mimetype' => MimeType::detectByFilename($path)];
    }
    /**
     * {@inheritdoc}
     */
    public function getTimestamp($path)
    {
        return $this->getMetadata($path);
    }
    public function getTemporaryLink(string $path): string
    {
        return $this->client->getTemporaryLink($path);
    }
    public function getTemporaryUrl(string $path): string
    {
        return $this->getTemporaryLink($path);
    }
    public function getThumbnail(string $path, string $format = 'jpeg', string $size = 'w64h64')
    {
        return $this->client->getThumbnail($path, $format, $size);
    }
    public function createSharedLinkWithSettings($path, $settings)
    {
        return $this->createSharedLinkWithSettings($path, $settings);
    }
    /**
     * {@inheritdoc}
     */
    public function applyPathPrefix($path): string
    {
        $path = parent::applyPathPrefix($path);

        return '/'.trim($path, '/');
    }

    public function getClient(): Client
    {
        return $this->client;
    }
    /**
     * @param string $path
     * @param resource|string $contents
     * @param string $mode
     *
     * @return array|false file metadata
     */
    protected function upload(string $path, $contents, string $mode)
    {
        $path = $this->applyPathPrefix($path);
        try {
            $object = $this->client->upload($path, $contents, $mode);
        } catch (BadRequest $e) {
            return false;
        }
        return $this->normalizeResponse($object);
    }
    protected function normalizeResponse(array $response, $path=null): array
        {

        $normalizedResponse=  ['path' => $response['id']];
        if (isset($response['name'])) {
            $normalizedResponse['name'] = $response['name'];
        }
        if (isset($response['dateUpdated'])) {
            $normalizedResponse['timestamp'] = strtotime($response['dateUpdated']);
        }
        if (isset($response['fileSize'])) {
            $normalizedResponse['size'] = $response['fileSize'];
            $normalizedResponse['bytes'] = $response['fileSize'];
        }
        $type = ($response['type'] === 'folder' ? 'dir' : 'file');
        $normalizedResponse['type'] = $type;
        if($type== 'file'){
            if (isset($response['extension'])) {
                $normalizedResponse['extension'] = $response['extension'];
            }
        }
        return $normalizedResponse;
    }

    public function sanitizePath($path = NULL){
        $path = pathinfo($path, PATHINFO_FILENAME);
        $strippedPath = $path;
        if(strpos($strippedPath, "(") !== false) {
            $strippedPath = substr($path, strrpos($path, "(")+1, -1);
        }
            $sanitizedPath = explode('/', $strippedPath);
            if (is_array($sanitizedPath)) {
                $sanitizedPath = end($sanitizedPath);
            }
        return $sanitizedPath;
    }
    /**
     * {@inheritdoc}
     */
    public function searchContents($directory = '', $query = ''): array
    {
        $location = $this->applyPathPrefix($directory);
        $location = $this->sanitizePath($location);
        try {
            $results = $this->client->searchFolder($location, $query);
        } catch (BadRequest $e) {
            return [];
        }
        $entries = $results['results'];

        if (!count($entries)) {
            return [];
        }
         return array_map(function ($entry) {
            $path = NULL;
            if(isset($entry['$ref'])) {
                $path = $this->removePathPrefix($entry['$ref']);
            }
            return $this->normalizeSearchResponse($entry, $path);
        }, $entries);
    }
    protected function normalizeSearchResponse(array $response, $path=null): array
    {
        $normalizedResponse = array();
        \Drupal::logger('my_module1')->warning('<pre>'.print_r($response, true).'</pre>');


        $response = $response['ecmObject'];
        $normalizedResponse['path'] = $response['id'];

        if (isset($response['name'])) {
            $normalizedResponse['name'] = $response['name'];
        }
/*        if (isset($response['dateUpdated'])) {
            $normalizedResponse['timestamp'] = strtotime($response['dateUpdated']);
        }
        if (isset($response['fileSize'])) {
            $normalizedResponse['size'] = $response['fileSize'];
            $normalizedResponse['bytes'] = $response['fileSize'];
        }*/
        $type = ($response['type'] === 'folder' ? 'dir' : 'file');
        $normalizedResponse['type'] = $type;
        if($type== 'file'){
            if (isset($response['extension'])) {
                $normalizedResponse['extension'] = $response['extension'];
            }
        }
        \Drupal::logger('$normalizedResponse123')->warning('<pre>'.print_r($normalizedResponse, true).'</pre>');
        return $normalizedResponse;
    }
}
