<?php

namespace RscKit\Build;

/**
 * Notes every read of the properties a class keeps its data in.
 *
 * Unset, a declared property sends each access through __get and __set - the
 * parent's own methods included - so this holds for every method the class
 * has, and for one a later release adds. Overriding them by name would hold
 * for the ones somebody remembered.
 */
trait WatchesReads
{
    private Visitor $visitor;

    private string $what;

    /** @var array<string, mixed> */
    private array $held = [];

    /** @param  list<string>  $properties */
    private function watch(Visitor $visitor, string $what, array $properties): void
    {
        $this->visitor = $visitor;
        $this->what = $what;

        foreach ($properties as $property) {
            $this->held[$property] = $this->{$property};

            unset($this->{$property});
        }
    }

    public function &__get(string $name): mixed
    {
        if (! array_key_exists($name, $this->held)) {
            $missing = null;

            return $missing;
        }

        $this->visitor->read($this->what);

        return $this->held[$name];
    }

    public function __set(string $name, mixed $value): void
    {
        $this->visitor->read($this->what);

        $this->held[$name] = $value;
    }

    public function __isset(string $name): bool
    {
        $this->visitor->read($this->what);

        return isset($this->held[$name]);
    }
}
