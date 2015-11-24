<?php


namespace Silktide\Reposition\Sql\QueryInterpreter\Type;
use Silktide\Reposition\QueryBuilder\QueryToken\Entity;
use Silktide\Reposition\QueryBuilder\TokenSequencerInterface;

/**
 * DeleteInterpreter
 */
class DeleteInterpreter extends AbstractSqlQueryTypeInterpreter
{
    public function supportedQueryType()
    {
        return TokenSequencerInterface::TYPE_DELETE;
    }

    public function interpretQuery(TokenSequencerInterface $query)
    {
        $this->reset();
        $this->query = $query;

        $sql = "DELETE FROM " . $this->renderArbitraryReference($query->getEntityMetadata()->getCollection());

        while($token = $query->getNextToken()) {
            $sql .= " " . $this->renderToken($token);
        }

        return $sql;
    }

    protected function renderEntity(Entity $token)
    {
        return "";
    }


}