<?php

namespace Silktide\Reposition\Sql\Storage;


use Silktide\Reposition\Sql\Adapter\PdoAdapter;
use Silktide\Reposition\Hydrator\HydratorInterface;
use Silktide\Reposition\Normaliser\NormaliserInterface;
use Silktide\Reposition\Query\Query;
use Silktide\Reposition\QueryBuilder\QueryBuilderInterface;
use Silktide\Reposition\Sql\QueryInterpreter\SqlQueryInterpreter;
use Silktide\Reposition\Storage\StorageInterface;

class SqlStorage implements StorageInterface
{

    protected $database;
    protected $builder;
    protected $interpreter;
    protected $hydrator;

    protected $parameters = [];

    public function __construct(
        PdoAdapter $database,
        QueryBuilderInterface $builder,
        SqlQueryInterpreter $interpreter,
        HydratorInterface $hydrator = null,
        NormaliserInterface $normaliser = null
    ) {
        $this->database = $database;
        $this->builder = $builder;
        $this->interpreter = $interpreter;
        $this->hydrator = $hydrator;

        if (!empty($normaliser)) {
            $this->interpreter->setNormaliser($normaliser);
            if (!empty($hydrator)) {
                $this->hydrator->setNormaliser($normaliser);
            }
        }

    }

    /**
     * @return QueryBuilderInterface
     */
    public function getQueryBuilder()
    {
        return $this->builder;
    }

    /**
     * @param Query $query
     * @param string $entityClass
     * @return object
     */
    public function query(Query $query, $entityClass)
    {




        if ($this->hydrator instanceof HydratorInterface && !empty($entityClass)) {
            $response = $this->hydrator->hydrateAll($data, $entityClass);
        } else  {
            $response = $data;
        }
        return $response;
    }

    public function setQuery($sql)
    {
        $this->currentQuery = (string) $sql;
        $this->currentParameters = array();
    }

    public function addParameter($name, $value)
    {
        if (!is_string($name)) {
            throw new \InvalidArgumentException("Invalid name. Name must be a string value");
        }
        if ($name[0] != ":") {
            $name = ":$name";
        }
        $this->currentParameters[$name] = $value;
    }

    public function addParameters(array $parameters)
    {
        foreach ($parameters as $parameter => $value) {
            $this->addParameter($parameter, $value);
        }
    }

    protected function executeQuery()
    {
        if (empty($this->currentQuery)) {
            throw new StorageException("Cannot execute an empty query");
        }

        $statement = $this->database->prepare($this->currentQuery);
        $statement->execute($this->currentParameters);

        return $statement;
    }

    protected function tableExists($table)
    {
        $this->setQuery("SHOW TABLES LIKE :table");
        $this->addParameter("table", $table);
        $exists = $this->executeQuery();
        return $exists->rowCount() > 0;
    }

    protected function getFieldsFromTable(Reference $table)
    {

        $statement = $this->prepare("DESCRIBE {$table->getFullName()}");
        $result = $statement->execute();

        if ($result === false) {
            return false;
        }

        // TODO: refactor so this doesn't rely on the field name being the first column
        return $statement->fetchAll(\PDO::FETCH_COLUMN, 0);
    }

} 