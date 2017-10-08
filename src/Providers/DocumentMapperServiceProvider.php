<?php

namespace ChefsPlate\ODM\Providers;

use ChefsPlate\ODM\Types\CarbonDateArrayType;
use ChefsPlate\ODM\Types\CarbonDateType;
use ChefsPlate\ODM\Services\DocumentMapperService;
use Doctrine\Common\Cache\VoidCache;
use Doctrine\Common\EventManager;
use Doctrine\MongoDB\Connection;
use Doctrine\ODM\MongoDB\Configuration;
use Doctrine\ODM\MongoDB\Mapping\Driver\AnnotationDriver;
use Doctrine\ODM\MongoDB\Types\Type;
use Illuminate\Support\ServiceProvider;

class DocumentMapperServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the application services.
     *
     * @return void
     */
    public function boot()
    {
        //
    }

    /**
     * Register the application services.
     *
     * @return void
     */
    public function register()
    {
        $this->app->singleton('DocumentMapper', function ($app) {
            
            // Retrieve all of the required configuration
            $app_config = config('doctrine');
            $doctrine_dm_config = $app_config['doctrine_dm'];
            $laravel_dm_config = $app_config['laravel_dm'];
            $conn_config = config('database.connections.'. $app_config['connection']);

            // Setup the Doctrine configuration object
            $dm_config = new Configuration();
            $dm_config->setProxyDir($doctrine_dm_config['proxies']['path']);
            $dm_config->setProxyNamespace($doctrine_dm_config['proxies']['namespace']);
            $dm_config->setHydratorDir($doctrine_dm_config['hydrators']['path']);
            $dm_config->setHydratorNamespace($doctrine_dm_config['hydrators']['namespace']);
            $dm_config->setMetadataDriverImpl(AnnotationDriver::create($doctrine_dm_config['paths']));
            $dm_config->setDefaultDB($conn_config['database']);
            $dm_config->setMetadataCacheImpl(new VoidCache());

            // Init annotation driver
            AnnotationDriver::registerAnnotationClasses();

            // register custom types
            Type::registerType(CarbonDateType::CARBON, CarbonDateType::class);
            Type::registerType(CarbonDateArrayType::CARBON_ARRAY, CarbonDateArrayType::class);

            // Setup the Doctrine connection object
            $connection_config = new \Doctrine\MongoDB\Configuration();
            $connection_config->setRetryConnect($conn_config['retryConnect']);
            $connection_config->setRetryQuery($conn_config['retryQuery']);

            $connection = new Connection(
                $conn_config['dsn'],
                $conn_config['options'],
                $connection_config,
                null, // Event Manager
                $conn_config['driverOptions']
            );

            return new DocumentMapperService(
                $connection,
                $dm_config,
                new EventManager(),
                $laravel_dm_config
            );
        });
    }
}
