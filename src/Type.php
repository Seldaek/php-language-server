<?php
declare(strict_types = 1);

namespace LanguageServer\Type;

use PhpParser\Node;
use phpDocumentor\Reflection\{Types, Type, Fqsen, TypeResolver};

/**
 * Given an expression node, resolves that expression recursively to a type.
 * If the type could not be resolved, returns mixed.
 *
 * @param Node\Expr $expr
 * @return Type
 */
function resolveExpression(Node\Expr $expr): Type
{
    if ($expr instanceof Node\Expr\Variable) {
        // Find variable definition
        $def = getVariableDefinition($expr);
        return resolveExpression($def);
    }
    if ($expr instanceof Node\Expr\FuncCall) {
        // Find the function definition
        if ($expr->name instanceof Node\Expr) {
            // Cannot get type for dynamic function call
            return new Types\Mixed;
        }
        $fnFqn = (string)($expr->getAttribute('namespacedName') ?? $expr->name);
        return getDefinitionFromFqn($fnFqn)->returnType;
    }
    if ($expr instanceof Node\Expr\ConstFetch) {
        if (strtolower((string)$expr->name) === 'true' || strtolower((string)$expr->name) === 'false') {
            return new Types\Boolean;
        }
        // Resolve constant
        $constFqn = (string)($expr->getAttribute('namespacedName') ?? $expr->name);
        return getDefinitionFromFqn($constFqn)->type;
    }
    if ($expr instanceof Node\Expr\MethodCall) {
        // Resolve object
        $objType = resolveExpression($expr->var);
        if (!($objType instanceof Types\Object_) || $objType->getFqsen() === null || $expr->name instanceof Node\Expr) {
            // Need the class FQN of the object
            return new Types\Mixed_;
        }
        $fqn = (string)$objType->getFqsen() . '::' . $expr->name . '()';
        return getDefinitionFromFqn($fqn)->returnType;
    }
    if ($expr instanceof Node\Expr\PropertyFetch) {
        // Resolve object
        $objType = resolveExpression($expr->var);
        if (!($objType instanceof Types\Object_) || $objType->getFqsen() === null || $expr->name instanceof Node\Expr) {
            // Need the class FQN of the object
            return new Types\Mixed_;
        }
        $fqn = (string)$objType->getFqsen() . '::' . $expr->name;
        return getDefinitionFromFqn($fqn)->type;
    }
    if ($expr instanceof Node\Expr\StaticCall) {
        if ($expr->class instanceof Node\Expr || $expr->name instanceof Node\Expr) {
            // Need the FQN
            return new Types\Mixed_;
        }
        $fqn = (string)$expr->class . '::' . $expr->name . '()';
    }
    if ($expr instanceof Node\Expr\StaticPropertyFetch || $expr instanceof Node\Expr\ClassConstFetch) {
        if ($expr->class instanceof Node\Expr || $expr->name instanceof Node\Expr) {
            // Need the FQN
            return new Types\Mixed_;
        }
        $fqn = (string)$expr->class . '::' . $expr->name;
    }
    if ($expr instanceof New_) {
        if ($expr->class instanceof Node\Expr) {
            return new Types\Mixed_;
        }
        if ($expr->class instanceof Node\Stmt\Class_) {
            // Anonymous class
            return new Types\Object;
        }
        return new Types\Object(new Fqsen('\\' . (string)$expr->class));
    }
    if ($expr instanceof Clone_) {
        return resolveExpression($expr->expr);
    }
    if ($expr instanceof Node\Expr\Ternary) {
        // ?:
        if ($expr->if === null) {
            return new Types\Compound([
                resolveExpression($expr->cond),
                resolveExpression($expr->else)
            ]);
        }
        // Ternary is a compound of the two possible values
        return new Types\Compound([
            resolveExpression($expr->if),
            resolveExpression($expr->else)
        ]);
    }
    if ($expr instanceof Node\Expr\BinaryOp\Coalesce) {
        // ?? operator
        return new Types\Compound([
            resolveExpression($expr->left),
            resolveExpression($expr->right)
        ]);
    }
    if (
        $expr instanceof Node\Expr\InstanceOf_
        || $expr instanceof Node\Expr\BooleanNot
        || $expr instanceof Node\Expr\Empty_
        || $expr instanceof Node\Expr\Isset_
        || $expr instanceof Node\Expr\BinaryOp\Greater
        || $expr instanceof Node\Expr\BinaryOp\GreaterOrEqual
        || $expr instanceof Node\Expr\BinaryOp\Smaller
        || $expr instanceof Node\Expr\BinaryOp\SmallerOrEqual
        || $expr instanceof Node\Expr\BinaryOp\BooleanAnd
        || $expr instanceof Node\Expr\BinaryOp\BooleanOr
        || $expr instanceof Node\Expr\BinaryOp\LogicalAnd
        || $expr instanceof Node\Expr\BinaryOp\LogicalOr
        || $expr instanceof Node\Expr\BinaryOp\LogicalXor
        || $expr instanceof Node\Expr\BinaryOp\NotEqual
        || $expr instanceof Node\Expr\BinaryOp\NotIdentical
    ) {
        return new Types\Boolean_;
    }
    if (
        $expr instanceof Node\Expr\Concat
        || $expr instanceof Node\Expr\Cast\String_
        || $expr instanceof Node\Expr\Scalar\String_
    ) {
        return new Types\String_;
    }
    if (
        $expr instanceof Node\Expr\BinaryOp\Minus
        || $expr instanceof Node\Expr\BinaryOp\Plus
        || $expr instanceof Node\Expr\BinaryOp\Pow
        || $expr instanceof Node\Expr\BinaryOp\Mul
        || $expr instanceof Node\Expr\BinaryOp\Div
    ) {
        return new Types\Integer;
    }
    if ($expr instanceof Node\Expr\Array_) {
        $valueTypes = [];
        $keyTypes = [];
        foreach ($expr->items as $item) {
            $valueTypes[] = resolveExpression($item->value);
            $keyTypes[] = $item->key ? resolveExpression($item->key) : new Types\Integer;
        }
        $valueTypes = array_unique($keyTypes);
        $keyTypes = array_unique($keyTypes);
        $valueType = count($valueTypes) > 1 ? new Types\Compound($valueTypes) : $valueTypes[0];
        $keyType = count($keyTypes) > 1 ? new Types\Compound($keyTypes) : $keyTypes[0];
        return new Types\Array_($valueTypes, $keyTypes);
    }
    if ($expr instanceof Node\Expr\ArrayDimFetch) {
        $varType = resolveExpression($expr->var);
        if (!($varType instanceof Types\Array_)) {
            return new Types\Mixed;
        }
        return $varType->getValueType();
    }
    if ($expr instanceof Node\Expr\Include_) {
        // TODO: resolve path to PhpDocument and find return statement
        return new Types\Mixed;
    }
    return new Types\Mixed;
}

/**
 * Returns the assignment or parameter node where a variable was defined
 *
 * @param Node\Expr\Variable $n The variable access
 * @return Node\Expr\Assign|Node\Param|Node\Expr\ClosureUse|null
 */
function getVariableDefinition(Node\Expr\Variable $var)
{
    $n = $var;
    // Traverse the AST up
    do {
        // If a function is met, check the parameters and use statements
        if ($n instanceof Node\FunctionLike) {
            foreach ($n->getParams() as $param) {
                if ($param->name === $var->name) {
                    return $param;
                }
            }
            // If it is a closure, also check use statements
            if ($n instanceof Node\Expr\Closure) {
                foreach ($n->uses as $use) {
                    if ($use->var === $var->name) {
                        return $use;
                    }
                }
            }
            break;
        }
        // Check each previous sibling node for a variable assignment to that variable
        while ($n->getAttribute('previousSibling') && $n = $n->getAttribute('previousSibling')) {
            if ($n instanceof Node\Expr\Assign && $n->var instanceof Node\Expr\Variable && $n->var->name === $var->name) {
                return $n;
            }
        }
    } while (isset($n) && $n = $n->getAttribute('parentNode'));
    // Return null if nothing was found
    return null;
}
