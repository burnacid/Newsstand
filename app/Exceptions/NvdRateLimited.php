<?php

namespace App\Exceptions;

use RuntimeException;

/** NVD asked us to slow down (HTTP 403/429); not an error, retry later. */
class NvdRateLimited extends RuntimeException {}
