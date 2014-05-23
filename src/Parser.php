<?php

namespace NginxConf;

/**
 * Nginx Config Parser
 */
class Parser
{
    /**
     * The config file to parse.
     * @var string
     */
    private $file;

    /**
     * Current Position in String Buffer
     * @var int
     */
    private $index;

    public function __construct($file)
    {
        $this->file    = $file;
        $this->index   = -1;
        $this->tree    = null;
        $this->context = null;
        $this->error   = null;
    }

    public function parse($file = '')
    {
        $this->file = ($file !== '') ? $file : $this->file;
        echo 'Reading ' . $this->file;

        $this->source  = file_get_contents($this->file);
        $this->index   = 0;
        $this->tree    = new TreeNode('[root]');
        $this->context = new TreeNode(null, null, $this->tree);
        $this->error   = null;

        do {
            $this->parseNext();
            if ($this->error) {
                print_r($this->error);
                return;
            }
        } while ($this->index < strlen($this->source));

        print_r($this->tree);
    }

    function parseNext()
    {
        $c = $this->source{$this->index};

        //echo 'Current Token = "' . $c . '"' . PHP_EOL;

        $value = '';

        if (!$c) {
            return;
        }

        switch ($c) {
            case '{':
            case ';':
                $this->context->value = trim( $this->context->value );
                $this->context->parent->children[] = $this->context;
                //new context is child of current context, or a sibling to the parent
                $this->context = new TreeNode(null, null, $c === '{' ? $this->context : $this->context->parent);
                $this->index++;
                break;
            case '}':
                //new context is sibling to the parent
                $this->context = new TreeNode(null, null, $this->context->parent->parent);
                $this->index++;
                break;
            case "\n":
            case "\r":
                if ($this->context->value) {
                    $this->context->value += $c;
                }
                $this->index++;
                break;
            case "'":
            case '"':
                if (!$this->context->name) {
                    $this->setError('Found string, but expected directive.');
                    return;
                }

                $this->context->value += $this->readString();
                break;
            case '#':
                $this->context->comments[] = $this->readComment();
                break;
            default:
                $value = $this->readWord();
                if (!$this->context->name) {
                    $this->context->name = trim($value);
                    //read trailing whitespace
                    $ws = preg_match('/^\s*/', substr($this->source, $this->index), $matches);
                    if ($ws) {
                        $this->index += strlen($matches[0]);
                    }
                } else {
                    $this->context->value += $value;
                }
                break;
        }
    }

    function setError($message)
    {
        // determine current "line number" and "column" (index pos on last line)
        $source = substr($this->source, 0, $this->index);
        $lines = explode("\n", $source);
        $line = count($lines);
        $lastline = end($lines);
        $column = strlen($lastline);

        $this->error = array(
            'message' => $message,
            'line'    => $line,
            'column'  => $column,
            'index'   => $this->index
        );
    }

    function readString()
    {
        $delimiter = $this->source{$this->index};
        $value     = $delimiter;

        $pos    = $this->index + 1;
        $length = strlen($this->source);

        for ($i = $pos; $i < $length; $i++) {
            if ($this->source{$i} === '\\') {
                $value += $this->source{$i} + $this->source{($i + 1)};
                $i++;
                continue;
            }

            if ($this->source{$i} === $delimiter) {
                $value += $delimiter;
                break;
            }

            $value += $this->source{$i};
        }

        if (strlen($value) < 2 || $value{(strlen($value) - 1)} !== $delimiter) {
            $this->setError('Unable to parse quote-delimited value (probably an unclosed string)');
            return '';
        }

        $this->index += strlen($value);

        return $value;
    }

    // a comment doesn't have to end with a semicolon
    function readComment()
    {
        $str    = substr($this->source, $this->index);
        $result = preg_match('/^(.*?)(?:\r\n|\n|$)/', $str, $matches);

        $this->index += ($result) ? strlen($matches[0]) : 0;

        return substr($matches[1], 1); // ignore # character and EOL
    }

    function readWord()
    {
        $str    = substr($this->source, $this->index);
        $result = preg_match('/^(.+?)[\s#;{}\'"]/', $str, $matches);

        if ($result === 0) {
            $this->setError('Word not terminated. Are you missing a semicolon?');
            return '';
        }

        $this->index += strlen($matches[1]);

        return $matches[1];
    }

}

class TreeNode
{
    public $name;
    public $value;
    public $parent;
    public $children;
    public $comments;

    public function __construct($name = '', $value = '', $parent = null, $children = array())
    {
        $this->name     = $name;
        $this->value    = $value;
        $this->parent   = $parent;
        $this->children = $children;
        $this->comments = array();
    }

}