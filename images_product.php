<?php

@ini_set('max_execution_time', 0);
@ini_set('memory_limit', '256M');
require '../../config/config.inc.php';
require '../../init.php';

$context = Context::getContext();
$id_lang_adv = $context->language->id;
$shop_id_adv = $context->shop->id;
$shops = Shop::getContextListShopID();
//Functions to Add image to products:
function get_best_path($tgt_width, $tgt_height, $path_infos)
{
    $path_infos = array_reverse($path_infos);
    $path = '';
    foreach ($path_infos as $path_info) {
        list($width, $height, $path) = $path_info;
        if ($width >= $tgt_width && $height >= $tgt_height) {
            return $path;
        }
    }
    return $path;
}

function copyImg($id_entity, $id_image = null, $url, $entity = 'products', $regenerate = true)
{
    $tmpfile = tempnam(_PS_TMP_IMG_DIR_, 'ps_import');
    $watermark_types = explode(',', Configuration::get('WATERMARK_TYPES'));

    switch ($entity) {
        default:
        case 'products':
            $image_obj = new Image($id_image);
            $path = $image_obj->getPathForCreation();
            break;
        case 'categories':
            $path = _PS_CAT_IMG_DIR_ . (int)$id_entity;
            break;
        case 'manufacturers':
            $path = _PS_MANU_IMG_DIR_ . (int)$id_entity;
            break;
        case 'suppliers':
            $path = _PS_SUPP_IMG_DIR_ . (int)$id_entity;
            break;
    }

    $url = urldecode(trim($url));
    $parced_url = parse_url($url);

    if (isset($parced_url['path'])) {
        $uri = ltrim($parced_url['path'], '/');
        $parts = explode('/', $uri);
        foreach ($parts as &$part) {
            $part = rawurlencode($part);
        }
        unset($part);
        $parced_url['path'] = '/' . implode('/', $parts);
    }

    if (isset($parced_url['query'])) {
        $query_parts = array();
        parse_str($parced_url['query'], $query_parts);
        $parced_url['query'] = http_build_query($query_parts);
    }

    if (!function_exists('http_build_url')) {
        require_once(_PS_TOOL_DIR_ . 'http_build_url/http_build_url.php');
    }

    $url = http_build_url('', $parced_url);

    $orig_tmpfile = $tmpfile;

    if (Tools::copy($url, $tmpfile)) {
        // Evaluate the memory required to resize the image: if it's too much, you can't resize it.
        if (!ImageManager::checkImageMemoryLimit($tmpfile)) {
            @unlink($tmpfile);
            return false;
        }

        $tgt_width = $tgt_height = 0;
        $src_width = $src_height = 0;
        $error = 0;
        ImageManager::resize($tmpfile, $path . '.jpg', null, null, 'jpg', false, $error, $tgt_width, $tgt_height, 5,
            $src_width, $src_height);
        $images_types = ImageType::getImagesTypes($entity, true);

        if ($regenerate) {
            $previous_path = null;
            $path_infos = array();
            $path_infos[] = array($tgt_width, $tgt_height, $path . '.jpg');
            foreach ($images_types as $image_type) {
                $tmpfile = get_best_path($image_type['width'], $image_type['height'], $path_infos);

                if (ImageManager::resize($tmpfile, $path . '-' . stripslashes($image_type['name']) . '.jpg', $image_type['width'],
                    $image_type['height'], 'jpg', false, $error, $tgt_width, $tgt_height, 5,
                    $src_width, $src_height)) {
                    // the last image should not be added in the candidate list if it's bigger than the original image
                    if ($tgt_width <= $src_width && $tgt_height <= $src_height) {
                        $path_infos[] = array($tgt_width, $tgt_height, $path . '-' . stripslashes($image_type['name']) . '.jpg');
                    }
                    if ($entity == 'products') {
                        if (is_file(_PS_TMP_IMG_DIR_ . 'product_mini_' . (int)$id_entity . '.jpg')) {
                            unlink(_PS_TMP_IMG_DIR_ . 'product_mini_' . (int)$id_entity . '.jpg');
                        }
                        if (is_file(_PS_TMP_IMG_DIR_ . 'product_mini_' . (int)$id_entity . '_' . (int)Context::getContext()->shop->id . '.jpg')) {
                            unlink(_PS_TMP_IMG_DIR_ . 'product_mini_' . (int)$id_entity . '_' . (int)Context::getContext()->shop->id . '.jpg');
                        }
                    }
                }
                if (in_array($image_type['id_image_type'], $watermark_types)) {
                    Hook::exec('actionWatermark', array('id_image' => $id_image, 'id_product' => $id_entity));
                }
            }
        }
    } else {
        @unlink($orig_tmpfile);
        return false;
    }
    unlink($orig_tmpfile);
    return true;
}
function is_url_exist($url){
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_NOBODY, true);
    curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if($code == 200){
        $status = true;
    }else{
        $status = false;
    }
    curl_close($ch);
    return $status;
}

//Read data csv:
if (($handle = fopen("images.csv", "r")) !== FALSE) {
    
    $flog_archive = fopen(_PS_ROOT_DIR_.'/webservice/products_images/log/log_image.txt', "w+");
    $flog_product_image = '';
    $image_folder = 'alljet_img/' ;
    for ($current_line = 0; $line = fgetcsv($handle, 0, ";"); $current_line++) {
        if ($current_line > 0 ) {
            //Encodage en utf8 des cellules de chaque ligne:
            $line = array_map('utf8_encode', $line);
            
            $id_product=$line[0];
           
            $image_url = Tools::getHttpHost(true).__PS_BASE_URI__.$image_folder.trim($line[1]);
           
            if (empty($id_product) || !is_url_exist($image_url)) {
                $flog_product_image .= $id_product . ':' . $image_url . ';';
            } else {
                if (Validate::isLoadedObject($product = new Product($id_product, $id_lang_adv, $shop_id_adv))) {
                    
                    if (!empty($image_url)) {
                        $image_url = str_replace(' ', '%20', $image_url);
                        $image = new Image();
                        $image->id_product = (int)$product->id;
                        $image->position = Image::getHighestPosition($product->id) + 1;
                       
                            $image->cover = TRUE;
                       
                        if (($field_error = $image->validateFields(false, true)) === true &&
                            ($lang_field_error = $image->validateFieldsLang(false, true)) === true && $image->add()) {
                            $image->associateTo($shops);
                            if (!copyImg($product->id, $image->id, $image_url, 'products', true)) {
                                $image->delete();
                                sprintf(Tools::displayError('Error copying image: '.$image_url), $image_url);
                                $flog_product_image .= "Error Uploading Image:   ". date("d") . date("m") . date("Y") . "_" . date('H') . date('s')." PRODUCT ID:  ".$id_product . ':IMAGE URL: ' . $image_url . "\r\n";
                               
                            }
                         else {
                            $flog_product_image .= "Image Uploded:   ". date("d") . date("m") . date("Y") . "_" . date('H').date('s')." PRODUCT ID:  ".$id_product . ':IMAGE URL: ' . $image_url . "\r\n";
                            
                        }
                    }
                        
                    }
                }
            }

        }
        
    }
    fwrite($flog_archive, $flog_product_image);
    
    fclose($flog_archive);

    die('----- FINISHED ------');
}



