<?php

namespace App\Support;

use RuntimeException;

/** A cart line that can't be sold; OrderCart turns it into a validation error on that line. */
class CartError extends RuntimeException {}
