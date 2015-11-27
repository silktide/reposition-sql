<?php

namespace Silktide\Reposition\Sql\Storage;

/**
 * Class PdoAdapter
 *
 * Wrapper for a PDO connection with lazy loading
 *
 * @package Silktide\Reposition\Sql\Storage
 */
class PdoAdapter
{

    /**
     * @var DbCredentialsInterface
     */
    protected $credentials;

    /**
     * @var \PDO
     */
    protected $pdo;

    /**
     * @param DbCredentialsInterface $credentials
     * @param bool $lazyConnection
     */
    public function __construct(DbCredentialsInterface $credentials, $lazyConnection = true)
    {
        $this->credentials = $credentials;
        if (!$lazyConnection) {
            $this->connect();
        }
    }

    public function connect()
    {
        $acceptedAdapters = \PDO::getAvailableDrivers();

        $driver = $this->credentials->getDriver();

        if (!in_array($driver, $acceptedAdapters)) {
            throw new \PDOException("The driver '$driver' is not currently available to use with PDO");
        }

        $dsn = "$driver:dbname={$this->credentials->getSchema()};host={$this->credentials->getHost()}";

        $this->pdo = new \PDO($dsn, $this->credentials->getUsername(), $this->credentials->getPassword());
    }

    /**
     * @param string $statement
     * @param array $driverOptions
     * @return \PDOStatement
     */
    public function prepare($statement, array $driverOptions = [])
    {
        if (empty($this->pdo)) {
            $this->connect();
        }
        return $this->pdo->prepare($statement, $driverOptions);
    }

    public function getLastInsertId($sequence = "")
    {
        return $this->pdo->lastInsertId($sequence);
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