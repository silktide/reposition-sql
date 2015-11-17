<?php

namespace Silktide\Reposition\Sql\Test\QueryInterpreter\Type;

/**
 * Entity
 */
class Entity
{

    protected $id;

    protected $child;

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
     * @return Child
     */
    public function getChild()
    {
        return $this->child;
    }

    /**
     * @param Child $child
     */
    public function setChild(Child $child)
    {
        $this->child = $child;
    }

    public function toArray()
    {
        return ["id" => $this->id, "field_1" => "value"];
    }

}