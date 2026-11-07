<?php

namespace App\Services\Mobile;

use RuntimeException;

/** An offline change points at another offline change that has not been saved. */
class UnresolvedReference extends RuntimeException {}
