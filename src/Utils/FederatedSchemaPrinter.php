<?php

/**
 * 
 * This source file includes modified code from webonyx/graphql-php.
 *
 * Copyright (c) 2015-present, Webonyx, LLC.
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 *
 * @copyright Copyright (c) Webonyx, LLC.
 * @license https://opensource.org/licenses/MIT MIT License
 */

declare(strict_types=1);

namespace Apollo\Federation\Utils;

use GraphQL\Error\Error;
use GraphQL\Language\Printer;
use GraphQL\Type\Definition\Directive;
use GraphQL\Type\Definition\EnumType;
use GraphQL\Type\Definition\InputObjectType;
use GraphQL\Type\Definition\InterfaceType;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\ScalarType;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Definition\UnionType;
use GraphQL\Type\Introspection;
use GraphQL\Type\Schema;
use GraphQL\Utils\AST;
use GraphQL\Utils\Utils;

use Apollo\Federation\Types\EntityObjectType;
use Apollo\Federation\Types\EntityRefObjectType;

use function array_filter;
use function array_keys;
use function array_map;
use function array_merge;
use function array_values;
use function count;
use function explode;
use function implode;
use function ksort;
use function mb_strlen;
use function preg_match_all;
use function sprintf;
use function str_replace;
use function strlen;
use function substr;

/**
 * Given an instance of Schema, prints it in GraphQL type language.
 */
class FederatedSchemaPrinter
{
    /**
     * Accepts options as a second argument:
     *
     *    - commentDescriptions:
     *        Provide true to use preceding comments as the description.
     *
     * @param bool[] $options
     *
     * @api
     */
    public static function doPrint(Schema $schema, array $options = []): string
    {
        return self::printFilteredSchema(
            $schema,
            static function ($type) {
                return !Directive::isSpecifiedDirective($type) && !self::isFederatedDirective($type);
            },
            static function ($type) {
                return !Type::isBuiltInType($type);
            },
            $options
        );
    }

    public static function isFederatedDirective($type): bool
    {
        return in_array($type->name, ['key', 'provides', 'requires', 'external']);
    }

    /**
     * @param bool[] $options
     */
    private static function printFilteredSchema(Schema $schema, $directiveFilter, $typeFilter, $options): string
    {
        $directives = array_filter($schema->getDirectives(), static function ($directive) use ($directiveFilter) {
            return $directiveFilter($directive);
        });

        $types = $schema->getTypeMap();
        ksort($types);
        $types = array_filter($types, $typeFilter);

        return sprintf(
            "%s\n",
            implode(
                "\n\n",
                array_filter(
                    array_merge(
                        array_map(static function ($directive) use ($options) {
                            return self::printDirective($directive, $options);
                        }, $directives),
                        array_map(static function ($type) use ($options) {
                            return self::printType($type, $options);
                        }, $types)
                    )
                )
            )
        );
    }

    private static function printDirective($directive, $options): string
    {
        return self::printDescription($options, $directive) .
            'directive @' .
            $directive->name .
            self::printArgs($options, $directive->args) .
            ' on ' .
            implode(' | ', $directive->locations);
    }

    private static function printDescription($options, $def, $indentation = '', $firstInBlock = true): string
    {
        if (!$def->description) {
            return '';
        }

        $lines = self::descriptionLines($def->description, 120 - strlen($indentation));

        if (isset($options['commentDescriptions'])) {
            return self::printDescriptionWithComments($lines, $indentation, $firstInBlock);
        }

        $description = $indentation && !$firstInBlock ? "\n" . $indentation . '"""' : $indentation . '"""';

        // In some circumstances, a single line can be used for the description.
        if (count($lines) === 1 && mb_strlen($lines[0]) < 70 && substr($lines[0], -1) !== '"') {
            return $description . self::escapeQuote($lines[0]) . "\"\"\"\n";
        }

        // Format a multi-line block quote to account for leading space.
        $hasLeadingSpace = isset($lines[0]) && (substr($lines[0], 0, 1) === ' ' || substr($lines[0], 0, 1) === '\t');

        if (!$hasLeadingSpace) {
            $description .= "\n";
        }

        $lineLength = count($lines);

        for ($i = 0; $i < $lineLength; $i++) {
            if ($i !== 0 || !$hasLeadingSpace) {
                $description .= $indentation;
            }
            $description .= self::escapeQuote($lines[$i]) . "\n";
        }

        $description .= $indentation . "\"\"\"\n";

        return $description;
    }

    /**
     * @return string[]
     */
    private static function descriptionLines(string $description, int $maxLen): array
    {
        $lines = [];
        $rawLines = explode("\n", $description);

        foreach ($rawLines as $line) {
            if ($line === '') {
                $lines[] = $line;
            } else {
                // For > 120 character long lines, cut at space boundaries into sublines
                // of ~80 chars.
                $sublines = self::breakLine($line, $maxLen);

                foreach ($sublines as $subline) {
                    $lines[] = $subline;
                }
            }
        }

        return $lines;
    }

    /**
     * @return string[]
     */
    private static function breakLine(string $line, int $maxLen): array
    {
        if (strlen($line) < $maxLen + 5) {
            return [$line];
        }

        preg_match_all('/((?: |^).{15,' . ($maxLen - 40) . '}(?= |$))/', $line, $parts);

        $parts = $parts[0];

        return array_map('trim', $parts);
    }

    private static function printDescriptionWithComments($lines, $indentation, $firstInBlock): string
    {
        $description = $indentation && !$firstInBlock ? "\n" : '';

        foreach ($lines as $line) {
            if ($line === '') {
                $description .= $indentation . "#\n";
            } else {
                $description .= $indentation . '# ' . $line . "\n";
            }
        }

        return $description;
    }

    private static function escapeQuote($line): string
    {
        return str_replace('"""', '\\"""', $line);
    }

    private static function printArgs($options, $args, $indentation = ''): string
    {
        if (!$args) {
            return '';
        }

        // If every arg does not have a description, print them on one line.
        if (
            Utils::every($args, static function ($arg) {
                return empty($arg->description);
            })
        ) {
            return '(' . implode(', ', array_map('self::printInputValue', $args)) . ')';
        }

        return sprintf(
            "(\n%s\n%s)",
            implode(
                "\n",
                array_map(
                    static function ($arg, $i) use ($indentation, $options) {
                        return self::printDescription($options, $arg, '  ' . $indentation, !$i) .
                            '  ' .
                            $indentation .
                            self::printInputValue($arg);
                    },
                    $args,
                    array_keys($args)
                )
            ),
            $indentation
        );
    }

    private static function printInputValue($arg): string
    {
        $argDecl = $arg->name . ': ' . (string) $arg->getType();

        if ($arg->defaultValueExists()) {
            $argDecl .= ' = ' . Printer::doPrint(AST::astFromValue($arg->defaultValue, $arg->getType()));
        }

        return $argDecl;
    }

    /**
     * @param bool[] $options
     */
    public static function printType(Type $type, array $options = []): string
    {
        if ($type instanceof ScalarType) {
            if ($type->name !== '_Any') {
                return self::printScalar($type, $options);
            } else {
                return '';
            }
        }

        if ($type instanceof EntityObjectType || $type instanceof EntityRefObjectType) {
            return self::printEntityObject($type, $options);
        }

        if ($type instanceof ObjectType) {
            if ($type->name !== '_Service') {
                return self::printObject($type, $options);
            } else {
                return '';
            }
        }

        if ($type instanceof InterfaceType) {
            return self::printInterface($type, $options);
        }

        if ($type instanceof UnionType) {
            if ($type->name !== '_Entity') {
                return self::printUnion($type, $options);
            } else {
                return '';
            }
        }

        if ($type instanceof EnumType) {
            return self::printEnum($type, $options);
        }

        if ($type instanceof InputObjectType) {
            return self::printInputObject($type, $options);
        }

        throw new Error(sprintf('Unknown type: %s.', Utils::printSafe($type)));
    }

    /**
     * @param bool[] $options
     */
    private static function printScalar(ScalarType $type, array $options): string
    {
        return sprintf('%sscalar %s', self::printDescription($options, $type), $type->name);
    }

    /**
     * @param bool[] $options
     */
    private static function printObject(ObjectType $type, array $options): string
    {
        if (empty($type->getFields())) {
            return '';
        }

        $interfaces = $type->getInterfaces();
        $implementedInterfaces = !empty($interfaces)
            ? ' implements ' .
                implode(
                    ' & ',
                    array_map(static function ($i) {
                        return $i->name;
                    }, $interfaces)
                )
            : '';

        $queryExtends = $type->name === 'Query' || $type->name === 'Mutation' ? 'extend ' : '';

        return self::printDescription($options, $type) .
            sprintf(
                "%stype %s%s {\n%s\n}",
                $queryExtends,
                $type->name,
                $implementedInterfaces,
                self::printFields($options, $type)
            );
    }

    /**
     * @param bool[] $options
     */
    private static function printEntityObject(EntityObjectType $type, array $options): string
    {
        $interfaces = $type->getInterfaces();
        $implementedInterfaces = !empty($interfaces)
            ? ' implements ' .
                implode(
                    ' & ',
                    array_map(static function ($i) {
                        return $i->name;
                    }, $interfaces)
                )
            : '';

        $keyDirective = '';

        foreach ($type->getKeyFields() as $keyField) {
            $keyDirective = $keyDirective . sprintf(' @key(fields: "%s")', $keyField);
        }

        $isEntityRef = $type instanceof EntityRefObjectType;
        $extends = $isEntityRef ? 'extend ' : '';

        return self::printDescription($options, $type) .
            sprintf(
                "%stype %s%s%s {\n%s\n}",
                $extends,
                $type->name,
                $implementedInterfaces,
                $keyDirective,
                self::printFields($options, $type)
            );
    }

    /**
     * @param bool[] $options
     */
    private static function printFields($options, $type): string
    {
        $fields = array_values($type->getFields());

        if ($type->name === 'Query') {
            $fields = array_filter($fields, function ($field) {
                return $field->name !== '_service' && $field->name !== '_entities';
            });
        }

        return implode(
            "\n",
            array_map(
                static function ($f, $i) use ($options) {
                    return self::printDescription($options, $f, '  ', !$i) .
                        '  ' .
                        $f->name .
                        self::printArgs($options, $f->args, '  ') .
                        ': ' .
                        (string) $f->getType() .
                        self::printDeprecated($f) .
                        ' ' .
                        self::printFieldFederatedDirectives($f);
                },
                $fields,
                array_keys($fields)
            )
        );
    }

    private static function printDeprecated($fieldOrEnumVal): string
    {
        $reason = $fieldOrEnumVal->deprecationReason;
        if (empty($reason)) {
            return '';
        }
        if ($reason === '' || $reason === Directive::DEFAULT_DEPRECATION_REASON) {
            return ' @deprecated';
        }

        return ' @deprecated(reason: ' . Printer::doPrint(AST::astFromValue($reason, Type::string())) . ')';
    }

    private static function printFieldFederatedDirectives($field)
    {
        $directives = [];

        if (isset($field->config['isExternal']) && $field->config['isExternal'] === true) {
            array_push($directives, '@external');
        }

        if (isset($field->config['provides'])) {
            array_push($directives, sprintf('@provides(fields: "%s")', $field->config['provides']));
        }

        if (isset($field->config['requires'])) {
            array_push($directives, sprintf('@requires(fields: "%s")', $field->config['requires']));
        }

        return implode(' ', $directives);
    }

    /**
     * @param bool[] $options
     */
    private static function printInterface(InterfaceType $type, array $options): string
    {
        return self::printDescription($options, $type) .
            sprintf("interface %s {\n%s\n}", $type->name, self::printFields($options, $type));
    }

    /**
     * @param bool[] $options
     */
    private static function printUnion(UnionType $type, array $options): string
    {
        return self::printDescription($options, $type) .
            sprintf('union %s = %s', $type->name, implode(' | ', $type->getTypes()));
    }

    /**
     * @param bool[] $options
     */
    private static function printEnum(EnumType $type, array $options): string
    {
        return self::printDescription($options, $type) .
            sprintf("enum %s {\n%s\n}", $type->name, self::printEnumValues($type->getValues(), $options));
    }

    /**
     * @param bool[] $options
     */
    private static function printEnumValues($values, $options): string
    {
        return implode(
            "\n",
            array_map(
                static function ($value, $i) use ($options) {
                    return self::printDescription($options, $value, '  ', !$i) .
                        '  ' .
                        $value->name .
                        self::printDeprecated($value);
                },
                $values,
                array_keys($values)
            )
        );
    }

    /**
     * @param bool[] $options
     */
    private static function printInputObject(InputObjectType $type, array $options): string
    {
        $fields = array_values($type->getFields());

        return self::printDescription($options, $type) .
            sprintf(
                "input %s {\n%s\n}",
                $type->name,
                implode(
                    "\n",
                    array_map(
                        static function ($f, $i) use ($options) {
                            return self::printDescription($options, $f, '  ', !$i) . '  ' . self::printInputValue($f);
                        },
                        $fields,
                        array_keys($fields)
                    )
                )
            );
    }

    /**
     * @param bool[] $options
     *
     * @api
     */
    public static function printIntrospectionSchema(Schema $schema, array $options = []): string
    {
        return self::printFilteredSchema(
            $schema,
            [Directive::class, 'isSpecifiedDirective'],
            [Introspection::class, 'isIntrospectionType'],
            $options
        );
    }
}
