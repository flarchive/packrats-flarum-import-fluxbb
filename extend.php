<?php

namespace Packrats\ImportFluxBB;

use Packrats\ImportFluxBB\Console\ImportFromFluxBB;
use Flarum\Extend;

return [
    (new Extend\Console())->command(ImportFromFluxBB::class),
];
