<?php
namespace ChefsPlate\ODM\Services;

use ChefsPlate\ODM\Entities\Model;
use Doctrine\Common\EventManager;
use Doctrine\MongoDB\Connection;
use Doctrine\ODM\MongoDB\Configuration;
use Doctrine\ODM\MongoDB\DocumentManager;
use Doctrine\ODM\MongoDB\SoftDelete\SoftDeleteManager;

class DocumentMapperService extends DocumentManager
{
    /* @var SoftDeleteManager $sdm */
    private $sdm;

    /* @var bool */
    private $soft_deletes_enabled = true;

    /* @var $entity_classname string */
    private $entity_classname = null;

    /**
     * DocumentMapperService constructor.
     * @param Connection $conn
     * @param mixed $config
     * @param EventManager $event_manager
     * @param array $laravel_config
     */
    public function __construct(
        Connection $conn = null,
        Configuration $config = null,
        EventManager $event_manager = null,
        $laravel_config = array()
    ) {
        $manager = $event_manager == null ? new EventManager() : $event_manager;
        parent::__construct($conn, $config, $manager);

        $this->sdm                  = null;
        $this->soft_deletes_enabled = $laravel_config['soft_deletes']['enabled'];
        if ($this->soft_deletes_enabled) {
            $soft_delete_configuration = new \Doctrine\ODM\MongoDB\SoftDelete\Configuration();
            $soft_delete_configuration->setDeletedFieldName($laravel_config['soft_deletes']['field_name']);
            $this->sdm = new SoftDeleteManager($this, $soft_delete_configuration, $manager);
        }
    }

    /**
     * @param $repository
     * @param array $conditions
     * @return self|Model|mixed|null
     */
    public function first($repository, $conditions = array())
    {
        $query = self::createQueryBuilder($repository);
        foreach ($conditions as $condition_field => $condition_value) {
            $query->field($condition_field)->equals($condition_value);
        }
        if ($this->soft_deletes_enabled) {
            $query->field($this->getSoftDeleteManager()->getConfiguration()->getDeletedFieldName())
                ->exists(false);
        }
        $result = $query->getQuery()->getSingleResult();

        return $result;
    }

    /**
     * @param $repository string entity class name
     * @param array $conditions a mapping of field names and their expected value
     *
     * @return self|Model|mixed|null this should always return a model or null if it cannot be created
     * @throws \Doctrine\ODM\MongoDB\MongoDBException
     */
    public function firstOrCreate($repository, $conditions = array())
    {
        $query = self::createQueryBuilder($repository);
        foreach ($conditions as $condition_field => $condition_value) {
            $query->field($condition_field)->equals($condition_value);
        }
        if ($this->soft_deletes_enabled) {
            $query->field($this->getSoftDeleteManager()->getConfiguration()->getDeletedFieldName())
                ->exists(false);
        }
        if (is_null($result = $query->getQuery()->getSingleResult())) {
            /* @var $new_model Model */
            $new_model = new $repository();
            foreach ($conditions as $condition_field => $condition_value) {
                $new_model->__set($condition_field, $condition_value);
            }
            self::persistAndFlush($new_model);

            // use reflection to determine actual entity class and create and return instantiated model
            return $new_model;
        }
        return $result;
    }

    /**
     * Perform a Doctrine query, but do not execute it.
     *
     * @param $repository string entity class name
     * @param array $conditions fields to test
     * @param array|string $projections a field name string (or an array of field name strings) to return in the
     *  projection
     * @param bool $include_deleted whether to include deleted results
     * @return \Doctrine\ODM\MongoDB\Query\Builder
     */
    public function where($repository, $conditions = array(), $projections = array(), $include_deleted = false)
    {
        $query = self::createQueryBuilder($repository);
        if ($conditions) {
            foreach ($conditions as $condition_field => $condition_value) {
                $query->field($condition_field)->equals($condition_value);
            }
        }
        if ($this->soft_deletes_enabled && !$include_deleted) {
            $query->field($this->getSoftDeleteManager()->getConfiguration()->getDeletedFieldName())
                ->exists(false);
        }
        if ($projections) {
            $query->select($projections);
        }
        return $query;
    }

    /**
     * @param \ChefsPlate\ODM\Entities\Model $document
     */
    public function persistAndFlush($document)
    {
        if ($this->soft_deletes_enabled && $document->getDeletedAt()) {
            $this->sdm->flush();
        } else {
            self::persist($document);
            self::flush();
        }
    }

    /**
     * Tells the DocumentManager to make an instance managed and persistent.
     *
     * The document will be entered into the database at or before transaction
     * commit or as a result of the flush operation.
     *
     * NOTE: The persist operation always considers documents that are not yet known to
     * this DocumentManager as NEW. Do not pass detached documents to the persist operation.
     *
     * @param object $document The instance to make managed and persistent.
     * @throws \InvalidArgumentException When the given $document param is not an object
     */
    public function persist($document)
    {
        $this->entity_classname = get_class($document);
        parent::persist($document);
    }

    /**
     * Flushes all changes to objects that have been queued up to now to the database.
     * This effectively synchronizes the in-memory state of managed objects with the
     * database.
     *
     * @param object $document
     * @param array $options Array of options to be used with batchInsert(), update() and remove()
     * @throws \InvalidArgumentException
     */
    public function flush($document = null, array $options = array())
    {
        if ($document) {
            $this->entity_classname = get_class($document);
        }
        parent::flush($document, $options);
    }


    public function restore($document)
    {
        if ($this->soft_deletes_enabled) {
            $this->sdm->restore($document);
            $this->sdm->flush();
        } else {
            throw new \BadMethodCallException('Soft deletes are not enabled. Enable soft deletes to restore documents');
        }
    }

    /**
     * Alias for delete.
     *
     * @param object $document
     */
    public function remove($document)
    {
        $this->delete($document);
    }

    public function delete($document)
    {
        if ($this->soft_deletes_enabled) {
            $this->sdm->delete($document);
            $this->sdm->flush();
        } else {
            parent::remove($document);
            self::flush();
        }
    }

    // TODO: we may need to override getDocumentCollections() as well
    public function getDocumentCollection($classname)
    {
        $embedded_collections = config('doctrine.embedded_collections');

        if (isset($embedded_collections[$classname])) {
            $collection_name = $embedded_collections[$classname];
            $db              = $this->getDocumentDatabase($classname);

            // assume DB collection
            $collection = $db->selectCollection($collection_name);
            $collection->setSlaveOkay(); // TODO: extract from metadata annotations (currently using default = true)

            return $collection;
        }
        return parent::getDocumentCollection($classname);
    }

    /**
     * Returns the metadata for a class.
     *
     * @param string $class_name The class name.
     * @return \Doctrine\ODM\MongoDB\Mapping\ClassMetadata
     * @internal Performance-sensitive method.
     */
    public function getClassMetadata($class_name)
    {
        $class = parent::getClassMetadata($class_name);

        if ($this->entity_classname == $class_name) {
            $embedded_collections = config('doctrine.embedded_collections');

            // treat embeddable collections as regular entities
            if (isset($embedded_collections[$class_name])) {
                $class->isEmbeddedDocument = false;
                $class->collection         = $embedded_collections[$class_name];
            }
        }

        return $class;
    }

    public function getSoftDeleteManager()
    {
        return $this->sdm;
    }

    public function setSoftDeletesEnabled($enabled = true)
    {
        $this->soft_deletes_enabled = $enabled;
    }

    /**
     * Helper function to return the specific type for each annotated field.
     *
     * @param $field string
     * @param $model \ChefsPlate\ODM\Entities\Model
     * @param $metadata \Doctrine\Common\Persistence\Mapping\ClassMetadata
     * @return null|string
     */
    public function getTypeForValue($field, $model, $metadata = null)
    {
        if (is_null($metadata)) {
            $metadata = self::getClassMetadata(get_class($model));
        }

        $value = $metadata->getTypeOfField($field);

        if (is_null($value)) {
            return $this->formatFQCN(get_class($model));
        }

        $reserved_fieldnames = $model->getReservedProperties();
        if (in_array($field, $reserved_fieldnames)) {
            return $this->formatFQCN(\DateTime::class);
        }
        switch ($value) {
            case 'carbon':
            case 'date':
                $type = $this->formatFQCN(\Carbon\Carbon::class);
                break;
            case 'carbon_array':
                $type = $this->formatFQCN(\Carbon\Carbon::class) . "[]";
                break;
            case 'string':
                $type = 'string';
                break;
            case 'id':
                $type = $this->formatFQCN(\MongoId::class);
                break;
            case 'int':
                $type = 'int';
                break;
            case 'collection':
            case 'hash':
                $type = 'array';
                break;
            case 'one':
                $type = $this->formatFQCN($metadata->getAssociationTargetClass($field));
                break;
            case 'many':
                $type = $metadata->getAssociationTargetClass($field) ?
                    $this->formatFQCN($metadata->getAssociationTargetClass($field)) . "[]" :
                    "array";
                break;
            default:
                $type = 'mixed';
                break;
        }

        if ($type == null) {
            $type = $this->formatFQCN(get_class($model));
        }

        return $type;
    }

    public function formatFQCN($class)
    {
        // the Doctrine annotation may have already been fully qualified, so we need to remove any double-leading
        // slashes
        $formatted = ltrim($class, "\\");
        return "\\" . $formatted;
    }
}
