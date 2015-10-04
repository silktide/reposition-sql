<?php

namespace Silktide\Reposition\Sql\QueryInterpreter\Type;

use Silktide\Reposition\Exception\QueryException;
use Silktide\Reposition\QueryBuilder\QueryToken\Entity;
use Silktide\Reposition\QueryBuilder\QueryToken\Value;
use Silktide\Reposition\QueryBuilder\TokenSequencerInterface;

class FindInterpreter extends AbstractSqlQueryTypeInterpreter
{

    public function supportedQueryType()
    {
        return TokenSequencerInterface::TYPE_FIND;
    }

    public function interpretQuery(TokenSequencerInterface $query)
    {
        if (empty($this->entityMetadataProvider)) {
            throw new QueryException("Cannot interpret this 'find' query without an EntityMetadataProvider");
        }

        $this->query = $query;

        $sql = "SELECT ";
        $token = $query->getNextToken();

        $this->fields = [];
        while (!empty($token) && $token->getType() == "function") {
            // render the aggregate function SQL e.g. COUNT(*), SUM(field), etc...
            /** @var Value $token */
            $fieldSql = $this->renderFunction($token);

            // create an alias for this aggregate and append the SQL
            $collectionAlias = $this->findFreeAlias($token->getValue(), $this->fields);
            $fieldSql .= $this->renderAlias($collectionAlias);

            // add the sql to the fields array and load the next token
            $this->fields[$collectionAlias] = $fieldSql;

            $token = $query->getNextToken();
        }

        $includes = $query->getIncludes();
        $entity = $query->getEntityName();
        $metadata = $this->getEntityMetadata($entity);
        $mainCollection = $metadata->getCollection();

        // if we had no aggregate functions in the sequence, then this is a standard select query
        // so, we need to get the fields to return from the entity metadata for each
        if (empty($this->fields)) {
            $collectionAlias = $entity;

            do {
                $metadata = $this->getEntityMetadata($entity);

                // get the collection
                $collection = $metadata->getCollection();

                // if the alias is the same as the entity name, use the collection instead
                if ($collectionAlias == $entity) {
                    $collectionAlias = $collection;
                }

                // for each entity field, create an aliased SQL reference
                $entityFields = $metadata->getFieldNames();
                foreach ($entityFields as $field) {
                    $thisFieldAlias = $collectionAlias . "__" . $field;
                    $field = $collectionAlias . "." . $field;
                    $this->fields[$thisFieldAlias] = $this->renderArbitraryReference($field, $thisFieldAlias);
                }

            } while (list($collectionAlias, $entity) = each($includes));

        }

        if (empty($this->fields)) {
            throw new QueryException("Cannot interpret find query, there are no fields to return");
        }

        $sql .= implode(", ", $this->fields) . " FROM " . $this->renderArbitraryReference($mainCollection);

        // render all other tokens
        do {
            if (empty($token)) {
                break;
            }
            if ($token->getType() == "sort") {
                $this->renderSort($token);
                continue;
            }

            $sql .= " " . $this->renderToken($token);
        } while ($token = $this->query->getNextToken());

        return $sql;
    }

    protected function findFreeAlias($reference, $aliases)
    {
        $ordinal = 0;
        $alias = $reference;
        while(!empty($aliases[$alias])) {
            $alias = $reference . "_" . ++$ordinal;
        };
        return $alias;
    }

    /**
     * @param Entity $token
     *
     * @return string
     */
    protected function renderEntity(Entity $token)
    {
        return "";
    }


} 