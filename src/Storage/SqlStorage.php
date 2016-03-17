<?php

namespace Silktide\Reposition\Sql\Storage;

use Psr\Log\LoggerAwareTrait;
use Silktide\Reposition\Hydrator\HydratorInterface;
use Silktide\Reposition\Metadata\EntityMetadataProviderInterface;
use Silktide\Reposition\Normaliser\NormaliserInterface;
use Silktide\Reposition\QueryBuilder\TokenSequencerInterface;
use Silktide\Reposition\QueryInterpreter\CompiledQuery;
use Silktide\Reposition\Sql\QueryInterpreter\SqlQueryInterpreter;
use Silktide\Reposition\Storage\StorageInterface;
use Silktide\Reposition\Storage\Logging\QueryLogProcessorInterface;
use Silktide\Reposition\Storage\Logging\ErrorLogProcessorInterface;

class SqlStorage implements StorageInterface
{
    use LoggerAwareTrait;

    /**
     * @var PdoAdapter
     */
    protected $database;

    /**
     * @var SqlQueryInterpreter
     */
    protected $interpreter;

    /**
     * @var EntityMetadataProviderInterface
     */
    protected $entityMetadataProvider;

    /**
     * @var HydratorInterface
     */
    protected $hydrator;

    protected $parameters = [];

    /**
     * @var ErrorLogProcessorInterface
     */
    protected $errorProcessor;

    /**
     * @var QueryLogProcessorInterface
     */
    protected $queryProcessor;

    public function __construct(
        PdoAdapter $database,
        SqlQueryInterpreter $interpreter,
        HydratorInterface $hydrator = null,
        NormaliserInterface $normaliser = null
    ) {
        $this->database = $database;
        $this->interpreter = $interpreter;
        $this->hydrator = $hydrator;

        if (!empty($normaliser)) {
            $this->interpreter->setNormaliser($normaliser);
            if (!empty($hydrator)) {
                $this->hydrator->setNormaliser($normaliser);
            }
        }

    }

    public function setEntityMetadataProvider(EntityMetadataProviderInterface $provider)
    {
        $this->entityMetadataProvider = $provider;
        $this->interpreter->setEntityMetadataProvider($this->entityMetadataProvider);
    }

    public function hasEntityMetadataProvider()
    {
        return !empty($this->entityMetadataProvider);
    }

    public function setErrorLogProcessor(ErrorLogProcessorInterface $processor)
    {
        $this->errorProcessor = $processor;
    }

    public function setQueryLogProcessor(QueryLogProcessorInterface $processor)
    {
        $this->queryProcessor = $processor;
    }

    protected function canLogErrors()
    {
        return !empty($this->logger) && !empty($this->errorProcessor);
    }

    protected function canLogQueries()
    {
        return !empty($this->logger) && !empty($this->queryProcessor);
    }

    /**
     * @param TokenSequencerInterface $query
     * @param string $entityClass
     * @return object
     */
    public function query(TokenSequencerInterface $query, $entityClass)
    {
        $compiledQuery = $this->interpreter->interpret($query);

        $sql = $compiledQuery->getQuery();
        $statement = $this->database->prepare($sql);
        
        $this->prepareQueryLog($compiledQuery);
        try {
            $this->bindValues($statement, $compiledQuery->getArguments());
            $statement->execute();
        } catch (\PDOException $e) {
        }
        $this->completeQueryLog();

        // check for errors (some drivers don't throw exceptions on SQL errors)
        $this->checkForSqlErrors($statement->errorInfo(), "Query - ", $sql);

        // if we're an insert statement, get the new ID and check for errors again
        if (!empty($compiledQuery->getPrimaryKeySequence())) {
            try {
                $newId = $this->database->getLastInsertId($compiledQuery->getPrimaryKeySequence());
            } catch (\PDOException $e) {
            }
            $this->checkForSqlErrors($this->database->getErrorInfo(), "Insert ID - ", $sql);
        }

        $this->logQuery();
        
        $data = $statement->fetchAll(\PDO::FETCH_ASSOC);

        // close the statement to release memory as we don't need it anymore
        $statement->closeCursor();

        $options = [
            "metadataProvider" => $this->entityMetadataProvider,
            "entityMap" => $query->getIncludes(),
            "entity" => $entityClass,
            "trackCollectionChanges" => true
        ];

        if ($this->hydrator instanceof HydratorInterface && !empty($entityClass)) {
            $response = $this->hydrator->hydrateAll($data, $entityClass, $options);
        } else  {
            if (!empty($newId)) {
                $data[StorageInterface::NEW_INSERT_ID_RETURN_FIELD] = $newId;
            }
            $response = $data;
        }
        return $response;
    }

    protected function bindValues(\PDOStatement $statement, $arguments)
    {
        foreach ($arguments as $param => $value) {
            $type = \PDO::PARAM_STR;
            if (strpos($param, "int") !== false) {
                $type = \PDO::PARAM_INT;
            } elseif (strpos($param, "bool") !== false) {
                $type = \PDO::PARAM_BOOL;
            }
            $statement->bindValue($param, $value, $type);
        }
    }

    /**
     * @param array $errorInfo
     * @param $prefix
     * @param $originalSql
     */
    protected function checkForSqlErrors(array $errorInfo, $prefix, $originalSql)
    {
        if ($errorInfo[0] != "00000") { // ANSI SQL error code for "success"
            $this->logError($originalSql, $prefix, $errorInfo);
            $e = new \PDOException($prefix . $errorInfo[0] . " (" . $errorInfo[1] . "): " . $errorInfo[2] . ",\nSQL: " . $originalSql);
            $e->errorInfo = $errorInfo;
            throw $e;
        }
    }

    /**
     * @param CompiledQuery $query
     */
    protected function prepareQueryLog(CompiledQuery $query)
    {
        if ($this->canLogQueries()) {
            $this->queryProcessor->recordQueryStart($query);
        }
    }

    protected function completeQueryLog()
    {
        if ($this->canLogQueries()) {
            $this->queryProcessor->recordQueryEnd();
        }
    }

    protected function logQuery()
    {
        if ($this->canLogQueries()) {
            $this->logger->debug("SQL query complete");
        }
    }

    protected function logError($query, $prefix, array $errorInfo)
    {
        if ($this->canLogErrors()) {
            $this->errorProcessor->recordError($query, $errorInfo);
            $this->logger->error($prefix . $errorInfo[2]);
        }
    }

}