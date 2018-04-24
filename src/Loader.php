<?php

namespace Codeurx\Dotenv;

use Codeurx\Dotenv\Exception\InvalidFileException;
use Codeurx\Dotenv\Exception\InvalidPathException;

class Loader
{
    protected $filePath;

    protected $immutable;

    public function __construct($filePath, $immutable = false)
    {
        $this->filePath = $filePath;
        $this->immutable = $immutable;
    }

    public function setImmutable($immutable = false)
    {
        $this->immutable = $immutable;

        return $this;
    }

    public function getImmutable()
    {
        return $this->immutable;
    }

    public function load()
    {
        $this->ensureFileIsReadable();

        $filePath = $this->filePath;
        $lines = $this->readLinesFromFile($filePath);
        foreach ($lines as $line) {
            if (!$this->isComment($line) && $this->looksLikeSetter($line)) {
                $this->setEnvironmentVariable($line);
            }
        }
        return $lines;
    }

    protected function ensureFileIsReadable()
    {
        if (!is_readable($this->filePath) || !is_file($this->filePath)) {
            throw new InvalidPathException(sprintf('Unable to read the environment file at %s.', $this->filePath));
        }
    }

    protected function normaliseEnvironmentVariable($name, $value)
    {
        list($name, $value) = $this->processFilters($name, $value);
        $value = $this->resolveNestedVariables($value);
        return array($name, $value);
    }

    public function processFilters($name, $value)
    {
        list($name, $value) = $this->splitCompoundStringIntoParts($name, $value);
        list($name, $value) = $this->sanitiseVariableName($name, $value);
        list($name, $value) = $this->sanitiseVariableValue($name, $value);
        return array($name, $value);
    }

    protected function readLinesFromFile($filePath)
    {
        $autodetect = ini_get('auto_detect_line_endings');
        ini_set('auto_detect_line_endings', '1');
        $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        ini_set('auto_detect_line_endings', $autodetect);
        return $lines;
    }

    protected function isComment($line)
    {
        $line = ltrim($line);
        return isset($line[0]) && $line[0] === '#';
    }

    protected function looksLikeSetter($line)
    {
        return strpos($line, '=') !== false;
    }

    protected function splitCompoundStringIntoParts($name, $value)
    {
        if (strpos($name, '=') !== false) {
            list($name, $value) = array_map('trim', explode('=', $name, 2));
        }
        return array($name, $value);
    }

    protected function sanitiseVariableValue($name, $value)
    {
        $value = trim($value);
        if (!$value) {
            return array($name, $value);
        }
        if ($this->beginsWithAQuote($value)) {
            $quote = $value[0];
            $regexPattern = sprintf(
                '/^
                %1$s          # match a quote at the start of the value
                (             # capturing sub-pattern used
                 (?:          # we do not need to capture this
                  [^%1$s\\\\] # any character other than a quote or backslash
                  |\\\\\\\\   # or two backslashes together
                  |\\\\%1$s   # or an escaped quote e.g \"
                 )*           # as many characters that match the previous rules
                )             # end of the capturing sub-pattern
                %1$s          # and the closing quote
                .*$           # and discard any string after the closing quote
                /mx',
                $quote
            );
            $value = preg_replace($regexPattern, '$1', $value);
            $value = str_replace("\\$quote", $quote, $value);
            $value = str_replace('\\\\', '\\', $value);
        } else {
            $parts = explode(' #', $value, 2);
            $value = trim($parts[0]);
            if (preg_match('/\s+/', $value) > 0) {
                throw new InvalidFileException('Dotenv values containing spaces must be surrounded by quotes.');
            }
        }
        return array($name, trim($value));
    }

    protected function resolveNestedVariables($value)
    {
        if (strpos($value, '$') !== false) {
            $loader = $this;
            $value = preg_replace_callback(
                '/\${([a-zA-Z0-9_.]+)}/',
                function ($matchedPatterns) use ($loader) {
                    $nestedVariable = $loader->getEnvironmentVariable($matchedPatterns[1]);
                    if ($nestedVariable === null) {
                        return $matchedPatterns[0];
                    } else {
                        return $nestedVariable;
                    }
                },
                $value
            );
        }
        return $value;
    }

    protected function sanitiseVariableName($name, $value)
    {
        $name = trim(str_replace(array('export ', '\'', '"'), '', $name));
        return array($name, $value);
    }

    protected function beginsWithAQuote($value)
    {
        return isset($value[0]) && ($value[0] === '"' || $value[0] === '\'');
    }

    public function getEnvironmentVariable($name)
    {
        switch (true) {
            case array_key_exists($name, $_ENV):
                return $_ENV[$name];
            case array_key_exists($name, $_SERVER):
                return $_SERVER[$name];
            default:
                $value = getenv($name);
                return $value === false ? null : $value;
        }
    }

    public function setEnvironmentVariable($name, $value = null)
    {
        list($name, $value) = $this->normaliseEnvironmentVariable($name, $value);
        if ($this->immutable && $this->getEnvironmentVariable($name) !== null) {
            return;
        }
        if (function_exists('apache_getenv') && function_exists('apache_setenv') && apache_getenv($name)) {
            apache_setenv($name, $value);
        }
        if (function_exists('putenv')) {
            putenv("$name=$value");
        }
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }

    public function clearEnvironmentVariable($name)
    {
        if ($this->immutable) {
            return;
        }
        if (function_exists('putenv')) {
            putenv($name);
        }
        unset($_ENV[$name], $_SERVER[$name]);
    }
}
