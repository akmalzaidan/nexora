<?php

namespace App\Exceptions;

use Exception;

/**
 * Thrown when login credentials are invalid or the account is not active.
 *
 * The response is deliberately generic so callers cannot distinguish
 * "unknown email" from "wrong password" or "inactive account".
 */
class InvalidCredentialsException extends Exception {}
