<?php
declare(strict_types = 1);

namespace LanguageServer;

use LanguageServer\Protocol\SymbolInformation;
use phpDocumentor\Reflection\DocBlockFactory;
use PhpParser\{ParserFactory, Lexer, Node};
use function LanguageServer\Fqn\{getDefinedFqn, getVariableDefinition, getReferencedFqn};

class Project
{
    /**
     * An associative array [string => PhpDocument]
     * that maps URIs to loaded PhpDocuments
     *
     * @var PhpDocument[]
     */
    private $documents = [];

    /**
     * An associative array that maps fully qualified symbol names to definitions
     *
     * @var Definition[]
     */
    private $definitions = [];

    /**
     * An associative array that maps fully qualified symbol names to arrays of document URIs that reference the symbol
     *
     * @var string[][]
     */
    private $references = [];

    /**
     * Instance of the PHP parser
     *
     * @var \PhpParser\Parser
     */
    private $parser;

    /**
     * The DocBlockFactory instance to parse docblocks
     *
     * @var DocBlockFactory
     */
    private $docBlockFactory;

    /**
     * Reference to the language server client interface
     *
     * @var LanguageClient
     */
    private $client;

    public function __construct(LanguageClient $client)
    {
        $this->client = $client;

        $lexer = new Lexer(['usedAttributes' => ['comments', 'startLine', 'endLine', 'startFilePos', 'endFilePos']]);
        $this->parser = (new ParserFactory)->create(ParserFactory::PREFER_PHP7, $lexer, ['throwOnError' => false]);
        $this->docBlockFactory = DocBlockFactory::createInstance();
    }

    /**
     * Returns the document indicated by uri.
     * If the document is not open, tries to read it from disk, but the document is not added the list of open documents.
     *
     * @param string $uri
     * @return LanguageServer\PhpDocument
     */
    public function getDocument(string $uri)
    {
        if (!isset($this->documents[$uri])) {
            return $this->loadDocument($uri);
        } else {
            return $this->documents[$uri];
        }
    }

    /**
     * Reads a document from disk.
     * The document is NOT added to the list of open documents, but definitions are registered.
     *
     * @param string $uri
     * @return LanguageServer\PhpDocument
     */
    public function loadDocument(string $uri)
    {
        $content = file_get_contents(uriToPath($uri));
        if (isset($this->documents[$uri])) {
            $document = $this->documents[$uri];
            $document->updateContent($content);
        } else {
            $document = new PhpDocument($uri, $content, $this, $this->client, $this->parser, $this->docBlockFactory);
        }
        return $document;
    }

    /**
     * Ensures a document is loaded and added to the list of open documents.
     *
     * @param string $uri
     * @param string $content
     * @return void
     */
    public function openDocument(string $uri, string $content)
    {
        if (isset($this->documents[$uri])) {
            $document = $this->documents[$uri];
            $document->updateContent($content);
        } else {
            $document = new PhpDocument($uri, $content, $this, $this->client, $this->parser, $this->docBlockFactory);
            $this->documents[$uri] = $document;
        }
        return $document;
    }

    /**
     * Removes the document with the specified URI from the list of open documents
     *
     * @param string $uri
     * @return void
     */
    public function closeDocument(string $uri)
    {
        unset($this->documents[$uri]);
    }

    /**
     * Returns true if the document is open (and loaded)
     *
     * @param string $uri
     * @return bool
     */
    public function isDocumentOpen(string $uri): bool
    {
        return isset($this->documents[$uri]);
    }

    /**
     * Returns an associative array [string => Definition] that maps fully qualified symbol names
     * to definitions
     *
     * @return Definition[]
     */
    public function getDefinitions()
    {
        return $this->definitions;
    }

    /**
     * @param Node $node
     * @return Definition|null
     */
    public function getDefinitionByNode(Node $node)
    {
        if ($node instanceof Node\Expr\Variable) {
            throw new \Exception('Cannot get Definition object of variable');
        }
        $fqn = getReferencedFqn($node);
        if (!isset($fqn)) {
            return null;
        }
        $document = $this->getDefinitionDocument($fqn);
        if (!isset($document)) {
            // If the node is a function or constant, it could be namespaced, but PHP falls back to global
            // http://php.net/manual/en/language.namespaces.fallback.php
            $parent = $node->getAttribute('parentNode');
            if ($parent instanceof Node\Expr\ConstFetch || $parent instanceof Node\Expr\FuncCall) {
                $parts = explode('\\', $fqn);
                $fqn = end($parts);
                $document = $this->getDefinitionDocument($fqn);
            }
        }
        if (!isset($document)) {
            return null;
        }
        return $document->getDefinitionByFqn($fqn);
    }

    public function getDefinitionNodeByNode(Node $node)
    {
        // Variables always stay in the boundary of the file and need to be searched inside their function scope
        // by traversing the AST
        if ($node instanceof Node\Expr\Variable) {
            return getVariableDefinition($node);
        }
        $fqn = getReferencedFqn($node);
        if (!isset($fqn)) {
            return null;
        }
        $document = $this->getDefinitionDocument($fqn);
        if (!isset($document)) {
            // If the node is a function or constant, it could be namespaced, but PHP falls back to global
            // http://php.net/manual/en/language.namespaces.fallback.php
            $parent = $node->getAttribute('parentNode');
            if ($parent instanceof Node\Expr\ConstFetch || $parent instanceof Node\Expr\FuncCall) {
                $parts = explode('\\', $fqn);
                $fqn = end($parts);
                $document = $this->getDefinitionDocument($fqn);
            }
        }
        if (!isset($document)) {
            return null;
        }
        return $document->getDefinitionNodeByFqn($fqn);

    }

    /**
     * Adds a Definition for a specific symbol
     *
     * @param string $fqn The fully qualified name of the symbol
     * @param Definition $definition The URI
     * @return void
     */
    public function setDefinition(string $fqn, Definition $definition)
    {
        $this->definition[$fqn] = $definition;
    }

    /**
     * Sets the Definition index
     *
     * @param Definition[] $definitions
     * @return void
     */
    public function setDefinitions(array $definitions)
    {
        $this->definitions = $definitions;
    }

    /**
     * Removes a definition and removes all references pointing to that definition
     *
     * @param string $fqn The fully qualified name of the definition
     * @return void
     */
    public function removeDefinition(string $fqn) {
        unset($this->definitions[$fqn]);
        unset($this->references[$fqn]);
    }

    /**
     * Adds a document URI as a referencee of a specific symbol
     *
     * @param string $fqn The fully qualified name of the symbol
     * @return void
     */
    public function addReferenceUri(string $fqn, string $uri)
    {
        if (!isset($this->references[$fqn])) {
            $this->references[$fqn] = [];
        }
        // TODO: use DS\Set instead of searching array
        if (array_search($uri, $this->references[$fqn], true) === false) {
            $this->references[$fqn][] = $uri;
        }
    }

    /**
     * Removes a document URI as the container for a specific symbol
     *
     * @param string $fqn The fully qualified name of the symbol
     * @param string $uri The URI
     * @return void
     */
    public function removeReferenceUri(string $fqn, string $uri) {
        if (!isset($this->references[$fqn])) {
            return;
        }
        $index = array_search($fqn, $this->references[$fqn], true);
        if ($index === false) {
            return;
        }
        array_splice($this->references[$fqn], $index, 1);
    }

    /**
     * Returns all documents that reference a symbol
     *
     * @param string $fqn The fully qualified name of the symbol
     * @return PhpDocument[]
     */
    public function getReferenceDocuments(string $fqn)
    {
        if (!isset($this->references[$fqn])) {
            return [];
        }
        return array_map([$this, 'getDocument'], $this->references[$fqn]);
    }

    /**
     * Returns an associative array [string => string[]] that maps fully qualified symbol names
     * to URIs of the document where the symbol is referenced
     *
     * @return string[][]
     */
    public function getReferenceUris()
    {
        return $this->references;
    }

    /**
     * Sets the reference index
     *
     * @param string[][] $references an associative array [string => string[]] from FQN to URIs
     * @return void
     */
    public function setReferenceUris(array $references)
    {
        $this->references = $references;
    }

    /**
     * Returns the document where a symbol is defined
     *
     * @param string $fqn The fully qualified name of the symbol
     * @return PhpDocument|null
     */
    public function getDefinitionDocument(string $fqn)
    {
        if (!isset($this->definitions[$fqn])) {
            return null;
        }
        return $this->getDocument($this->definitions[$fqn]->symbolInformation->location->uri);
    }

    /**
     * Returns true if the given FQN is defined in the project
     *
     * @param string $fqn The fully qualified name of the symbol
     * @return bool
     */
    public function isDefined(string $fqn): bool
    {
        return isset($this->definitions[$fqn]);
    }
}
