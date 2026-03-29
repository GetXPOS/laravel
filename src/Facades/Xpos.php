<?php

namespace GetXPOS\Laravel\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \GetXPOS\Laravel\XposTunnel connect(array $options = [])
 *
 * @see \GetXPOS\Laravel\XposTunnel
 */
class Xpos extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'xpos';
    }
}
