<?php

declare(strict_types=1);

namespace Orbit\Extensions\Documents\Infrastructure;

use RuntimeException;

/** A typed corruption signal preserved through the AWS/Guzzle exception chain. Never contains bytes or keys. */
final class DocumentBodyUnavailable extends RuntimeException {}
