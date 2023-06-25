<?php

namespace Amohamed\DatabaseAi\Oracle\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \BeyondCode\Oracle\Oracle
 */
class Oracle extends Facade
{
    protected static function getFacadeAccessor()
    {
        return \Amohamed\DatabaseAi\Oracle\Oracle::class;
    }
}
