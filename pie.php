<?php
    /**
     * this script will look for a file named `image_queue.json` in the current working directory
     * 
     * image_queue.json must be formatted as so:
     * {
     *   "<hash>": {
     *     "png": "<path/to/png.png>",
     *     "webp": "<path/to/webp.webp>"
     *   }
     * }
     */
    define("ASSETS", "./_assets/");
    define("IMAGE_QUEUE", "pie.json");
    
    function main() {
      if (!file_exists(IMAGE_QUEUE)) {
        echo "pie.json does not exist\n";
        exit;
      }

      initDirectories();
      
      $imgs = json_decode(@file_get_contents(IMAGE_QUEUE), true);
      var_dump($imgs);
      foreach($imgs as $hash => $img) {
        echo "processing ".$hash."\n";
        try {
            downloadAsset($hash);
            unpackAsset($hash, $img);
        } catch (Exception $exception) {
            echo "encountered exception while processing ".$hash."! ".$exception->getMessage();
        }
      }

      // write `_success` file
      fopen("_success", "w");
    }
    
    function initDirectories() {
      mkdir(ASSETS);
    }
    
    function downloadAsset($hash) {
        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_URL=>'http://prd-priconne-redive.akamaized.net/dl/pool/AssetBundles/'.substr($hash,0,2).'/'.$hash,
            CURLOPT_RETURNTRANSFER=>true,
        ));
        $bundle = curl_exec($curl);
        $response = curl_getinfo($curl, CURLINFO_HTTP_CODE);

        if ($response == 403) {
            // invalid hash given
            echo "inavlid hash provided\n";
            unset($response);
            throw new Exception("Invalid hash provided");
        }

        file_put_contents(ASSETS."$hash", $bundle);
        unset($response);
        unset($bundle);
        unset($curl);
    }
    
    function unpackAsset($hash, $fileInfo) {
        require_once './unity-texture-toolkit/UnityAsset.php';
        $bundleFileStream = new FileStream(ASSETS."$hash");
        $assetsList = extractBundle($bundleFileStream);
        unset($bundleFileStream);

        $stillFound = false;
        foreach ($assetsList as $asset) {
            if (substr($asset, -4,4) == '.resS') continue;
            $asset = new AssetFile($asset);
          
            foreach ($asset->preloadTable as &$item) {
                if ($item->typeString == 'Texture2D') {
                    try {
                        $item = new Texture2D($item, true);
                    } catch (Exception $exception) {
                        // texture2d issue (unsupported?)
                        throw new Exception("Issue creating Texture2D, unsupported? ".$exception->getMessage());
                    }
                    
                    $item->exportTo($item->name, 'png');
                    $item->exportTo($item->name, 'webp', '-lossless 1');

                    // flip png
                    $image = new Imagick();
                    $image->readImage("./"."$item->name".".png");
                    $image->flipImage();
                    $image->writeImage($fileInfo["png"]);
                    $image->destroy();
                    unlink("./"."$item->name".".png"); // delete original copy
                    unset($image);

                    // flip webp
                    $image = new Imagick();
                    $image->readImage("./"."$item->name".".webp");
                    $image->flipImage();
                    $image->writeImage($fileInfo["webp"]);
                    $image->destroy();
                    unlink("./"."$item->name".".webp"); // delete original copy
                    unset($image);
            
                    if (strpos($item->name, "icon_equipment_") !== false) {
                        // if icon equipment found, leave early because memory leaks
                        $stillFound = true;
                        unset($item);
                        break;
                    }
                    if (strpos($item->name, "still_unit_") !== false) {
                        // if still unit found, leave early because memory leaks
                        $stillFound = true;
                        unset($item);
                        break;
                    }

                    unset($item);
                }
            }
            $asset->__desctruct();
            unset($asset);

            if ($stillFound == true) {
                break;
            }
        }
        // clean up files
        foreach ($assetsList as $asset) {
            unlink($asset);
        }
        unset($assetsList);

        // delete asset
        unlink(ASSETS."$hash");
    }

    main();
?>