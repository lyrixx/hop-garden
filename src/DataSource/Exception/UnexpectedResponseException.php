<?php

namespace App\DataSource\Exception;

/**
 * An API answered with a status 200 and a JSON body that is not what its documentation describes.
 */
final class UnexpectedResponseException extends \UnexpectedValueException
{
}
