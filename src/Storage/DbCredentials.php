<?php

namespace Silktide\Reposition\Sql\Storage;

/**
 * DbCredentials
 */
class DbCredentials implements DbCredentialsInterface
{

    /**
     * @var string
     */
    protected $driver;

    /**
     * @var string
     */
    protected $host;

    /**
     * @var string
     */
    protected $username;

    /**
     * @var string
     */
    protected $password;

    /**
     * @var string
     */
    protected $schema;

    /**
     * @param string $driver
     * @param string $host
     * @param string $username
     * @param string $password
     * @param string $schema
     */
    public function __construct($driver, $host, $username, $password, $schema)
    {
        $this->driver = $driver;
        $this->host = $host;
        $this->username = $username;
        $this->password = $password;
        $this->schema = $schema;
    }

    /**
     * @return string
     */
    public function getDriver()
    {
        return $this->driver;
    }

    /**
     * @return string
     */
    public function getHost()
    {
        return $this->host;
    }

    /**
     * @return string
     */
    public function getUsername()
    {
        return $this->username;
    }

    /**
     * @return string
     */
    public function getPassword()
    {
        return $this->password;
    }

    /**
     * @return string
     */
    public function getSchema()
    {
        return $this->schema;
    }

}