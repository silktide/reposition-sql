<?php

namespace Silktide\Reposition\Sql\Storage;

class PdoAdapter extends \PDO
{

    public function __construct(DbCredentialsInterface $credentials) {

        $acceptedAdapters = self::getAvailableDrivers();

        $driver = $credentials->getDriver();

        if (!in_array($driver, $acceptedAdapters)) {
            throw new \PDOException("The driver '$driver' is not currently available to use with PDO");
        }

        $dsn = "$driver:dbname={$credentials->getSchema()};host={$credentials->getHost()}";

        parent::__construct($dsn, $credentials->getUsername(), $credentials->getPassword());
    }

    /**
     * As PDO is unable to accept labels (table names, column names, etc...) as parameters, we
     * have to prepare them manually. This is poor, PDO needs to up it's game.
     *
     * @param $label
     *
     * @return string
     */
    public function prepareLabel($label)
    {
        $label = str_replace(
            array("\x00", "\n", "\r", "\\", "'", "\"", "\x1a", "`"),
            array("\\\x00", "\\\n", "\\\r", "\\\\", "\\'", "\\\"", "\\\x1a", "\\`"),
            $label
        );
        return $label;
    }

} 