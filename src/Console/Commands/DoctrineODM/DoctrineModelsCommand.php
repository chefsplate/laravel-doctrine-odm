<?php
/**
 * Laravel IDE Helper Generator
 *
 * @author    Barry vd. Heuvel <barryvdh@gmail.com>, David Chang <davidchchang@gmail.com>
 * @copyright 2014 Barry vd. Heuvel / Fruitcake Studio (http://www.fruitcakestudio.nl)
 * @license   http://www.opensource.org/licenses/mit-license.php MIT
 * @link      https://github.com/barryvdh/laravel-ide-helper
 */

namespace ChefsPlate\ODM\Commands;

use Barryvdh\LaravelIdeHelper\Console\ModelsCommand;
use ChefsPlate\ODM\Entities\Model;
use ChefsPlate\ODM\Facades\DocumentMapper;
use Illuminate\Support\Str;
use phpDocumentor\Reflection\DocBlock;
use phpDocumentor\Reflection\DocBlock\Context;
use phpDocumentor\Reflection\DocBlock\Serializer as DocBlockSerializer;
use phpDocumentor\Reflection\DocBlock\Tag;
use Symfony\Component\ClassLoader\ClassMapGenerator;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * A command to generate autocomplete information for your IDE
 *
 * @author Barry vd. Heuvel <barryvdh@gmail.com>, David Chang <davidchchang@gmail.com>
 */
class DoctrineModelsCommand extends ModelsCommand
{
    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'ide-helper:doctrine-models';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate autocompletion for Doctrine ODM models';

    protected function generateDocs($loadModels, $ignore = '')
    {


        $output = "<?php
/**
 * A helper file for your Doctrine Models
 * Copy the phpDocs from this file to the correct Model,
 * And remove them from this file, to prevent double declarations.
 *
 * @author Barry vd. Heuvel <barryvdh@gmail.com>, David Chang <davidchchang@gmail.com>
 */
\n\n";

        if (empty($loadModels)) {
            $models = $this->loadModels();
        } else {
            $models = array();
            foreach ($loadModels as $model) {
                $models = array_merge($models, explode(',', $model));
            }
        }

        $ignore = explode(',', $ignore);

        foreach ($models as $name) {
            if (in_array($name, $ignore)) {
                if ($this->output->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
                    $this->comment("Ignoring model '$name'");
                }
                continue;
            }
            $this->properties = array();
            $this->methods    = array();
            if (class_exists($name)) {
                try {
                    // handle abstract classes, interfaces, ...
                    $reflectionClass = new \ReflectionClass($name);

                    if (!$reflectionClass->isSubclassOf('App\Entities\Model')) {
                        continue;
                    }

                    if ($this->output->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
                        $this->comment("Loading model '$name'");
                    }

                    if (!$reflectionClass->isInstantiable()) {
                        // ignore abstract class or interface
                        continue;
                    }

                    /* @var $model Model */
                    $model = $this->laravel->make($name);

                    // get properties from Doctrine
                    $this->getPropertiesFromFields($model);

                    $output .= $this->createPhpDocs($name);
                    $ignore[] = $name;
                } catch (\Exception $e) {
                    $this->error("Exception: " . $e->getMessage() . "\nCould not analyze class $name.");
                }
            }
        }

        return $output;
    }

    /**
     * Take a string_like_this and return a StringLikeThis
     *
     * @param string
     * @return string
     */
    protected function snakeToCamel($val)
    {
        return str_replace(' ', '', ucwords(str_replace('_', ' ', $val)));
    }

    protected function loadModels()
    {
        $models = array();
        foreach ($this->dirs as $dir) {
            $dir = base_path() . '/' . $dir;
            if (file_exists($dir)) {
                foreach (ClassMapGenerator::createMap($dir) as $model => $path) {
                    $models[] = $model;
                }
            }
        }
        return $models;
    }

    /**
     * Load the properties from the database table.
     *
     * @param \Illuminate\Database\Eloquent\Model $model
     */
    protected function getPropertiesFromTable($model)
    {
        // not relevant for non-relational databases
        /*
        $table            = $model->getConnection()->getTablePrefix() . $model->getTable();
        $schema           = $model->getConnection()->getDoctrineSchemaManager($table);
        $databasePlatform = $schema->getDatabasePlatform();
        $databasePlatform->registerDoctrineTypeMapping('enum', 'string');

        $platformName = $databasePlatform->getName();
        $customTypes  = $this->laravel['config']->get("ide-helper.custom_db_types.{$platformName}", array());
        foreach ($customTypes as $yourTypeName => $doctrineTypeName) {
            $databasePlatform->registerDoctrineTypeMapping($yourTypeName, $doctrineTypeName);
        }

        $database = null;
        if (strpos($table, '.')) {
            list($database, $table) = explode('.', $table);
        }

        $columns = $schema->listTableColumns($table, $database);

        if ($columns) {
            foreach ($columns as $column) {
                $name = $column->getName();
                if (in_array($name, $model->getDates())) {
                    $type = '\Carbon\Carbon';
                } else {
                    $type = $column->getType()->getName();
                    switch ($type) {
                        case 'string':
                        case 'text':
                        case 'date':
                        case 'time':
                        case 'guid':
                        case 'datetimetz':
                        case 'datetime':
                            $type = 'string';
                            break;
                        case 'integer':
                        case 'bigint':
                        case 'smallint':
                            $type = 'integer';
                            break;
                        case 'decimal':
                        case 'float':
                            $type = 'float';
                            break;
                        case 'boolean':
                            $type = 'boolean';
                            break;
                        default:
                            $type = 'mixed';
                            break;
                    }
                }

                $comment = $column->getComment();
                $this->setProperty($name, $type, true, true, $comment);
                $this->setMethod(Str::camel("where_" . $name),
                    '\Illuminate\Database\Query\Builder|\\' . get_class($model), array('$value'));
            }
        }
        */
    }

    /**
     * @param string $name
     * @param string|null $type
     * @param bool|null $read
     * @param bool|null $write
     * @param string|null $comment
     */
    protected function setProperty($name, $type = null, $read = null, $write = null, $comment = '')
    {
        if (!isset($this->properties[$name])) {
            $this->properties[$name]            = array();
            $this->properties[$name]['type']    = is_null($type) ? 'mixed' : $type;
            $this->properties[$name]['read']    = false;
            $this->properties[$name]['write']   = false;
            $this->properties[$name]['comment'] = (string)$comment;
        }
        if ($type !== null) {
            $this->properties[$name]['type'] = $type;
        }
        if ($read !== null) {
            $this->properties[$name]['read'] = $read;
        }
        if ($write !== null) {
            $this->properties[$name]['write'] = $write;
        }
    }

    protected function setMethod($name, $type = '', $arguments = array())
    {
        $methods = array_change_key_case($this->methods, CASE_LOWER);

        if (!isset($methods[strtolower($name)])) {
            $this->methods[$name]              = array();
            $this->methods[$name]['type']      = $type;
            $this->methods[$name]['arguments'] = $arguments;
        }
    }

    /**
     * @param string $class
     * @return string
     */
    protected function createPhpDocs($class)
    {

        $reflection  = new \ReflectionClass($class);
        $namespace   = $reflection->getNamespaceName();
        $classname   = $reflection->getShortName();
        $originalDoc = $reflection->getDocComment();
        $originalPhpdoc = null;

        if ($this->reset) {
            $phpdoc = new DocBlock('', new Context($namespace));
            $originalPhpdoc = new DocBlock($reflection, new Context($namespace));
        } else {
            $phpdoc = new DocBlock($reflection, new Context($namespace));
        }

        if (!$phpdoc->getText()) {
            $phpdoc->setText($class);
        }

        if ($this->reset) {
            // we must add back all the non-generated annotations, otherwise Doctrine will break
            foreach ($originalPhpdoc->getTags() as $tag) {
                $name = $tag->getName();
                if (!in_array($name, ["property", "property-read", "property-write", "method"])) {
                    $cloned_tag = clone $tag;
                    $cloned_tag->setDocBlock(null);
                    $phpdoc->appendTag($cloned_tag);
                }
            }
        }

        $properties = array();
        $methods    = array();
        foreach ($phpdoc->getTags() as $tag) {
            $name = $tag->getName();
            if ($name == "property" || $name == "property-read" || $name == "property-write") {
                $properties[] = $tag->getVariableName();
            } elseif ($name == "method") {
                $methods[] = $tag->getMethodName();
            }
        }

        foreach ($this->properties as $name => $property) {
            $name = "\$$name";
            if (in_array($name, $properties)) {
                continue;
            }
            if ($property['read'] && $property['write']) {
                $attr = 'property';
            } elseif ($property['write']) {
                $attr = 'property-write';
            } else {
                $attr = 'property-read';
            }
            $tagLine = trim("@{$attr} {$property['type']} {$name} {$property['comment']}");
            $tag     = Tag::createInstance($tagLine, $phpdoc);
            $phpdoc->appendTag($tag);
        }

        $existing_methods = get_class_methods($class);

        foreach ($this->methods as $name => $method) {
            if (in_array($name, $methods)) {
                continue;
            }
            // skip this method if we already overrode it with another implementation within the class
            if (in_array($name, $existing_methods)) {
                continue;
            }
            $arguments = implode(', ', $method['arguments']);
            $tag_prefix = $name == "getDeletedAt" ? "@method " : "@method static ";
            $tag       = Tag::createInstance($tag_prefix . "{$method['type']} {$name}({$arguments})", $phpdoc);
            $phpdoc->appendTag($tag);
        }

        // @mixin tag currently not supported by Doctrine annotation parser
//        if ($this->write && !$phpdoc->getTagsByName('mixin')) {
//            $phpdoc->appendTag(Tag::createInstance("@mixin \\App\\Entities\\Model", $phpdoc));
//        }

        $serializer = new DocBlockSerializer();
        $serializer->getDocComment($phpdoc);
        $docComment = $serializer->getDocComment($phpdoc);


        if ($this->write) {
            $filename = $reflection->getFileName();
            $contents = $this->files->get($filename);
            if ($originalDoc) {
                $contents = str_replace($originalDoc, $docComment, $contents);
            } else {
                $needle  = "class {$classname}";
                $replace = "{$docComment}\nclass {$classname}";
                $pos     = strpos($contents, $needle);
                if ($pos !== false) {
                    $contents = substr_replace($contents, $replace, $pos, strlen($needle));
                }
            }
            if ($this->files->put($filename, $contents)) {
                $this->info('Written new phpDocBlock to ' . $filename);
            }
        }

        $output =
            "namespace {$namespace}{\n{$docComment}\n\tclass {$classname} extends \\App\\Entities\\Model {}\n}\n\n";
        return $output;
    }

    /**
     * @param $model Model
     */
    private function getPropertiesFromFields($model)
    {
        $properties = $model->getModelProperties();

        $metadata = DocumentMapper::getClassMetadata(get_class($model));

        foreach ($properties as $field) {
            $name = Str::camel($field);
            if (!empty($name)) {
                $type = DocumentMapper::getTypeForValue($field, $model, $metadata);

                $this->setProperty($field, $type, true, null);

                // Magic get<name>
                $this->setMethod(Str::camel("get_" . $name), $type, array());

                // Magic fluent set<name>
                $this->setMethod(Str::camel("set_" . $name), "\\" . get_class($model), array('$value'));
            }
        }
    }
}
