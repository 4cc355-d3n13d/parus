<?php

namespace rokorolov\parus\blog\repositories;

use rokorolov\parus\blog\models\Post;
use rokorolov\parus\blog\models\Category;
use rokorolov\parus\blog\dto\PostDto;
use rokorolov\parus\user\models\User;
use rokorolov\parus\user\models\Profile;
use rokorolov\parus\admin\base\BaseReadRepository;
use Yii;
use yii\db\Query;

/**
 * UserReadRepository
 *
 * @author Roman Korolov <rokorolov@gmail.com>
 */
class PostReadRepository extends BaseReadRepository
{
    const TABLE_SELECT_PREFIX_POST = 'p';
    
    public $softDelete = true;
    public $softDeleteAttribute = 'deleted_at';
    
    protected $categoryReadRepository;
    protected $userReadRepository;

    /**
     * Find by id
     *
     * @param int $id
     * @return
     */
    public function findById($id, array $with = [])
    {
        $this->applyRelations($with);
        return $this->findFirstBy('p.id', $id);
    }
    
    public function findPopularPost($limit)
    {
        return $this->limit($limit)
            ->orderBy(['p.hits' => SORT_DESC])
            ->findAll();
    }
    
    public function findLastAddedPost($limit)
    {
        return $this->limit($limit)
            ->orderBy(['p.created_at' => SORT_DESC])
            ->findAll();
    }
    
    public function findForLinkPostPicker()
    {
        return $this->make()
            ->select('p.id, p.title')
            ->all();
    }
     
    public function getSlugByPostId($id)
    {
        $slug = $this->make()
            ->select('p.slug')
            ->where(['p.id' => $id])
            ->scalar();
        
        $this->reset();
        
        return $slug;
    }
    
    public function getIdByPostSlug($slug)
    {
        $id = $this->make()
            ->select('p.id')
            ->where(['p.slug' => $slug])
            ->scalar();
        
        $this->reset();
        
        return $id;
    }
    
    public function existsBySlug($attribute, $id = null)
    {
        $exist = (new Query())
            ->from(Post::tableName())
            ->where(['slug' => $attribute])
            ->andFilterWhere(['!=', 'id', $id])
            ->exists();

        return $exist;
    }

    public function make()
    {
        if (null === $this->query) {
            $query = (new Query())->from(Post::tableName() . ' p');
            
            if ($this->softDelete) {
                $query->andWhere(['p.' . $this->softDeleteAttribute => null]);
            }
            
            $this->query = $query;
        }

        return $this->query;
    }

    public function populate(&$data, $prefix = true)
    {
        return $this->toPostDto($data, $prefix);
    }

    protected function getRelations()
    {
        return [
            'createdBy' => self::RELATION_ONE,
            'updatedBy' => self::RELATION_ONE,
            'author' => self::RELATION_ONE,
            'category' => self::RELATION_ONE
        ];
    }

    public function resolveCreatedBy($query)
    {
        if (!in_array('updatedBy', $this->resolvedRelations)) {
            $query->addSelect($this->getUserReadRepository()->selectAttributesMap())
                ->leftJoin(User::tableName() . ' u', 'p.created_by = u.id');
        }
    }

    public function resolveUpdatedBy($query)
    {
        if (!in_array('createdBy', $this->resolvedRelations)) {
            $query->addSelect($this->getUserReadRepository()->selectAttributesMap())
                ->leftJoin(User::tableName() . ' u', 'p.updated_by = u.id');
        }
    }
    
    public function resolveAuthor($query)
    {
        $userRepository = $this->getUserReadRepository();
        $query->addSelect($userRepository->selectAttributesMap() . ', ' . $userRepository->selectProfileAttributesMap())
            ->leftJoin(User::tableName() . ' u', 'p.created_by = u.id')
            ->leftJoin(Profile::tableName() . ' up', 'up.user_id = u.id');
    }

    public function resolveCategory($query)
    {
        $query->addSelect($this->getCategoryReadRepository()->selectAttributesMap())
            ->leftJoin(Category::tableName() . ' c', 'p.category_id = c.id');
    }

    protected function populateCreatedBy($post, &$data)
    {
        if (!in_array('updatedBy', $this->populatedRelations)) {
            $post->createdBy = $this->getUserReadRepository()->parserResult($data);
        } else {
            $post->createdBy = $this->getUserReadRepository()->findById($post->created_by);
        }
    }

    protected function populateUpdatedBy($post, &$data)
    {
        if (!in_array('createdBy', $this->populatedRelations)) {
            $post->updatedBy = $this->getUserReadRepository()->parserResult($data);
        } else {
            $post->updatedBy = $this->getUserReadRepository()->findById($post->updated_by);
        }
    }
    
    protected function populateAuthor($post, &$data)
    {
        $post->author = $this->getUserReadRepository()->with(['profile'])->parserResult($data);
    }

    protected function populateCategory($post, &$data)
    {
        $post->category = $this->getCategoryReadRepository()->parserResult($data);
    }
    
    protected function getCategoryReadRepository()
    {
        if ($this->categoryReadRepository === null) {
            $this->categoryReadRepository = Yii::createObject('rokorolov\parus\blog\repositories\CategoryReadRepository');
        }
        return $this->categoryReadRepository;
    }

    protected function getUserReadRepository()
    {
        if ($this->userReadRepository === null) {
            $this->userReadRepository = Yii::createObject('rokorolov\parus\user\repositories\UserReadRepository');
        }
        return $this->userReadRepository;
    }

    public function selectAttributesMap()
    {
        return 'p.id AS p_id, p.category_id AS p_category_id, p.status AS p_status, p.hits AS p_hits, p.image AS p_image, p.post_type AS p_post_type,'
        . ' p.published_at AS p_published_at, p.publish_up AS p_publish_up, p.publish_down AS p_publish_down, p.created_by AS p_created_by,'
        . ' p.language AS p_language, p.title AS p_title, p.slug AS p_slug, p.introtext AS p_introtext, p.fulltext AS p_fulltext,  p.view AS p_view,'
        . ' p.version AS p_version,  p.reference AS p_reference, p.meta_title AS p_meta_title, p.meta_keywords AS p_meta_keywords, p.meta_description AS p_meta_description,'
        . ' p.created_at AS p_created_at, p.updated_by AS p_updated_by, p.updated_at AS p_updated_at, p.deleted_at AS p_deleted_at';
    }

    public function toPostDto(&$data, $prefix = true)
    {
        $prefix = $prefix === true ? self::TABLE_SELECT_PREFIX_POST : null;
        return new PostDto($data, $prefix);
    }
}
