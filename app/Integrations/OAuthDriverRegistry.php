<?php

namespace App\Integrations;

use App\Contracts\OAuthDriver;
use App\ServiceProvider;
use RuntimeException;

class OAuthDriverRegistry
{
    /**
     * @param  iterable<OAuthDriver>  $drivers
     */
    public function __construct(private iterable $drivers) {}

    public function for(ServiceProvider $provider): OAuthDriver
    {
        foreach ($this->drivers as $driver) {
            if ($driver->supports($provider)) {
                return $driver;
            }
        }

        throw new RuntimeException('Driver OAuth non disponibile per '.$provider->label().'.');
    }
}
