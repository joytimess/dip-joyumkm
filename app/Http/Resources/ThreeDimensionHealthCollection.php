<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\ResourceCollection;

class ThreeDimensionHealthCollection extends ResourceCollection
{
    public $collects = ThreeDimensionHealthItemResource::class;
}
