<?php

namespace Silktide\Reposition\Sql\Storage;

/**
 * DbCredentialsInterface
 */
interface DbCredentialsInterface
{

    /**
     * @return string
     */
    public function getDriver();

    /**
     * @return string
     */
    public function getHost();

    /**
     * @return string
     */
    public function getUsername();

    /**
     * @return string
     */
    public function getPassword();

    /**
     * @return string
     */
    public function getSchema();

}