<?php

namespace Silktide\Reposition\Sql\Adapter;

class PdoAdapter extends \PDO
{

    public function __construct($driver, $schema, $host, $username, $password) {

        $acceptedAdapters = self::getAvailableDrivers();

        if (!in_array($driver, $acceptedAdapters)) {
            throw new \PDOException("The driver '$driver' is not currently available to use with PDO");
        }

        $dsn = "$driver:dbname=$schema;host=$host";

        parent::__construct($dsn, $username, $password);
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
            array("\x00", "\n", "\r", "\\", "\'", "\"", "\x1a"),
            array("\\\x00", "\\\n", "\\\r", "\\\\", "\\\'", "\\\"", "\\\x1a"),
            $label
        );
        return $label;
    }

} 