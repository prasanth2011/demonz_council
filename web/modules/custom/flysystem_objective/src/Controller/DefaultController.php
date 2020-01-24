<?php

namespace Drupal\flysystem_objective\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\Request;
use Drupal\Component\Utility\Unicode;
use Drupal\flysystem_objective\Flysystem\Objective as flySystem;
use Drupal\flysystem_objective\Client as objClient;

class DefaultController extends ControllerBase
{

    /**
     * Returns response for the autocompletion.
     *
     * @param \Symfony\Component\HttpFoundation\Request $request
     *   The current request object containing the search string.
     *
     * @return \Symfony\Component\HttpFoundation\JsonResponse
     *   A JSON response containing the autocomplete suggestions.
     */

    public function autocomplete(request $request) {
        $configuration = array();
        $current_scheme = '';
        $schemes = \Drupal\Core\Site\Settings::get('flysystem');
        if (is_array($schemes)) {
            foreach ($schemes as $key => $scheme) {
                if ($scheme['driver'] == 'objective') {
                    $configuration = $scheme['config'];
                    $current_scheme = $key;
                }
            }
        }
        $keywords = $request->query->get('q');
        $matches = array();
        if ($keywords) {
            $matches = array();
            $flySystem = new flySystem($configuration);
            $adapter = $flySystem->getAdapter();
            $content = $adapter->searchContents('test', $keywords);
            \Drupal::logger('$content')->warning('<pre>'.print_r($content, true).'</pre>');
            if ($content) {
                $files = [];
                foreach ($content as $item) {
                    $temp = [];
                    $filename = $item['name'];
                    $file = \Drupal\file\Entity\File::load($item['path']);
                    $path = $item['path'];
                    $temp['filename'] = $filename;
                    $temp['url'] = $path;
                    $files[] = $temp;
                    $extension = $item['extension']?'.'.$item['extension']:'';
                    $filevalue = $filename.'<'.$path.'>'.$extension;
                  //  $filename = $filename.'<'.$path.'>'.$extension;
                    $filelabel = $filename.'('.$path.')'.$extension;
                    $matches[] = ['value' =>$filevalue, 'label' => $filelabel];

                }
            }

        }
        return new JsonResponse($matches);
    }
}