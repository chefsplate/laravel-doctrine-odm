<?php

namespace ChefsPlate\ODM\Entities;

use Carbon\Carbon;
use DateTime;
use Doctrine\Common\Collections\Collection;
use Doctrine\ODM\MongoDB\Mapping\Annotations as ODM;
use Doctrine\ODM\MongoDB\SoftDelete\SoftDeleteable;
use Doctrine\ODM\MongoDB\Query\Builder as QueryBuilder;
use ChefsPlate\ODM\Facades\DocumentMapper;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Support\Jsonable;
use MongoId;
use ReflectionProperty;
use ReflectionClass;

/**
 * @ODM\MappedSuperclass
 * @ODM\HasLifecycleCallbacks
 * @method static Carbon getCreatedAt()
 * @method static Model setCreatedAt($value)
 * @method static Carbon getUpdatedAt()
 * @method static Model setUpdatedAt($value)
 * @method static Model setDeletedAt($value)
 */
class Model implements SoftDeleteable, Jsonable, Arrayable
{
    // TODO: port over translatable trait
    use Translatable;

    // TODO: use traits once this PR is approved (if ever) - https://github.com/doctrine/annotations/pull/58
    // use EloquentTimestamps;

    /** @ODM\Date */
    protected $updated_at;

    /** @ODM\Date */
    protected $created_at;

    /** @ODM\Date */
    protected $deleted_at;

    /** @var array */
    private $reserved_field_names = ['updated_at', 'created_at', 'deleted_at'];

    /** @var string */
    protected $response_format = 'default';

    /** @var callable|string|null */
    protected $es_index_helper = null;

    /** @var string */
    protected $es_info = null;

    /** @var boolean */
    protected $simple_entity = false;

    /** @var boolean */
    public $track_changes = true;

    /**
     * Array containing Doctrine-mapped properties of classes. Strings of property names are within arrays keyed off by
     * the class name they belong to
     *
     * @var array array('class_name' => array('property', ...))
     */
    private static $model_properties = [];

    /** @var array */
    protected static $response_formats = [
        // If the Chefs Plate response formatter (https://github.com/chefsplate/laravel-api-response-formatter) is used,
        // each model can define its own formats that are supported when toJson is called on it
        // e.g. 'format_name' => ['field to unset 1', 'field to unset 2', ... ]
        // also supported:
        // 'format_name' => ['field.subfield']
        // which will unset the subfield within the field
        // wildcards are allowed too
        // 'format_name' => ['*|except:field,field,field']
        // this will unset all fields except the comma-separated ones
        // 'format_name' => ['field.*|except:subfield,subfield,subfield']
        // use the above if you want to specify certain subfields to unset
    ];

    /**
     * Returns the class name of the Document Mapper
     *
     * Important Notice: the instance returned from this is meant to access Static Methods ONLY!
     * Warning: Do NOT remove this method
     *
     * @param string $document_mapper
     * @return DocumentMapper
     */
    public static function getStaticDocumentMapper($document_mapper = null)
    {
        /** @var DocumentMapper $instance */
        $document_mapper = is_null($document_mapper) ? DocumentMapper::class : $document_mapper;
        $reflection = new ReflectionClass($document_mapper);
        $instance = $reflection->newInstanceWithoutConstructor();
        return $instance;
    }

    /**
     * @return array all supported response formats
     */
    public static function getResponseFormats()
    {
        return static::$response_formats;
    }

    /**
     * @ODM\PrePersist
     * @ODM\PreUpdate
     */
    public function updateTimestamps()
    {
        if (!$this->isEmbeddedDocument()) {
            $this->setUpdatedAt(new DateTime('now', timezone_open(UTC_TIMEZONE)));

            if ($this->getCreatedAt() == null) {
                $this->setCreatedAt($this->getUpdatedAt());
            }
        }
    }

    /**
     * TODO: use trait to load in ElasticSearch helper
     * Update the ElasticSearch index based on the helper specified in the model.
     *
     * @ODM\PostPersist
     * @ODM\PostUpdate
     */
    public function updateIndex()
    {
        if ($this->es_index_helper) {
            call_user_func($this->es_index_helper, $this);
        }
    }

    /**
     * TODO: use trait to load in ElasticSearch helper
     * @ODM\postRemove
     */
    public function deleteFromIndex()
    {
        if ($this->es_info) {
            ESHelper::deleteDocumentFromType($this);
        }
    }

    /**
     * @param array $conditions
     * @param string [$document_mapper]
     * @return static|null
     */
    public static function first($conditions = [], $document_mapper = null)
    {
        return static::getStaticDocumentMapper($document_mapper)::first(static::class, $conditions);
    }

    /**
     * @param array $conditions
     * @param string [$document_mapper]
     * @return static|null
     */
    public static function firstOrCreate($conditions = [], $document_mapper = null)
    {
        return static::getStaticDocumentMapper($document_mapper)::firstOrCreate(static::class, $conditions);
    }

    /**
     * @param MongoId|string $id
     * @return static|null
     */
    public static function find($id)
    {
        return self::first(['_id' => $id]);
    }

    /**
     * Perform a Doctrine query, but do not execute it.
     *
     * @param array $conditions fields to test
     * @param array|string $projections a field name string (or an array of field name strings) to return in the
     *  projection
     * @param bool [$include_deleted] whether to include deleted results
     * @param string [$document_mapper]
     *
     * @return QueryBuilder
     */
    public static function where(
        array $conditions = [],
        array $projections = [],
        $include_deleted = false,
        $document_mapper = null
    ) {
        return static::getStaticDocumentMapper($document_mapper)::where(
            static::class,
            $conditions,
            $projections,
            $include_deleted
        );
    }

    /**
     * @param string $property_name
     * @return bool
     */
    public function isPersistedProperty($property_name)
    {
        if (!$this->areClassPropertiesCached()) {
            $this->cacheModelPropertiesWithPersistenceFlag();
        }

        return in_array($property_name, self::$model_properties[static::class]);
    }

    /**
     * Returns an array of Model properties that are mapped with an "@ODM" annotation
     *
     * @return string[]
     */
    public function getPersistedProperties()
    {
        if (!$this->areClassPropertiesCached()) {
            $this->cacheModelPropertiesWithPersistenceFlag();
        }

        return self::$model_properties[static::class];
    }

    /**
     * @return bool
     */
    private function areClassPropertiesCached()
    {
        return isset(self::$model_properties[static::class]);
    }

    /**
     * @return void
     */
    private function cacheModelPropertiesWithPersistenceFlag()
    {
        self::$model_properties[static::class] = [];

        $this->storeVisibleAnnotatedProperties();
        $this->storePrivateAnnotatedProperties();
    }

    /**
     * @return void
     */
    private function storeVisibleAnnotatedProperties()
    {
        $inheritance_tree = array_merge([static::class => static::class], $this->getFullInheritanceTree());

        $static_reflection = new ReflectionClass(static::class);

        $this->storeAnnotatedProperties($static_reflection, $inheritance_tree);
    }

    /**
     * Private properties still belong to an object regardless of class, but are invisible to any child classes.
     * Annotated private properties very much so since they define the document that is ultimately flushed to MongoDB
     * through Doctrine.
     *
     * @return void
     */
    private function storePrivateAnnotatedProperties()
    {
        $inheritance_tree = $this->getFullInheritanceTree();

        foreach ($inheritance_tree as $class_name) {
            $reflection_class = new ReflectionClass($class_name);

            $this->storeAnnotatedProperties($reflection_class, $inheritance_tree, ReflectionProperty::IS_PRIVATE);
        }
    }

    /**
     * @return string[]
     */
    private function getFullInheritanceTree()
    {
        return array_merge(class_parents(static::class), class_uses_recursive(static::class));
    }

    /**
     * @param ReflectionClass $reflection
     * @param array $class_names
     * @param int|null $filter  Defaults to all properties of a class
     * @return void
     */
    private function storeAnnotatedProperties(ReflectionClass $reflection, array $class_names, $filter = null)
    {
        $properties = $filter
            ? $reflection->getProperties($filter)
            : $reflection->getProperties();

        foreach ($properties as $property) {
            if (in_array($property->name, self::$model_properties[static::class])) {
                continue;
            }

            if ($this->isPropertyMapped($property->name, $class_names)) {
                self::$model_properties[static::class][] = $property->name;
            }
        }
    }

    /**
     * @param string $property_name
     * @param array $class_names_to_search
     * @return bool
     * @throws ValidationException
     */
    private function isPropertyMapped($property_name, array $class_names_to_search)
    {
        foreach ($class_names_to_search as $class) {
            if (!property_exists($class, $property_name)) {
                continue;
            }

            $reflection_property = new ReflectionProperty($class, $property_name);

            if (strpos($reflection_property->getDocComment(), '@ODM') !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return string[]
     */
    public function getReservedProperties()
    {
        return $this->reserved_field_names;
    }

    /**
     * @param string [$document_mapper]
     * @return bool
     */
    public function isEmbeddedDocument($document_mapper = null)
    {
        return static::getStaticDocumentMapper($document_mapper)::getClassMetadata(
            static::class
        )->isEmbeddedDocument;
    }

    /**
     * @param mixed $data
     * @param string [$document_mapper]
     * @throws \App\Exceptions\BaseException
     */
    public function update($data, $document_mapper = null)
    {
        // Ensure we are not saving a translated document
        if ($this->isTranslated()) {
            throw ValidationException::create(ERR300_CANNOT_SAVE_TRANSLATED_DOCUMENT);
        }

        foreach ($data as $field => $value) {
            $this->__set($field, $value);
        }
        $this->assertHydratedEntity();

        static::getStaticDocumentMapper($document_mapper)::persistAndFlush($this);
    }

    /**
     * @param string $name
     * @param mixed $value
     * @return static
     * @throws \App\Exceptions\BaseException
     */
    public function __set($name, $value)
    {
        if (!$this->isPersistedProperty($name)) {
            throw InvalidArgumentException::create(
                ERR300_INVALID_PROPERTY_ON_SUBJECT_WITH_ID,
                "property=$name",
                "class=".static::class
            );
        }

        $this->{$name} = $value;

        return $this; // "Fluent Setter"
    }

    /**
     * @param string $name
     * @return mixed
     * @throws \App\Exceptions\BaseException
     */
    public function __get($name)
    {
        if (!$this->isPersistedProperty($name)) {
            throw InvalidArgumentException::create(
                ERR300_INVALID_PROPERTY_ON_SUBJECT_WITH_ID,
                "property=$name",
                "class=".static::class
            );
        }

        return $this->{$name};
    }

    /**
     * Triggered when invoking inaccessible methods in an object context.
     *
     * @param string $name
     * @param array $arguments
     * @return mixed
     * @throws \App\Exceptions\BaseException
     * @link http://php.net/manual/en/language.oop5.overloading.php#language.oop5.overloading.methods
     */
    public function __call($name, $arguments)
    {
        // check that the name starts with get or set
        if (strpos($name, "get") === 0) {
            $property = $this->camelToSnake(lcfirst(substr($name, 3)));
            return $this->__get($property);
        }

        if (strpos($name, "set") === 0) {
            $property = $this->camelToSnake(lcfirst(substr($name, 3)));
            return $this->__set($property, $arguments[0]);
        }

        throw BadMethodCallException::create(ERR300_NO_SUCH_METHOD_CALLED_WITH_ID, $name);
    }

    /**
     * @param string $name
     * @param array $arguments
     * @return mixed
     * @throws \App\Exceptions\BaseException
     */
    public static function __callStatic($name, $arguments)
    {
        // check that the name starts with getValid
        if (strpos($name, "getValid") === 0) {
            return self::getValidModel(...$arguments);
        }

        throw BadMethodCallException::create(ERR300_NO_SUCH_METHOD_CALLED_WITH_ID, $name);
    }

    /**
     * @param string $val
     * @return string
     */
    private function camelToSnake(string $val): string
    {
        return preg_replace_callback(
            '/[A-Z]/',
            function ($match) {
                return "_" . strtolower($match[0]);
            },
            $val
        );
    }

    /**
     * Gets the date that this object was deleted at. Required by SoftDeleteable interface.
     *
     * @return DateTime $deletedAt
     */
    public function getDeletedAt()
    {
        return $this->deleted_at;
    }

    /**
     * Deletes a Doctrine Model
     *
     * @param string [$document_mapper]
     */
    public function delete($document_mapper = null)
    {
        static::getStaticDocumentMapper($document_mapper)::delete($this);
    }

    /**
     * Saves a managed Doctrine Model
     *
     * @param bool $flush
     * @param string [$document_mapper]
     * @throws \App\Exceptions\BaseException
     */
    public function save($flush = true, $document_mapper = null)
    {
        // Ensure we are saving a hydrated document
        $this->assertHydratedEntity();

        // Ensure we are not saving a translated document
        if ($this->isTranslated()) {
            throw ValidationException::create(ERR300_CANNOT_SAVE_TRANSLATED_DOCUMENT);
        }

        if (!$flush) {
            static::getStaticDocumentMapper($document_mapper)::persist($this);
        } else {
            static::getStaticDocumentMapper($document_mapper)::persistAndFlush($this);
        }
    }

    /**
     * Restores a Doctrine Model
     *
     * @param string [$document_mapper]
     */
    public function restore($document_mapper = null)
    {
        static::getStaticDocumentMapper($document_mapper)::restore($this);
    }

    /**
     * Convert the object to its JSON representation.
     *
     * @param int $options
     * @return string|false     JSON string on success, false on failure
     */
    public function toJson($options = 0)
    {
        return json_encode($this->toArray(), $options);
    }

    /**
     * @param array $format_mappings
     * @param string $response_output
     * @param string|null $locale
     * @return array
     */
    public function toArray(
        array $format_mappings = [],
        $response_output = 'hash',
        $locale = null
    ) {
        foreach ($format_mappings as $class_name => $format_name) {
            if (is_a($this, $class_name)) {
                $this->response_format = $format_mappings[$class_name];
                break;
            }
        }

        $result = [];
        foreach ($this->getPersistedProperties() as $field) {
            if ($this->shouldFieldBeExcluded($field)) {
                continue;
            }

            $value = $this->__get($field);
            if ($value instanceof Collection) {
                $value = $this->collectionToArray($value, $format_mappings, $response_output, $locale);
            } elseif ($value instanceof Arrayable) {
                if ($value instanceof Model) {
                    if ($this->isTranslated()) {
                        $value = $locale
                            ? $value->getTranslation($locale, $response_output)->toArray($format_mappings)
                            : $value->getTranslated($response_output)->toArray($format_mappings);
                    } else {
                        $value = $value->toArray($format_mappings);
                    }
                } else {
                    $value = $value->toArray();
                }
            }
            $result[$field] = $value;
        }

        $format = [];
        if (isset(static::$response_formats[$this->response_format])) {
            $format = static::$response_formats[$this->response_format];
        }
        foreach ($format as $field_to_unset) {
            $this->unsetFieldFromArray($result, $field_to_unset);
        }

        return $result;
    }

    /**
     * @param string $json
     * @return static
     */
    public static function fromJson($json)
    {
        $array = json_decode($json, true);
        return static::fromArray($array);
    }

    /**
     * @param array $array
     * @return static
     */
    public static function fromArray(array $array)
    {
        /** @var static $model */
        $class = static::class;
        $reflection = new ReflectionClass($class);
        $model = $reflection->newInstanceWithoutConstructor();
        foreach ($array as $property => $value) {
            $model->__set($property, $value);
        }
        return $model;
    }

    /**
     * @param Collection $collection
     * @param array $format_mappings
     * @param string $response_output
     * @param string|null $locale
     * @return array
     */
    private function collectionToArray(
        Collection $collection,
        array $format_mappings = [],
        $response_output = 'hash',
        $locale = null
    ) {
        $elements = [];
        foreach ($collection->toArray() as $element) {
            if ($element instanceof Collection) {
                $elements[] = $this->collectionToArray($element, $format_mappings, $response_output, $locale);
            } elseif ($element instanceof Arrayable) {
                if ($element instanceof Model) {
                    if ($this->isTranslated()) {
                        $elements[] = $locale
                            ? $element->getTranslation($locale, $response_output)
                                ->toArray($format_mappings, $response_output, $locale)
                            : $element->getTranslated($response_output)
                                ->toArray($format_mappings, $response_output);
                    } else {
                        $elements[] = $element->toArray($format_mappings, $response_output, $locale);
                    }
                } else {
                    $elements[] = $element->toArray();
                }
            } else {
                $elements[] = $element;
            }
        }
        return $elements;
    }

    /**
     * @param string $field the field to test
     * @return bool true if field should be excluded based on rules
     */
    public function shouldFieldBeExcluded($field)
    {
        // don't include updated_at, created_at, or deleted_at fields for embedded models
        if ($this->isEmbeddedDocument() && in_array($field, $this->getReservedProperties())) {
            return true;
        }

        $rules = [];
        if (isset(static::$response_formats[$this->response_format])) {
            $rules = static::$response_formats[$this->response_format];
        }
        // sort rules according to complexity (simple/no dots, with a dot, with asterisk, with dot and asterisk)
        usort($rules, array($this, "ruleComparator"));

        // to prevent accidental traversal of unwanted values, evaluate conditions based on complexity
        foreach ($rules as $rule) {
            if ($this->checkFieldAgainstRule($field, $rule)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array $response_array
     * @param string $field_to_unset
     * @throws \App\Exceptions\BaseException
     */
    public function unsetFieldFromArray(&$response_array, $field_to_unset)
    {
        if (str_contains($field_to_unset, array('.', '*'))) {
            if (str_contains($field_to_unset, '.') and !str_contains($field_to_unset, '*')) {
                array_forget($response_array, $field_to_unset);
                return;
            } elseif (str_contains($field_to_unset, '*')) {
                $fields_to_include = [];
                // TODO: add validation for |<filtername> to see if filtername is supported
                if (($pos = strpos($field_to_unset, '|except:')) >= 0) {
                    $fields_to_include = explode(',', substr($field_to_unset, strpos($field_to_unset, ':') + 1));
                    $field_key         = substr($field_to_unset, 0, $pos);
                } else {
                    $field_key = substr($field_to_unset, 0, strpos($field_to_unset, '*'));
                }
                $field_key = trim($field_key, '.*');
                if (strlen($field_key) === 0) {
                    $array = $response_array;
                } else {
                    $array = array_get($response_array, $field_key);
                }
                // only unset fields when the array is not null
                if (!is_null($array) and is_array($array)) {
                    foreach ($array as $subfield_key => $subfield_value) {
                        if (!in_array($subfield_key, $fields_to_include)) {
                            array_forget($response_array, ($field_key ? $field_key . '.' : '') . $subfield_key);
                        }
                    }
                }
                return;
            }
            throw InvalidArgumentException::create(ERR300_INVALID_FORMAT_FOR_FIELD_WITH_NAME, $field_to_unset);
        } else {
            unset($response_array[$field_to_unset]);
        }
    }

    /**
     * @param string $field
     * @param string $rule
     * @return bool
     */
    private function checkFieldAgainstRule($field, $rule)
    {
        $contains_dot      = str_contains($rule, '.');
        $contains_asterisk = str_contains($rule, '*');

        // field
        if (!$contains_dot and !$contains_asterisk) {
            return $field === $rule;
        }

        // field.subfield
        // field.*|except:subfield,subfield
        // we don't really know if we can safely omit all subfields, so we must parse the remaining structure
        if ($contains_dot) {
            return false;
        }

        // *|except:field,field
        // at this point, the rule must contain an asterisk without any dots
        $fields_to_include = [];
        if (($pos = strpos($rule, '|except:')) >= 0) {
            $fields_to_include = explode(',', substr($rule, strpos($rule, ':') + 1));
        }

        // exclude everything except included fields
        if (in_array($field, $fields_to_include)) {
            return false;
        }
        return true;
    }

    /**
     * @param string $a
     * @param string $b
     * @return int
     */
    private function ruleComparator($a, $b)
    {
        $value_a = $this->getRuleValue($a);
        $value_b = $this->getRuleValue($b);

        if ($value_a != $value_b) {
            return $value_a - $value_b;
        }
        return strcasecmp($a, $b);
    }

    /**
     * @param string $rule
     * @return int
     */
    private function getRuleValue($rule)
    {
        $contains_dot      = str_contains($rule, '.');
        $contains_asterisk = str_contains($rule, '*');
        if ($contains_dot) {
            if (!$contains_asterisk) {
                return 1;
            }
            return 3;
        }
        if ($contains_asterisk) {
            if (!$contains_dot) {
                return 2;
            }
            // should not reach here - dot + asterisk is captured in first contains_dot test
        }
        return 0; // no dot, no asterisk
    }

    /**
     * @return null|string
     */
    public function getEsInfo()
    {
        if ($this->es_info) {
            return $this->es_info;
        }
        return null;
    }

    /**
     * @param string $field
     * @param bool $return_list
     * @return mixed[]|string
     */
    public static function getDistinctValuesForField($field, $return_list = false)
    {
        $values = static::where()->distinct($field)->getQuery()->toArray();

        if ($return_list) {
            $values = implode(',', $values);
        }
        return $values;
    }

    /**
     * Checks if this Entity is a Simple Entity
     *
     * @return bool
     */
    final public function isSimpleEntity()
    {
        return $this->simple_entity;
    }

    /**
     * Ensures that this instance is hydrate if was a Simple Entity
     *
     * @return void
     * @throws \App\Exceptions\BaseException
     */
    final public function assertHydratedEntity()
    {
        if ($this->isSimpleEntity()) {
            throw ValidationException::create(ERR300_CANNOT_PERSIST_SIMPLE_ENTITY);
        }
    }

    /**
     * Returns a Simple Entity Object based on given Model
     * A Simple Entity is a hydrated Doctrine object without any of the Doctrine bloat
     *
     * @param Model $instance
     * @param string [$document_mapper]
     * @return Model
     */
    public static function toSimpleEntity(Model &$instance, $document_mapper = null)
    {
        /**
         * Create Reflection objects from $instance
         * @var Model $simple_instance
         */
        $model = new ReflectionClass($instance);
        $simple_instance = $model->newInstanceWithoutConstructor();
        $simple_model = new ReflectionClass($simple_instance);

        // Copy over the data from given instance to simple instance
        foreach ($model->getProperties() as $property) {
            $property->setAccessible(true);
            $property_simple = $simple_model->getProperty($property->getName());
            $property_simple->setAccessible(true);
            $property_simple->setValue($simple_instance, $property->getValue($instance));
            unset($property);
            unset($property_simple);
        }

        // Set the simple instance property to TRUE
        $property = $simple_model->getProperty('simple_entity');
        $property->setAccessible(true);
        $property->setValue($simple_instance, true);

        // Clear DocumentManager and memory allocations
        static::getStaticDocumentMapper($document_mapper)::detach($instance);
        $instance = null;
        unset($model);
        unset($property);
        unset($simple_model);
        return $simple_instance;
    }

    /**
     * @param $id
     * @param bool $include_deleted
     * @return static
     * @throws \App\Exceptions\BaseException
     */
    public static function getValidModel($id, $include_deleted = false)
    {
        /** @var static $class */
        $class = static::class;
        $model = $class::where(['id' => $id], [], $include_deleted)->getQuery()->getSingleResult();
        if (!$model) {
            throw ResourceNotFoundException::create(ERR200_MODEL_WITH_CLASS_AND_ID, "Class=".$class, "id=".$id);
        }

        /** @var static $model */
        return $model;
    }

    /**
     * @param QueryBuilder $query
     * @param $field  string
     * @return QueryBuilder
     */
    public static function getUnsetFieldQuery(QueryBuilder $query, $field)
    {
        $query = $query->addAnd(
            $query->expr()->addOr($query->expr()->field($field)->equals(null))
                ->addOr($query->expr()->field($field)->exists(false))
        );
        return $query;
    }

    /**
     * Support Deep Cloning of Entities
     */
    public function __clone()
    {
        foreach (get_object_vars($this) as $property => $value) {
            if (is_object($value)) {
                $this->{$property} = clone $value;
            } elseif (is_array($value)) {
                foreach ($value as $value_key => $value_item) {
                    if (is_object($value_item)) {
                        $this->{$property}[$value_key] = clone $value_item;
                    }
                }
            }
        }
    }
}
