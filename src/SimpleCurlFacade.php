<?php

namespace SquareBoat\SimpleCurl;

use Illuminate\Support\Facades\Facade;

class SimpleCurlFacade extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     *
     * @throws \RuntimeException
     */
    protected static function getFacadeAccessor()
    {
        return 'simplecurl';
    }
}
