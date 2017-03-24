# Laravel Doctrine 2 ODM for MongoDB [WIP]

A smart, lightweight Laravel wrapper around the [Doctrine ODM](https://github.com/doctrine/mongodb-odm) document mapper. 
Convenient features like soft deletions, automatic create and modification times, and consistent and flexible response formats ()useful when developing APIs) are built in.  

This ODM wrapper is compatible with jensseger's [laravel-mongodb](https://github.com/jenssegers/laravel-mongodb) library, should you want to leverage both Eloquent and Doctrine at the same time. 
 
Note: a "minimum-stability" of "dev" is currently required for this package.  

Please check out the [chefsplate/laravel-doctrine-odm-example](https://github.com/chefsplate/laravel-doctrine-odm-example) repo for a fully-working example of how this package can be used to create a Doctrine-based API on top of Mongo.

Table of contents
-----------------
* [Requirements](#requirements)
* [Installation](#installation)
* [Using the Eloquent-like wrapper methods](#eloquent-like-wrapper-methods)
* [IDE helper for generating phpDocumentation](#ide-helper-for-generating-phpdocumentation)
* [Response Formats](#response-formats)
* [References](#references)


Requirements
------------
- PHP 5.4+
- Laravel 5.3+ (for Laravel 5.1 - 5.2, please use the 5.1 branch)
- PHP mongo extension (ext-mongo) must be installed: http://php.net/manual/en/mongo.installation.php
    - On a Mac, the easiest way to install this extension is through `brew`: `brew install php56` followed by `brew install php56-mongo`

Installation
------------

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

Eloquent-like Wrapper Methods
-----------------------------

## `first` and `where`

`first` is an Eloquent-like way of constructing queries. It uses the arrow (associative array) notation of specifying parameters:

    $user = User::first([
        'username' => 'davidchchang'
    ]);
    
The `first` wrapper will automatically construct the query and fetch the first result returned, so if you want to retrieve more than one document or if you want to use specific query builder methods, you'll need to use `where` method instead.

    $users_named_david_chang = User::where([
        'first_name' => 'David',
        'last_name' => 'Chang'
    ])->getQuery()->execute();
    
    foreach ($users_named_david_chang as $user) {
        // do something with $user here
    }

There is an additional caveat with using these wrapper methods; the `first` and `where` wrapper methods only work with non-entities such as strings, booleans, numbers, and regexes. 
If you want to use any (non-equals) [conditional operators](http://docs.doctrine-project.org/projects/doctrine-mongodb-odm/en/latest/reference/query-builder-api.html#conditional-operators), 
you'll need to chain them before executing the query (note: `first` does not support chaining since it executes the query immediately).

    $recent_user_tasks = Task::where([
        'status' => 'Active'
    ])->field('created_at')->gte(new \MongoDate($date->getTimestamp()))
      ->field('user')->references($user)
      ->getQuery()->execute();

Both `first` and `where` allow you to define an array of projections you would like returned. For example, if you only care about the username and email address fields being set on the returned models, you can specify this in the second parameter:

    $users_named_david = User::where([
        'first_name' => new \MongoRegex('/^David/i')
    ], ['username', 'email']);

## `find`

`find` will return the entity that corresponds to a specific ID.
 
    $user = User::find("davidchchang");


IDE helper for generating phpDocumentation
------------------------------------------

If you're familiar with @barryvdh's IDE helper for generating phpDocumentation (useful for auto-complete), we have built on top of his command generator.

To get started, add the Service Provider to the providers array in config/app.php:

    ChefsPlate\ODM\Providers\IdeOdmHelperServiceProvider::class,
    
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


Response Formats
----------------

If you are using our [Laravel API Response Formatter](https://github.com/chefsplate/laravel-api-response-formatter) (highly recommended with this package), you can leverage the built-in
response format support, which allows you to customize which fields you want returned to the front-end from your APIs.

There are two simple steps you can do to make use of response formats in your code.

## Step One: Define your response formats

First, we define which fields in the model we want to have returned. For example, let's assume your user has the following fields: 
`id`, `username`, `first_name`, `last_name`, `email` and `password`. Upon returning a user object to the front-end, 
we don't ever want to return the `password` field. This can be done by creating a `default` response format within the 
`User.php` model:

    protected static $response_formats = [
        'default' => ['password'],
    ]

The response format is a blacklist array of fields you **_don't_** want in the response. By default, all fields are returned.

So if you don't need the first name, last name or password, you would specify:

    protected static $response_formats = [
        'default' => ['first_name', 'last_name', 'password'],
    ]

Note that this gets pretty cumbersome if there are more fields you don't want then the fields that you do want. As an
alternative, you can specify to exclude all fields using the `*` symbol, and include only the ones you want using the special `|except:` syntax. 
This makes the response format behave more like a whitelist.

As an example:

    'default'   => ['*|except:first_name,email'],

Which means, exclude everything except `first_name` and `email`.

### Multiple response formats for a model

You can add as many named formats as you want here:

For example, if we wanted to add a new response format for formatting emails that only contains the user's first name 
and email address, we could do something like:

    protected static $response_formats = [
        'default' => ['password'],
        'email'   => ['*|except:first_name,email'],
    ]    

### Nested response formats

If your model references other models, you can form complex response formats that restrict what is returned by the referenced models.
For example, if a `Project` model contains a reference to the `User` model, you can specify which user fields you want 
returned (again, all fields for each model are returned by default).

Within `Project.php`:

    protected static $response_formats = [
        'listing_view' => ['created_at', 'updated_at', 'user.*|except:id,username'],
    ]

This example combines both exclusion and inclusion type filters. This corresponds to saying: don't return me
the `created_at` and `updated_at` properties, and also don't return any of the `user` fields except `user.id` and 
`user.username`.

This allows for some very powerful nested response formats while maintaining simplicity in syntax.


## Step Two: Inform your controller endpoints of which response formats to use
 
Now that the formats have been defined in the models, you can specify which models you would like to use when 
returning the payload back to the front-end.
 
Within your controller:
 
    return (new ResponseObject($projects))
        ->setResponseFormatsForModels(
            [
                Project::class   => 'listing_view',
                User::class      => 'email',
            ]
        );

To demonstrate the power of response formats, consider the following:

Here `$projects` is an array of `Project`s, which contain references to `User`s and an embedded list of `Comment`s, 
and the `Comment` model references the `User` model. Since the response format for `Comment` is not defined here, `default` 
is assumed. If the `default` response format is not defined within `Comment.php`, then all fields will be returned. 

However, since we specified the response format to use for `User`s, our API response formatter will format the `User` 
entity referenced within `Comment` using the `email` response format automatically. The `listing_view` on `Project` (as we described 
above) already indicates how it would like to format its `User`s references, so it is not formatted using the `email` 
response format.


ODM Helpers [coming soon]
-------------------------

## OdmHelper

`convertCarbonToMongoDate` will convert a Carbon date to a Mongo date:

    OdmHelper::convertCarbonToMongoDate(Carbon::parse('2016-11-17'))


References
----------

For more info see:
 
- [Using the PHP Library (PHPLIB)](http://php.net/manual/en/mongodb.tutorial.library.php)
- [Doctrine MongoDB ODM’s documentation](http://docs.doctrine-project.org/projects/doctrine-mongodb-odm/en/latest/)
- [chefsplate/laravel-doctrine-odm-example](https://github.com/chefsplate/laravel-doctrine-odm-example) for a fully-working example
- [PHP Annotations plug-in for PhpStorm](https://plugins.jetbrains.com/plugin/7320), compatible with Doctrine