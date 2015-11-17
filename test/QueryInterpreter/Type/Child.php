<?php

namespace Silktide\Reposition\Sql\Test\QueryInterpreter\Type;

/**
 * Child
 */
class Child
{

    protected $id;

    protected $theirField;

    /**
     * @return mixed
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * @param mixed $id
     */
    public function setId($id)
    {
        $this->id = $id;
    }

    /**
     * @return mixed
     */
    public function getTheirField()
    {
        return $this->theirField;
    }

    /**
     * @param mixed $theirField
     */
    public function setTheirField($theirField)
    {
        $this->theirField = $theirField;
    }



}