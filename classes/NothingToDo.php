<?php
declare(strict_types=1);

namespace RaPostcode;

/** Thrown to leave the importer cleanly when the release is already imported. */
final class NothingToDo extends \RuntimeException
{
}
