<?php

namespace rokorolov\parus\admin\services;

use Intervention\Image\ImageManager as Manager;
use Closure;
use Yii;
use yii\helpers\Inflector;
use yii\helpers\FileHelper;
use yii\base\InvalidParamException;

/**
 * ImageManager
 *
 * @author Roman Korolov <rokorolov@gmail.com>
 */
class ImageManager
{
    const ORIGIN_IMAGE_KEY = 'origin';

    const METHOD_RESIZE = 'resize';
    const METHOD_CROP = 'crop';

    const IMAGE_QUALITY = 100;
    const IMAGE_UP_SIZE = true;
    const IMAGE_WITH_CANVAS = false;

    public $response = false;
    public $ensureUnique = false;
    public $saveOriginal = true;
    public $originalMaxWidth = 0;
    public $originalMaxHeight = 0;
    public $extension = 'jpg';
    public $imageNameCreator;
    public $imagePathCreator;
    public $fileTransformations = [];
    public $imageManagerDriver = 'gd';
    public $defaultResizeMethod = self::METHOD_RESIZE;

    private $imageName;
    private $originalImageName;
    private $imagePath;
    private $uploadPath;

    public function __construct(
      $imageName,
      $imagePath,
      $uploadPath,
     array $fileTransformations = []
     ) {
        if ($imagePath && !file_exists($imagePath)) {
            throw new InvalidParamException("The image directory '$imagePath' does not exist");
        }

        $this->imageName = $imageName;
        $this->imagePath = $imagePath;
        $this->uploadPath = $uploadPath;
        $this->fileTransformations = $fileTransformations;
        $this->manager = new Manager(['driver' => $this->imageManagerDriver]);
    }

    public function save()
    {
        if (empty($fileTransformations = $this->getFileTransformations())) {
            return;
        }

        $responseData = [];
        $this->createDirectory($this->uploadPath);
        $imageName = $this->getOriginalImageName();

        foreach ($fileTransformations as $key => $option) {

            $result = true;
            $postfix = isset($option['postfix']) ? $option['postfix'] : null;
            $withCanvas = isset($option['withcanvas']) ? $option['withcanvas'] : self::IMAGE_WITH_CANVAS;
            $upSize = isset($option['upsize']) ? $option['upsize'] : self::IMAGE_UP_SIZE;
            $imagePath = $this->getFullFilePath($imageName, $this->uploadPath, $this->extension, $postfix);

            $image = $this->manager->make($this->imagePath);

            !$withCanvas && $image->backup();

            if ($key !== self::ORIGIN_IMAGE_KEY && ($option['width'] || $option['height'])) {
                $resizeMethod = isset($option['method']) ? $option['method'] : $this->defaultResizeMethod;
                switch ($resizeMethod) {
                    case self::METHOD_CROP:
                        $image->fit($option['width'], $option['height']);
                        break;
                    case self::METHOD_RESIZE:
                        if ($image->width() >= $image->height()) {
                            $image->resize($option['width'], null, function($constraint) use ($upSize) {
                                $constraint->aspectRatio();
                                $upSize && $constraint->upsize();
                            });
                        } else {
                            $image->resize(null, $option['height'], function($constraint) use ($upSize) {
                                $constraint->aspectRatio();
                                $upSize && $constraint->upsize();
                            });
                        }
                        break;
                }
            }

            $withCanvas && $image = $this->manager->canvas($image->width(), $image->height(), 'ffffff')->insert($image, 'center');
            $quality = isset($option['compress']) ? $option['compress'] : self::IMAGE_QUALITY;

            try {
                $image->encode($this->extension, $quality)->save($imagePath);
                !$withCanvas && $image->reset();
            } catch (\Exception $e) {
                $result = false;
                throw new \Exception('Save Image Failed: ',  $e->getMessage(), "\n");
            }

            if ($this->response) {
                if ($result === true) {
                    $responseData[$key] = [
                        'result' => $result,
                        'image' => [
                            'name' => $image->filename,
                            'basename' => $image->basename,
                            'dirname' => $image->dirname,
                            'size' => $image->filesize(),
                            'extension' => $image->extension,
                            'mime' => $image->mime,
                            'fileTransformations' => $option,
                        ],
                    ];
                } else {
                    $responseData[$key] = [
                        'result' => $result,
                    ];
                }
                $result = $responseData;
            }
        }

        return $result;
    }

    public function delete()
    {
        $fileTransformations = $this->getFileTransformations();

        foreach($fileTransformations as $option) {
            $postfix = isset($option['postfix']) ? $option['postfix'] : null;
            $this->deleteFile($this->getFullFilePath($this->getOriginalImageName(), $this->uploadPath, $this->extension, $postfix));
        }
        return true;
    }

    public function deleteAll()
    {
        if (is_dir($path = $this->uploadPath)) {
            array_map('unlink', glob($path . '/*'));
            rmdir($path);
        }
    }

    public function deleteFile($path)
    {
        if (file_exists($path)) {
            unlink($path);
        }
    }

    public function setImageName($name)
    {
        $this->imageName = $name;
    }

    public function setUploadPath($path)
    {
        $this->uploadPath = $path;
    }

    protected function getFileTransformations()
    {
        return $this->saveOriginal === true ? array_merge($this->fileTransformations, [
            self::ORIGIN_IMAGE_KEY => ['width' => $this->originalMaxWidth, 'height' => $this->originalMaxHeight, 'method' => 'resize']
        ]) : $this->fileTransformations;
    }

    protected function getFullFilePath($name, $uploadPath, $extension, $postfix = null)
    {
        if ($this->imagePathCreator instanceof Closure) {
            $filePath = call_user_func($this->imagePathCreator, $name, $uploadPath, $extension, $this);
        } else {
            $postfix !== null && $name = $name . '-' . $postfix;
            $filePath = $uploadPath . DIRECTORY_SEPARATOR . $name . '.' . $extension;
        }
        return $filePath;
    }

    public function getOriginalImageName()
    {
        if (null === $this->originalImageName) {
            $name = $this->imageName;
            if ($this->imageNameCreator instanceof Closure) {
                $name = call_user_func($this->imageNameCreator, $name, $this);
            } else {
                $name = Inflector::slug(preg_replace("/[\s_]/", "-", strtolower($name)));
                if ($this->ensureUnique) {
                    $name = $this->makeFileNameUnique($name);
                }
            }
            $this->originalImageName = $name;
        }

        return $this->originalImageName;
    }

    public function setOriginalImageName($name)
    {
         $this->originalImageName = $name;
    }

    protected function makeFileNameUnique($name)
    {
        if (null === $this->uploadPath) {
            throw new InvalidParamException("For unique file name generation must set 'uploadPath' property");
        }

        $filePath = $this->uploadPath . DIRECTORY_SEPARATOR . $name . '.' . $this->extension;

        if (file_exists($filePath)) {
            $name = $this->makeFileNameUnique($name . '-' . uniqid());
        }
        return $name;
    }

    protected function createDirectory($path)
    {
        return FileHelper::createDirectory($path, 0775, true);
    }
}
