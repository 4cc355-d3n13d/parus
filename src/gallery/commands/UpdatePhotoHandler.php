<?php

namespace rokorolov\parus\gallery\commands;

use rokorolov\parus\gallery\repositories\PhotoRepository;
use rokorolov\parus\admin\traits\PurifierTrait;
use rokorolov\parus\gallery\helpers\Settings;
use rokorolov\parus\admin\exceptions\LogicException;
use Yii;

/**
 * UpdatePhotoHandler
 *
 * @author Roman Korolov <rokorolov@gmail.com>
 */
class UpdatePhotoHandler
{
    use PurifierTrait;
    
    private $photoRepository;
    
    public function __construct(
        PhotoRepository $photoRepository
    ) {
        $this->photoRepository = $photoRepository;
    }
    
    public function handle(UpdatePhotoCommand $command)
    {
        if (null ===  $photo = $this->photoRepository->findById($command->getId())) {
            throw new LogicException('Photo does not exist.');
        }

        $config = Settings::uploadAlbumConfig($photo->album_id);
        $photo->updated_at = Yii::$app->formatter->asDatetime('now', 'php:Y-m-d H:i:s');
        
        $transaction = Yii::$app->db->beginTransaction();
        
        try {
            $this->photoRepository->update($photo);
            
            foreach($command->getTranslations() as $translation) {
                
                $photoLanguage = null;
                
                foreach ($photo->translations as $translationRelation) {
                    if ((string)$translationRelation->language === (string)$translation['language']) {
                        $photoLanguage = $translationRelation;
                    }
                }
                
                if (null === $photoLanguage) {
                    $photoLanguage = $this->photoRepository->makePhotoLangModel();
                    $photoLanguage->photo_id = $photo->id;
                    $photoLanguage->language = $translation['language'];
                }
                
                $photoLanguage->caption = $config['allowedHtmlTags'] ? $this->purify($translation['caption']) : $this->textPurify($translation['caption']);
                $photoLanguage->description = $config['allowedHtmlTags'] ? $this->purify($translation['description']) : $this->textPurify($translation['description']);

                $this->photoRepository->update($photoLanguage);
            }
            
            $transaction->commit();
        } catch (\Exception $e) {
            $transaction->rollBack();
            throw $e;
        }
        
        $command->model = $photo;
    }
}