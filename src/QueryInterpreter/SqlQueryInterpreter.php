<?php

namespace Silktide\Reposition\Sql\QueryInterpreter;

use Silktide\Reposition\Exception\QueryException;
use Silktide\Reposition\Exception\InterpretationException;
use Silktide\Reposition\Metadata\EntityMetadataProviderInterface;
use Silktide\Reposition\Normaliser\NormaliserInterface;
use Silktide\Reposition\QueryBuilder\TokenSequencerInterface;
use Silktide\Reposition\QueryBuilder\TokenParser;
use Silktide\Reposition\QueryBuilder\QueryToken\Token;
use Silktide\Reposition\QueryBuilder\QueryToken\Value;
use Silktide\Reposition\QueryBuilder\QueryToken\Reference;
use Silktide\Reposition\QueryBuilder\QueryToken\Entity;
use Silktide\Reposition\QueryInterpreter\CompiledQuery;
use Silktide\Reposition\QueryInterpreter\QueryInterpreterInterface;
use Silktide\Reposition\Sql\QueryInterpreter\Type\AbstractSqlQueryTypeInterpreter;

class SqlQueryInterpreter implements QueryInterpreterInterface
{

    /**
     * @var NormaliserInterface
     */
    protected $normaliser;

    /**
     * @var EntityMetadataProviderInterface
     */
    protected $metadataProvider;

    /**
     * @var TokenParser
     */
    protected $tokenParser;

    /**
     * @var array
     */
    protected $queryTypeInterpreters;

    protected $fields = [];

    /**
     * Switch between PDO style substitution and mysqli escaping
     *
     * @var bool
     */
    protected $useSubstitution = true;

    public function __construct(TokenParser $parser, array $queryTypeInterpreters)
    {
        $this->tokenParser = $parser;
        $this->setQueryTypeInterpreters($queryTypeInterpreters);
    }

    /**
     * {@inheritDoc}
     */
    public function setNormaliser(NormaliserInterface $normaliser)
    {
        $this->normaliser = $normaliser;
    }

    /**
     * {@inheritDoc}
     */
    public function setEntityMetadataProvider(EntityMetadataProviderInterface $provider)
    {
        $this->metadataProvider = $provider;
    }

    public function setQueryTypeInterpreters(array $interpreters)
    {
        $this->queryTypeInterpreters = [];
        foreach ($interpreters as $interpreter) {
            if ($interpreter instanceof AbstractSqlQueryTypeInterpreter) {
                $this->addQueryTypeInterpreter($interpreter);
            }
        }
    }

    public function addQueryTypeInterpreter(AbstractSqlQueryTypeInterpreter $interpreter)
    {
        $this->queryTypeInterpreters[] = $interpreter;
    }

    /**
     * @param TokenSequencerInterface $query
     *
     * @throws InterpretationException
     * @return CompiledQuery
     */
    public function interpret(TokenSequencerInterface $query)
    {
        if (empty($this->metadataProvider)) {
            throw new InterpretationException("Cannot interpret any queries without an Entity Metadata Provider");
        }

        $this->tokenParser->parseTokenSequence($query);

        // select interpreter
        $selectedInterpreter = null;
        $queryType = $query->getType();
        foreach ($this->queryTypeInterpreters as $interpreter) {
            /** @var AbstractSqlQueryTypeInterpreter $interpreter */
            if ($interpreter->supportedQueryType() == $queryType) {
                $selectedInterpreter = $interpreter;
                break;
            }
        }
        if (empty($selectedInterpreter)) {
            throw new InterpretationException("Cannot interpret query. The query type '$queryType' is not supported by any of the installed QueryTypeInterpreters");
        }

        $compiledQuery = new CompiledQuery();
        $compiledQuery->setQuery($selectedInterpreter->interpretQuery($query));
        $compiledQuery->setArguments($selectedInterpreter->getValues());

        return $compiledQuery;
    }

} 