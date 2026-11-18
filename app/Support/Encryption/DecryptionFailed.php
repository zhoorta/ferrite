<?php

namespace App\Support\Encryption;

use RuntimeException;

/** A blob that cannot be read back: wrong or missing key, damaged, truncated or tampered with. */
class DecryptionFailed extends RuntimeException {}
