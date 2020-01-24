<?php
namespace Drupal\flysystem_objective\Client;


use Exception;
use GuzzleHttp\Psr7;
use GuzzleHttp\Psr7\PumpStream;
use GuzzleHttp\Psr7\StreamWrapper;
use Psr\Http\Message\StreamInterface;
use GuzzleHttp\Client as GuzzleClient;
use Psr\Http\Message\ResponseInterface;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\RequestException;
use GrahamCampbell\GuzzleFactory\GuzzleFactory;
use Guzzle\Stream\PhpStreamRequestFactory;

class ObjectiveClient
{
    const THUMBNAIL_FORMAT_JPEG = 'jpeg';
    const THUMBNAIL_FORMAT_PNG = 'png';
    const THUMBNAIL_SIZE_XS = 'w32h32';
    const THUMBNAIL_SIZE_S = 'w64h64';
    const THUMBNAIL_SIZE_M = 'w128h128';
    const THUMBNAIL_SIZE_L = 'w640h480';
    const THUMBNAIL_SIZE_XL = 'w1024h768';
    const MAX_CHUNK_SIZE = 1024 * 1024 * 150;
    const UPLOAD_SESSION_START = 0;
    const UPLOAD_SESSION_APPEND = 1;
    /** @var string */
    protected $accessToken;
    /** @var \GuzzleHttp\Client */
    protected $client;
    /** @var int */
    protected $maxChunkSize;
    /** @var int */
    protected $maxUploadChunkRetries;
    /**
     * Whether to serve files via Objective.
     *
     * @var bool
     */
    protected $username;
    /**
     * Whether to serve files via Objective.
     *
     * @var bool
     */
    protected $password;

    /**
     * Whether to serve files via Objective.
     *
     * @var bool
     */
    protected $apiUrl;

    /**
     * @param string $username
     * @param string $password
     * @param string $apiUrl.
      */
    public function __construct(string $username, string $password, string $apiUrl)
    {
        $this->username = $username;
        $this->password = $password;
        $this->apiUrl = $apiUrl;
        $this->client = new GuzzleClient();
    }

   /**
     * Copy a file or folder to a different location in the user's Objective.
     *
     * If the source path is a folder all its contents will be copied.
     *
     */
    public function copy(string $fromPath, string $toPath): array
    {
        $parameters = [
            'from_path' => $this->normalizePath($fromPath),
            'to_path' => $this->normalizePath($toPath),
        ];
        return $this->rpcEndpointRequest('files/copy_v2', $parameters);
    }

    /**
     * Create a folder at a given path.
     *
     */
    public function createFolder(string $path): array
    {
/*        $parameters = [
            'path' => $this->normalizePath($path),
        ];*/

        $path = $this->normalizePath($path);
        $name = array_pop($data);
        $parent = array_pop($data);

        $parameters  = array('name'=>$name,'parentId'=>$parent,'path'=>$path);

        $object = $this->objEndpointRequestPost('resources/folders', $parameters);
        $object['type'] = 'folder';
        return $object;
    }

    /**
     * Create a shared link with custom settings.
     *
     * If no settings are given then the default visibility is RequestedVisibility.public.
     * The resolved visibility, though, may depend on other aspects such as team and
     * shared folder settings). Only for paid users.
     *
     */
    public function createSharedLinkWithSettings(string $path, array $settings = [])
    {
        $parameters = [
            'path' => $this->normalizePath($path),
        ];
        if (count($settings)) {
            $parameters = array_merge(compact('settings'), $parameters);
        }
        return $this->rpcEndpointRequest('sharing/create_shared_link_with_settings', $parameters);
    }

    /**
     * List shared links.
     *
     * For empty path returns a list of all shared links. For non-empty path
     * returns a list of all shared links with access to the given path.
     *
     * If direct_only is set true, only direct links to the path will be returned, otherwise
     * it may return link to the path itself and parent folders as described on docs.
     *
     */
    public function listSharedLinks(string $path = null, bool $direct_only = false, string $cursor = null): array
    {
        $parameters = [
            'path' => $path ? $this->normalizePath($path) : null,
            'cursor' => $cursor,
            'direct_only' => $direct_only,
        ];
        $body = $this->rpcEndpointRequest('sharing/list_shared_links', $parameters);
        return $body['links'];
    }

    /**
     * Delete the file or folder at a given path.
     *
     * If the path is a folder, all its contents will be deleted too.
     * A successful response indicates that the file or folder was deleted.
     *
     */
    public function delete(string $path): array
    {
        $parameters = [
            'path' => $this->normalizePath($path),
        ];
        return $this->rpcEndpointRequest('files/delete', $parameters);
    }

    /**
     * Download a file from a user's Objective.
     *
     * @param string $path
     *
     * @return resource
     *
      */
    public function download(string $path)
    {
        $arguments = [
            'path' => $this->normalizePath($path),
        ];
        $downloadableVersion = $this->objEndpointRequest('resources/documents/'.$path);

        if(is_array($downloadableVersion) && isset($downloadableVersion['latestOpenableVersion']['id'])){
            $id = $downloadableVersion['latestOpenableVersion']['id'];
        }
        $path = 'resources/documentversions/'.$id;

        $response = $this->objContentEndpointRequest($path.'/content', $arguments);
        return StreamWrapper::getResource($response->getBody());
    }

    /**
     * Returns the metadata for a file or folder.
     *
     * Note: Metadata for the root folder is unsupported.
     *
     */
    public function getMetadata(string $path): array
    {
        if($path != '' && $path != '/') {
            $current_path = explode("/", $path);
            if(is_array($current_path)){
              $path = '/'.end($current_path);
            } else{
                 $path = '/'.$path;
            }
        }
        $parameters = [
            'path' => $this->normalizePath($path),
        ];

        if(substr($path, 0, 2) == '/A'){
            $type = 'documents';

        }else{
            $type= 'folders';
        }
        return $this->objEndpointRequest('resources/'.$type.$path, $parameters);
    }

    /**
     * Get a temporary link to stream content of a file.
     *
     * This link will expire in four hours and afterwards you will get 410 Gone.
     * Content-Type of the link is determined automatically by the file's mime type.
     *
     */
    public function getTemporaryLink(string $path): string
    {
        $parameters = [
            'path' => $this->normalizePath($path),
        ];
        $body = $this->rpcEndpointRequest('files/get_temporary_link', $parameters);
        return $body['link'];
    }

    /**
     * Get a thumbnail for an image.
     *
     * This method currently supports files with the following file extensions:
     * jpg, jpeg, png, tiff, tif, gif and bmp.
     *
     * Photos that are larger than 20MB in size won't be converted to a thumbnail.
     *
     */
    public function getThumbnail(string $path, string $format = 'jpeg', string $size = 'w64h64'): string
    {
        $arguments = [
            'path' => $this->normalizePath($path),
            'format' => $format,
            'size' => $size,
        ];
        $response = $this->contentEndpointRequest('files/get_thumbnail', $arguments);
        return (string)$response->getBody();
    }

    /**
     * Starts returning the contents of a folder.
     *
     * If the result's ListFolderResult.has_more field is true, call
     * list_folder/continue with the returned ListFolderResult.cursor to retrieve more entries.
     *
     * Note: auth.RateLimitError may be returned if multiple list_folder or list_folder/continue calls
     * with same parameters are made simultaneously by same API app for same user. If your app implements
     * retry logic, please hold off the retry until the previous request finishes.
     *
     */
    public function listFolder(string $path = '', bool $recursive = false): array
    {
        $parameters = [
            'path' => $this->normalizePath($path),
            'recursive' => $recursive,
        ];
        $path = $this->normalizePath($path);
        return $this->objEndpointRequest('resources/folders'.$path.'/children', $parameters);
    }

    /**
     * Once a cursor has been retrieved from list_folder, use this to paginate through all files and
     * retrieve updates to the folder, following the same rules as documented for list_folder.
     *
      */
    public function listFolderContinue(string $cursor = ''): array
    {
        return $this->rpcEndpointRequest('files/list_folder/continue', compact('cursor'));
    }

    /**
     * Move a file or folder to a different location in the user's Objective.
     *
     * If the source path is a folder all its contents will be moved.
     *
     */
    public function move(string $fromPath, string $toPath): array
    {
        $parameters = [
            'from_path' => $this->normalizePath($fromPath),
            'to_path' => $this->normalizePath($toPath),
        ];
        return $this->rpcEndpointRequest('files/move_v2', $parameters);
    }

    /**
     * The file should be uploaded in chunks if it size exceeds the 150 MB threshold
     * or if the resource size could not be determined (eg. a popen() stream).
     *
     * @param string|resource $contents
     *
     * @return bool
     */
    protected function shouldUploadChunked($contents): bool
    {
        $size = is_string($contents) ? strlen($contents) : fstat($contents)['size'];
        if ($this->isPipe($contents)) {
            return true;
        }
        if ($size === null) {
            return true;
        }
        return $size > $this->maxChunkSize;
    }

    /**
     * Check if the contents is a pipe stream (not seekable, no size defined).
     *
     * @param string|resource $contents
     *
     * @return bool
     */
    protected function isPipe($contents): bool
    {
        return is_resource($contents) ? (fstat($contents)['mode'] & 010000) != 0 : false;
    }

    /**
     * Create a new file with the contents provided in the request.
     *
     * Do not use this to upload a file larger than 150 MB. Instead, create an upload session with upload_session/start.
     *
     *
     * @param string $path
     * @param string|resource $contents
     * @param string $mode
     *
     * @return array
     */
    public function upload(string $path, $contents, $mode = 'add'): array
    {
        if ($this->shouldUploadChunked($contents)) {
            return $this->uploadChunked($path, $contents, $mode);
        }
        $arguments = [
            'path' => $this->normalizePath($path),
            'mode' => $mode,
        ];
        $response = $this->contentEndpointRequest('files/upload', $arguments, $contents);
        $metadata = json_decode($response->getBody(), true);
        $metadata['.tag'] = 'file';
        return $metadata;
    }

    /**
     * Upload file split in chunks. This allows uploading large files, since
     * Objective API v2 limits the content size to 150MB.
     *
     * The chunk size will affect directly the memory usage, so be careful.
     * Large chunks tends to speed up the upload, while smaller optimizes memory usage.
     *
     * @param string $path
     * @param string|resource $contents
     * @param string $mode
     * @param int $chunkSize
     *
     * @return array
     */
    public function uploadChunked(string $path, $contents, $mode = 'add', $chunkSize = null): array
    {
        if ($chunkSize === null || $chunkSize > $this->maxChunkSize) {
            $chunkSize = $this->maxChunkSize;
        }
        $stream = $this->getStream($contents);
        $cursor = $this->uploadChunk(self::UPLOAD_SESSION_START, $stream, $chunkSize, null);
        while (!$stream->eof()) {
            $cursor = $this->uploadChunk(self::UPLOAD_SESSION_APPEND, $stream, $chunkSize, $cursor);
        }
        return $this->uploadSessionFinish('', $cursor, $path, $mode);
    }

    /**
     * @param int $type
     * @param Psr7\Stream $stream
     * @param int $chunkSize
     * @throws Exception
     */
    protected function uploadChunk($type, &$stream, $chunkSize, $cursor = null): UploadSessionCursor
    {
        $maximumTries = $stream->isSeekable() ? $this->maxUploadChunkRetries : 0;
        $pos = $stream->tell();
        $tries = 0;
        tryUpload:
        try {
            $tries++;
            $chunkStream = new Psr7\LimitStream($stream, $chunkSize, $stream->tell());
            if ($type === self::UPLOAD_SESSION_START) {
                return $this->uploadSessionStart($chunkStream);
            }
            if ($type === self::UPLOAD_SESSION_APPEND && $cursor !== null) {
                return $this->uploadSessionAppend($chunkStream, $cursor);
            }
            throw new Exception('Invalid type');
        } catch (RequestException $exception) {
            if ($tries < $maximumTries) {
                // rewind
                $stream->seek($pos, SEEK_SET);
                goto tryUpload;
            }
            throw $exception;
        }
    }

    /**
     * Upload sessions allow you to upload a single file in one or more requests,
     * for example where the size of the file is greater than 150 MB.
     * This call starts a new upload session with the given data.
     *
     *
     * @param string|StreamInterface $contents
     * @param bool $close
     *
     * @return UploadSessionCursor
     */
    public function uploadSessionStart($contents, bool $close = false): UploadSessionCursor
    {
        $arguments = compact('close');
        $response = json_decode(
            $this->contentEndpointRequest('files/upload_session/start', $arguments, $contents)->getBody(),
            true
        );
        return new UploadSessionCursor($response['session_id'], ($contents instanceof StreamInterface ? $contents->tell() : strlen($contents)));
    }

    /**
     * Append more data to an upload session.
     * When the parameter close is set, this call will close the session.
     * A single request should not upload more than 150 MB.
     *
     *
     * @param string|StreamInterface $contents
     * @param UploadSessionCursor $cursor
     * @param bool $close
     *
     * @return \Spatie\Objective\UploadSessionCursor
     */
    public function uploadSessionAppend($contents, UploadSessionCursor $cursor, bool $close = false): UploadSessionCursor
    {
        $arguments = compact('cursor', 'close');
        $pos = $contents instanceof StreamInterface ? $contents->tell() : 0;
        $this->contentEndpointRequest('files/upload_session/append_v2', $arguments, $contents);
        $cursor->offset += $contents instanceof StreamInterface ? ($contents->tell() - $pos) : strlen($contents);
        return $cursor;
    }

    /**
     * Finish an upload session and save the uploaded data to the given file path.
     * A single request should not upload more than 150 MB.
     *
     * @param string|StreamInterface $contents
     * @param \Spatie\Objective\UploadSessionCursor $cursor
     * @param string $path
     * @param string|array $mode
     * @param bool $autorename
     * @param bool $mute
     *
     * @return array
     */
    public function uploadSessionFinish($contents, UploadSessionCursor $cursor, string $path, $mode = 'add', $autorename = false, $mute = false): array
    {
        $arguments = compact('cursor');
        $arguments['commit'] = compact('path', 'mode', 'autorename', 'mute');
        $response = $this->contentEndpointRequest(
            'files/upload_session/finish',
            $arguments,
            ($contents == '') ? null : $contents
        );
        $metadata = json_decode($response->getBody(), true);
        $metadata['.tag'] = 'file';
        return $metadata;
    }

    /**
     * Get Account Info for current authenticated user.
     *
     *
     * @return array
     */
    public function getAccountInfo(): array
    {
        return $this->rpcEndpointRequest('users/get_current_account');
    }

    /**
     * Revoke current access token.
     *
     */
    public function revokeToken()
    {
        $this->rpcEndpointRequest('auth/token/revoke');
    }

    public function normalizePath(string $path): string
    {
/*        if (preg_match("/^id:.*|^rev:.*|^(ns:[0-9]+(\/.*)?)/", $path) === 1) {
            return $path;
        }*/
        $current_path = explode("/", $path);
            if(is_array($current_path)){
              $path = '/'.end($current_path);
            }
        $path = pathinfo($path, PATHINFO_FILENAME);
        $path = trim($path, '/');
        return ($path === '') ? '' : '/' . $path;
    }

    protected function getEndpointUrl(string $subdomain, string $endpoint): string
    {
              /* if (count($parts = explode('::', $endpoint)) === 2) {
                   [$subdomain, $endpoint] = $parts;
               }*/
              // return "https://{$subdomain}.Objectiveapi.com/2/{$endpoint}";
        return $this->apiUrl.$endpoint;
    }

    /**
     * @param string $endpoint
     * @param array $arguments
     * @param string|resource|StreamInterface $body
     *
     * @return \Psr\Http\Message\ResponseInterface
     *
     * @throws \Exception
     */
    public function contentEndpointRequest(string $endpoint, array $arguments, $body = ''): ResponseInterface
    {

        $headers = ['Objective-API-Arg' => json_encode($arguments)];
        if ($body !== '') {
            $headers['Content-Type'] = 'application/octet-stream';
        }
        try {
            $response = $this->client->post($this->getEndpointUrl('content', $endpoint), [
                'headers' => $this->getHeaders($headers),
                'body' => $body,
            ]);
        } catch (ClientException $exception) {
            throw $this->determineException($exception);
        }
        return $response;
    }

    public function rpcEndpointRequest(string $endpoint, array $parameters = null): array
    {
        try {
            $options = ['headers' => $this->getHeaders(), 'auth' => [$this->username, $this->password]];

            if ($parameters) {
                $options['json'] = $parameters;
            }

            $val = $this->getEndpointUrl('api', $endpoint);
            $response = $this->client->post($this->getEndpointUrl('api', $endpoint), $options);
        } catch (ClientException $exception) {
            throw $this->determineException($exception);
        }
        $response = json_decode($response->getBody(), true);
        return $response ?? [];
    }

    public function soapEndpointRequest(string $endpoint, array $parameters = null): array
    {
        try {
            $options = ['headers' => $this->getHeaders()];
            $response = $this->client->$endpoint($parameters);
            // $response = $this->client->post($this->getEndpointUrl('api', $endpoint), $options);
        } catch (ClientException $exception) {
            throw $this->determineException($exception);
        }
        // $response = json_decode($response->getBody(), true);
        return $response ?? [];
    }

    protected function determineException(ClientException $exception): Exception
    {
        if (in_array($exception->getResponse()->getStatusCode(), [400, 409])) {
            return new BadRequest($exception->getResponse());
        }
        return $exception;
    }

    /**
     * @param $contents
     *
     * @return \GuzzleHttp\Psr7\PumpStream|\GuzzleHttp\Psr7\Stream
     */
    protected function getStream($contents)
    {
        if ($this->isPipe($contents)) {
            /* @var resource $contents */
            return new PumpStream(function ($length) use ($contents) {
                $data = fread($contents, $length);
                if (strlen($data) === 0) {
                    return false;
                }
                return $data;
            });
        }
        return Psr7\stream_for($contents);
    }

    /**
     * Get the access token.
     */
    public function getAccessToken(): string
    {
        return $this->accessToken;
    }

    /**
     * Set the access token.
     */
    public function setAccessToken(string $accessToken): self
    {
        $this->accessToken = $accessToken;
        return $this;
    }

    /**
     * Get the HTTP headers.
     */
    protected function getHeaders(array $headers = []): array
    {
        return array_merge([
            'Authorization' => "Basic {$this->accessToken}",
            'Content-type' => "application/json",
        ], $headers);

        return array_merge([
            'Range' => "items=0-10",
            'Content-type' => "application/json",
        ], $headers);


    }


    public function objEndpointRequest(string $endpoint, array $parameters = null): array
    {
    try {
        if ($parameters) {
            $options['json'] = $parameters;
        }
        $options['auth'] = [$this->username, $this->password];
        $response = $this->client->request('GET', $this->apiUrl.$endpoint,$options);
        $response = json_decode($response->getBody(), true);
    } catch (ClientException $exception) {

        throw $this->determineException($exception);

    }
    return $response ?? [];
    }

    public function objEndpointRequestPost(string $endpoint, array $parameters = null): array
    {
        try {
            if ($parameters) {
                $options['json'] = $parameters;
            }
            $options['auth'] = [$this->username, $this->password];


            $response = $this->client->request('POST', $this->apiUrl.$endpoint, $options);

        } catch (ClientException $exception) {
            throw $this->determineException($exception);
        }
        $response = json_decode($response->getBody(), true);
         return $response ?? [];
    }

/**
 * @param string $endpoint
 * @param array $arguments
 * @param string|resource|StreamInterface $body
 *
 * @return \Psr\Http\Message\ResponseInterface
 *
 * @throws \Exception
 */
public function objContentEndpointRequest(string $endpoint, array $arguments, $body = ''): ResponseInterface
{
    $headers['Content-Type'] = 'text/html';
    $options = ['headers' => $headers];
    $options['auth'] = [$this->username, $this->password];
    try {
        $response = $this->client->request('GET',$this->apiUrl.$endpoint, $options);
    } catch (ClientException $exception) {
        throw $this->determineException($exception);
    }
    return $response;
  }
    /**
     * Starts returning the contents of a folder.
     *
     * If the result's ListFolderResult.has_more field is true, call
     * list_folder/continue with the returned ListFolderResult.cursor to retrieve more entries.
     *
     * Note: auth.RateLimitError may be returned if multiple list_folder or list_folder/continue calls
     * with same parameters are made simultaneously by same API app for same user. If your app implements
     * retry logic, please hold off the retry until the previous request finishes.
     *
     */
    public function searchFolder(string $path = '', string $query = ''): array
    {
        $parameters = [
              'query' => $query,
            'definitionIds'=>array("dotdA9"),

        ];
        $path = $this->normalizePath($path);
        return $this->objEndpointRequestPost('search/', $parameters);
    }

}