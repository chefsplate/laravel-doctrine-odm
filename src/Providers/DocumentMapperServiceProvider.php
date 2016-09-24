<?php

namespace ChefsPlate\ODM\Providers;

use ChefsPlate\DoctrineODM\Types\CarbonDateArrayType;
use ChefsPlate\DoctrineODM\Types\CarbonDateType;
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
            $laravel_config = config('doctrine');
            $config         = new Configuration();
            $config->setProxyDir($laravel_config['proxies']['path']);
            $config->setProxyNamespace($laravel_config['proxies']['namespace']);
            $config->setHydratorDir($laravel_config['hydrators']['path']);
            $config->setHydratorNamespace($laravel_config['hydrators']['namespace']);
            $config->setMetadataDriverImpl(AnnotationDriver::create($laravel_config['paths']));
            $config->setDefaultDB($laravel_config['mongodb']['database']);
            $config->setMetadataCacheImpl(new VoidCache());

            AnnotationDriver::registerAnnotationClasses();

            // register custom types
            Type::registerType(CarbonDateType::CARBON, CarbonDateType::class);
            Type::registerType(CarbonDateArrayType::CARBON_ARRAY, CarbonDateArrayType::class);

            $dsn           = config('database.connections.mongodb.dsn');
            $connection    = new Connection($dsn);
            $event_manager = new EventManager();

            return new DocumentMapperService(
                $connection,
                $config,
                $event_manager,
                $laravel_config
            );
        });
    }
}
