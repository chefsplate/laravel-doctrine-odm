<?php

namespace ChefsPlate\ODM\Entities;

use DateTime;
use Doctrine\Common\Collections\Collection;
use Doctrine\ODM\MongoDB\Mapping\Annotations as ODM;
use Doctrine\ODM\MongoDB\SoftDelete\SoftDeleteable;
use DocumentMapper;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Support\Jsonable;

/**
 * @ODM\MappedSuperclass
 * @ODM\HasLifecycleCallbacks
 */
class Model implements SoftDeleteable, Jsonable, Arrayable
{
    // TODO: use traits once this PR is approved (if ever) - https://github.com/doctrine/annotations/pull/58
    // use EloquentTimestamps;

    // TODO: implement serializable

    /** @ODM\Date */
    protected $updated_at;

    /** @ODM\Date */
    protected $created_at;

    /** @ODM\Date */
    protected $deleted_at;

    private $reserved_field_names = ['updated_at', 'created_at', 'deleted_at'];

    protected $response_format = 'default';

    /** @var callable|string|null */
    protected $es_index_helper = null;

    protected $es_info = null;

    protected static $response_formats = [
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
                $this->setCreatedAt(new DateTime('now', timezone_open(UTC_TIMEZONE)));
            }
        }
    }

    /**
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
     *@ODM\postRemove
     */
    public function deleteFromIndex()
    {
        if ($this->es_info) {
            ESHelper::deleteDocumentFromType($this);
        }
    }

    private $model_properties = [];

    public function __construct()
    {
    }

    /**
     * @param array $conditions
     * @return self|Model|mixed|null|static
     */
    public static function first($conditions = array())
    {
        return DocumentMapper::first(static::class, $conditions);
    }

    /**
     * @param array $conditions
     * @return self|Model|mixed|null|static
     */
    public static function firstOrCreate($conditions = array())
    {
        return DocumentMapper::firstOrCreate(static::class, $conditions);
    }

    /**
     * @param $id
     * @return self|Model|mixed|null|static
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
     * @param bool $include_deleted whether to include deleted results
     *
     * @return \Doctrine\ODM\MongoDB\Query\Builder
     */
    public static function where($conditions = array(), $projections = array(), $include_deleted = false)
    {
        return DocumentMapper::where(static::class, $conditions, $projections, $include_deleted);
    }

    public function getModelProperties()
    {
        if ($this->model_properties) {
            return $this->model_properties;
        }

        $this->model_properties = DocumentMapper::getClassMetadata(static::class)->getFieldNames();
        return $this->model_properties;
    }

    public function getReservedProperties()
    {
        return $this->reserved_field_names;
    }

    public function isModelProperty($name)
    {
        // use reflection to determine that this property actually exists
        $properties = $this->getModelProperties();
        return in_array($name, $properties);
    }

    public function isEmbeddedDocument()
    {
        return DocumentMapper::getClassMetadata(static::class)->isEmbeddedDocument;
    }

    public function update($data)
    {
        foreach ($data as $field => $value) {
            $this->__set($field, $value);
        }
        DocumentMapper::persistAndFlush($this);
    }

    public function __set($name, $value)
    {
        if (!$this->isModelProperty($name)) {
            throw new \InvalidArgumentException("$name is not a valid property on model " . static::class);
        }

        $this->{$name} = $value;

        // fluent setter
        return $this;
    }

    public function __get($name)
    {
        if (!$this->isModelProperty($name)) {
            throw new \InvalidArgumentException("$name is not a valid property on model " . static::class);
        }

        return $this->{$name};
    }

    /**
     * is triggered when invoking inaccessible methods in an object context.
     *
     * @param $name string
     * @param $arguments array
     * @return mixed
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

        throw new \BadMethodCallException("No such method called " . $name);
    }

    private function camelToSnake($val)
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

    public function delete()
    {
        DocumentMapper::delete($this);
    }

    public function save()
    {
        DocumentMapper::persistAndFlush($this);
    }

    public function restore()
    {
        DocumentMapper::restore($this);
    }

    /**
     * Convert the object to its JSON representation.
     *
     * @param  int $options
     * @return string
     */
    public function toJson($options = 0)
    {
        return json_encode($this->toArray(), $options);
    }

    /**
     * @param array $format_mappings
     * @return array
     */
    public function toArray($format_mappings = [])
    {
        foreach ($format_mappings as $class_name => $format_name) {
            if (is_a($this, $class_name)) {
                $this->response_format = $format_mappings[$class_name];
                break;
            }
        }

        $result = [];
        foreach ($this->getModelProperties() as $field) {
            if ($this->shouldFieldBeExcluded($field)) {
                continue;
            }

            $value = $this->__get($field);
            if ($value instanceof Collection) {
                $value = $this->collectionToArray($value, $format_mappings);
            } elseif ($value instanceof Arrayable) {
                if ($value instanceof Model) {
                    $value = $value->toArray($format_mappings);
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

    private function collectionToArray(Collection $collection, $format_mappings = [])
    {
        $elements = [];
        foreach ($collection->toArray() as $element) {
            if ($element instanceof Collection) {
                $elements[] = $this->collectionToArray($element);
            } elseif ($element instanceof Arrayable) {
                if ($element instanceof Model) {
                    $elements[] = $element->toArray($format_mappings);
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
     * @param $field string the field to test
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

    public function unsetFieldFromArray(&$response_array, $field_to_unset)
    {
        // TODO: trim spaces around field names
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
            throw new \InvalidArgumentException('Invalid format provided for field: ' . $field_to_unset);
        } else {
            unset($response_array[$field_to_unset]);
        }
    }

    /**
     * @param $field string
     * @param $rule string
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

    private function ruleComparator($a, $b)
    {
        $value_a = $this->getRuleValue($a);
        $value_b = $this->getRuleValue($b);

        if ($value_a != $value_b) {
            return $value_a - $value_b;
        }
        return strcasecmp($a, $b);
    }

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

    public function getEsInfo()
    {
        if ($this->es_info) {
            return $this->es_info;
        }
        return null;
    }
}
