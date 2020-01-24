<?php

/**
 * @file
 * Contains \Drupal\flysystem_dropbox\Flysystem\Dropbox.
 */

namespace Drupal\flysystem_objective\Flysystem;

use Drupal\flysystem_objective\Adapter\ObjectiveAdapter;
use Drupal\flysystem_objective\Client\ObjectiveClient;
use Drupal\Core\Logger\RfcLogLevel;
use Drupal\flysystem\Flysystem\Adapter\MissingAdapter;
use Drupal\flysystem\Plugin\FlysystemPluginInterface;
use Drupal\flysystem\Plugin\FlysystemUrlTrait;
use Drupal\flysystem\Plugin\ImageStyleGenerationTrait;
use GuzzleHttp\Psr7\Uri;
use Drupal\imce;


/**
 * Drupal plugin for the "Objective" Flysystem adapter.
 *
 * @Adapter(id = "objective")
 */
class Objective implements FlysystemPluginInterface {

    use FlysystemUrlTrait {
        getExternalUrl as getDownloadlUrl;
    }

    /**
     * The Objective client.
     *
     * @var \Drupal\flysystem_objective\Client\ObjectiveClient
     */
    protected $client;

    /**
     * The Dropbox client ID.
     *
     * @var string
     */
    protected $clientId;

    /**
     * The path prefix inside the Dropbox folder.
     *
     * @var string
     */
    protected $prefix;

    /**
     * The Dropbox API token.
     *
     * @var string
     */
    protected $token;

    /**
     * Whether to serve files via Dropbox.
     *
     * @var bool
     */
    protected $usePublic;

    /**
     * Whether to serve files via Dropbox.
     *
     * @var bool
     */
    protected $username;


    /**
     * Whether to serve files via Dropbox.
     *
     * @var bool
     */
    protected $password;

    /**
     * Whether to serve files via Dropbox.
     *
     * @var bool
     */
    protected $apiUrl;

    /**
     * Constructs a Objective object.
     *
     * @param array $configuration
     *   Plugin configuration array.
     * @param \GuzzleHttp\ClientInterface $http_client
     *   The HTTP client.
     */
    /**
     * Whether to serve files via Dropbox.
     *
     * @var bool
     */
    protected $configuration;

    public function __construct(array $configuration) {
        $account = \Drupal::currentUser();
        if($account->isAnonymous()){
            $this->username = $configuration['username'];
            $this->password = $configuration['password'];
        }else{
            $this->username = $configuration['intra_username'];
            $this->password = $configuration['intra_password'];
        }

        $this->configuration = $configuration;
        $this->prefix = isset($configuration['prefix']) ? $configuration['prefix'] : '';
        $this->username = $configuration['username'];
        $this->password = $configuration['password'];
        $this->apiUrl = $configuration['apiUrl'];

    }

    /**
     * {@inheritdoc}
     */
    public function getAdapter() {

        try {
            $adapter = new ObjectiveAdapter($this -> configuration, $this->getClient(),$this->prefix );

        }

        catch (\Exception $e) {
            $adapter = new MissingAdapter();
        }

        return $adapter;
    }
    /**
     * {@inheritdoc}
     */
    public function ensure($force = FALSE) {
        try {
            $adapter = new ObjectiveAdapter($this->configuration, $this->getClient(),$this->prefix);
        }
        catch (\Exception $e) {
            return [[
                'severity' => RfcLogLevel::ERROR,
                'message' => 'The Objective client failed with: %error.',
                'context' => ['%error' => $e->getMessage()],
            ]];
        }
        return [];
    }
    /**
     * Returns the Objective client.
     *
     * @return \Drupal\flysystem_objective\Client\ObjectiveClient
     *   The Objective client.
     */
    protected function getClient() {
        if (!isset($this->client)) {

            $this->client = new ObjectiveClient($this->username, $this->password, $this->apiUrl);
        }

        return $this->client;
    }

    public function getConfiguration(){
        return $this->configuration;
    }

    /**
     * Returns the contents of a directory.
     */
    public static function scanDir($diruri, array $options = []) {
        $configuration = array();
        $current_scheme = '';
        $schemes =  \Drupal\Core\Site\Settings::get('flysystem');
        if(is_array($schemes)){
            foreach($schemes as $key => $scheme){
                if($scheme['driver'] == 'objective'){
                    $configuration = $scheme['config'];
                    $current_scheme = $key;
                }
            }
        }

        if($current_scheme == '' || strpos($diruri,$current_scheme) === false){
            return \Drupal\imce\Imce::scanDir($diruri, $options);
        }
        $content = ['files' => [], 'subfolders' => []];
        $browse_files = isset($options['browse_files']) ? $options['browse_files'] : TRUE;
        $browse_subfolders = isset($options['browse_subfolders']) ? $options['browse_subfolders'] : TRUE;
        $uriprefix = substr($diruri, -1) === '/' ? $diruri : $diruri . '/';
        if (!$browse_files && !$browse_subfolders) {
            return $content;
        }
        if (!$opendir = opendir($diruri)) {
            return $content + ['error' => TRUE];
        }
        $current_path = explode("/", $diruri);
        $id = $diruri;
        if(is_array($current_path)){
            $id = '/'.end($current_path);
        }
        $tst = new objective($configuration);
        $adapter = $tst->getAdapter();
        $objContents = $adapter->listContents($id.'/');

        // Prepare filters
        $name_filter = empty($options['name_filter']) ? FALSE : $options['name_filter'];
        $callback = empty($options['filter_callback']) ? FALSE : $options['filter_callback'];

        if (is_array($objContents)) {
            foreach ($objContents as $obj) {
                $ext= '';
                if(isset($obj['name'])){
                    $name = $obj['name'];
                }
                if(isset($obj['extension'])){
                    $ext = '.'.$obj['extension'];
                }
               // $content[($obj['type'] == 'dir') ? 'subfolders' : 'files'][$obj['path'].'('.$name.')'.$ext] = $obj['path'];
                $content[($obj['type'] == 'dir') ? 'subfolders' : 'files'][$name.'('.$obj['path'].')'.$ext] = $name.$ext;
            }
        }
        closedir($opendir);
        return $content;
    }


}
