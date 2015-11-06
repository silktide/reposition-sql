<?php


namespace Silktide\Reposition\Sql\QueryInterpreter\Type;
use Silktide\Reposition\QueryBuilder\QueryToken\Entity;
use Silktide\Reposition\QueryBuilder\QueryToken\Value;
use Silktide\Reposition\QueryBuilder\TokenSequencerInterface;

/**
 * UpdateInterpreter
 */
class UpdateInterpreter extends AbstractSqlQueryTypeInterpreter
{
    public function supportedQueryType()
    {
        TokenSequencerInterface::TYPE_UPDATE;
    }

    public function interpretQuery(TokenSequencerInterface $query)
    {
        $this->query = $query;

        $sql = "UPDATE " . $this->renderArbitraryReference($this->query->getEntityMetadata()->getCollection()) . " SET ";

        $assignments = [];
        $currentAssignment = [];
        $level = 0;

        $assignmentEndTypes = ["where", "sort", "limit"];

        while(($token = $this->query->getNextToken()) && !in_array($token->getType(), $assignmentEndTypes)) {
            // add token SQL to the current assignment
            $currentAssignment[] = $this->renderToken($token);

            $type = $token->getType();

            // detect closures (we can't end an assignment while still in a closure)
            if ($type == "open") {
                ++$level;
            } elseif ($type == "close") {
                --$level;
            }

            // check if this is the type of token that could be the end of this assignment
            $expressionEndTypes = [
                "field",
                "function",
                "close",
                Value::TYPE_BOOL,
                Value::TYPE_NULL,
                Value::TYPE_INT,
                Value::TYPE_FLOAT,
                Value::TYPE_STRING
            ];

            // if we're in the root level for this assignment and the current token is an "end" type ...
            if ($level == 0 && in_array($type, $expressionEndTypes)) {
                // check if the next token is an operator
                $token = $this->query->getNextToken();
                if ($token !== false && $token->getType() == "operator") {
                    // if we have an operator next, this can't be the end of the assignment, so add it to the current one and continue
                    $currentAssignment[] = $this->renderToken($token);
                } else {
                    // We've reached the end of the assignment; save it and start a new one
                    $assignments[] = $currentAssignment;
                    // exist if we're done with the assignments
                    if ($token === false || in_array($token->getType(), $assignmentEndTypes)) {
                        break;
                    }
                    $currentAssignment = [$this->renderToken($token)];
                }
            }


        }

        // join each assignment's token SQL together
        $assignmentList = [];
        foreach ($assignments as $assignment) {
            $assignmentList[] = implode(" ", $assignment);
        }

        // join the assignment list, separated by commas
        $sql .= implode(", ", $assignmentList);

        // render the rest of the tokens
        while($token !== false) {
            $sql .= " " . $this->renderToken($token);
            $token = $this->query->getNextToken();
        }

        return $sql;
    }

    protected function renderEntity(Entity $token)
    {
        return "";
    }


}