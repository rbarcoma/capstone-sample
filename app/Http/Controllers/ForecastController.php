<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Admin\ForecastingController;

class ForecastController extends ForecastingController
{
    public function runForecast()
    {
        abort_unless(auth()->check() && auth()->user()->role === 'admin', 403);

        return $this->predict(request());
    }
}
