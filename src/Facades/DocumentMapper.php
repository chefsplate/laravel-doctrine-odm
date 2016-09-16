<?php
namespace ChefsPlate\ODM\Facades;

use Illuminate\Support\Facades\Facade;

class DocumentMapper extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor()
    {
        return 'DocumentMapper';
    }
}
