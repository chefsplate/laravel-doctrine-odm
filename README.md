# Laravel Doctrine 2 ODM for MongoDB

A smart, lightweight Laravel wrapper around the [doctrine/mongodb-odm](https://github.com/doctrine/mongodb-odm) document mapper.

This ODM wrapper is compatible with jensseger's [laravel-mongodb](https://github.com/jenssegers/laravel-mongodb) library, should you want to leverage both Eloquent and Doctrine at the same time. 
 
Note: a "minimum-stability" of "dev" is currently required for this package.  

Please check out the [chefsplate/laravel-doctrine-odm-example](https://github.com/chefsplate/laravel-doctrine-odm-example) repo for a fully-working example of how this package can be used to create a Doctrine-based API on top of Mongo.
 
## Requirements
- PHP 5.4+
- Laravel 5.x
- PHP mongo extension (ext-mongo) must be installed: http://php.net/manual/en/mongo.installation.php

## Install

Require the latest version of this package with Composer:

    composer require chefsplate/laravel-doctrine-odm:"0.1.x"

Add the Service Provider to the providers array in config/app.php:

    ChefsPlate\ODM\Providers\DocumentMapperServiceProvider::class,
    
Add the facade to your class aliases array in config/app.php:

    'DocumentMapper' => ChefsPlate\ODM\Facades\DocumentMapper::class,

You should now be able to use the **mongodb** driver in config/database.php.

    'mongodb' => array(
        'driver'   => 'mongodb',
        'dsn'      => 'mongodb://127.0.0.1:27017',
        'database' => 'database_name'
    ),

The format for the DSN is:
`mongodb://[username:password@]host1[:port1][,host2[:port2:],...]/db`

# IDE helper for generating phpDocumentation

If you're familiar with @barryvdh's IDE helper for generating phpDocumentation (useful for auto-complete), we have built on top of his command generator.

You just need to include it in your list of $commands within `app\Console\Kernel.php`:

    \ChefsPlate\ODM\Commands\DoctrineModelsCommand::class,

## Usage

The default usage will analyze all models under App\Entities and write all annotations to a `_ide_helper_models.php` file.

    php artisan ide-helper:doctrine-models

You can alternatively choose to write annotations directly to the PHP DocBlock class annotations within the PHP files themselves.

If the annotations contain duplicates, you can use the --reset option to replace existing DocBlock annotations:

    php artisan ide-helper:doctrine-models --reset
    
Or specifically, to reset a single entity:

    php artisan ide-helper:doctrine-models App\Entities\ModelName --reset

For complete usage on generating helper annotations, use `--help`:

    php artisan ide-helper:doctrine-models --help


# References

For more info see:
 
- [Using the PHP Library (PHPLIB)](http://php.net/manual/en/mongodb.tutorial.library.php)
- [Doctrine MongoDB ODM’s documentation](http://docs.doctrine-project.org/projects/doctrine-mongodb-odm/en/latest/)
- [chefsplate/laravel-doctrine-odm-example](https://github.com/chefsplate/laravel-doctrine-odm-example) for a fully-working example
- [PHP Annotations plug-in for PhpStorm](https://plugins.jetbrains.com/plugin/7320), compatible with Doctrine