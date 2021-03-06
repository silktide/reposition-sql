<?php

namespace Silktide\Reposition\Sql\QueryInterpreter\Type;

use Silktide\Reposition\Exception\QueryException;
use Silktide\Reposition\QueryBuilder\QueryToken\Entity;
use Silktide\Reposition\QueryBuilder\QueryToken\Reference;
use Silktide\Reposition\QueryBuilder\QueryToken\Token;
use Silktide\Reposition\QueryBuilder\QueryToken\Value;
use Silktide\Reposition\QueryBuilder\TokenSequencerInterface;

abstract class AbstractSqlQueryTypeInterpreter
{

    /**
     * @var TokenSequencerInterface
     */
    protected $query;

    protected $fields = [];

    protected $tables = [];

    protected $values = [];

    protected $identifierDelimiter = "";

    protected $primaryKeySequence = "";

    /**
     * @return string
     */
    abstract public function supportedQueryType();

    abstract public function interpretQuery(TokenSequencerInterface $query);

    protected function reset()
    {
        $this->fields = [];
        $this->tables = [];
        $this->values = [];
    }

    public function getValues()
    {
        return $this->values;
    }

    public function setIdentifiedDelimiter($delimiter)
    {
        $this->identifierDelimiter = $delimiter;
    }

    public function getPrimaryKeySequence()
    {
        return $this->primaryKeySequence;
    }

    /**
     * @param Token $token
     *
     * @return string
     */
    protected function renderToken(Token $token)
    {
        $type = $token->getType();

        // handle edge cases
        switch ($type) {
            case "open":
                return "(";
            case "close":
                return ")";
            case "group":
                return $this->renderGroupBy();
            case "sort":
                return $this->renderSort();
            case "function":
                /** @var Value $token */
                return $this->renderFunction($token);
        }

        if ($token instanceof Entity) {
            return $this->renderEntity($token);
        }
        if ($token instanceof Reference) {
            return $this->renderReference($token);
        }
        if ($token instanceof Value) {
            return $this->renderValue($token);
        }

        return strtoupper($type);
    }

    /**
     * @param Value $token
     *
     * @return string
     */
    protected function renderValue(Value $token)
    {
        $type = $token->getType();
        $value = $token->getValue();
        switch ($type) {
            case Value::TYPE_NULL:
            case Value::TYPE_BOOL:
            case Value::TYPE_BOOLEAN:
            case Value::TYPE_STRING:
            case Value::TYPE_INT:
            case Value::TYPE_INTEGER:
            case Value::TYPE_FLOAT:
            case Value::TYPE_ARRAY:
            case Value::TYPE_DATETIME:
                return $this->renderValueParameter($value, $type);
            case "sort-direction":
                return $value == TokenSequencerInterface::SORT_DESC? "DESC": "ASC";
            case "function":
                // translate function name
                $nameMap = [
                    "total" => "sum",
                    "maximum" => "max",
                    "minimum" => "min",
                    "average" => "avg"
                ];
                if (!empty($nameMap[$value])) {
                    $value = $nameMap[$value];
                }
                break;
            case "operator":
                if ($token->getValue() == "in") {
                    return $this->renderInCondition();
                }
                break;
        }

        // this token is an sql keyword or operator, so render it's value directly
        return strtoupper($value);

    }

    /**
     * @param Reference $token
     *
     * @return string
     */
    protected function renderReference(Reference $token)
    {
        return $this->renderArbitraryReference($token->getValue(), $token->getAlias());
    }

    protected function renderArbitraryReference($reference, $alias = "")
    {
        // escape the identifier delimiter character
        $d = $this->identifierDelimiter;
        $reference = str_replace($d, "\\$d", $reference);
        // split and encapsulate reference chains, e.g. database.table.field => `database`.`table`.`field`
        $sql = $d . str_replace(".", "$d.$d", $reference) . $d;
        if (!empty($alias)) {
            $sql .= $this->renderAlias($alias);
        }
        return $sql;
    }

    protected function renderAlias($alias)
    {
        $d = $this->identifierDelimiter;
        return " AS {$d}$alias{$d}";
    }

    protected function renderValueParameter($value, $type = null)
    {
        if (empty($type)) {
            $type = "value";
        }
        
        if ($type == Value::TYPE_NULL) {
            return "NULL";
        }
        if ($type == Value::TYPE_BOOL || $type == Value::TYPE_BOOLEAN) {
            return $value? "TRUE": "FALSE";
        }

        if ($type == Value::TYPE_DATETIME && $value instanceof \DateTime) {
            $value = $value->getTimestamp();
        }

        // encode any array data to JSON
        if (is_array($value)) {
            $value = json_encode($value);
        }

        // create value reference ID for replacing
        $ordinal = count($this->values);
        $valueId = $type . "_$ordinal";
        // save the value and return the reference
        $this->values[$valueId] = $value;
        return ":$valueId";
    }

    /**
     * @param Entity $token
     *
     * @return string
     */
    abstract protected function renderEntity(Entity $token);

    protected function renderSort()
    {
        $sql = "ORDER BY";
        $order = [];

        while ($token = $this->query->getNextToken()) {
            // if this is a limit token, we're done so create the sort list SQL and also render the limit token
            // not ideal to render the limit token here, but there's no other way of detecting the end of a sort list
            if ($token->getType() == "limit") {
                $sql .= implode(",", $order);
                $sql .= " " . $this->renderToken($token);
                return $sql;
            }
            // if this is a direction token, append it to the last sort field
            if ($token->getType() == "sort-direction") {
                $order[count($order) - 1] .= " " . $this->renderToken($token);
                continue;
            }

            // render sort field
            $order[] = " " . $this->renderToken($token);
        }

        // render sort list
        $sql .= implode(",", $order);

        return $sql;
    }

    protected function renderGroupBy()
    {
        $sql = "GROUP BY";
        $groups = [];
        $currentItem = "";

        while ($token = $this->query->getNextToken()) {
            if (in_array($token->getType(), ["sort", "limit"])) {
                if (!empty($currentItem)) {
                    $groups[] = $currentItem;
                }
                break;
            }
            $currentItem .= $this->renderToken($token);
            // if we have a field
            if (in_array($token->getType(), ["field", "close"])) {
                $groups[] = $currentItem;
                $currentItem = "";
            }
        }

        $sql .= " " . implode(", ", $groups);
        if ($token != false) {
            $sql .= " " . $this->renderToken($token);
        }
        return $sql;
    }

    /**
     * @param Value $token
     *
     * @throws QueryException
     * @return string
     */
    protected function renderFunction(Value $token)
    {

        $open = $this->query->getNextToken();
        if (empty($open)) {
            throw new QueryException("Unexpected end of token sequence. Expecting 'open parentheses' token for a function");
        }

        $sql =
            $this->renderValue($token) .                    // function name
            $this->renderToken($open);     // open parentheses

        // get the next token and keep adding SQL to the arguments array until we hit a "close parentheses" token
        $token = $this->query->getNextToken();
        $arguments = [];
        $argumentFinished = true;
        $lastArgument = null;
        $continuationTokens = ["operator" => true, "open" => true, "close" => true];
        $level = 1;

        while (!empty($token) && !($token->getType() == "close" && $level == 1)) {
            $type = $token->getType();
            if (isset($continuationTokens[$type])) {
                // if this is a continuation token, render it and set the argument finished flag to false
                if ($type == "open") {
                    ++$level;
                } elseif ($type == "close") {
                    --$level;
                }
                $argumentFinished = false;
                $tokenSql = $this->renderToken($token);
                // if this happens to be the first token, start a new argument, otherwise continue the last one
                if (!is_null($lastArgument)) {
                    $arguments[$lastArgument] .= " " . $tokenSql;
                } else {
                    $arguments[] = $tokenSql;
                    $lastArgument = 0;
                }
            } elseif (!$argumentFinished) {
                // if we're not finished with the last argument, add this token to it

                $arguments[$lastArgument] .= " " . $this->renderToken($token);
                $argumentFinished = true;
            } else {
                // otherwise, start a new argument
                $arguments[] = $this->renderToken($token);
                $lastArgument = count($arguments) - 1;
            }
            $token = $this->query->getNextToken();
        }
        $sql .= implode(", ", $arguments); // add the arguments to the SQL

        if (empty($token)) {
            throw new QueryException("Unexpected end of token sequence. Expecting 'close parentheses' token for a function: $sql");
        }
        $sql .= $this->renderToken($token); // close parentheses
        return $sql;
    }

    protected function renderInCondition()
    {
        $sql = "IN ";
        $sql .= $this->renderToken($this->query->getNextToken()); // open
        $list = [];
        while (($token = $this->query->getNextToken()) && $token->getType() != "close") {
            $list[] = $this->renderToken($token);
        }

        $sql .= implode(", ", $list);

        if (!empty($token)) {
            $sql .= $this->renderToken($token); // close
        }

        return $sql;
    }

} 