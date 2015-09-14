<?php

namespace Silktide\Reposition\Sql\QueryInterpreter;

use Silktide\Reposition\Normaliser\NormaliserInterface;
use Silktide\Reposition\Query\DeleteQuery;
use Silktide\Reposition\Query\FindQuery;
use Silktide\Reposition\Query\InsertQuery;
use Silktide\Reposition\Query\Query;
use Silktide\Reposition\Query\UpdateQuery;
use Silktide\Reposition\QueryInterpreter\CompiledQuery;
use Silktide\Reposition\QueryInterpreter\QueryInterpreterInterface;

class SqlQueryInterpreter implements QueryInterpreterInterface
{

    protected $normaliser;
    /**
     * @param Query $query
     * @return CompiledQuery
     */
    public function interpret(Query $query)
    {
        switch ($query->getAction()) {
            case Query::ACTION_FIND:
                /** @var FindQuery $query */
                return $this->compileFindQuery($query);
            case Query::ACTION_INSERT:
                /** @var InsertQuery $query */
                return $this->compileInsertQuery($query);
            case Query::ACTION_UPDATE:
                /** @var UpdateQuery $query */
                return $this->compileUpdateQuery($query);
            case Query::ACTION_DELETE:
                /** @var DeleteQuery $query */
                return $this->compileDeleteQuery($query);
            default:
                throw new QueryException("Invalid query action: {$query->getAction()}");
        }
    }

    protected function compileFindQuery(FindQuery $query)
    {
        $compiled = new CompiledQuery($query->getTable());


        // TODO: all the field names need preparing
        $fields = $query->getFields();
        foreach ($fields as $alias => $field) {
            if (is_string($alias)) {
                $fields[$alias] = "$field AS $alias";
            }
        }


        $conditions = $query->getFilters();

        $sql = "SELECT ";


        return $compiled;
    }

    protected function compileInsertQuery(InsertQuery $query)
    {

    }

    protected function compileUpdateQuery(UpdateQuery $query)
    {

    }

    protected function compileDeleteQuery(DeleteQuery $query)
    {

    }

    /**
     * @param NormaliserInterface $normaliser
     */
    public function setNormaliser(NormaliserInterface $normaliser)
    {
        $this->normaliser = $normaliser;
    }

} 