<?php
/**
 * Laravel IDE Helper Generator
 *
 * @author    Barry vd. Heuvel <barryvdh@gmail.com>, David Chang <davidchchang@gmail.com>
 * @copyright 2014 Barry vd. Heuvel / Fruitcake Studio (http://www.fruitcakestudio.nl)
 * @license   http://www.opensource.org/licenses/mit-license.php MIT
 * @link      https://github.com/barryvdh/laravel-ide-helper
 */

namespace ChefsPlate\ODM\Providers;

use ChefsPlate\ODM\Console\Commands\DoctrineModelsCommand;
use Illuminate\Support\ServiceProvider;

class IdeOdmHelperServiceProvider extends ServiceProvider
{

    /**
     * Indicates if loading of the provider is deferred.
     *
     * @var bool
     */
    protected $defer = true;

    /**
     * Bootstrap the application events.
     *
     * @return void
     */
    public function boot()
    {
        $configPath = __DIR__ . '/../config/ide-odm-helper.php';
        if (function_exists('config_path')) {
            $publishPath = config_path('ide-odm-helper.php');
        } else {
            $publishPath = base_path('config/ide-odm-helper.php');
        }
        $this->publishes([$configPath => $publishPath], 'config');
    }

    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
        $configPath = __DIR__ . '/../../config/ide-odm-helper.php';
        $this->mergeConfigFrom($configPath, 'ide-helper');

        $this->app['command.ide-helper.doctrine-models'] = $this->app->share(
            function ($app) {
                return new DoctrineModelsCommand($app['files']);
            }
        );

        $this->commands('command.ide-helper.doctrine-models');
    }

    /**
     * Get the services provided by the provider.
     *
     * @return array
     */
    public function provides()
    {
        return array('command.ide-helper.doctrine-models');
    }
}
