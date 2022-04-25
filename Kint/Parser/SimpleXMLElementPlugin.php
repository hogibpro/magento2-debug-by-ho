<?php

/*
 * The MIT License (MIT)
 *
 * Copyright (c) 2013 Jonathan Vollebregt (jnvsor@gmail.com), Rokas Šleinius (raveren@gmail.com)
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy of
 * this software and associated documentation files (the "Software"), to deal in
 * the Software without restriction, including without limitation the rights to
 * use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies of
 * the Software, and to permit persons to whom the Software is furnished to do so,
 * subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY, FITNESS
 * FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR
 * COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER
 * IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN
 * CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.
 */

namespace Ho\Firephp\Kint\Parser;

use Ho\Firephp\Kint\Zval\BlobValue;
use Ho\Firephp\Kint\Zval\Representation\Representation;
use Ho\Firephp\Kint\Zval\SimpleXMLElementValue;
use Ho\Firephp\Kint\Zval\Value;
use SimpleXMLElement;

class SimpleXMLElementPlugin extends Plugin
{
    /**
     * Show all properties and methods.
     *
     * @var bool
     */
    public static $verbose = false;

    public function getTypes()
    {
        return ['object'];
    }

    public function getTriggers()
    {
        return Parser::TRIGGER_SUCCESS;
    }

    public function parse(&$var, Value &$o, $trigger)
    {
        if (!$var instanceof SimpleXMLElement) {
            return;
        }

        if (!self::$verbose) {
            $o->removeRepresentation('properties');
            $o->removeRepresentation('iterator');
            $o->removeRepresentation('methods');
        }

        // An invalid SimpleXMLElement can gum up the works with
        // warnings if we call stuff children/attributes on it.
        if (!$var) {
            $o->size = null;

            return;
        }

        $x = new SimpleXMLElementValue();
        $x->transplant($o);

        $namespaces = \array_merge([null], $var->getDocNamespaces());

        // Attributes
        $a = new Representation('Attributes');

        $base_obj = new Value();
        $base_obj->depth = $x->depth;

        if ($x->access_path) {
            $base_obj->access_path = '(string) '.$x->access_path;
        }

        // Attributes are strings. If we're too deep set the
        // depth limit to enable parsing them, but no deeper.
        if ($this->parser->getDepthLimit() && $this->parser->getDepthLimit() - 2 < $base_obj->depth) {
            $base_obj->depth = $this->parser->getDepthLimit() - 2;
        }

        $attribs = [];

        foreach ($namespaces as $nsAlias => $nsUrl) {
            if ($nsAttribs = $var->attributes($nsUrl)) {
                $nsAttribs = \iterator_to_array($nsAttribs);
                $nsAttribs = \array_map('strval', $nsAttribs);

                if (null === $nsUrl) {
                    $obj = clone $base_obj;
                    if ($obj->access_path) {
                        $obj->access_path .= '->attributes()';
                    }

                    $a->contents = $this->parser->parse($nsAttribs, $obj)->value->contents;
                } else {
                    $obj = clone $base_obj;
                    if ($obj->access_path) {
                        $obj->access_path .= '->attributes('.\var_export($nsAlias, true).', true)';
                    }

                    $nsAttribs = $this->parser->parse($nsAttribs, $obj)->value->contents;

                    foreach ($nsAttribs as $attribute) {
                        $attribute->name = $nsAlias.':'.$attribute->name;
                        $a->contents[] = $attribute;
                    }
                }
            }
        }

        $x->addRepresentation($a, 0);

        // Children
        $c = new Representation('Children');

        foreach ($namespaces as $nsAlias => $nsUrl) {
            // This is doubling items because of the root namespace
            // and the implicit namespace on its children.
            $thisNs = $var->getNamespaces();
            if (isset($thisNs['']) && $thisNs[''] === $nsUrl) {
                continue;
            }

            if ($nsChildren = $var->children($nsUrl)) {
                $nsap = [];
                foreach ($nsChildren as $name => $child) {
                    $obj = new Value();
                    $obj->depth = $x->depth + 1;
                    $obj->name = $name;
                    if ($x->access_path) {
                        if (null === $nsUrl) {
                            $obj->access_path = $x->access_path.'->children()->';
                        } else {
                            $obj->access_path = $x->access_path.'->children('.\var_export($nsAlias, true).', true)->';
                        }

                        if (\preg_match('/^[a-zA-Z_\\x7f-\\xff][a-zA-Z0-9_\\x7f-\\xff]*$/', $name)) {
                            $obj->access_path .= $name;
                        } else {
                            $obj->access_path .= '{'.\var_export((string) $name, true).'}';
                        }

                        if (isset($nsap[$obj->access_path])) {
                            ++$nsap[$obj->access_path];
                            $obj->access_path .= '['.$nsap[$obj->access_path].']';
                        } else {
                            $nsap[$obj->access_path] = 0;
                        }
                    }

                    $value = $this->parser->parse($child, $obj);

                    if ($value->access_path && 'string' === $value->type) {
                        $value->access_path = '(string) '.$value->access_path;
                    }

                    $c->contents[] = $value;
                }
            }
        }

        $x->size = \count($c->contents);

        if ($x->size) {
            $x->addRepresentation($c, 0);
        } else {
            $x->size = null;

            if (\strlen((string) $var)) {
                $base_obj = new BlobValue();
                $base_obj->depth = $x->depth + 1;
                $base_obj->name = $x->name;
                if ($x->access_path) {
                    $base_obj->access_path = '(string) '.$x->access_path;
                }

                $value = (string) $var;

                $s = $this->parser->parse($value, $base_obj);
                $srep = $s->getRepresentation('contents');
                $svalrep = $s->value && 'contents' == $s->value->getName() ? $s : null;

                if ($srep || $svalrep) {
                    $x->setIsStringValue(true);
                    $x->value = $srep ?: $svalrep;

                    if ($srep) {
                        $x->replaceRepresentation($x->value, 0);
                    }
                }

                $reps = \array_reverse($s->getRepresentations());

                foreach ($reps as $rep) {
                    $x->addRepresentation($rep, 0);
                }
            }
        }

        $o = $x;
    }
}