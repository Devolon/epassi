<?php

namespace Devolon\EPassi;

use Devolon\Payment\Contracts\PaymentGatewayInterface;
use Illuminate\Support\ServiceProvider;

class DevolonEPassiServiceProvider extends ServiceProvider
{
    public function register()
    {
        if (env('IS_EPASSI_AVAILABLE', false)) {
            $this->app->tag(EPassiGateway::class, PaymentGatewayInterface::class);
        }

        $this->mergeConfigFrom(__DIR__ . '/../config/epassi.php', 'epassi');
        $this->publishes([
            __DIR__ . '/../config/epassi.php' => config_path('epassi.php')
        ], 'epassi-config');
    }
}
